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
    public static function deliver(int $notification): void {
        global $wpdb;
        $n = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('notifications') . ' WHERE id=%d', $notification), ARRAY_A);
        if (!$n || $n['read_at'] || !user_can($n['user_id'], 'ctai_agent')) { return; }
        $prefs = Notifications::preferences((int) $n['user_id']);
        if (!$prefs['push'] || empty($prefs[$n['type']])) { return; }
        $hour = wp_date('H:i');
        $start = $prefs['quiet_start']; $end = $prefs['quiet_end'];
        if ($start && $end && ($start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end))) { return; }
        $c = Conversations::get((int) $n['conversation_id']);
        if (!user_can($n['user_id'], 'ctai_supervise') && (int) $c['assigned_agent'] !== (int) $n['user_id'] && $c['status'] !== 'waiting') { return; }
        $s = Settings::get();
        if (!Settings::secret('vapid_private') || !$s['vapid_public']) { return; }
        if (!class_exists(\Minishlink\WebPush\WebPush::class)) { throw new \RuntimeException('Web Push library is missing'); }
        $push = new \Minishlink\WebPush\WebPush(['VAPID' => ['subject' => $s['vapid_subject'], 'publicKey' => $s['vapid_public'], 'privateKey' => Settings::secret('vapid_private')]], ['TTL' => 300], 10, ['allow_redirects' => false, 'verify' => getenv('CTAI_CA_BUNDLE') ?: true]);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DB::table('push_subscriptions') . ' WHERE user_id=%d LIMIT 10', $n['user_id']), ARRAY_A);
        $sessions = get_user_meta((int) $n['user_id'], 'session_tokens', true) ?: [];
        $failed = false;
        foreach ($rows as $row) {
            if (!isset($sessions[$row['session_hash']]) || ($sessions[$row['session_hash']]['expiration'] ?? 0) < time()) { $wpdb->delete(DB::table('push_subscriptions'), ['id' => $row['id']]); continue; }
            try {
                $sub = json_decode(self::decrypt($row['subscription']), true);
                self::validate($sub);
                $payload = wp_json_encode(['title' => 'CrystalTap Agent', 'body' => 'A support conversation needs your attention.', 'tag' => 'ctai-' . $n['id'], 'conversation' => (int) $n['conversation_id']]);
                $report = $push->sendOneNotification(\Minishlink\WebPush\Subscription::create($sub), $payload);
                if ($report->isSubscriptionExpired()) { $wpdb->delete(DB::table('push_subscriptions'), ['id' => $row['id']]); }
                elseif (!$report->isSuccess()) { $failed = true; }
            } catch (\Throwable $e) { $failed = true; }
        }
        if ($failed) { throw new \RuntimeException('Push retry required'); }
    }
}
