<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class API {
    private static bool $streamed = false;
    private static function route(string $path, string $methods, callable $callback, string $access = 'agent'): void {
        register_rest_route('crystaltap-ai/v1', $path, ['methods' => $methods, 'permission_callback' => static function (\WP_REST_Request $r) use ($access) {
            try {
                Auth::origin();
                if ($access === 'public') { return true; }
                if ($access === 'visitor') { Auth::visitor($r); return true; }
                Auth::agent($access === 'manage' ? 'ctai_manage' : ($access === 'supervise' ? 'ctai_supervise' : 'ctai_agent'));
                return true;
            } catch (Fault $e) { return new \WP_Error($e->slug, $e->getMessage(), ['status' => $e->status]); }
        }, 'callback' => static function (\WP_REST_Request $r) use ($callback) {
            try {
                $result = $callback($r);
                $response = rest_ensure_response($result);
                $response->header('Cache-Control', 'no-store, private, max-age=0');
                $response->header('X-Content-Type-Options', 'nosniff');
                return $response;
            } catch (Fault $e) { return new \WP_Error($e->slug, $e->getMessage(), ['status' => $e->status]); }
            catch (\InvalidArgumentException $e) { return new \WP_Error('invalid_setting', $e->getMessage(), ['status' => 400]); }
            catch (\Throwable $e) { return new \WP_Error('server_error', 'This operation could not be completed. Please retry or contact support.', ['status' => 500]); }
        }]);
    }
    private static function input(\WP_REST_Request $r): array {
        if (strlen($r->get_body()) > 65536) { throw new Fault('Request is too large', 413); }
        $body = $r->get_json_params();
        if (!is_array($body)) { throw new Fault('JSON object required'); }
        return $body;
    }
    public static function register(): void {
        add_filter('rest_pre_serve_request', static fn($served) => self::$streamed || $served);
        self::route('/public/session', 'GET', static function () {
            global $wpdb;
            $s = Auth::session(true);
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DB::table('conversations') . " WHERE session_id=%d AND status<>'closed' ORDER BY id DESC LIMIT 5", $s['id']), ARRAY_A);
            return ['csrf' => $s['csrf'], 'config' => Settings::publicConfig(), 'conversations' => array_map([Conversations::class, 'view'], $rows)];
        }, 'public');
        self::route('/public/session', 'DELETE', static function ($r) {
            global $wpdb; $s = Auth::visitor($r); $wpdb->delete(DB::table('sessions'), ['id' => $s['id']]); unset($_COOKIE[Auth::COOKIE]); return ['revoked' => true];
        }, 'visitor');
        self::route('/public/conversations', 'POST', static fn($r) => Conversations::start((int) Auth::visitor($r)['id']), 'visitor');
        self::route('/public/conversations/(?P<id>\d+)/messages', 'GET', static function ($r) {
            $c = Conversations::owned((int) $r['id'], (int) Auth::visitor($r)['id']);
            return ['conversation' => Conversations::view($c), 'messages' => Conversations::history((int) $r['id'], max(0, (int) $r['after']))];
        }, 'visitor');
        self::route('/public/conversations/(?P<id>\d+)/messages', 'POST', static function ($r) {
            Conversations::owned((int) $r['id'], (int) Auth::visitor($r)['id']); $b = self::input($r);
            return Conversations::customer((int) $r['id'], Auth::text($b['content'] ?? ''), Auth::key($b['key'] ?? ''));
        }, 'visitor');
        self::route('/public/conversations/(?P<id>\d+)/stream', 'POST', static function ($r) {
            Conversations::owned((int) $r['id'], (int) Auth::visitor($r)['id']); $b = self::input($r);
            $text = Auth::text($b['content'] ?? ''); $key = Auth::key($b['key'] ?? '');
            self::$streamed = true;
            header('Content-Type: text/event-stream; charset=utf-8'); header('Cache-Control: no-store'); header('X-Accel-Buffering: no');
            $emit = static function ($type, $data) { echo 'event: ' . $type . "\ndata: " . wp_json_encode($data) . "\n\n"; if (ob_get_level()) { @ob_flush(); } flush(); };
            $emit('accepted', ['processing' => true]);
            try {
                $result = Conversations::customer((int) $r['id'], $text, $key, static fn($text) => $emit('delta', ['text' => $text]));
                $emit('complete', $result);
            } catch (\Throwable $e) { $emit('error', ['message' => $e instanceof Fault ? $e->getMessage() : 'The response was interrupted. Retry the same message to check its status.']); }
            return null;
        }, 'visitor');
        self::route('/public/conversations/(?P<id>\d+)/handoff', 'POST', static function ($r) {
            Conversations::owned((int) $r['id'], (int) Auth::visitor($r)['id']); return Conversations::handoff((int) $r['id']);
        }, 'visitor');
        self::route('/public/conversations/(?P<id>\d+)/lead', 'POST', static function ($r) {
            Conversations::owned((int) $r['id'], (int) Auth::visitor($r)['id']); Auth::rate('lead', 5, 3600); return Leads::capture((int) $r['id'], self::input($r));
        }, 'visitor');
        self::route('/agent/bootstrap', 'GET', static fn() => Plugin::bootstrap());
        self::route('/agent/conversations', 'GET', static fn($r) => Conversations::inbox((string) ($r['filter'] ?? 'all'), max(1, (int) $r['page']), sanitize_text_field((string) ($r['search'] ?? ''))));
        self::route('/agent/conversations/(?P<id>\d+)', 'GET', static function ($r) {
            global $wpdb;
            $c = Conversations::get((int) $r['id']); Auth::scope($c);
            $customer = $wpdb->get_row($wpdb->prepare('SELECT name,email,phone,location,interest,water_source FROM ' . DB::table('leads') . ' WHERE conversation_id=%d', $r['id']), ARRAY_A);
            return ['conversation' => Conversations::view($c), 'messages' => Conversations::history((int) $r['id'], max(0, (int) $r['after']), true), 'customer' => $customer];
        });
        self::route('/agent/conversations/(?P<id>\d+)/messages', 'POST', static function ($r) { $b = self::input($r); Auth::rate('agent_send', 60, 60, (string) get_current_user_id()); return Conversations::agentMessage((int) $r['id'], Auth::text($b['content'] ?? ''), Auth::key($b['key'] ?? ''), !empty($b['internal'])); });
        self::route('/agent/conversations/(?P<id>\d+)/actions/(?P<action>[a-z-]+)', 'POST', static function ($r) { $b = self::input($r); return Conversations::action((int) $r['id'], $r['action'], (int) ($b['version'] ?? 0), (int) ($b['agent'] ?? 0)); });
        self::route('/agent/conversations/(?P<id>\d+)/read', 'PUT', static function ($r) {
            global $wpdb; $c = Conversations::get((int) $r['id']); Auth::scope($c); $b = self::input($r);
            $seq = min((int) $c['next_seq'] - 1, max(0, (int) ($b['seq'] ?? 0)));
            $wpdb->query($wpdb->prepare('INSERT INTO ' . DB::table('conversation_reads') . ' (conversation_id,user_id,last_seq) VALUES (%d,%d,%d) ON DUPLICATE KEY UPDATE last_seq=GREATEST(last_seq,VALUES(last_seq))', $r['id'], get_current_user_id(), $seq)); return ['saved' => true];
        });
        self::route('/agent/availability', 'GET,PUT', static function ($r) {
            global $wpdb; $id = get_current_user_id(); $table = DB::table('agent_profiles');
            if ($r->get_method() === 'PUT') {
                $b = self::input($r); $status = $b['status'] ?? '';
                if (!in_array($status, ['online','away','offline'], true)) { throw new Fault('Invalid availability'); }
                $wpdb->query($wpdb->prepare("INSERT INTO $table (user_id,availability,heartbeat) VALUES (%d,%s,%s) ON DUPLICATE KEY UPDATE availability=VALUES(availability),heartbeat=VALUES(heartbeat)", $id, $status, DB::now()));
            } else { $wpdb->query($wpdb->prepare("UPDATE $table SET heartbeat=%s WHERE user_id=%d AND (heartbeat IS NULL OR heartbeat<%s)", DB::now(), $id, gmdate('Y-m-d H:i:s', time() - 60))); }
            return $wpdb->get_row($wpdb->prepare("SELECT user_id,availability,heartbeat FROM $table WHERE user_id=%d", $id), ARRAY_A) ?: ['availability' => 'offline'];
        });
        self::route('/agent/notifications', 'GET', static fn($r) => Notifications::listing(get_current_user_id(), max(0, (int) $r['before'])));
        self::route('/agent/notifications/(?P<id>\d+)/read', 'PUT', static function ($r) { Notifications::read(get_current_user_id(), (int) $r['id'], false); return ['saved' => true]; });
        self::route('/agent/notifications/read-all', 'POST', static function ($r) { $b = self::input($r); Notifications::read(get_current_user_id(), max(0, (int) ($b['through'] ?? 0)), true); return ['saved' => true]; });
        self::route('/agent/preferences', 'GET,PUT', static function ($r) {
            global $wpdb; $uid = get_current_user_id(); $prefs = Notifications::preferences($uid);
            if ($r->get_method() === 'PUT') {
                $b = self::input($r);
                foreach ($prefs as $key => $old) { if (array_key_exists($key, $b)) { $prefs[$key] = is_bool($old) ? (bool) $b[$key] : (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $b[$key]) ? $b[$key] : ''); } }
                $wpdb->query($wpdb->prepare('INSERT INTO ' . DB::table('agent_profiles') . ' (user_id,preferences) VALUES (%d,%s) ON DUPLICATE KEY UPDATE preferences=VALUES(preferences)', $uid, wp_json_encode($prefs)));
            }
            return $prefs;
        });
        self::route('/agent/push-config', 'GET', static fn() => ['publicKey' => Settings::get()['vapid_public'], 'available' => Dependencies::pushStatus()['ready'] && (bool) Settings::secret('vapid_private')]);
        self::route('/agent/push-status', 'GET', static fn() => Push::diagnostics(get_current_user_id()));
        self::route('/agent/push-test', 'POST', static function ($r) {
            Auth::rate('push_test', 3, 60, (string) get_current_user_id());
            $b = self::input($r); $endpoint = $b['endpoint'] ?? '';
            if (!is_string($endpoint) || !$endpoint || strlen($endpoint) > 2000) { throw new Fault('Enable push on this device before testing.'); }
            return Push::test(get_current_user_id(), $endpoint);
        });
        self::route('/agent/devices', 'POST', static function ($r) { Auth::rate('devices', 10, 3600, (string) get_current_user_id()); return Push::register(get_current_user_id(), self::input($r)); });
        self::route('/agent/devices', 'GET', static function () { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT id,created_at FROM ' . DB::table('push_subscriptions') . ' WHERE user_id=%d ORDER BY id DESC', get_current_user_id()), ARRAY_A); });
        self::route('/agent/devices/(?P<id>\d+)', 'DELETE', static function ($r) { global $wpdb; $wpdb->delete(DB::table('push_subscriptions'), ['id' => (int) $r['id'], 'user_id' => get_current_user_id()]); DB::audit('device_revoked', (int) $r['id']); return ['revoked' => true]; });
        self::route('/agent/revoke-other-sessions', 'POST', static function () {
            global $wpdb; $uid = get_current_user_id();
            \WP_Session_Tokens::get_instance($uid)->destroy_others(wp_get_session_token());
            $wpdb->query($wpdb->prepare('DELETE FROM ' . DB::table('push_subscriptions') . ' WHERE user_id=%d AND session_hash<>%s', $uid, hash('sha256', wp_get_session_token())));
            DB::audit('other_sessions_revoked'); return ['revoked' => true];
        });
        self::route('/agent/leads', 'GET', static fn($r) => Leads::listing(max(1, (int) $r['page']), sanitize_text_field((string) ($r['status'] ?? ''))));
        self::route('/agent/leads/(?P<id>\d+)', 'PATCH', static fn($r) => Leads::update((int) $r['id'], self::input($r)));
        self::route('/admin/leads/(?P<id>\d+)', 'DELETE', static function ($r) { global $wpdb; $wpdb->delete(DB::table('leads'), ['id' => (int) $r['id']]); DB::audit('lead_deleted', (int) $r['id']); return ['deleted' => true]; }, 'manage');
        self::route('/admin/leads/export', 'GET', static function ($r) {
            global $wpdb; $page = max(1, (int) $r['page']);
            DB::audit('leads_exported');
            return $wpdb->get_results('SELECT id,name,email,phone,location,interest,water_source,status,consent,created_at FROM ' . DB::table('leads') . ' ORDER BY id LIMIT 40 OFFSET ' . (($page - 1) * 40), ARRAY_A);
        }, 'manage');
        self::route('/admin/settings', 'GET,PUT', static function ($r) { return $r->get_method() === 'PUT' ? Settings::save(self::input($r)) : Settings::get(); }, 'manage');
        self::route('/admin/content/(?P<type>products|knowledge)', 'GET,POST', static function ($r) { return $r->get_method() === 'GET' ? Content::all($r['type'], max(1, (int) $r['page'])) : Content::save($r['type'], self::input($r)); }, 'manage');
        self::route('/admin/content/(?P<type>products|knowledge)/(?P<id>\d+)', 'PUT,DELETE', static function ($r) {
            global $wpdb; if ($r->get_method() === 'PUT') { return Content::save($r['type'], self::input($r), (int) $r['id']); }
            $wpdb->delete(DB::table(Content::type($r['type'])), ['id' => (int) $r['id']]); DB::audit('content_deleted', (int) $r['id']); return ['deleted' => true];
        }, 'manage');
        self::route('/admin/agents', 'GET', static function () {
            global $wpdb; $result = [];
            foreach (get_users(['capability' => 'ctai_agent']) as $u) {
                $p = $wpdb->get_row($wpdb->prepare('SELECT availability,heartbeat FROM ' . DB::table('agent_profiles') . ' WHERE user_id=%d', $u->ID), ARRAY_A) ?: ['availability' => 'offline', 'heartbeat' => null];
                if (!$p['heartbeat'] || strtotime($p['heartbeat'] . ' UTC') < time() - 180) { $p['availability'] = 'offline'; }
                $result[] = ['id' => $u->ID, 'name' => $u->display_name] + $p;
            }
            return $result;
        }, 'supervise');
        self::route('/admin/health', 'GET', static function () {
            $push = Dependencies::pushStatus();
            return ['plugin_version' => CTAI_VERSION, 'plugin_directory' => basename(CTAI_DIR), 'https' => is_ssl(), 'openai_key_configured' => (bool) Settings::secret('openai_key'), 'vapid_private_configured' => (bool) Settings::secret('vapid_private'), 'push_library' => $push['sender_class'], 'push_vapid_class' => $push['vapid_class'], 'push_ready' => $push['ready'], 'push_diagnostic' => $push['issue'], 'push_missing_extensions' => implode(', ', $push['missing_extensions']) ?: 'None', 'last_worker' => (int) get_option('ctai_last_tick'), 'schema' => get_option('ctai_schema'), 'php' => PHP_VERSION, 'cron_note' => 'Configure a real cron every minute for timely notifications.'];
        }, 'manage');
        self::route('/admin/credentials', 'PUT', static function ($r) {
            if (!is_ssl() && !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1'], true)) { throw new Fault('HTTPS is required', 403); }
            $b = self::input($r);
            if (array_key_exists('openai_key', $b)) { $key = (string) $b['openai_key']; if (strlen($key)>500 || preg_match('/[\s\x00-\x1f]/', $key)) { throw new Fault('Invalid API key format'); } Settings::storeSecret('openai_key', $key); }
            return ['saved' => true];
        }, 'manage');
        self::route('/admin/vapid-generate', 'POST', static function () {
            if ((defined('CTAI_VAPID_PRIVATE') && constant('CTAI_VAPID_PRIVATE')) || getenv('CTAI_VAPID_PRIVATE')) { throw new Fault('The private VAPID key is configured externally. Configure its matching public key instead of generating a replacement.', 409); }
            $push = Dependencies::pushStatus();
            if (!$push['ready']) { throw new Fault($push['issue'], 503, 'push_dependencies'); }
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
            Settings::storeSecret('vapid_private', $keys['privateKey']);
            Settings::save(['vapid_public' => $keys['publicKey'], 'vapid_subject' => 'mailto:' . sanitize_email(get_option('admin_email'))]);
            global $wpdb; $wpdb->query('DELETE FROM ' . DB::table('push_subscriptions'));
            return ['generated' => true, 'public_key' => $keys['publicKey'], 'note' => 'Devices must register again after key rotation.'];
        }, 'manage');
        self::route('/admin/ai-test', 'POST', static function () {
            global $wpdb;
            Auth::rate('ai_test', 3, 3600, (string) get_current_user_id());
            $s = Settings::get(); $reserved = round((2000 * $s['input_price'] + 32 * $s['output_price']) / 1000000, 6); $periods = AI::periods(); AI::reserve($reserved, $periods);
            $id = 0; $cost = $reserved;
            try {
                $id = DB::insert('usage', ['conversation_id' => 0, 'request_key' => 'test-' . wp_generate_uuid4(), 'model' => $s['model'], 'estimated_usd' => $reserved, 'status' => 'reserved', 'created_at' => DB::now()]);
                $r = AI::provider()->generate(['model' => $s['model'], 'input' => 'Reply with OK.', 'max_output_tokens' => 32, 'store' => false], (int) $s['timeout']);
                $in = (int) ($r['usage']['input_tokens'] ?? 2000); $out = (int) ($r['usage']['output_tokens'] ?? 32);
                $cost = round(($in * $s['input_price'] + $out * $s['output_price']) / 1000000, 6);
                $wpdb->update(DB::table('usage'), ['input_tokens' => $in, 'output_tokens' => $out, 'estimated_usd' => $cost, 'status' => 'completed'], ['id' => $id]);
                return ['connected' => true];
            } catch (\Throwable $e) { if ($id) { $wpdb->update(DB::table('usage'), ['status' => 'uncertain'], ['id' => $id]); } throw $e; }
            finally { AI::settle($periods, $reserved, $cost); DB::audit('ai_connection_test'); }
        }, 'manage');
        self::route('/admin/analytics', 'GET', [self::class, 'analytics'], 'supervise');
        self::route('/admin/logs', 'GET', static fn() => self::logs(), 'manage');
        self::route('/admin/conversations/(?P<id>\d+)', 'DELETE', static function ($r) { Privacy::eraseConversation((int) $r['id']); return ['deleted' => true]; }, 'manage');
        self::route('/admin/conversations/(?P<id>\d+)/export', 'GET', static function ($r) {
            global $wpdb; DB::audit('conversation_exported', (int) $r['id']);
            return ['conversation' => Conversations::view(Conversations::get((int) $r['id'])), 'messages' => $wpdb->get_results($wpdb->prepare('SELECT seq,sender,visibility,content,created_at FROM ' . DB::table('messages') . ' WHERE conversation_id=%d ORDER BY seq', $r['id']), ARRAY_A)];
        }, 'manage');
    }
    public static function logs(): array { global $wpdb; return ['audit' => $wpdb->get_results('SELECT * FROM ' . DB::table('audit_log') . ' ORDER BY id DESC LIMIT 100', ARRAY_A), 'usage' => $wpdb->get_results('SELECT * FROM ' . DB::table('usage') . ' ORDER BY id DESC LIMIT 100', ARRAY_A)]; }
    public static function analytics(\WP_REST_Request $r): array {
        global $wpdb;
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['from']) ? $r['from'] . ' 00:00:00' : gmdate('Y-m-01 00:00:00');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['to']) ? $r['to'] . ' 23:59:59' : DB::now();
        $c = DB::table('conversations'); $u = DB::table('usage');
        return ['from' => $from, 'to' => $to, 'conversations' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $c WHERE created_at BETWEEN %s AND %s", $from, $to)), 'today' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $c WHERE created_at>=%s", gmdate('Y-m-d 00:00:00'))), 'states' => $wpdb->get_results("SELECT status,COUNT(*) AS count FROM $c GROUP BY status", ARRAY_A), 'usage' => $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS requests,COALESCE(SUM(input_tokens),0) AS input_tokens,COALESCE(SUM(output_tokens),0) AS output_tokens,COALESCE(SUM(estimated_usd),0) AS estimated_usd,SUM(status='uncertain') AS failures FROM $u WHERE created_at BETWEEN %s AND %s", $from, $to), ARRAY_A), 'leads' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DB::table('leads') . ' WHERE created_at BETWEEN %s AND %s', $from, $to))];
    }
}
