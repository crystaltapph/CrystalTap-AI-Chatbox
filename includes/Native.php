<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

/** Browser-approved device pairing with a verifier held only by the Android client. */
final class Native {
    private static ?array $device = null;
    public static function secure(): void {
        if (!is_ssl() && !in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1','::1'],true)) { throw new Fault('HTTPS is required for Android sign-in.',503); }
    }
    public static function begin(string $challenge): array {
        self::secure(); Auth::rate('android_pair',10,3600);
        if (!preg_match('/^[a-f0-9]{64}$/',$challenge)) { throw new Fault('Invalid device challenge.'); }
        $id=bin2hex(random_bytes(24));$code=strtoupper(substr(bin2hex(random_bytes(8)),0,8));
        DB::insert('native_links',['request_id'=>$id,'challenge'=>$challenge,'code'=>$code,'expires_at'=>gmdate('Y-m-d H:i:s',time()+600),'created_at'=>DB::now()]);
        return ['id'=>$id,'code'=>$code,'expires_in'=>600,'site'=>untrailingslashit(home_url()),'api'=>rest_url('crystaltap-ai/v1/'),'verification_url'=>home_url('/crystaltap-agent/?android_link='.$id.'#profile')];
    }
    private static function link(string $id, bool $lock=false): array {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{48}$/',$id)) { throw new Fault('Device link expired. Start sign-in again.',410); }
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('native_links').' WHERE request_id=%s AND expires_at>%s'.($lock?' FOR UPDATE':''),$id,DB::now()),ARRAY_A);
        if (!$row) { throw new Fault('Device link expired. Start sign-in again.',410); }return $row;
    }
    public static function review(string $id): array { $row=self::link($id);return ['id'=>$id,'code'=>$row['code'],'approved'=>(bool)$row['user_id'],'expires_at'=>$row['expires_at']]; }
    public static function approve(string $id, string $code): array {
        Auth::agent();return DB::transaction(function()use($id,$code){global $wpdb;$row=self::link($id,true);
            if (!hash_equals($row['code'],$code) || $row['user_id']) { throw new Fault('Device code changed or was already approved.',409); }
            $wpdb->update(DB::table('native_links'),['user_id'=>get_current_user_id()],['id'=>$row['id']]);DB::audit('android_link_approved');return ['approved'=>true];
        });
    }
    public static function complete(string $id, string $verifier): array {
        self::secure();Auth::rate('android_poll',180,600,substr(hash('sha256',$id),0,64));
        if (!preg_match('/^[a-f0-9]{64}$/',$verifier)) { throw new Fault('Invalid device verifier.',403); }
        return DB::transaction(function()use($id,$verifier){global $wpdb;$row=self::link($id,true);
            if (!hash_equals($row['challenge'],hash('sha256',$verifier))) { throw new Fault('Invalid device verifier.',403); }
            if (!$row['user_id']) { return ['pending'=>true]; }
            $user=get_userdata((int)$row['user_id']);if (!$user || !user_can($user,'ctai_agent')) { throw new Fault('Agent access is no longer available.',403); }
            if ($row['issued_token']) { $token=Push::decrypt($row['issued_token']); }
            else {
                $token=bin2hex(random_bytes(32));$expires=time()+30*DAY_IN_SECONDS;$session=\WP_Session_Tokens::get_instance($user->ID)->create($expires);
                DB::insert('native_devices',['user_id'=>$user->ID,'token_hash'=>hash('sha256',$token),'session_hash'=>hash('sha256',$session),'credential_version'=>self::version($user),'label'=>'CrystalTap Android','expires_at'=>gmdate('Y-m-d H:i:s',$expires),'created_at'=>DB::now()]);
                $wpdb->update(DB::table('native_links'),['issued_token'=>Push::encrypt($token)],['id'=>$row['id']]);DB::audit('android_device_linked');
            }
            return ['pending'=>false,'token'=>$token,'site'=>untrailingslashit(home_url()),'api'=>rest_url('crystaltap-ai/v1/'),'name'=>$user->display_name];
        });
    }
    private static function version(\WP_User $user): string { return hash_hmac('sha256',$user->user_pass,wp_salt('auth')); }
    public static function valid(array $row): bool {
        $user=get_userdata((int)$row['user_id']);$sessions=$user?get_user_meta($user->ID,'session_tokens',true):[];
        return $user && user_can($user,'ctai_agent') && $row['expires_at']>DB::now() && hash_equals($row['credential_version'],self::version($user)) && isset($sessions[$row['session_hash']]) && ($sessions[$row['session_hash']]['expiration']??0)>time();
    }
    public static function authenticateToken(string $token): array {
        global $wpdb;self::secure();
        if (!preg_match('/^[a-f0-9]{64}$/',$token)) { throw new Fault('Android session expired. Sign in again.',401,'android_session'); }
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('native_devices').' WHERE token_hash=%s',hash('sha256',$token)),ARRAY_A);
        if (!$row || !self::valid($row)) { throw new Fault('Android session expired. Sign in again.',401,'android_session'); }
        self::$device=$row;wp_set_current_user((int)$row['user_id']);return $row;
    }
    public static function authenticate(\WP_REST_Request $r): void {
        $header=$r->get_header('Authorization') ?: ($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if (preg_match('/^Bearer\s+(.*)$/i',$header,$m)) { self::authenticateToken($m[1]); }else {self::$device=null;}
    }
    public static function isNative(): bool {return self::$device!==null;}
    public static function current(): array { if (!self::$device) { throw new Fault('Use the signed-in Android app for this action.',403); }return self::$device; }
    public static function devices(int $user): array {global $wpdb;return $wpdb->get_results($wpdb->prepare('SELECT id,label,created_at,expires_at,(fcm_token IS NOT NULL) AS push_registered FROM '.DB::table('native_devices').' WHERE user_id=%d AND expires_at>%s ORDER BY id DESC',$user,DB::now()),ARRAY_A);}
    public static function register(string $token, bool $systemNotifications=false): array {
        global $wpdb;
        $row=self::current();
        if(strlen($token)<20||strlen($token)>4096||preg_match('/[\s\x00-\x1f]/',$token)) {throw new Fault('Invalid Android push registration.');}
        // App resumes refresh the same registration frequently. Only actual changes
        // consume the limit, so checking settings cannot exhaust token registration.
        if (!empty($row['fcm_token']) && (bool)$row['system_notifications']===$systemNotifications && hash_equals(Push::decrypt($row['fcm_token']),$token)) {
            return ['registered'=>true];
        }
        Auth::rate('android_registration_change',20,3600,(string)$row['id']);
        $wpdb->update(DB::table('native_devices'),['fcm_token'=>Push::encrypt($token),'system_notifications'=>$systemNotifications?1:0],['id'=>$row['id']]);
        return ['registered'=>true];
    }
    public static function revoke(int $id, int $user): array {global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('native_devices').' WHERE id=%d AND user_id=%d',$id,$user),ARRAY_A);if($row){$sessions=get_user_meta($user,'session_tokens',true)?:[];unset($sessions[$row['session_hash']]);update_user_meta($user,'session_tokens',$sessions);$wpdb->delete(DB::table('native_devices'),['id'=>$id]);}return ['revoked'=>true];}
}
