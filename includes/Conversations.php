<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Conversations {
    public static function get(int $id, bool $lock = false): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('conversations') . ' WHERE id=%d' . ($lock ? ' FOR UPDATE' : ''), $id), ARRAY_A);
        if (!$row) { throw new Fault('Conversation not found', 404); }
        return $row;
    }
    public static function owned(int $id, int $session): array {
        $row = self::get($id);
        if ((int) $row['session_id'] !== $session) { throw new Fault('Conversation not found', 404); }
        return $row;
    }
    public static function start(int $session, ?array $customer = null): array {
        Auth::rate('conversations', 10, 3600, (string) $session);
        $customer=$customer===null?null:Customer::validate($customer);
        $now = DB::now();
        $profile=$customer===null?[]:['customer_name'=>$customer['name'],'customer_email'=>$customer['email'],'customer_phone'=>$customer['phone']];
        $id = DB::insert('conversations', ['session_id' => $session, 'created_at' => $now, 'updated_at' => $now]+$profile);
        return self::view(self::get($id));
    }
    public static function view(array $row): array {
        return array_intersect_key($row, array_flip(['id','status','version','assigned_agent','created_at','updated_at']));
    }
    public static function message(array $conversation, string $sender, string $text, string $key, bool $internal = false, array $metadata = []): array {
        global $wpdb;
        $table = DB::table('messages');
        $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE conversation_id=%d AND client_key=%s", $conversation['id'], $key), ARRAY_A);
        if ($old) {
            if ((json_decode($old['metadata'] ?: '{}',true)['attachment_ids'] ?? []) !== ($metadata['attachment_ids'] ?? []) || $old['content'] !== $text || $old['sender'] !== $sender || ($old['visibility'] === 'internal') !== $internal || ($sender === 'agent' && (int) $old['user_id'] !== get_current_user_id())) { throw new Fault('Idempotency key already used for a different message', 409); }
            $old['duplicate'] = true;
            return $old;
        }
        $seq = (int) $conversation['next_seq'];
        $id = DB::insert('messages', ['conversation_id' => $conversation['id'], 'seq' => $seq, 'sender' => $sender, 'user_id' => $sender === 'agent' ? get_current_user_id() : null, 'visibility' => $internal ? 'internal' : 'public', 'content' => $text, 'metadata' => wp_json_encode($metadata), 'client_key' => $key, 'created_at' => DB::now()]);
        if ($wpdb->query($wpdb->prepare('UPDATE ' . DB::table('conversations') . ' SET next_seq=next_seq+1, updated_at=%s WHERE id=%d', DB::now(), $conversation['id'])) === false) { throw new \RuntimeException('Conversation update failed'); }
        self::event((int) $conversation['id'], $internal ? 'note' : 'message');
        if ($sender === 'customer' && $conversation['assigned_agent']) {
            Notifications::emit((int) $conversation['assigned_agent'], 'message-' . $id, (int) $conversation['id'], 'message', 'New customer message');
        } elseif ($sender === 'customer' && $conversation['status'] === 'waiting') {
            Notifications::queue((int) $conversation['id'], 'message', 'New customer message in the support queue', 'message-' . $id);
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
    }
    public static function customer(int $id, string $text, string $key, ?callable $delta = null, array $attachments = []): array {
        global $wpdb;
        Auth::rate('messages', 20, 60);
        $result = DB::transaction(function () use ($id, $text, $key, $wpdb, $attachments) {
            $c = self::get($id, true);
            if (in_array($c['status'], ['resolved','closed'], true)) { throw new Fault('Start a new conversation or ask an agent to reopen this one', 409); }
            $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . DB::table('sessions') . ' WHERE id=%d FOR UPDATE', $c['session_id']));
            $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DB::table('messages') . ' m INNER JOIN ' . DB::table('conversations') . ' c ON c.id=m.conversation_id WHERE c.session_id=%d AND m.sender=%s', $c['session_id'], 'customer'));
            $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . DB::table('messages') . ' WHERE conversation_id=%d AND client_key=%s', $id, 'visitor-' . $key));
            if (!$existing && $count >= Settings::get()['session_limit']) { throw new Fault('Conversation message limit reached. Please contact support.', 429); }
            $message = self::message($c, 'customer', $text, 'visitor-' . $key, false, ['attachment_ids'=>Images::ids($attachments)]);
            Images::bind($attachments,$c,(int)$message['id'],['kind'=>'visitor','id'=>(int)$c['session_id']]);
            $wpdb->update(DB::table('conversations'),['visitor_typing_until'=>null],['id'=>$id]);
            if (empty($message['duplicate'])) { $wpdb->query($wpdb->prepare('UPDATE ' . DB::table('conversations') . ' SET generation=generation+1 WHERE id=%d', $id)); }
            $message['_generation'] = (int) $c['generation'] + 1;
            return $message;
        });
        if ($attachments && empty($result['duplicate']) && self::get($id)['status'] === 'ai_active') { self::handoff($id); }
        if (!$attachments && empty($result['duplicate']) && self::get($id)['status'] === 'ai_active') { AI::reply($id, (int) $result['id'], (int) $result['_generation'], $delta); }
        return ['accepted' => true, 'message' => self::publicMessage($result), 'conversation' => self::view(self::get($id))];
    }
    public static function agentMessage(int $id, string $text, string $key, bool $internal, array $attachments = []): array {
        return DB::transaction(function () use ($id, $text, $key, $internal, $attachments) {
            $c = self::get($id, true);
            Auth::scope($c);
            if (!$internal && !in_array($c['status'], ['assigned','human_active'], true)) { throw new Fault('Take over this conversation before replying', 409); }
            $message = self::message($c, 'agent', $text, 'agent-' . $key, $internal, ['attachment_ids'=>Images::ids($attachments)]);
            Images::bind($attachments,$c,(int)$message['id'],['kind'=>'agent','id'=>get_current_user_id()]);
            global $wpdb; $wpdb->update(DB::table('conversations'),['agent_typing_until'=>null],['id'=>$id]);
            if (!$internal && $c['status'] === 'assigned') { self::update($c, ['status' => 'human_active']); }
            return self::publicMessage($message, true);
        });
    }
    public static function update(array $c, array $data): void {
        global $wpdb;
        $data['agent_typing_until'] = null; $data['visitor_typing_until'] = null;
        $data['version'] = (int) $c['version'] + 1;
        $data['generation'] = (int) $c['generation'] + 1;
        $data['updated_at'] = DB::now();
        if ($wpdb->update(DB::table('conversations'), $data, ['id' => $c['id']]) === false) { throw new \RuntimeException('State transition failed'); }
        self::event((int) $c['id'], 'state');
    }
    public static function handoff(int $id): array {
        return DB::transaction(function () use ($id) {
            $c = self::get($id, true);
            if ($c['status'] !== 'ai_active') { return self::view($c); }
            self::update($c, ['status' => 'human_requested']);
            $c = self::get($id, true);
            self::update($c, ['status' => 'waiting', 'followup_at' => gmdate('Y-m-d H:i:s', time() + Settings::get()['followup_minutes'] * 60)]);
            Notifications::queue($id, 'handoff', 'A customer requested human support', 'handoff-' . $id . '-' . $c['version']);
            return self::view(self::get($id));
        });
    }
    public static function action(int $id, string $action, int $version, int $target = 0): array {
        return DB::transaction(function () use ($id, $action, $version, $target) {
            $c = self::get($id, true);
            $uid = Auth::agent();
            if ((int) $c['version'] !== $version) { throw new Fault('Conversation changed. Refresh and try again.', 409, 'stale_version'); }
            $data = [];
            if ($action === 'claim') {
                if (!in_array($c['status'], ['waiting','ai_active'], true) || $c['assigned_agent']) { throw new Fault('This conversation cannot be claimed', 409); }
                $data = ['assigned_agent' => $uid, 'status' => 'assigned', 'followup_at' => null];
            } else {
                Auth::scope($c);
                switch ($action) {
                    case 'transfer':
                        Auth::agent('ctai_supervise');
                        if (!in_array($c['status'], ['assigned','human_active'], true) || !user_can($target, 'ctai_agent')) { throw new Fault('Invalid transfer'); }
                        $data = ['assigned_agent' => $target, 'status' => 'assigned']; break;
                    case 'resolve':
                        if (!in_array($c['status'], ['assigned','human_active','waiting','ai_active'], true)) { throw new Fault('Invalid resolution', 409); }
                        $data = ['status' => 'resolved', 'followup_at' => null]; break;
                    case 'close':
                        if ($c['status'] !== 'resolved') { throw new Fault('Resolve before closing', 409); }
                        $data = ['status' => 'closed']; break;
                    case 'reopen':
                        if (!in_array($c['status'], ['resolved','closed'], true)) { throw new Fault('Conversation is not resolved', 409); }
                        $data = ['status' => 'waiting', 'assigned_agent' => null, 'followup_at' => gmdate('Y-m-d H:i:s', time() + Settings::get()['followup_minutes'] * 60)]; break;
                    case 'return-to-ai':
                        if (!in_array($c['status'], ['assigned','human_active'], true) || !Settings::get()['ai_enabled']) { throw new Fault('AI is unavailable or this state is not eligible', 409); }
                        $data = ['status' => 'ai_active', 'assigned_agent' => null, 'followup_at' => null]; break;
                    default: throw new Fault('Unknown action');
                }
            }
            self::update($c, $data);
            DB::insert('conversation_assignments', ['conversation_id' => $id, 'agent_id' => $data['assigned_agent'] ?? $c['assigned_agent'], 'actor_id' => $uid, 'action' => $action, 'created_at' => DB::now()]);
            if ($action === 'transfer' || $action === 'claim') { Notifications::emit((int) $data['assigned_agent'], 'assignment-' . $id . '-' . ($version + 1), $id, 'assignment', 'Conversation assigned to you'); }
            if ($action === 'reopen') { Notifications::queue($id, 'handoff', 'A conversation was reopened for support', 'reopen-' . $id . '-' . ($version + 1)); }
            DB::audit('conversation_' . $action, $id);
            return self::view(self::get($id));
        });
    }
    public static function history(int $id, int $after = 0, bool $agent = false): array {
        global $wpdb;
        $sql = 'SELECT * FROM ' . DB::table('messages') . ' WHERE conversation_id=%d AND seq>%d' . ($agent ? '' : " AND visibility='public'") . ' ORDER BY seq ASC LIMIT 100';
        return array_map(fn($m) => self::publicMessage($m, $agent), $wpdb->get_results($wpdb->prepare($sql, $id, $after), ARRAY_A));
    }
    public static function publicMessage(array $m, bool $agent = false): array {
        $result = array_intersect_key($m, array_flip(['id','seq','sender','content','created_at','visibility']));
        $metadata = json_decode($m['metadata'] ?? '{}', true) ?: [];
        $result['attachments'] = Images::forMessage((int)$m['id']);
        $result['products'] = [];
        foreach (array_slice($metadata['product_ids'] ?? [], 0, 3) as $id) {
            $product = Content::product((int) $id);
            if ($product) { $result['products'][] = $product; }
        }
        return $result;
    }
    public static function event(int $id, string $type): void { DB::insert('events', ['conversation_id' => $id, 'type' => $type, 'created_at' => DB::now()]); }
    public static function inbox(string $filter, int $page = 1, string $search = ''): array {
        global $wpdb;
        $uid = Auth::agent();
        $where = current_user_can('ctai_supervise') ? '1=1' : $wpdb->prepare("(assigned_agent=%d OR (assigned_agent IS NULL AND status IN ('waiting','ai_active')))", $uid);
        if ($filter === 'mine') { $where .= $wpdb->prepare(' AND assigned_agent=%d', $uid); }
        elseif ($filter === 'active') { $where .= " AND status NOT IN ('resolved','closed')"; }
        elseif (in_array($filter, ['waiting','resolved','closed'], true)) { $where .= $wpdb->prepare(' AND status=%s', $filter); }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like(substr($search, 0, 80)) . '%';
            $canSearch = current_user_can('ctai_supervise') ? '1=1' : $wpdb->prepare('c.assigned_agent=%d', $uid);
            $where .= $wpdb->prepare(' AND (CAST(c.id AS CHAR) LIKE %s OR ((' . $canSearch . ') AND (c.customer_name LIKE %s OR EXISTS(SELECT 1 FROM ' . DB::table('leads') . ' l WHERE l.conversation_id=c.id AND l.name LIKE %s) OR EXISTS(SELECT 1 FROM ' . DB::table('messages') . " m WHERE m.conversation_id=c.id AND m.visibility='public' AND m.content LIKE %s))))", $like, $like, $like, $like);
        }
        $rows = $wpdb->get_results('SELECT c.* FROM ' . DB::table('conversations') . " c WHERE $where ORDER BY updated_at DESC,id DESC LIMIT 40 OFFSET " . ((max(1, min(1000, $page)) - 1) * 40), ARRAY_A);
        return array_map(function ($c) use ($wpdb, $uid) {
            $view = self::view($c);
            if (current_user_can('ctai_supervise') || (int) $c['assigned_agent'] === $uid) {
                $view['customer'] = Customer::details($c);
                $view['preview'] = $wpdb->get_var($wpdb->prepare('SELECT content FROM ' . DB::table('messages') . " WHERE conversation_id=%d AND visibility='public' ORDER BY seq DESC LIMIT 1", $c['id']));
                $view['unread'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DB::table('messages') . " WHERE conversation_id=%d AND sender='customer' AND seq>COALESCE((SELECT last_seq FROM " . DB::table('conversation_reads') . ' WHERE conversation_id=%d AND user_id=%d),0)', $c['id'], $c['id'], $uid));
            } else { $view['preview'] = 'Claim to view customer details'; $view['unread'] = 0; }
            return $view;
        }, $rows);
    }
}
