<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Leads {
    public static function capture(int $conversation, array $input): array {
        global $wpdb;
        if (($input['consent'] ?? null) !== true) { throw new Fault('Consent to use these details for this inquiry is required'); }
        $data = ['name' => Auth::text($input['name'] ?? '', 190), 'email' => sanitize_email((string) ($input['email'] ?? '')), 'phone' => sanitize_text_field(substr((string) ($input['phone'] ?? ''), 0, 60)), 'location' => sanitize_text_field(substr((string) ($input['location'] ?? ''), 0, 190)), 'interest' => sanitize_text_field(substr((string) ($input['interest'] ?? ''), 0, 190)), 'water_source' => sanitize_text_field(substr((string) ($input['water_source'] ?? ''), 0, 190)), 'inquiry' => sanitize_textarea_field(substr((string) ($input['inquiry'] ?? ''), 0, 4000)), 'consent' => wp_json_encode(['purpose' => 'respond_to_inquiry', 'at' => DB::now(), 'notice_hash' => hash('sha256', Settings::get()['privacy_notice'])]), 'updated_at' => DB::now()];
        if (!$data['email'] && !$data['phone']) { throw new Fault('Provide an email address or phone number'); }
        return DB::transaction(function () use ($conversation, $data, $wpdb) {
            Conversations::get($conversation, true);
            $old = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . DB::table('leads') . ' WHERE conversation_id=%d', $conversation));
            if ($old) { $wpdb->update(DB::table('leads'), $data, ['id' => $old]); $id = (int) $old; }
            else {
                $id = DB::insert('leads', $data + ['conversation_id' => $conversation, 'created_at' => DB::now()]);
                Notifications::queue($conversation, 'lead', 'A new customer inquiry needs attention', 'lead-' . $id);
            }
            return ['saved' => true];
        });
    }
    public static function listing(int $page, string $status = ''): array {
        global $wpdb;
        $where = current_user_can('ctai_supervise') ? '1=1' : $wpdb->prepare('assigned_agent=%d', get_current_user_id());
        if ($status) { $where .= $wpdb->prepare(' AND status=%s', $status); }
        return $wpdb->get_results('SELECT * FROM ' . DB::table('leads') . " WHERE $where ORDER BY id DESC LIMIT 40 OFFSET " . (max(0, min(1000, $page - 1)) * 40), ARRAY_A);
    }
    public static function update(int $id, array $data): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('leads') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$row) { throw new Fault('Lead not found', 404); }
        if (!current_user_can('ctai_supervise') && (int) $row['assigned_agent'] !== get_current_user_id()) { throw new Fault('Lead access denied', 403); }
        $update = ['updated_at' => DB::now()];
        if (isset($data['status'])) {
            if (!in_array($data['status'], ['new','contacted','interested','quotation_sent','converted','closed'], true)) { throw new Fault('Invalid lead status'); }
            $update['status'] = $data['status'];
        }
        if (isset($data['assigned_agent'])) {
            Auth::agent('ctai_supervise');
            if ($data['assigned_agent'] && !user_can((int) $data['assigned_agent'], 'ctai_agent')) { throw new Fault('Invalid agent'); }
            $update['assigned_agent'] = (int) $data['assigned_agent'] ?: null;
        }
        if (!empty($data['note'])) {
            $notes = json_decode($row['notes'] ?: '[]', true) ?: [];
            $notes[] = ['by' => get_current_user_id(), 'at' => DB::now(), 'text' => Auth::text($data['note'], 2000)];
            $update['notes'] = wp_json_encode(array_slice($notes, -100));
        }
        $wpdb->update(DB::table('leads'), $update, ['id' => $id]); DB::audit('lead_updated', $id);
        return ['saved' => true];
    }
}
