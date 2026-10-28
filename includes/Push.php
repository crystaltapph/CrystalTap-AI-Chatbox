<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Push {
    public static function encrypt(string $plain): string {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', wp_salt('secure_auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new \RuntimeException('Encryption failed'); }
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt(string $encoded): string {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) { throw new \RuntimeException('Invalid subscription'); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('secure_auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new \RuntimeException('Subscription key changed; register the device again'); }
        return $plain;
    }
    public static function validate(array $sub): void {
        $url = $sub['endpoint'] ?? '';
        $parts = is_string($url) ? wp_parse_url($url) : false;
        $hosts = ['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com','wns2-db5p.notify.windows.com'];
        $hosts = apply_filters('ctai_push_hosts', $hosts);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', $hosts, true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443) || strlen($url) > 2000) { throw new Fault('Unsupported push endpoint'); }
        foreach (['p256dh','auth'] as $key) { if (!is_string($sub['keys'][$key] ?? null) || !preg_match('/^[A-Za-z0-9_-]{20,200}={0,2}$/', $sub['keys'][$key])) { throw new Fault('Invalid push subscription key'); } }
    }
    public static function register(int $user, array $sub): array {
        global $wpdb;
        if (!wp_get_session_token()) { throw new Fault('A cookie-authenticated device session is required', 403); }
        self::validate($sub);
        $t = DB::table('push_subscriptions');
        $hash = hash('sha256', $sub['endpoint']);
        $wpdb->query($wpdb->prepare("INSERT INTO $t (user_id,session_hash,endpoint_hash,subscription,created_at) VALUES (%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),session_hash=VALUES(session_hash),subscription=VALUES(subscription)", $user, hash('sha256', wp_get_session_token()), $hash, self::encrypt(wp_json_encode($sub)), DB::now()));
        DB::audit('device_registered');
        return ['registered' => true];
    }
    public static function logout(): void {
        global $wpdb;
        if (get_current_user_id()) { $wpdb->delete(DB::table('push_subscriptions'), ['user_id' => get_current_user_id(), 'session_hash' => hash('sha256', wp_get_session_token())]); }
    }
    public static function diagnostics(int $user): array {
        global $wpdb;
        $last = (int) get_option('ctai_last_tick');
        $next = wp_next_scheduled('ctai_tick');
        $prefs = Notifications::preferences($user);
        $registered = wp_get_session_token() && (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . DB::table('push_subscriptions') . ' WHERE user_id=%d AND session_hash=%s LIMIT 1', $user, hash('sha256', wp_get_session_token())));
        $pending = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DB::table('jobs') . ' j INNER JOIN ' . DB::table('notifications') . " n ON j.dedup_key=CONCAT('push-',n.id) WHERE j.type='push' AND n.user_id=%d AND n.read_at IS NULL", $user));
        return ['this_session_registered' => $registered, 'push_enabled' => (bool) $prefs['push'], 'quiet_hours_now' => self::quiet($prefs), 'worker_scheduled' => (bool) $next, 'worker_last_run_seconds_ago' => $last ? max(0, time() - $last) : null, 'worker_next_run_in_seconds' => $next ? $next - time() : null, 'pending_push_jobs' => $pending];
    }
    private static function quiet(array $prefs): bool {
        $hour = wp_date('H:i'); $start = $prefs['quiet_start']; $end = $prefs['quiet_end'];
        return $start && $end && ($start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end));
    }
    private static function sender(): \Minishlink\WebPush\WebPush {
        $s = Settings::get(); $dependencies = Dependencies::pushStatus();
        if (!$dependencies['ready']) { throw new Fault($dependencies['issue'], 503, 'push_dependencies'); }
        if (!$s['vapid_public'] || !Settings::secret('vapid_private')) { throw new Fault('Configure VAPID keys in CrystalTap Settings first.', 409, 'push_configuration'); }
        $options = apply_filters('ctai_push_client_options', []);
        // Keep verified TLS and prevent redirects, including with a custom transport.
        $options['verify'] = getenv('CTAI_CA_BUNDLE') ?: true;
        $options['allow_redirects'] = false; $options['timeout'] = 10;
        return new \Minishlink\WebPush\WebPush(['VAPID' => ['subject' => $s['vapid_subject'], 'publicKey' => $s['vapid_public'], 'privateKey' => Settings::secret('vapid_private')]], ['TTL' => 300], 10, $options);
    }
    private static function send(\Minishlink\WebPush\WebPush $sender, array $row, array $payload): \Minishlink\WebPush\MessageSentReport {
        $sub = json_decode(self::decrypt($row['subscription']), true);
        self::validate($sub);
        return $sender->sendOneNotification(\Minishlink\WebPush\Subscription::create($sub), wp_json_encode($payload));
    }
    public static function test(int $user, string $endpoint): array {
        global $wpdb;
        $token = wp_get_session_token();
        if (!$token || !\WP_Session_Tokens::get_instance($user)->verify($token)) { throw new Fault('Sign in again before testing push.', 403, 'push_session'); }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('push_subscriptions') . ' WHERE user_id=%d AND session_hash=%s AND endpoint_hash=%s LIMIT 1', $user, hash('sha256', $token), hash('sha256', $endpoint)), ARRAY_A);
        if (!$row) { throw new Fault('Enable push on this device again before testing.', 409, 'push_registration'); }
        try {
            $report = self::send(self::sender(), $row, ['test' => true, 'tag' => 'ctai-test-' . wp_generate_uuid4(), 'conversation' => 0]);
            if ($report->isSubscriptionExpired()) {
                $wpdb->delete(DB::table('push_subscriptions'), ['id' => $row['id']]);
                throw new Fault('This push registration expired. Enable push on this device again.', 409, 'push_expired');
            }
            if (!$report->isSuccess()) {
                $status = $report->getResponse()?->getStatusCode();
                if (in_array($status, [401, 403], true)) { throw new Fault('The push service rejected authentication. Check that the public and private VAPID keys match, then register this device again.', 502, 'push_authentication'); }
                throw new Fault('The push service did not accept the test. Ask your host to check outbound HTTPS to browser push services and try again.', 502, 'push_delivery');
            }
        } catch (Fault $e) { throw $e; }
        catch (\Throwable $e) { throw new Fault('The push test failed. Check VAPID configuration and ask your host to check outbound HTTPS, TLS trust and PHP OpenSSL support.', 502, 'push_delivery'); }
        DB::audit('push_test', 0, 'accepted');
        return ['accepted' => true, 'message' => 'The push service accepted the test. Check your phone notification tray. Acceptance does not confirm device delivery. This manual test bypasses quiet hours, event preferences and WordPress cron.'];
    }
    public static function deliver(int $notification): void {
        global $wpdb;
        $n = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('notifications') . ' WHERE id=%d', $notification), ARRAY_A);
        if (!$n || $n['read_at'] || !user_can($n['user_id'], 'ctai_agent')) { return; }
        $prefs = Notifications::preferences((int) $n['user_id']);
        if (!$prefs['push'] || empty($prefs[$n['type']])) { return; }
        if (self::quiet($prefs)) { return; }
        $c = Conversations::get((int) $n['conversation_id']);
        if (!user_can($n['user_id'], 'ctai_supervise') && (int) $c['assigned_agent'] !== (int) $n['user_id'] && $c['status'] !== 'waiting') { return; }
        $s = Settings::get();
        if (!Settings::secret('vapid_private') || !$s['vapid_public']) { return; }
        $push = self::sender();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DB::table('push_subscriptions') . ' WHERE user_id=%d LIMIT 10', $n['user_id']), ARRAY_A);
        $sessions = get_user_meta((int) $n['user_id'], 'session_tokens', true) ?: [];
        $failed = false;
        foreach ($rows as $row) {
            if (!isset($sessions[$row['session_hash']]) || ($sessions[$row['session_hash']]['expiration'] ?? 0) < time()) { $wpdb->delete(DB::table('push_subscriptions'), ['id' => $row['id']]); continue; }
            try {
                $report = self::send($push, $row, ['tag' => 'ctai-' . $n['id'], 'conversation' => (int) $n['conversation_id']]);
                if ($report->isSubscriptionExpired()) { $wpdb->delete(DB::table('push_subscriptions'), ['id' => $row['id']]); }
                elseif (!$report->isSuccess()) { $failed = true; }
            } catch (\Throwable $e) { $failed = true; }
        }
        if ($failed) { throw new \RuntimeException('Push retry required'); }
    }
}
