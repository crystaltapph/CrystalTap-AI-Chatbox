<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Typing {
    public static function set(array $c, bool $agent, bool $active): array {
        global $wpdb;
        $field = $agent ? 'agent_typing_until' : 'visitor_typing_until';
        // Never expose private-note typing or activity from a former assignee.
        $allowed = $agent ? in_array($c['status'],['assigned','human_active'],true) : !in_array($c['status'],['resolved','closed'],true);
        $wpdb->update(DB::table('conversations'),[$field=>$active && $allowed ? gmdate('Y-m-d H:i:s',time()+8) : null],['id'=>$c['id'],'version'=>$c['version']]);
        Realtime::changed((int)$c['id'],$agent?'typing-agent':'typing-customer');
        return ['saved'=>true];
    }
    public static function state(array $c): array {
        return ['customer'=>!empty($c['visitor_typing_until']) && $c['visitor_typing_until'] > DB::now(), 'agent'=>in_array($c['status'],['assigned','human_active'],true) && !empty($c['agent_typing_until']) && $c['agent_typing_until'] > DB::now()];
    }
}
