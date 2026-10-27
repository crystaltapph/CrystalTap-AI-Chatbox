<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Auth {
    public const COOKIE = 'ctai_session';
    public static function origin(): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && strtolower($origin) !== strtolower((string) wp_parse_url(home_url(), PHP_URL_SCHEME) . '://' . wp_parse_url(home_url(), PHP_URL_HOST) . (wp_parse_url(home_url(), PHP_URL_PORT) ? ':' . wp_parse_url(home_url(), PHP_URL_PORT) : ''))) {
            throw new Fault('Cross-origin request denied', 403, 'origin');
        }
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') { throw new Fault('Cross-site request denied', 403, 'origin'); }
    }
    public static function session(bool $create = false): array {
        global $wpdb;
        self::origin();
        if (!is_ssl() && !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1','::1'], true)) { throw new Fault('HTTPS is required for customer sessions', 503, 'https_required'); }
        $token = $_COOKIE[self::COOKIE] ?? '';
        $token = is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
        $row = $token ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('sessions') . ' WHERE token_hash=%s AND expires_at>%s', hash('sha256', $token), DB::now()), ARRAY_A) : null;
        if (!$row && $create) {
            self::rate('session', 15, 3600);
            $token = bin2hex(random_bytes(32));
            $csrf = hash_hmac('sha256', $token, wp_salt('nonce'));
            $expires = time() + Settings::get()['session_hours'] * HOUR_IN_SECONDS;
            $id = DB::insert('sessions', ['token_hash' => hash('sha256', $token), 'csrf_hash' => hash('sha256', $csrf), 'expires_at' => gmdate('Y-m-d H:i:s', $expires), 'created_at' => DB::now()]);
            setcookie(self::COOKIE, $token, ['expires' => $expires, 'path' => wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
            $_COOKIE[self::COOKIE] = $token;
            $row = ['id' => $id, 'expires_at' => gmdate('Y-m-d H:i:s', $expires)];
        }
        if (!$row) { throw new Fault('Visitor session expired. Start a new session.', 401, 'session_expired'); }
        $row['csrf'] = hash_hmac('sha256', $token, wp_salt('nonce'));
        return $row;
    }
    public static function visitor(\WP_REST_Request $request): array {
        $session = self::session();
        if ($request->get_method() !== 'GET' && !hash_equals($session['csrf'], (string) $request->get_header('X-CTAI-CSRF'))) { throw new Fault('Invalid visitor request token', 403, 'csrf'); }
        return $session;
    }
    public static function agent(string $cap = 'ctai_agent'): int {
        self::origin();
        if (!is_user_logged_in() || !current_user_can($cap)) { throw new Fault('You do not have permission for this operation', 403, 'forbidden'); }
        return get_current_user_id();
    }
    public static function scope(array $conversation): void {
        self::agent();
        if (!current_user_can('ctai_supervise') && (int) $conversation['assigned_agent'] !== get_current_user_id()) { throw new Fault('Conversation is not assigned to you', 403, 'forbidden'); }
    }
    public static function rate(string $action, int $limit, int $seconds, string $identity = ''): void {
        global $wpdb;
        $identity = $identity ?: hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local', wp_salt());
        $bucket = hash('sha256', $action . ':' . $identity . ':' . (int) floor(time() / $seconds));
        $table = DB::table('rate_limits');
        if ($wpdb->query($wpdb->prepare("INSERT INTO $table (bucket,hits,expires_at) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=hits+1", $bucket, gmdate('Y-m-d H:i:s', time() + $seconds))) === false) { throw new Fault('Rate limiting unavailable', 503); }
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT hits FROM $table WHERE bucket=%s", $bucket)) > $limit) { throw new Fault('Too many requests. Please try again later.', 429, 'rate_limit'); }
    }
    public static function text(mixed $value, int $max = 4000): string {
        if (!is_string($value) || strlen($value) > $max || trim($value) === '') { throw new Fault('Text is missing or too long'); }
        return sanitize_textarea_field($value);
    }
    public static function key(mixed $value): string {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{12,80}$/', $value)) { throw new Fault('A valid idempotency key is required'); }
        return $value;
    }
}
