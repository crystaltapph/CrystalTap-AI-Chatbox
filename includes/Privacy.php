<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Privacy {
    public static function eraseConversation(int $id): void {
        global $wpdb;
        DB::transaction(function () use ($id, $wpdb) {
            Conversations::get($id, true);
            foreach (['messages','conversation_reads','conversation_assignments','leads','notifications','events'] as $table) { $wpdb->delete(DB::table($table), ['conversation_id' => $id]); }
            // Keep only aggregate usage, disconnected from the deleted conversation.
            $wpdb->update(DB::table('usage'), ['conversation_id' => 0], ['conversation_id' => $id]);
            $wpdb->delete(DB::table('conversations'), ['id' => $id]);
            DB::audit('conversation_erased', $id);
        });
    }
    public static function cleanup(): void {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - Settings::get()['retention_days'] * DAY_IN_SECONDS);
        foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . DB::table('conversations') . ' WHERE updated_at<%s LIMIT 100', $cutoff)) as $id) { self::eraseConversation((int) $id); }
        $wpdb->query($wpdb->prepare('DELETE s FROM ' . DB::table('sessions') . ' s LEFT JOIN ' . DB::table('conversations') . ' c ON c.session_id=s.id WHERE s.expires_at<%s AND c.id IS NULL', DB::now()));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . DB::table('rate_limits') . ' WHERE expires_at<%s', DB::now()));
        foreach (['audit_log','events','notifications'] as $table) { $wpdb->query($wpdb->prepare('DELETE FROM ' . DB::table($table) . ' WHERE created_at<%s LIMIT 1000', $cutoff)); }
    }
    public static function exporter(array $items): array {
        $items['crystaltap-ai'] = ['exporter_friendly_name' => 'CrystalTap AI', 'callback' => [self::class, 'exportEmail']]; return $items;
    }
    public static function eraser(array $items): array {
        $items['crystaltap-ai'] = ['eraser_friendly_name' => 'CrystalTap AI', 'callback' => [self::class, 'eraseEmail']]; return $items;
    }
    public static function exportEmail(string $email, int $page = 1): array {
        global $wpdb;
        $leads = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DB::table('leads') . ' WHERE email=%s ORDER BY id LIMIT 20 OFFSET %d', $email, max(0, $page - 1) * 20), ARRAY_A);
        $data = [];
        foreach ($leads as $lead) {
            $public = array_intersect_key($lead, array_flip(['name','email','phone','location','interest','water_source','inquiry','consent','created_at']));
            $messages = $wpdb->get_results($wpdb->prepare('SELECT sender,content,created_at FROM ' . DB::table('messages') . " WHERE conversation_id=%d AND visibility='public' ORDER BY seq", $lead['conversation_id']), ARRAY_A);
            $public['conversation'] = wp_json_encode($messages);
            $data[] = ['group_id' => 'crystaltap-ai', 'group_label' => 'CrystalTap AI support', 'item_id' => 'ctai-lead-' . $lead['id'], 'data' => array_map(fn($k, $v) => ['name' => $k, 'value' => (string) $v], array_keys($public), array_values($public))];
        }
        return ['data' => $data, 'done' => count($leads) < 20];
    }
    public static function eraseEmail(string $email, int $page = 1): array {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT conversation_id FROM ' . DB::table('leads') . ' WHERE email=%s LIMIT 20', $email));
        foreach ($ids as $id) { self::eraseConversation((int) $id); }
        return ['items_removed' => (bool) $ids, 'items_retained' => false, 'messages' => [], 'done' => count($ids) < 20];
    }
}
