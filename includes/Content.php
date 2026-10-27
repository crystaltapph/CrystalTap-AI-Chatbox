<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Content {
    public static function type(string $type): string {
        if (!in_array($type, ['products','knowledge'], true)) { throw new Fault('Unknown content type', 404); }
        return $type;
    }
    public static function all(string $type, int $page = 1): array {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . DB::table(self::type($type)) . ' ORDER BY id DESC LIMIT 50 OFFSET ' . ((max(1, min(1000, $page)) - 1) * 50), ARRAY_A);
    }
    public static function save(string $type, array $input, int $id = 0): array {
        global $wpdb;
        $type = self::type($type);
        $data = ['title' => Auth::text($input['title'] ?? '', 190), 'category' => sanitize_text_field(substr((string) ($input['category'] ?? ''), 0, 100)), 'content' => Auth::text($input['content'] ?? '', 20000), 'keywords' => sanitize_text_field(substr((string) ($input['keywords'] ?? ''), 0, 1000)), 'source' => sanitize_text_field(substr((string) ($input['source'] ?? ''), 0, 2000)), 'priority' => max(0, min(100, (int) ($input['priority'] ?? 0))), 'enabled' => !empty($input['enabled']) ? 1 : 0, 'updated_at' => DB::now()];
        $details = (array) ($input['details'] ?? []);
        $clean = [];
        foreach (['specifications','warranty','availability','currency'] as $field) { $clean[$field] = sanitize_textarea_field(substr((string) ($details[$field] ?? ''), 0, 3000)); }
        $clean['url'] = esc_url_raw((string) ($details['url'] ?? ''), ['https']);
        $clean['image'] = esc_url_raw((string) ($details['image'] ?? ''), ['https']);
        $clean['price'] = isset($details['price']) && $details['price'] !== '' && is_numeric($details['price']) && $details['price'] >= 0 ? number_format((float) $details['price'], 2, '.', '') : null;
        $data['details'] = wp_json_encode($clean);
        if ($id) {
            $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table($type) . ' WHERE id=%d', $id), ARRAY_A);
            if (!$old) { throw new Fault('Record not found', 404); }
            $data['revision'] = (int) $old['revision'] + 1;
            if ($wpdb->update(DB::table($type), $data, ['id' => $id]) === false) { throw new \RuntimeException('Content update failed'); }
        } else { $data['created_at'] = DB::now(); $id = DB::insert($type, $data); }
        DB::audit($type . '_saved', $id);
        return ['id' => $id];
    }
    public static function product(int $id): ?array {
        global $wpdb;
        $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('products') . ' WHERE id=%d AND enabled=1', $id), ARRAY_A);
        if (!$p) { return null; }
        return ['id' => $id, 'title' => $p['title'], 'description' => $p['content'], 'details' => json_decode($p['details'] ?: '{}', true)];
    }
    public static function retrieve(string $question): array {
        global $wpdb;
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [], fn($w) => mb_strlen($w) >= 3)));
        $words = array_slice($words, 0, 12);
        $results = [];
        foreach (['knowledge','products'] as $type) {
            $clauses = [];
            foreach ($words as $word) {
                $like = '%' . $wpdb->esc_like($word) . '%';
                $clauses[] = $wpdb->prepare('(title LIKE %s OR keywords LIKE %s OR content LIKE %s)', $like, $like, $like);
            }
            if (!$clauses) { continue; }
            $rows = $wpdb->get_results('SELECT * FROM ' . DB::table($type) . ' WHERE enabled=1 AND (' . implode(' OR ', $clauses) . ') ORDER BY priority DESC,id DESC LIMIT 20', ARRAY_A);
            foreach ($rows as $r) {
                $score = (int) $r['priority'] / 100;
                foreach ($words as $word) {
                    $score += str_contains(mb_strtolower($r['title']), $word) ? 5 : 0;
                    $score += str_contains(mb_strtolower($r['keywords'] ?? ''), $word) ? 3 : 0;
                    $score += str_contains(mb_strtolower($r['content']), $word) ? 1 : 0;
                }
                $results[] = ['type' => $type, 'id' => (int) $r['id'], 'revision' => (int) $r['revision'], 'title' => $r['title'], 'content' => mb_substr($r['content'], 0, 1600), 'details' => json_decode($r['details'] ?: '{}', true), 'source' => $r['source'], 'score' => $score];
            }
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, 5);
    }
}
