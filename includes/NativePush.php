<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

/** FCM HTTP v1. Signing keys and OAuth access tokens never leave the server. */
final class NativePush {
    public static function config(): array {
        $raw=Settings::secret('firebase_service');$service=json_decode($raw,true)?:[];
        $client=json_decode(Settings::secret('firebase_android'),true)?:[];
        return ['service'=>$service,'client'=>$client];
    }
    public static function status(): array { $c=self::config();return ['configured'=>!empty($c['service']['private_key'])&&!empty($c['client']['app_id']),'openssl'=>function_exists('openssl_sign')]; }
    public static function save(array $input): array {
        $c=self::config();
        if (array_key_exists('service_account',$input)) {
            $s=json_decode((string)$input['service_account'],true);
            if (!is_array($s)||($s['type']??'')!=='service_account'||!preg_match('/^[a-z][a-z0-9-]{4,60}$/',$s['project_id']??'')||!preg_match('/^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\.gserviceaccount\.com$/',$s['client_email']??'')) {throw new Fault('Choose a valid Firebase service-account JSON file.');}
            $key=@openssl_pkey_get_private($s['private_key']??'');$details=$key?openssl_pkey_get_details($key):[];
            if (!$key||($details['type']??-1)!==OPENSSL_KEYTYPE_RSA||($details['bits']??0)<2048){throw new Fault('Firebase signing key is invalid.');}
            $c['service']=array_intersect_key($s,array_flip(['project_id','client_email','private_key']));
        }
        if (array_key_exists('android_config',$input)) {
            $j=json_decode((string)$input['android_config'],true);$found=null;
            foreach($j['client']??[]as$client){if(($client['client_info']['android_client_info']['package_name']??'')==='com.crystaltap.agent'){$found=$client;break;}}
            if (!$found||!preg_match('/^[a-z][a-z0-9-]{4,60}$/',$j['project_info']['project_id']??'')||!preg_match('/^[0-9]+$/',(string)($j['project_info']['project_number']??''))||!is_string($found['api_key'][0]['current_key']??null)||!is_string($found['client_info']['mobilesdk_app_id']??null)){throw new Fault('Use google-services.json for the Android package com.crystaltap.agent.');}
            $c['client']=['project_id'=>$j['project_info']['project_id'],'sender_id'=>(string)$j['project_info']['project_number'],'app_id'=>$found['client_info']['mobilesdk_app_id'],'api_key'=>$found['api_key'][0]['current_key']];
        }
        if (!empty($c['client'])&&!empty($c['service'])&&$c['client']['project_id']!==$c['service']['project_id']){throw new Fault('Both Firebase files must belong to the same project.');}
        if(array_key_exists('service_account',$input))Settings::storeSecret('firebase_service',wp_json_encode($c['service']));
        if(array_key_exists('android_config',$input))Settings::storeSecret('firebase_android',wp_json_encode($c['client']));
        delete_transient('ctai_fcm_oauth');return self::status();
    }
    private static function b64(string $value): string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    private static function access(): string {
        $s=self::config()['service'];if(empty($s['private_key']))throw new Fault('Configure Firebase in CrystalTap Settings first.',503);
        $cached=get_transient('ctai_fcm_oauth');if(is_string($cached)){try{return Push::decrypt($cached);}catch(\Throwable $e){delete_transient('ctai_fcm_oauth');}}
        $now=time();$body=self::b64(wp_json_encode(['alg'=>'RS256','typ'=>'JWT'])).'.'.self::b64(wp_json_encode(['iss'=>$s['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
        if(!openssl_sign($body,$signature,$s['private_key'],OPENSSL_ALGO_SHA256))throw new Fault('Firebase signing failed.',503);
        $r=wp_remote_post('https://oauth2.googleapis.com/token',['timeout'=>15,'redirection'=>0,'sslverify'=>true,'sslcertificates'=>getenv('CTAI_CA_BUNDLE')?:ABSPATH.WPINC.'/certificates/ca-bundle.crt','body'=>['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$body.'.'.self::b64($signature)]]);
        $j=is_wp_error($r)?[]:json_decode(wp_remote_retrieve_body($r),true);
        if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200||!is_string($j['access_token']??null)){throw new Fault('Firebase authentication failed. Check the service account, API permissions and server clock.',502,'firebase_auth');}
        set_transient('ctai_fcm_oauth',Push::encrypt($j['access_token']),max(1,min(3000,(int)($j['expires_in']??3600)-60)));return $j['access_token'];
    }
    public static function send(array $device, int $conversation, bool $test=false, ?string $event=null): void {
        global $wpdb;if(!Native::valid($device)||empty($device['fcm_token']))throw new Fault('Android device registration is unavailable. Sign in and enable notifications in the app.',409);
        $project=self::config()['service']['project_id']??'';if(!$project)throw new Fault('Configure Firebase first.',503);
        $event=$event??wp_generate_uuid4();
        $payload=['message'=>['token'=>Push::decrypt($device['fcm_token']),'data'=>['conversation'=>(string)$conversation,'site'=>untrailingslashit(home_url()),'event'=>$event,'test'=>$test?'1':'0'],'android'=>['priority'=>'HIGH','ttl'=>'300s']]];
        // New clients opt in: Firebase can display alerts while the app is in the
        // background, with the same private content and channel as foreground alerts.
        // Keep data-only delivery for the original APK, whose tap handler expects ints.
        if (!empty($device['system_notifications'])) {
            $payload['message']['notification']=['title'=>'CrystalTap Agent','body'=>$test?'Test alert received on this Android app.':'A support conversation needs your attention.'];
            $payload['message']['android']['notification']=['channel_id'=>'crystaltap_support_v1','sound'=>'crystaltap_alert','icon'=>'ic_notification','tag'=>$event,'visibility'=>'PRIVATE'];
        }
        $r=wp_remote_post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send',['timeout'=>15,'redirection'=>0,'sslverify'=>true,'sslcertificates'=>getenv('CTAI_CA_BUNDLE')?:ABSPATH.WPINC.'/certificates/ca-bundle.crt','headers'=>['Authorization'=>'Bearer '.self::access(),'Content-Type'=>'application/json'],'body'=>wp_json_encode($payload)]);
        $j=is_wp_error($r)?[]:json_decode(wp_remote_retrieve_body($r),true);$status=is_wp_error($r)?0:wp_remote_retrieve_response_code($r);
        if($status===200&&!empty($j['name']))return;
        foreach($j['error']['details']??[]as$detail)if(($detail['errorCode']??'')==='UNREGISTERED'){$wpdb->update(DB::table('native_devices'),['fcm_token'=>null],['id'=>$device['id']]);throw new Fault('Android push registration expired. Reopen the app and enable notifications again.',409);}
        if($status===401)delete_transient('ctai_fcm_oauth');
        throw new Fault('Firebase did not accept the alert. Check project permissions, outbound HTTPS and the Android registration.',502,'firebase_delivery');
    }
    public static function deliver(int $id): void {
        global $wpdb;$n=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('notifications').' WHERE id=%d',$id),ARRAY_A);if(!$n||$n['read_at']||!user_can($n['user_id'],'ctai_agent'))return;
        $p=Notifications::preferences((int)$n['user_id']);$hour=wp_date('H:i');$start=$p['quiet_start'];$end=$p['quiet_end'];
        if(!$p['push']||empty($p[$n['type']])||($start&&$end&&($start<$end?($hour>=$start&&$hour<$end):($hour>=$start||$hour<$end))))return;
        $c=Conversations::get((int)$n['conversation_id']);if(!user_can($n['user_id'],'ctai_supervise')&&(int)$c['assigned_agent']!==(int)$n['user_id']&&$c['status']!=='waiting')return;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('native_devices').' WHERE user_id=%d AND fcm_token IS NOT NULL LIMIT 10',$n['user_id']),ARRAY_A);$failed=false;
        foreach($rows as$row){if(!Native::valid($row)){Native::revoke((int)$row['id'],(int)$row['user_id']);continue;}try{self::send($row,(int)$n['conversation_id'],false,'notification-'.$id);}catch(\Throwable $e){$failed=true;}}
        if($failed)throw new \RuntimeException('Android push retry required');
    }
}
