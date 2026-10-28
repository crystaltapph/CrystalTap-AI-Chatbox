<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Notifications {
    public static function emit(int $user, string $event, int $conversation, string $type, string $title): void {
        global $wpdb;
        if (!user_can($user, 'ctai_agent')) { return; }
        $table = DB::table('notifications');
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d AND event_key=%s", $user, $event))) { return; }
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (user_id,event_key,conversation_id,type,title,created_at) VALUES (%d,%s,%d,%s,%s,%s)", $user, $event, $conversation, $type, $title, DB::now()));
        if ($wpdb->rows_affected) {
            $id = (int) $wpdb->insert_id;
            self::job('push-' . $id, 'push', ['notification' => $id]);
        }
    }
    public static function queue(int $conversation, string $type, string $title, string $key): void {
        global $wpdb;
        $profiles = DB::table('agent_profiles');
        foreach (get_users(['capability' => 'ctai_agent', 'fields' => 'ID']) as $id) {
            $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $profiles WHERE user_id=%d", $id), ARRAY_A);
            // Sleeping phones stop heartbeats. Keep alerts for explicitly online agents;
            // interactive availability still uses fresh heartbeats elsewhere.
            if (user_can($id, 'ctai_supervise') || ($p && $p['availability'] === 'online')) { self::emit((int) $id, $key, $conversation, $type, $title); }
        }
    }
    public static function job(string $key, string $type, array $payload): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . DB::table('jobs') . ' (dedup_key,type,payload,available_at,created_at) VALUES (%s,%s,%s,%s,%s)', $key, $type, wp_json_encode($payload), DB::now(), DB::now()));
    }
    public static function listing(int $user, int $before = 0): array {
        global $wpdb;
        $t = DB::table('notifications');
        $where = $wpdb->prepare('user_id=%d', $user);
        if ($before > 0) { $where .= $wpdb->prepare(' AND id<%d', $before); }
        $conversations = DB::table('conversations'); $messages = DB::table('messages');
        $previewPermission = user_can($user, 'ctai_supervise') ? '1=1' : $wpdb->prepare('c.assigned_agent=%d', $user);
        $where = $wpdb->prepare('n.user_id=%d', $user) . ($before > 0 ? $wpdb->prepare(' AND n.id<%d', $before) : '');
        $items = $wpdb->get_results("SELECT n.*,CASE WHEN ($previewPermission) THEN COALESCE((SELECT LEFT(m.content,120) FROM $messages m WHERE m.conversation_id=n.conversation_id AND m.sender='customer' AND m.visibility='public' ORDER BY m.seq DESC LIMIT 1),'') ELSE '' END AS preview FROM $t n LEFT JOIN $conversations c ON c.id=n.conversation_id WHERE $where ORDER BY n.id DESC LIMIT 40", ARRAY_A);
        return ['items' => $items, 'unread' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id=%d AND read_at IS NULL", $user))];
    }
    public static function read(int $user, int $through, bool $all): void {
        global $wpdb;
        $operator = $all ? '<=' : '=';
        $wpdb->query($wpdb->prepare('UPDATE ' . DB::table('notifications') . " SET read_at=%s WHERE user_id=%d AND id $operator %d AND read_at IS NULL", DB::now(), $user, $through));
    }
    public static function preferences(int $user): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare('SELECT preferences FROM ' . DB::table('agent_profiles') . ' WHERE user_id=%d', $user));
        return array_replace(['push' => true, 'message' => true, 'handoff' => true, 'lead' => true, 'followup' => true, 'assignment' => true, 'quiet_start' => '', 'quiet_end' => ''], json_decode($raw ?: '{}', true) ?: []);
    }
    public static function tick(): void {
        global $wpdb;
        $jobs = DB::table('jobs');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $jobs WHERE available_at<=%s AND (locked_until IS NULL OR locked_until<%s) ORDER BY id LIMIT 10", DB::now(), DB::now()), ARRAY_A);
        foreach ($rows as $job) {
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $jobs SET locked_until=%s, attempts=attempts+1 WHERE id=%d AND (locked_until IS NULL OR locked_until<%s)", gmdate('Y-m-d H:i:s', time() + 120), $job['id'], DB::now()));
            if (!$claimed) { continue; }
            try {
                $payload = json_decode($job['payload'], true) ?: [];
                if ($job['type'] === 'push') { Push::deliver((int) $payload['notification']); }
                if ($job['type'] === 'summary') { AI::summarize((int) $payload['conversation']); }
                $wpdb->delete($jobs, ['id' => $job['id']]);
            } catch (\Throwable $e) {
                DB::audit('push_delivery', (int) $job['id'], 'retry');
                if ((int) $job['attempts'] >= 4) { $wpdb->delete($jobs, ['id' => $job['id']]); }
                else { $wpdb->update($jobs, ['locked_until' => null, 'available_at' => gmdate('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** (int) $job['attempts'])))], ['id' => $job['id']]); }
            }
        }
        $due = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . DB::table('conversations') . " WHERE status='waiting' AND followup_at<=%s LIMIT 30", DB::now()));
        foreach ($due as $id) {
            DB::transaction(function () use ($id, $wpdb) {
                $c = Conversations::get((int) $id, true);
                if ($c['status'] !== 'waiting' || !$c['followup_at'] || $c['followup_at'] > DB::now()) { return; }
                self::queue((int) $id, 'followup', 'A customer is still waiting for support', 'followup-' . $id . '-' . $c['version']);
                $wpdb->update(DB::table('conversations'), ['followup_at' => null], ['id' => $id]);
            });
        }
        update_option('ctai_last_tick', time(), false);
    }
}
