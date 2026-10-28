<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Images {
    public const MAX_BYTES = 2097152;
    public static function ids(mixed $ids): array {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 3) { throw new Fault('Attach up to three images per message.'); }
        foreach ($ids as $id) { if (!is_int($id) || $id < 1) { throw new Fault('Invalid image attachment.'); } }
        if (count(array_unique($ids)) !== count($ids)) { throw new Fault('Duplicate image attachment.'); }
        sort($ids); return $ids;
    }
    public static function text(array $body): string {
        $ids = self::ids($body['attachments'] ?? []); $content=$body['content'] ?? '';
        if(!is_string($content)) { throw new Fault('Message text must be a string.'); }
        return $ids && trim($content) === '' ? 'Image attachment' : Auth::text($content);
    }
    public static function clean(string $path, string $name): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $mime = wp_get_image_mime($path);
        $types = ['image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/webp'=>['webp']];
        if (!isset($types[$mime]) || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $types[$mime], true)) { throw new Fault('Choose a genuine JPG, PNG or WebP image.'); }
        if (!filesize($path) || filesize($path) > self::MAX_BYTES) { throw new Fault('Each image must be 2 MB or smaller.', 413); }
        $size = wp_getimagesize($path);
        if (!$size || $size[0] * $size[1] > 20000000 || max($size[0], $size[1]) > 10000) { throw new Fault('Image dimensions are too large. Resize the image and try again.', 413); }
        // Re-encode pixels, discarding embedded metadata and trailing executable content.
        if (!extension_loaded('gd')) { throw new Fault('Image uploads require the PHP GD extension. Ask your host to enable it.',503); }
        $gd = static fn()=>['WP_Image_Editor_GD']; add_filter('wp_image_editors',$gd,100);
        try { $editor = wp_get_image_editor($path); } finally { remove_filter('wp_image_editors',$gd,100); }
        if (is_wp_error($editor)) { throw new Fault('This server cannot process the image. Ask your host to enable GD.', 503); }
        $editor->maybe_exif_rotate();
        $editor->resize(2400, 2400, false); $editor->set_quality(85);
        $tmp = wp_tempnam('ctai-image');
        if (!$tmp) { throw new Fault('Image processing is temporarily unavailable.', 503); }
        $saved = null;
        try {
            $saved = $editor->save($tmp . '.' . $types[$mime][0], $mime);
            if (is_wp_error($saved) || empty($saved['path'])) { throw new Fault('The image could not be processed.'); }
            $bytes = file_get_contents($saved['path']);
            if ($bytes === false || strlen($bytes) > self::MAX_BYTES) { throw new Fault('Processed image exceeds 2 MB. Resize it and try again.', 413); }
            return ['mime'=>$mime,'width'=>(int)$saved['width'],'height'=>(int)$saved['height'],'bytes'=>strlen($bytes),'payload'=>base64_encode($bytes),'checksum'=>hash('sha256',$bytes)];
        } finally { @unlink($tmp); if (is_array($saved) && !empty($saved['path'])) { @unlink($saved['path']); } }
    }
    public static function upload(array $conversation, array $actor, array $file, string $key): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) { throw new Fault('Choose a JPG, PNG or WebP image up to 2 MB. Your host may also limit upload size.'); }
        if ($actor['kind'] === 'visitor') { Auth::rate('image_upload_ip',40,3600); }
        Auth::rate('image_upload', 20, 3600, $actor['kind'] . ':' . $actor['id']);
        return self::store((int)$conversation['id'], $actor, self::clean($file['tmp_name'], $file['name']), $key);
    }
    public static function store(int $conversation, array $actor, array $image, string $key): array {
        global $wpdb;
        $key = Auth::key($key);
        return DB::transaction(function () use ($conversation,$actor,$image,$key,$wpdb) {
            $c = Conversations::get($conversation, true);
            if ($actor['kind'] === 'visitor') {
                if ((int)$c['session_id'] !== $actor['id']) { throw new Fault('Conversation not found',404); }
                if (in_array($c['status'],['resolved','closed'],true)) { throw new Fault('Start a new chat before attaching an image.',409); }
            } else { Auth::scope($c); }
            $hash = hash('sha256',$conversation.':'.$actor['kind'].':'.$actor['id'].':'.$key);
            $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('images').' WHERE upload_key=%s',$hash),ARRAY_A);
            if ($old) { if (!hash_equals($old['checksum'],$image['checksum'])) { throw new Fault('An upload retry must use the same image.',409); } return self::view($old); }
            if ((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('images').' WHERE conversation_id=%d',$conversation)) >= 20) { throw new Fault('This conversation has reached its 20-image limit.',429); }
            $id = DB::insert('images',$image+['conversation_id'=>$conversation,'actor_kind'=>$actor['kind'],'actor_id'=>$actor['id'],'upload_key'=>$hash,'created_at'=>DB::now()]);
            return self::view(['id'=>$id]+$image);
        });
    }
    public static function bind(array $ids, array $c, int $message, array $actor): void {
        global $wpdb;
        foreach (self::ids($ids) as $id) {
            $image = $wpdb->get_row($wpdb->prepare('SELECT id,conversation_id,message_id,actor_kind,actor_id FROM '.DB::table('images').' WHERE id=%d FOR UPDATE',$id),ARRAY_A);
            if (!$image || (int)$image['conversation_id'] !== (int)$c['id'] || $image['actor_kind'] !== $actor['kind'] || (int)$image['actor_id'] !== $actor['id'] || ($image['message_id'] && (int)$image['message_id'] !== $message)) { throw new Fault('Image attachment is unavailable for this message.',403); }
            if ($wpdb->update(DB::table('images'),['message_id'=>$message],['id'=>$id]) === false) { throw new \RuntimeException('Image binding failed'); }
        }
    }
    public static function view(array $image): array { return ['id'=>(int)$image['id'],'mime'=>$image['mime'],'width'=>(int)$image['width'],'height'=>(int)$image['height'],'bytes'=>(int)$image['bytes']]; }
    public static function forMessage(int $message): array {
        global $wpdb;
        return array_map([self::class,'view'],$wpdb->get_results($wpdb->prepare('SELECT id,mime,width,height,bytes FROM '.DB::table('images').' WHERE message_id=%d ORDER BY id',$message),ARRAY_A));
    }
    public static function export(int $conversation, bool $internal = false): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT i.id,i.mime,i.width,i.height,i.payload AS base64 FROM '.DB::table('images').' i INNER JOIN '.DB::table('messages').' m ON m.id=i.message_id WHERE i.conversation_id=%d'.($internal?'':" AND m.visibility='public'").' ORDER BY i.id',$conversation),ARRAY_A);
    }
    public static function read(int $id): array {
        global $wpdb;
        $image = $wpdb->get_row($wpdb->prepare('SELECT i.id,i.conversation_id,i.message_id,i.actor_kind,i.actor_id,i.mime,i.width,i.height,i.bytes,m.visibility FROM '.DB::table('images').' i LEFT JOIN '.DB::table('messages').' m ON m.id=i.message_id WHERE i.id=%d',$id),ARRAY_A);
        if (!$image || ($image['message_id'] && !$image['visibility'])) { throw new Fault('Image not found',404); }
        $c = Conversations::get((int)$image['conversation_id']);
        if (current_user_can('ctai_agent')) {
            Auth::scope($c);
            if (!$image['message_id'] && ($image['actor_kind'] !== 'agent' || (int)$image['actor_id'] !== get_current_user_id())) { throw new Fault('Image not found',404); }
        } else {
            $session = Auth::session(); Conversations::owned((int)$c['id'],(int)$session['id']);
            if ($image['visibility'] === 'internal' || (!$image['message_id'] && ($image['actor_kind'] !== 'visitor' || (int)$image['actor_id'] !== (int)$session['id']))) { throw new Fault('Image not found',404); }
        }
        $image['payload'] = $wpdb->get_var($wpdb->prepare('SELECT payload FROM '.DB::table('images').' WHERE id=%d',$id));
        return $image;
    }
}
