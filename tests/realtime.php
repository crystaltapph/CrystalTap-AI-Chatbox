<?php
/** Local disposable WordPress realtime tests; no external service is contacted. */
use CrystalTap\{DB,Auth,Settings,NativePush,Native,Realtime,Conversations,Notifications,Push,Fault};
if(!defined('WP_CLI')||!WP_CLI)exit;
if(home_url()!=='http://localhost:8080')throw new RuntimeException('Realtime fixtures require the local development site.');
global $wpdb;$prefix=$wpdb->prefix.'ctai_rt_test_'.bin2hex(random_bytes(4)).'_';add_filter('ctai_table_prefix',static fn()=>$prefix);
$options=[];foreach(['ctai_settings','ctai_secret_store','ctai_schema','ctai_last_tick','ctai_rt_last_publish','ctai_rt_error']as$key)$options[$key]=get_option($key,null);
$cookie=$_COOKIE[Auth::COOKIE]??null;$users=[];$stub=null;$result=0;$GLOBALS['rt_checks']=0;
function rcheck(bool $value,string $message):void{if(!$value)throw new RuntimeException('FAIL: '.$message);$GLOBALS['rt_checks']++;echo "PASS: $message\n";}
function rdeny(callable $f,int $status,string $message):void{try{$f();}catch(Fault $e){rcheck($e->status===$status,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function rtRequest(string $method,string $path,array $body=[],string $csrf='',string $bearer=''):WP_REST_Response{$r=new WP_REST_Request($method,'/crystaltap-ai/v1'.$path);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));if($csrf)$r->set_header('X-CTAI-CSRF',$csrf);if($bearer)$r->set_header('Authorization','Bearer '.$bearer);return rest_do_request($r);}
function rtSession():array{$token=bin2hex(random_bytes(32));$_COOKIE[Auth::COOKIE]=$token;$csrf=hash_hmac('sha256',$token,wp_salt('nonce'));$id=DB::insert('sessions',['token_hash'=>hash('sha256',$token),'csrf_hash'=>hash('sha256',$csrf),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'created_at'=>DB::now()]);return [$id,$csrf,$token];}
$decode=static fn($part)=>base64_decode(strtr($part,'-_','+/'));
try{
 DB::migrate();Settings::save(['ai_enabled'=>false]);Settings::storeSecret('firebase_realtime','');
 foreach(['agent','other','supervisor']as$name){$users[$name]=wp_insert_user(['user_login'=>'ctai_rt_'.$name.'_'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(32),'role'=>$name==='supervisor'?'ctai_supervisor':'ctai_agent']);}
 [$session,$csrf,$visitorToken]=rtSession();wp_set_current_user(0);
 rcheck(rtRequest('POST','/agent/realtime')->get_status()===403,'anonymous staff realtime credentials are denied');
 rcheck(rtRequest('POST','/public/realtime')->get_status()===403,'visitor realtime credentials require the session CSRF token');
 rcheck(rtRequest('POST','/public/realtime',[],$csrf)->get_data()===['enabled'=>false],'existing installations keep polling until realtime is configured');
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$private);$public=openssl_pkey_get_details($key)['key'];
 $service=['type'=>'service_account','project_id'=>'fixture-project','client_email'=>'fixture@fixture-project.iam.gserviceaccount.com','private_key'=>$private];
 $android=['project_info'=>['project_id'=>'fixture-project','project_number'=>'123456789'],'client'=>[['client_info'=>['mobilesdk_app_id'=>'1:123456789:android:fixture','android_client_info'=>['package_name'=>'com.crystaltap.agent']],'api_key'=>[['current_key'=>'AIza'.str_repeat('B',35)]]]]];
 NativePush::save(['service_account'=>wp_json_encode($service),'android_config'=>wp_json_encode($android)]);
 $config=['enabled'=>true,'database_url'=>'https://fixture-project-default-rtdb.asia-southeast1.firebasedatabase.app','firebase'=>['projectId'=>'fixture-project','apiKey'=>'AIza'.str_repeat('A',35),'appId'=>'1:123456789:web:abc123']];
 $saved=Realtime::save($config);rcheck($saved['enabled']&&$saved['firebase']['databaseURL']===$config['database_url'],'matching web and service-account configuration enables live updates');
 foreach(['https://127.0.0.1','https://fixture-project-default-rtdb.asia-southeast1.firebasedatabase.app.attacker.invalid','https://fixture-project-default-rtdb.asia-southeast1.firebasedatabase.app/path','http://fixture-project-default-rtdb.asia-southeast1.firebasedatabase.app']as$url)rdeny(fn()=>Realtime::save(array_replace($config,['database_url'=>$url])),400,'database configuration rejects a non-project or unsafe endpoint');
 $wrong=$config;$wrong['firebase']['projectId']='different-project';rdeny(fn()=>Realtime::save($wrong),400,'cross-project configuration is rejected');
 rcheck(Realtime::rules()===json_decode(file_get_contents(CTAI_DIR.'assets/firebase-database.rules.json'),true),'downloadable rules match the server rule contract');
 $visitor=rtRequest('POST','/public/realtime',[],$csrf)->get_data();$parts=explode('.',$visitor['token']);$claims=json_decode($decode($parts[1]),true);
 rcheck(openssl_verify($parts[0].'.'.$parts[1],$decode($parts[2]),$public,OPENSSL_ALGO_SHA256)===1,'WordPress signs Firebase custom tokens with the configured RSA key');
 rcheck($claims['aud']==='https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit'&&$claims['uid']===$claims['claims']['ctai_feed'],'custom token binds Firebase identity to a single opaque feed');
 rcheck($claims['claims']['ctai_site']===Realtime::site()&&$claims['claims']['ctai_until']<=((time()+180)*1000)&&$visitor['renew_after']<=120,'custom claims limit the website namespace and authorization lease');
 rcheck(!str_contains(wp_json_encode($visitor),$private)&&!str_contains($visitor['path'],(string)$users['agent'].'/'),'visitor grant contains no service private key or raw user ID');
 $otherSession=rtSession();$otherGrant=rtRequest('POST','/public/realtime',[],$otherSession[1])->get_data();rcheck($visitor['path']!==$otherGrant['path'],'different visitor sessions receive different authorized feeds');$_COOKIE[Auth::COOKIE]=$visitorToken;
 wp_set_current_user($users['agent']);$staff=rtRequest('POST','/agent/realtime')->get_data();wp_set_current_user($users['other']);$otherStaff=rtRequest('POST','/agent/realtime')->get_data();rcheck($staff['path']!==$otherStaff['path']&&$staff['path']!==$visitor['path'],'staff and visitor feeds are isolated by actor');
 wp_set_current_user($users['agent']);rcheck(rtRequest('POST','/admin/realtime',$config)->get_status()===403,'support agents cannot change realtime configuration');
 $verifier=bin2hex(random_bytes(32));$pair=Native::begin(hash('sha256',$verifier));Native::approve($pair['id'],$pair['code']);$login=Native::complete($pair['id'],$verifier);$native=rtRequest('POST','/agent/realtime',[],'',$login['token'])->get_data();rcheck($native['firebase']['appId']==='1:123456789:android:fixture'&&$native['path']===$staff['path'],'native grants use Android client configuration without changing the staff feed');
 rcheck(rtRequest('POST','/agent/realtime',[],'',str_repeat('0',64))->get_status()===401,'invalid native bearer cannot obtain Firebase credentials');wp_set_current_user($users['agent']);
 $patches=[];$mode='ok';$publicOpen=false;$oauth=0;$stub=static function($pre,$args,$url)use(&$patches,&$mode,&$publicOpen,&$oauth,$public,$decode,$config){
  if($url==='https://oauth2.googleapis.com/token'){$oauth++;$jwt=explode('.',$args['body']['assertion']);$claim=json_decode($decode($jwt[1]),true);rcheck(openssl_verify($jwt[0].'.'.$jwt[1],$decode($jwt[2]),$public,OPENSSL_ALGO_SHA256)===1&&str_contains($claim['scope'],'firebase.database'),'database OAuth assertions are signed and use the database scope');return ['response'=>['code'=>200],'body'=>wp_json_encode(['access_token'=>'fixture-realtime-oauth','expires_in'=>3600]),'headers'=>[],'cookies'=>[]];}
  if(str_starts_with($url,$config['database_url'])){
   if(($args['method']??'GET')==='GET'&&str_ends_with($url,'/.json'))return ['response'=>['code'=>$publicOpen?200:401],'body'=>'null','headers'=>[],'cookies'=>[]];
   rcheck(!DB::inTransaction(),'Firebase is never contacted before the SQL transaction commits');
   if(($args['method']??'GET')==='PATCH'){$body=json_decode($args['body'],true);$patches[]=$body;rcheck(!str_contains($args['body'],'PRIVATE_CUSTOMER_MESSAGE')&&!str_contains($args['body'],'PRIVATE_TEAM_NOTE')&&!str_contains($args['body'],'customer@example.invalid'),'Firebase payloads contain no messages, contact details or private notes');}
   return ['response'=>['code'=>$mode==='ok'?200:503],'body'=>$mode==='ok'?'null':'PRIVATE_PROVIDER_ERROR','headers'=>[],'cookies'=>[]];
  }
  return new WP_Error('fixture_network_disabled','External requests are disabled for this fixture.');
 };add_filter('pre_http_request',$stub,10,3);delete_transient('ctai_rt_oauth');
 $c=Conversations::start($session,['name'=>'Fixture Customer','email'=>'customer@example.invalid']);$id=(int)$c['id'];$before=count($patches);
 try{DB::transaction(function()use($id,&$patches,$before){Conversations::message(Conversations::get($id,true),'customer','Rolled back message','rt-rollback-fixture');rcheck(count($patches)===$before,'uncommitted message does not publish an event');throw new RuntimeException('rollback fixture');});}catch(RuntimeException $e){if($e->getMessage()!=='rollback fixture')throw $e;}
 Realtime::flush();rcheck(count($patches)===$before,'rolled-back events are not published during shutdown either');
 DB::transaction(fn()=>Conversations::message(Conversations::get($id,true),'customer','PRIVATE_CUSTOMER_MESSAGE','rt-commit-fixture'));rcheck(count($patches)===$before+1,'a committed message publishes immediately without running cron');
 $body=end($patches);$visitorFeed=explode('/',$visitor['path'])[2];$staffFeed=explode('/',$staff['path'])[2];$otherFeed=explode('/',$otherStaff['path'])[2];rcheck(isset($body[$visitorFeed.'/signal'],$body[$staffFeed.'/signal']),'message change reaches its visitor and eligible staff feeds');rcheck($wpdb->get_var("SELECT COUNT(*) FROM ".DB::table('jobs')." WHERE type='realtime'")==0,'successful immediate publication removes its durable outbox job');
 rcheck(get_transient('ctai_rt_oauth')!=='fixture-realtime-oauth'&&Push::decrypt(get_transient('ctai_rt_oauth'))==='fixture-realtime-oauth','realtime OAuth access tokens are encrypted at rest');
 $c=Conversations::handoff($id);$c=Conversations::action($id,'claim',(int)$c['version']);Conversations::agentMessage($id,'PRIVATE_TEAM_NOTE','rt-private-fixture',true);$body=end($patches);rcheck(!isset($body[$visitorFeed.'/signal'])&&!isset($body[$otherFeed.'/signal'])&&isset($body[$staffFeed.'/signal']),'private-note hints reach authorized staff only');
 $mode='fail';$message=Conversations::customer($id,'PRIVATE_CUSTOMER_MESSAGE saved despite failure','rt-retry-fixture');rcheck($message['accepted']&&$wpdb->get_var("SELECT COUNT(*) FROM ".DB::table('jobs')." WHERE type='realtime'")==1,'a provider failure preserves the message and queues a background retry');rcheck(!str_contains(wp_json_encode(Realtime::status()),'PRIVATE_PROVIDER_ERROR'),'diagnostics do not expose provider responses or credentials');
 $mode='ok';$wpdb->query("UPDATE ".DB::table('jobs')." SET available_at='".DB::now()."' WHERE type='realtime'");Notifications::tick();rcheck($wpdb->get_var("SELECT COUNT(*) FROM ".DB::table('jobs')." WHERE type='realtime'")==0,'the existing cron worker retries failed realtime events');
 // A database outage may leave many hints queued; existing push jobs keep priority.
 for($n=0;$n<10;$n++)Notifications::job('rt-backlog-'.$n,'realtime',['conversation'=>$id,'visitor'=>false,'previous_agent'=>0,'change'=>wp_generate_uuid4()]);
 Notifications::job('rt-priority-push','push',['notification'=>0]);Notifications::tick();
 rcheck($wpdb->get_var("SELECT COUNT(*) FROM ".DB::table('jobs')." WHERE dedup_key='rt-priority-push'")==0&&$wpdb->get_var("SELECT COUNT(*) FROM ".DB::table('jobs')." WHERE type='realtime'")>0,'a backlog of realtime retries does not starve existing push jobs');Notifications::tick();
 $test=Realtime::test();rcheck($test['writer_accepted']&&$test['public_root_denied'],'connection test checks database writer access and locked public reads');$publicOpen=true;rdeny(fn()=>Realtime::test(),409,'connection test rejects databases with public root reads');
 $disabled=$config;$disabled['enabled']=false;Realtime::save($disabled);rcheck(rtRequest('POST','/public/realtime',[],$csrf)->get_data()===['enabled'=>false],'disabling realtime safely returns clients to regular refresh');
 echo "\n".$GLOBALS['rt_checks']." realtime backend checks passed. No Google service called.\n";
}catch(Throwable $e){echo $e->getMessage()."\n";$result=1;}finally{if($stub)remove_filter('pre_http_request',$stub,10);delete_transient('ctai_rt_oauth');require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$user)wp_delete_user($user);foreach($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($prefix).'%'))as$t)$wpdb->query('DROP TABLE `'.$t.'`');remove_all_filters('ctai_table_prefix');foreach($options as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}if($cookie===null)unset($_COOKIE[Auth::COOKIE]);else $_COOKIE[Auth::COOKIE]=$cookie;}
if($result)WP_CLI::halt($result);
