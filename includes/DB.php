<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class DB {
    private static int $depth = 0;
    public static function table(string $name): string {
        global $wpdb;
        return apply_filters('ctai_table_prefix', $wpdb->prefix . 'crystaltap_ai_') . $name;
    }
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function transaction(callable $callback): mixed {
        global $wpdb;
        $level = self::$depth++;
        $wpdb->query($level === 0 ? 'START TRANSACTION' : 'SAVEPOINT ctai_' . $level);
        try {
            $result = $callback();
            if ($wpdb->query($level === 0 ? 'COMMIT' : 'RELEASE SAVEPOINT ctai_' . $level) === false) { throw new \RuntimeException('Commit failed'); }
            self::$depth--;
            return $result;
        } catch (\Throwable $e) {
            $wpdb->query($level === 0 ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ctai_' . $level);
            self::$depth--;
            throw $e;
        }
    }
    public static function insert(string $table, array $data): int {
        global $wpdb;
        if ($wpdb->insert(self::table($table), $data) === false) { throw new \RuntimeException('Database write failed'); }
        return (int) $wpdb->insert_id;
    }
    public static function migrate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $tables = [
            'native_links' => "id bigint unsigned NOT NULL AUTO_INCREMENT, request_id char(48) NOT NULL, challenge char(64) NOT NULL, code char(8) NOT NULL, user_id bigint unsigned DEFAULT NULL, issued_token text, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY request_id (request_id), KEY expiry (expires_at)",
            'native_devices' => "id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, token_hash char(64) NOT NULL, session_hash char(64) NOT NULL, credential_version char(64) NOT NULL, label varchar(100) NOT NULL, fcm_token text, system_notifications tinyint unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY token_hash (token_hash), KEY user_id (user_id)",
            'rate_limits' => "bucket char(64) NOT NULL, hits bigint unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, PRIMARY KEY  (bucket), KEY expiry (expires_at)",
            'sessions' => "id bigint unsigned NOT NULL AUTO_INCREMENT, token_hash char(64) NOT NULL, csrf_hash char(64) NOT NULL, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY token_hash (token_hash), KEY expiry (expires_at)",
            'conversations' => "id bigint unsigned NOT NULL AUTO_INCREMENT, session_id bigint unsigned NOT NULL, status varchar(24) NOT NULL DEFAULT 'ai_active', assigned_agent bigint unsigned DEFAULT NULL, version bigint unsigned NOT NULL DEFAULT 1, generation bigint unsigned NOT NULL DEFAULT 1, next_seq bigint unsigned NOT NULL DEFAULT 1, summary longtext, summary_seq bigint unsigned NOT NULL DEFAULT 0, visitor_typing_until datetime DEFAULT NULL, agent_typing_until datetime DEFAULT NULL, followup_at datetime DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY inbox (status,assigned_agent,updated_at), KEY visitor (session_id,id)",
            'messages' => "id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, seq bigint unsigned NOT NULL, sender varchar(16) NOT NULL, user_id bigint unsigned DEFAULT NULL, visibility varchar(12) NOT NULL DEFAULT 'public', content longtext NOT NULL, metadata longtext, client_key varchar(100) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY sequence (conversation_id,seq), UNIQUE KEY dedup (conversation_id,client_key)",
            'images' => "id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, message_id bigint unsigned DEFAULT NULL, actor_kind varchar(10) NOT NULL, actor_id bigint unsigned NOT NULL, upload_key char(64) NOT NULL, mime varchar(20) NOT NULL, width int unsigned NOT NULL, height int unsigned NOT NULL, bytes int unsigned NOT NULL, checksum char(64) NOT NULL, payload longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY upload_key (upload_key), KEY conversation (conversation_id,message_id), KEY expiry (message_id,created_at)",
            'conversation_reads' => "id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, user_id bigint unsigned NOT NULL, last_seq bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY actor (conversation_id,user_id)",
            'conversation_assignments' => "id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, agent_id bigint unsigned DEFAULT NULL, actor_id bigint unsigned NOT NULL, action varchar(32) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY conversation (conversation_id,id)",
            'agent_profiles' => "user_id bigint unsigned NOT NULL, availability varchar(12) NOT NULL DEFAULT 'offline', heartbeat datetime DEFAULT NULL, preferences longtext, PRIMARY KEY  (user_id)",
            'leads' => "id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, assigned_agent bigint unsigned DEFAULT NULL, name varchar(190) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', phone varchar(60) NOT NULL DEFAULT '', location varchar(190) NOT NULL DEFAULT '', interest varchar(190) NOT NULL DEFAULT '', water_source varchar(190) NOT NULL DEFAULT '', inquiry text, consent longtext, status varchar(24) NOT NULL DEFAULT 'new', notes longtext, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY conversation (conversation_id), KEY assignment (assigned_agent,status)",
            'products' => "id bigint unsigned NOT NULL AUTO_INCREMENT, title varchar(190) NOT NULL, category varchar(100) NOT NULL DEFAULT '', content longtext NOT NULL, details longtext, keywords text, source text, priority int NOT NULL DEFAULT 0, enabled tinyint NOT NULL DEFAULT 0, revision bigint unsigned NOT NULL DEFAULT 1, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY published (enabled,priority)",
            'knowledge' => "id bigint unsigned NOT NULL AUTO_INCREMENT, title varchar(190) NOT NULL, category varchar(100) NOT NULL DEFAULT '', content longtext NOT NULL, details longtext, keywords text, source text, priority int NOT NULL DEFAULT 0, enabled tinyint NOT NULL DEFAULT 0, revision bigint unsigned NOT NULL DEFAULT 1, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY published (enabled,priority)",
            'notifications' => "id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, event_key varchar(100) NOT NULL, conversation_id bigint unsigned DEFAULT NULL, type varchar(32) NOT NULL, title varchar(190) NOT NULL, read_at datetime DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY dedup (user_id,event_key), KEY unread (user_id,read_at,id)",
            'push_subscriptions' => "id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, session_hash char(64) NOT NULL, endpoint_hash char(64) NOT NULL, subscription longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY endpoint (endpoint_hash), KEY actor (user_id,session_hash)",
            'usage' => "id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, request_key varchar(100) NOT NULL, model varchar(100) NOT NULL, input_tokens bigint unsigned NOT NULL DEFAULT 0, output_tokens bigint unsigned NOT NULL DEFAULT 0, estimated_usd decimal(14,6) NOT NULL DEFAULT 0, status varchar(24) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY request_key (request_key), KEY period (created_at,status)",
            'budget_buckets' => "period varchar(16) NOT NULL, reserved decimal(14,6) NOT NULL DEFAULT 0, spent decimal(14,6) NOT NULL DEFAULT 0, PRIMARY KEY  (period)",
            'events' => "id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, type varchar(32) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY conversation (conversation_id,id)",
            'jobs' => "id bigint unsigned NOT NULL AUTO_INCREMENT, dedup_key varchar(100) NOT NULL, type varchar(32) NOT NULL, payload longtext NOT NULL, attempts int NOT NULL DEFAULT 0, available_at datetime NOT NULL, locked_until datetime DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY dedup (dedup_key), KEY runnable (available_at,locked_until)",
            'audit_log' => "id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, action varchar(60) NOT NULL, target_id bigint unsigned NOT NULL DEFAULT 0, outcome varchar(32) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY period (created_at)",
        ];
        foreach ($tables as $name => $schema) {
            dbDelta('CREATE TABLE ' . self::table($name) . " (\n" . str_replace(', ', ",\n", $schema) . "\n) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';');
            if ($wpdb->last_error) { throw new \RuntimeException('CrystalTap database migration failed. Check database permissions and compatibility.'); }
        }
        update_option('ctai_schema', CTAI_VERSION, false);
    }
    public static function audit(string $action, int $target = 0, string $outcome = 'success'): void {
        self::insert('audit_log', ['user_id' => get_current_user_id(), 'action' => $action, 'target_id' => $target, 'outcome' => $outcome, 'created_at' => self::now()]);
    }
}
