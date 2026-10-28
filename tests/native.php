<?php
/** Local disposable native-auth and FCM HTTP-v1 tests. No Google network calls. */
use CrystalTap\{DB,Native,NativePush,Settings,Push,Fault,Conversations,Notifications};
if(!defined('WP_CLI')||!WP_CLI)exit;
if(home_url()!=='http://localhost:8080')throw new RuntimeException('Native fixtures require the local development site.');
global $wpdb;$prefix=$wpdb->prefix.'ctai_native_test_'.bin2hex(random_bytes(4)).'_';add_filter('ctai_table_prefix',static fn()=>$prefix);
$savedSettings=get_option('ctai_settings',null);$savedSecrets=get_option('ctai_secret_store',null);$savedSchema=get_option('ctai_schema');$savedTick=get_option('ctai_last_tick',null);$users=[];$GLOBALS['native_checks']=0;$stub=null;$result=0;
function ncheck(bool $v,string $name):void{if(!$v)throw new RuntimeException('FAIL: '.$name);$GLOBALS['native_checks']++;echo "PASS: $name\n";}
function ndeny(callable $f,int $status,string $name):void{try{$f();}catch(Fault $e){ncheck($e->status===$status,$name);return;}throw new RuntimeException('Expected rejection: '.$name);}
function nativeReq(string $method,string $path,array $body=[],string $token=''):WP_REST_Response{$r=new WP_REST_Request($method,'/crystaltap-ai/v1'.$path);if($body){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}if($token)$r->set_header('Authorization','Bearer '.$token);return rest_do_request($r);}
try{
 DB::migrate();Settings::save(['ai_enabled'=>false]);
 foreach(['agent','other']as$name){$users[$name]=wp_insert_user(['user_login'=>'ctai_native_'.$name.'_'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(32),'role'=>'ctai_agent']);}
 wp_set_current_user(0);ncheck(nativeReq('GET','/native/config')->get_status()===403,'anonymous Android configuration denied');
 $verifier=bin2hex(random_bytes(32));$pair=Native::begin(hash('sha256',$verifier));ncheck(Native::complete($pair['id'],$verifier)['pending'],'device pairing waits for browser approval');
 ndeny(fn()=>Native::complete($pair['id'],bin2hex(random_bytes(32))),403,'a different device cannot complete a pairing');
 wp_set_current_user($users['agent']);ndeny(fn()=>Native::approve($pair['id'],'BADCODE1'),409,'approval requires the matching displayed code');Native::approve($pair['id'],$pair['code']);ndeny(fn()=>Native::approve($pair['id'],$pair['code']),409,'pairing approval is one-time');
 $login=Native::complete($pair['id'],$verifier);$token=$login['token'];ncheck(strlen($token)===64&&!$login['pending'],'approved device receives a strong opaque access token');ncheck(Native::complete($pair['id'],$verifier)['token']===$token,'network retry returns the same encrypted issued token');
 $row=$wpdb->get_row('SELECT * FROM '.DB::table('native_devices').' LIMIT 1',ARRAY_A);ncheck($row['token_hash']===hash('sha256',$token)&&!str_contains(wp_json_encode($row),$token),'device table stores a token hash, not the access token');
 $wpdb->query('ALTER TABLE '.DB::table('native_devices').' DROP COLUMN system_notifications');DB::migrate();$migrated=$wpdb->get_row('SELECT * FROM '.DB::table('native_devices').' LIMIT 1',ARRAY_A);ncheck($migrated['token_hash']===$row['token_hash']&&(int)$migrated['system_notifications']===0,'upgrading an existing device preserves sign-in and defaults to compatible delivery');
 $link=$wpdb->get_row('SELECT * FROM '.DB::table('native_links').' LIMIT 1',ARRAY_A);ncheck($link['issued_token']!==$token&&Push::decrypt($link['issued_token'])===$token,'temporary retry token is encrypted');
 wp_set_current_user(0);ncheck(nativeReq('GET','/agent/conversations',[],$token)->get_status()===200,'Android bearer session can use the existing agent inbox');
 ncheck(nativeReq('GET','/admin/settings',[],$token)->get_status()===403,'support Android session cannot access administrator settings');
 ncheck(nativeReq('GET','/agent/conversations',[],str_repeat('0',64))->get_status()===401,'invalid bearer cannot fall back to a browser session');
 wp_set_current_user(0);ncheck(nativeReq('GET','/agent/devices')->get_status()===403,'retired browser push endpoint remains protected');wp_set_current_user($users['agent']);ncheck(nativeReq('GET','/agent/push-config')->get_status()===410,'browser push registration is explicitly retired');
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$private);$public=openssl_pkey_get_details($key)['key'];
 $service=['type'=>'service_account','project_id'=>'fixture-project','client_email'=>'fixture@fixture-project.iam.gserviceaccount.com','private_key'=>$private];
 $client=['project_info'=>['project_id'=>'fixture-project','project_number'=>'123456789'],'client'=>[['client_info'=>['mobilesdk_app_id'=>'1:123456789:android:fixture','android_client_info'=>['package_name'=>'com.crystaltap.agent']],'api_key'=>[['current_key'=>'public-client-configuration-fixture']]]]];
 $status=NativePush::save(['service_account'=>wp_json_encode($service),'android_config'=>wp_json_encode($client)]);ncheck($status['configured'],'matching Firebase files configure Android push');
 ncheck(!str_contains(wp_json_encode($status),$private)&&!str_contains(wp_json_encode(Settings::get()),$private),'Firebase status and settings never expose server signing keys');
 $wrong=$client;$wrong['project_info']['project_id']='different-project';ndeny(fn()=>NativePush::save(['android_config'=>wp_json_encode($wrong)]),400,'mismatched Firebase projects are rejected');ncheck(NativePush::config()['client']['project_id']==='fixture-project','failed Firebase save preserves the existing configuration');
 ncheck(nativeReq('POST','/admin/firebase',['service_account'=>wp_json_encode($service)],$token)->get_status()===403,'support agents cannot replace Firebase credentials');
 $config=nativeReq('GET','/native/config',[],$token)->get_data();ncheck(isset($config['firebase']['app_id'])&&!isset($config['firebase']['private_key'])&&!str_contains(wp_json_encode($config),$private),'signed Android receives client configuration only');
 ncheck(nativeReq('PUT','/native/device',['token'=>'fixture-fcm-registration-12345'],$token)->get_status()===200,'Android can register its own FCM token');
 $row=$wpdb->get_row('SELECT * FROM '.DB::table('native_devices').' LIMIT 1',ARRAY_A);ncheck($row['fcm_token']!=='fixture-fcm-registration-12345'&&Push::decrypt($row['fcm_token'])==='fixture-fcm-registration-12345','FCM registration token is encrypted at rest');
 $calls=[];$sent=[];$mode='success';$stub=static function($pre,$args,$url)use(&$calls,&$sent,&$mode,$public){
  if(!str_starts_with($url,'https://oauth2.googleapis.com/')&&!str_starts_with($url,'https://fcm.googleapis.com/'))return $pre;$calls[]=$url;
  if($url==='https://oauth2.googleapis.com/token'){$parts=explode('.',$args['body']['assertion']);$decode=static fn($v)=>base64_decode(strtr($v,'-_','+/'));$claims=json_decode($decode($parts[1]),true);ncheck(openssl_verify($parts[0].'.'.$parts[1],$decode($parts[2]),$public,OPENSSL_ALGO_SHA256)===1&&$claims['aud']==='https://oauth2.googleapis.com/token','OAuth assertion is signed with the configured RSA key and fixed audience');return ['response'=>['code'=>200],'body'=>wp_json_encode(['access_token'=>'oauth-fixture-do-not-display','expires_in'=>3600]),'headers'=>[],'cookies'=>[]];}
  $body=json_decode($args['body'],true);$sent[]=$body['message'];ncheck($body['message']['android']['priority']==='HIGH'&&!str_contains(wp_json_encode($body),'PRIVATE_CUSTOMER_TEXT'),'FCM sends high-priority alerts without customer message previews');
  return ['response'=>['code'=>$mode==='success'?200:404],'body'=>wp_json_encode($mode==='success'?['name'=>'projects/fixture-project/messages/fixture']:['error'=>['message'=>'PRIVATE_PROVIDER_ERROR','details'=>[['errorCode'=>'UNREGISTERED']]]]),'headers'=>[],'cookies'=>[]];
 };add_filter('pre_http_request',$stub,10,3);delete_transient('ctai_fcm_oauth');
 $test=nativeReq('POST','/native/push-test',[],$token);ncheck($test->get_status()===200&&$test->get_data()['accepted'],'Android push test uses actual HTTP-v1 signing and delivery code');
 ncheck(!str_contains(wp_json_encode($test->get_data()),'oauth-fixture'),'push-test response does not expose OAuth credentials');
 ncheck(!isset(end($sent)['notification']),'original APK keeps compatible data-only delivery');
 ncheck(nativeReq('PUT','/native/device',['token'=>'fixture-fcm-registration-12345','system_notifications'=>true],$token)->get_status()===200,'updated Android opts in to background system notifications');
 $test=nativeReq('POST','/native/push-test',[],$token);$modern=end($sent);
 ncheck($test->get_status()===200&&$modern['notification']['body']==='Test alert received on this Android app.'&&$modern['android']['notification']['channel_id']==='crystaltap_support_v1'&&$modern['android']['notification']['sound']==='crystaltap_alert','updated APK receives a generic system notification on the custom sound channel');
 ncheck($modern['data']['conversation']==='0'&&$modern['android']['notification']['visibility']==='PRIVATE','system notification retains safe routing data and private visibility');
 $refreshes=true;for($i=0;$i<25;$i++){$refreshes=$refreshes&&nativeReq('PUT','/native/device',['token'=>'fixture-fcm-registration-12345','system_notifications'=>true],$token)->get_status()===200;}ncheck($refreshes,'repeated app resumes do not exhaust registration limits for an unchanged token');

 $s=DB::insert('sessions',['token_hash'=>hash('sha256',random_bytes(32)),'csrf_hash'=>hash('sha256','fixture'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'created_at'=>DB::now()]);$c=Conversations::start($s);
 $id=(int)$c['id'];$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('notifications'));
 DB::transaction(fn()=>Conversations::message(Conversations::get($id,true),'customer','AI-only fixture','ai-only-fixture'));
 ncheck((int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('notifications'))===$before,'AI-only customer message does not notify human agents');
 DB::insert('agent_profiles',['user_id'=>$users['agent'],'availability'=>'online','heartbeat'=>gmdate('Y-m-d H:i:s',time()-600)]);
 DB::insert('agent_profiles',['user_id'=>$users['other'],'availability'=>'offline']);
 $c=Conversations::handoff($id);$before=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('notifications').' WHERE user_id=%d',$users['agent']));
 $message=Conversations::customer($id,'PRIVATE_CUSTOMER_TEXT waiting for support','waiting-fixture-1234');
 ncheck((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('notifications').' WHERE user_id=%d',$users['agent']))===$before+1,'new message in unclaimed queue alerts an online sleeping agent');
 $event='message-'.$message['message']['id'];$recipient=$wpdb->get_var($wpdb->prepare('SELECT user_id FROM '.DB::table('notifications').' WHERE event_key=%s AND user_id=%d',$event,$users['other']));
 ncheck($recipient===null,'waiting customer message excludes offline agents');
 Conversations::customer($id,'PRIVATE_CUSTOMER_TEXT waiting for support','waiting-fixture-1234');
 ncheck((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('notifications').' WHERE user_id=%d',$users['agent']))===$before+1,'retrying a queued customer message does not create another alert');
 update_option('ctai_last_tick',time()-420,false);$wpdb->query("UPDATE ".DB::table('jobs')." SET created_at='".gmdate('Y-m-d H:i:s',time()-180)."'");$health=Notifications::health();
 ncheck($health['last_worker_age_seconds']>=420&&$health['pending_push_jobs']>=2&&$health['oldest_pending_push_age_seconds']>=180&&str_starts_with($health['worker_status'],'Delayed'),'delivery diagnostics reveal a delayed scheduler and queued alert age');
 wp_set_current_user($users['agent']);$c=Conversations::action((int)$c['id'],'claim',(int)$c['version']);
 $wpdb->query('DELETE FROM '.DB::table('jobs'));Notifications::emit($users['agent'],'native-worker',(int)$c['id'],'message','Support alert');$before=count($calls);Notifications::tick();ncheck(count($calls)===$before+1,'background notification worker delivers to registered Android devices');$workerMessage=end($sent);ncheck($workerMessage['android']['notification']['tag']===$workerMessage['data']['event']&&str_starts_with($workerMessage['data']['event'],'notification-'),'queued alert keeps a stable event and notification tag for retries');ncheck(Notifications::health()['pending_push_jobs']===0&&Notifications::health()['worker_status']==='Recently active','delivery diagnostics report a drained queue after the worker runs');
 $mode='expired';$expired=nativeReq('POST','/native/push-test',[],$token);ncheck($expired->get_status()===409&&!str_contains(wp_json_encode($expired->get_data()),'PRIVATE_PROVIDER_ERROR'),'expired FCM token gives safe re-registration guidance');
 ncheck($wpdb->get_var('SELECT fcm_token FROM '.DB::table('native_devices').' LIMIT 1')===null,'expired FCM registration is cleared without deleting sign-in');
 ncheck(nativeReq('POST','/native/logout',[],$token)->get_status()===200,'Android can revoke its own device session');wp_set_current_user(0);ncheck(nativeReq('GET','/agent/conversations',[],$token)->get_status()===401,'revoked Android access token immediately stops working');
 echo "\n".$GLOBALS['native_checks']." native Android backend checks passed. No Google service called.\n";
}catch(Throwable $e){echo $e->getMessage()."\n";$result=1;}finally{if($stub)remove_filter('pre_http_request',$stub,10);delete_transient('ctai_fcm_oauth');require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$u)wp_delete_user($u);foreach($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($prefix).'%'))as$t)$wpdb->query('DROP TABLE `'.$t.'`');remove_all_filters('ctai_table_prefix');if($savedSettings===null)delete_option('ctai_settings');else update_option('ctai_settings',$savedSettings,false);if($savedSecrets===null)delete_option('ctai_secret_store');else update_option('ctai_secret_store',$savedSecrets,false);update_option('ctai_schema',$savedSchema,false);if($savedTick===null)delete_option('ctai_last_tick');else update_option('ctai_last_tick',$savedTick,false);}
if($result)WP_CLI::halt($result);
