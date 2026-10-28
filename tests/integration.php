<?php
/** Run using wp eval-file inside the local development site; all plugin tables are isolated. */
use CrystalTap\{DB,Auth,Conversations,Settings,Content,AI,AIProvider,Notifications,Push,Leads,Privacy,Fault};
if (!defined('WP_CLI') || !WP_CLI) { exit; }
global $wpdb;
$prefix = $wpdb->prefix . 'ctai_test_' . bin2hex(random_bytes(4)) . '_';
add_filter('ctai_table_prefix', static fn() => $prefix);
$savedSecrets = get_option('ctai_secret_store', null); $savedCron = get_option('cron'); $savedTick = get_option('ctai_last_tick', null);
$saved = get_option('ctai_settings', null); $schema = get_option('ctai_schema'); $users = []; $GLOBALS['ctai_checks'] = 0;
function check(bool $condition, string $name): void { if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } $GLOBALS['ctai_checks']++; echo "PASS: $name\n"; }
function denied(callable $fn, int $status, string $name): void { try { $fn(); } catch (Fault $e) { check($e->status === $status, $name); return; } throw new RuntimeException('Expected rejection: ' . $name); }
function req(string $method, string $path, array $body = []): WP_REST_Response { $r = new WP_REST_Request($method, '/crystaltap-ai/v1' . $path); if ($body) { $r->set_header('Content-Type','application/json'); $r->set_body(wp_json_encode($body)); } return rest_do_request($r); }
$result = 0;
try {
    DB::migrate();
    check(count($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'))) === 20, '20 isolated plugin tables created');
    DB::migrate(); check($wpdb->last_error === '', 'schema migration is repeatable');
    $admin = get_users(['role'=>'administrator','number'=>1])[0]->ID;
    foreach (['agent','other','supervisor'] as $name) {
        $u = wp_insert_user(['user_login'=>'ctai_test_'.$name.'_'.bin2hex(random_bytes(3)), 'user_pass'=>wp_generate_password(32), 'role'=>$name==='supervisor'?'ctai_supervisor':'ctai_agent']);
        if (is_wp_error($u)) { throw new RuntimeException('Could not create test user'); } $users[$name] = $u;
    }
    wp_set_current_user($admin);
    Settings::save(['ai_enabled'=>false,'session_limit'=>100,'input_price'=>0.4,'output_price'=>1.6,'daily_budget'=>5,'monthly_budget'=>75]);
    $session = DB::insert('sessions',['token_hash'=>hash('sha256','test-one'),'csrf_hash'=>hash('sha256','test-csrf'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'created_at'=>DB::now()]);
    $session2 = DB::insert('sessions',['token_hash'=>hash('sha256','test-two'),'csrf_hash'=>hash('sha256','test-csrf2'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'created_at'=>DB::now()]);
    $c = Conversations::start($session); $id = (int) $c['id'];
    denied(fn()=>Conversations::owned($id,$session2),404,'visitor cannot access another session');
    $r = Conversations::customer($id,'Hello po','abcdefghijkl1234');
    $r2 = Conversations::customer($id,'Hello po','abcdefghijkl1234');
    check($r['message']['id']===$r2['message']['id'],'duplicate sends return the same message');
    denied(fn()=>Conversations::customer($id,'Changed content','abcdefghijkl1234'),409,'idempotency key cannot change payload');
    wp_set_current_user(0);
    check(req('GET','/agent/conversations')->get_status()===403,'anonymous agent API denied');
    check(req('GET','/admin/settings')->get_status()===403,'anonymous settings API denied');
    wp_set_current_user($users['agent']);
    check(req('GET','/admin/settings')->get_status()===403,'support agent cannot read settings');
    check(req('GET',"/agent/conversations/$id")->get_status()===403,'unassigned agent cannot read history');
    $c = Conversations::handoff($id); check($c['status']==='waiting','handoff queues conversation');
    $version = (int) $c['version']; $c = Conversations::action($id,'claim',$version);
    check((int)$c['assigned_agent']===$users['agent'],'eligible agent claims conversation');
    wp_set_current_user($users['other']);
    denied(fn()=>Conversations::action($id,'claim',$version),409,'stale competing claim rejected');
    denied(fn()=>Conversations::agentMessage($id,'Unauthorized','unauthorized1234',false),403,'other agent cannot reply');
    wp_set_current_user($users['agent']);
    Conversations::agentMessage($id,'Internal payment discussion','internalnote1234',true);
    Conversations::agentMessage($id,'Hello from support','agentmessage1234',false);
    check(count(array_filter(Conversations::history($id),fn($m)=>$m['visibility']==='internal'))===0,'private notes never appear in visitor history');
    check(Conversations::get($id)['status']==='human_active','agent reply activates human state');
    $n = Notifications::listing($users['agent']); check($n['unread']>=1,'assignment creates persistent notification');
    Notifications::emit($users['agent'],'dedup-test',$id,'message','Test alert'); Notifications::emit($users['agent'],'dedup-test',$id,'message','Test alert');
    $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('notifications').' WHERE event_key=%s','dedup-test'));check($count===1,'notifications deduplicate');
    $list=Notifications::listing($users['agent']);$through=(int)$list['items'][0]['id'];
    Notifications::emit($users['agent'],'new-after-boundary',$id,'message','New alert');Notifications::read($users['agent'],$through,true);
    check(Notifications::listing($users['agent'])['unread']===1,'mark all read preserves newly arriving notifications');
    check(Notifications::listing($users['other'])['unread']===0,'notification recipient isolation');
    // Private images: real decoder/re-encoding, ownership, binding, retry and retention.
    $imageFile=wp_tempnam('ctai-test-image');$pixels=imagecreatetruecolor(8,8);imagepng($pixels,$imageFile);imagedestroy($pixels);
    try {
        file_put_contents($imageFile,'PRIVATE_TRAILING_METADATA',FILE_APPEND);
        $clean=CrystalTap\Images::clean($imageFile,'fixture.png');
        check($clean['mime']==='image/png'&&!str_contains(base64_decode($clean['payload']),'PRIVATE_TRAILING_METADATA'),'image processing re-encodes pixels and discards trailing content');
        denied(fn()=>CrystalTap\Images::clean($imageFile,'fixture.php'),400,'image extension spoofing rejected');
        file_put_contents($imageFile,str_repeat('a',CrystalTap\Images::MAX_BYTES),FILE_APPEND);
        denied(fn()=>CrystalTap\Images::clean($imageFile,'fixture.png'),413,'oversized images rejected');
        file_put_contents($imageFile,'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        denied(fn()=>CrystalTap\Images::clean($imageFile,'fixture.png'),400,'SVG disguised as PNG rejected');
    } finally {@unlink($imageFile);}
    foreach ([['1'],[1,1],[1,2,3,4]] as $invalid)denied(fn()=>CrystalTap\Images::ids($invalid),400,'attachment IDs are bounded, numeric and unique');
    $image=CrystalTap\Images::store($id,['kind'=>'visitor','id'=>$session],$clean,'fixtureupload1234');
    check(CrystalTap\Images::store($id,['kind'=>'visitor','id'=>$session],$clean,'fixtureupload1234')['id']===$image['id'],'upload retries return the original private image');
    $different=$clean;$different['checksum']=hash('sha256','different');
    denied(fn()=>CrystalTap\Images::store($id,['kind'=>'visitor','id'=>$session],$different,'fixtureupload1234'),409,'upload retry key cannot change image bytes');
    $message=Conversations::customer($id,'Photo for support','image-message-1234',null,[$image['id']]);
    $retry=Conversations::customer($id,'Photo for support','image-message-1234',null,[$image['id']]);
    check($message['message']['id']===$retry['message']['id']&&count($message['message']['attachments'])===1,'image message retries preserve one message and its attachment');
    denied(fn()=>Conversations::customer($id,'Photo for support','image-message-1234'),409,'message retry cannot silently drop attached images');
    $secondImageConversation=(int)Conversations::start($session)['id'];
    denied(fn()=>Conversations::customer($secondImageConversation,'Wrong image','image-cross-1234',null,[$image['id']]),403,'images cannot be attached to another conversation');
    check(Conversations::history($secondImageConversation)===[],'invalid image binding rolls back the entire message');
    $visitorToken=bin2hex(random_bytes(32));$wpdb->update(DB::table('sessions'),['token_hash'=>hash('sha256',$visitorToken)],['id'=>$session]);
    wp_set_current_user(0);$_COOKIE[Auth::COOKIE]=$visitorToken;
    $imageResponse=req('GET','/images/'.$image['id']);
    check($imageResponse->get_status()===200&&$imageResponse->get_headers()['Content-Type']==='image/png','own visitor can retrieve protected image with the correct content type');
    check(!str_contains(wp_json_encode($message),$clean['payload']),'message history does not expose stored image bytes or public upload paths');
    unset($_COOKIE[Auth::COOKIE]);check(req('GET','/images/'.$image['id'])->get_status()===401,'anonymous image retrieval is denied');
    $otherToken=bin2hex(random_bytes(32));$wpdb->update(DB::table('sessions'),['token_hash'=>hash('sha256',$otherToken)],['id'=>$session2]);$_COOKIE[Auth::COOKIE]=$otherToken;
    check(req('GET','/images/'.$image['id'])->get_status()===404,'another visitor cannot retrieve the image');
    wp_set_current_user($users['other']);check(req('GET','/images/'.$image['id'])->get_status()===403,'unassigned support agent cannot retrieve the image');
    wp_set_current_user($users['agent']);check(req('GET','/images/'.$image['id'])->get_status()===200,'assigned agent can retrieve the customer image');
    $privateImage=CrystalTap\Images::store($id,['kind'=>'agent','id'=>$users['agent']],$clean,'privateimage1234');
    Conversations::agentMessage($id,'Private image note','private-image-note',true,[$privateImage['id']]);
    check(count(CrystalTap\Images::export($id))===1&&count(CrystalTap\Images::export($id,true))===2,'privacy image exports exclude internal notes while administrator exports include them');
    wp_set_current_user(0);$_COOKIE[Auth::COOKIE]=$visitorToken;
    check(req('GET','/images/'.$privateImage['id'])->get_status()===404,'visitor cannot retrieve an internal-note image by guessing its ID');
    wp_set_current_user($users['agent']);unset($_COOKIE[Auth::COOKIE]);
    $typingC=Conversations::get($id);CrystalTap\Typing::set($typingC,true,true);
    check(CrystalTap\Typing::state(Conversations::get($id))['agent'],'agent typing indicator has a short-lived server state');
    $wpdb->update(DB::table('conversations'),['agent_typing_until'=>gmdate('Y-m-d H:i:s',time()-1)],['id'=>$id]);
    check(!CrystalTap\Typing::state(Conversations::get($id))['agent'],'typing indicator expires without an explicit stop request');
    $staged=CrystalTap\Images::store($id,['kind'=>'agent','id'=>$users['agent']],$clean,'staged-cleanup-123');
    $wpdb->update(DB::table('images'),['created_at'=>gmdate('Y-m-d H:i:s',time()-2*DAY_IN_SECONDS)],['id'=>$staged['id']]);Privacy::cleanup();
    check(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('images').' WHERE id=%d',$staged['id'])),'abandoned image uploads are removed by cleanup');
    check((bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('images').' WHERE id=%d',$image['id'])),'cleanup preserves images attached to retained messages');
    // Contact destinations are public links, never provider credentials or forwarded messages.
    $contacts=Settings::save(['whatsapp_number'=>'+63 (912) 345-6789','messenger_url'=>'https://m.me/CrystalTapFixture','viber_number'=>'+63 912 345 6789','viber_url'=>'']);
    $links=Settings::publicConfig()['channels'];
    check(count($links)===3&&$links[0]['url']==='https://wa.me/639123456789'&&$links[2]['url']==='viber://chat?number=%2B639123456789','configured contacts publish canonical WhatsApp, Messenger and Viber links');
    check(!isset(Settings::publicConfig()['openai_key'])&&!isset(Settings::publicConfig()['vapid_private']),'public contact configuration excludes private credentials');
    Settings::save(['whatsapp_number'=>'639123456780']);Settings::save(['whatsapp_number'=>'639123456789']);
    check(Settings::get()['whatsapp_number']==='639123456789','numeric contact values retain string type across repeated saves');
    denied(fn()=>Settings::save(['whatsapp_number'=>'09123456789']),400,'local phone number without country code is rejected');
    denied(fn()=>Settings::save(['messenger_url'=>'https://m.me.attacker.invalid/Page']),400,'lookalike Messenger domain rejected');
    denied(fn()=>Settings::save(['messenger_url'=>'https://m.me@attacker.invalid/Page']),400,'Messenger URL userinfo attack rejected');
    denied(fn()=>Settings::save(['viber_url'=>'javascript:alert(1)']),400,'executable Viber URL rejected');
    denied(fn()=>Settings::save(['viber_url'=>'https://vb.me.attacker.invalid/chat']),400,'lookalike Viber share domain rejected');
    denied(fn()=>Settings::save(['viber_url'=>'viber://chat?number[]=12345678']),400,'Viber query arrays rejected');
    check(Settings::get()['whatsapp_number']==='639123456789'&&Settings::get()['viber_url']==='','invalid contact save leaves existing settings intact');
    Settings::save(['viber_url'=>'viber://pa?chatURI=crystaltap_fixture']);
    check(Settings::publicConfig()['channels'][2]['url']==='viber://pa?chatURI=crystaltap_fixture','Viber bot link overrides the optional phone link');
    Settings::save(['viber_url'=>'https://invite.viber.com/?g2=public-fixture']);
    check(Settings::publicConfig()['channels'][2]['url']==='https://invite.viber.com/?g2=public-fixture','official Viber share links are supported');
    check(CrystalTap\Channels::viber('https://VB.ME/public-fixture')==='https://vb.me/public-fixture','Viber host casing is normalized for consistent browser rendering');
    check(CrystalTap\Channels::links(['messenger_url'=>'javascript:alert(1)'])===[],'unsafe legacy contact settings remain hidden on read');
    Settings::save(['whatsapp_number'=>'','messenger_url'=>'','viber_number'=>'','viber_url'=>'']);
    check(Settings::publicConfig()['channels']===[],'blank contact settings hide all external buttons');
    check(Push::decrypt(Push::encrypt('subscription-secret'))==='subscription-secret','existing encrypted credentials remain compatible');
    // Background agents must keep queue alerts after browsers suspend their heartbeat.
    $wpdb->insert(DB::table('agent_profiles'), ['user_id'=>$users['other'],'availability'=>'online','heartbeat'=>gmdate('Y-m-d H:i:s',time()-600)]);
    Notifications::queue($id,'handoff','Sleeping phone test','sleeping-phone');
    check(Notifications::listing($users['other'])['unread']===1,'explicitly online sleeping agent receives queue alerts');
    $wpdb->update(DB::table('agent_profiles'),['availability'=>'offline'],['user_id'=>$users['other']]);
    Notifications::queue($id,'handoff','Offline phone test','offline-phone');
    check(Notifications::listing($users['other'])['unread']===1,'explicitly offline agent is excluded from queue alerts');
    wp_clear_scheduled_hook('ctai_tick'); CrystalTap\Plugin::ensureSchedule(); $scheduled=wp_next_scheduled('ctai_tick'); CrystalTap\Plugin::ensureSchedule();
    check($scheduled&&wp_next_scheduled('ctai_tick')===$scheduled&&wp_get_schedule('ctai_tick')==='ctai_minute','missing worker event repairs itself without duplicating or postponing it');
    $decoder = new CrystalTap\ResponseStream(); $deltas = [];
    $wire = 'data: '.wp_json_encode(['type'=>'response.output_text.delta','delta'=>'{"answer":"Hello \\u006dundo","product_ids":[],"needs_human":false}'])."\r\n\r\n";
    $wire .= 'data: '.wp_json_encode(['type'=>'response.completed','response'=>['status'=>'completed']])."\r\n\r\n";
    foreach(str_split($wire,3)as$bytes)$decoder->feed($bytes,static function($text)use(&$deltas){$deltas[]=$text;});
    check(end($deltas)==='Hello mundo'&&$decoder->completed['status']==='completed','stream decoder handles split events, CRLF and escaped Unicode');
    $product=Content::save('products',['title'=>'Verified Test Purifier','content'=>'An approved test product for district water.','keywords'=>'purifier district','enabled'=>true,'details'=>['price'=>'123.45','currency'=>'PHP']]);
    Content::save('knowledge',['title'=>'Private draft','content'=>'Unapproved secret promotion','enabled'=>false]);
    $retrieved=Content::retrieve('district purifier');check(count($retrieved)>=1&&$retrieved[0]['title']==='Verified Test Purifier','approved knowledge retrieval works');
    check(Content::retrieve('Unapproved secret promotion')===[],'draft knowledge excluded');
    denied(fn()=>Leads::capture($id,['name'=>'Test','phone'=>'123']),400,'lead capture requires consent');
    Leads::capture($id,['name'=>'Test Customer','email'=>'fixture@example.invalid','consent'=>true]);
    check(Leads::listing(1)===[],'agent cannot access an unassigned lead');
    wp_set_current_user($users['supervisor']);check(count(Leads::listing(1))===1,'supervisor can manage queue leads');
    AI::reserve(0.5); denied(fn()=>AI::reserve(5),429,'budget cannot be over-reserved'); AI::settle(AI::periods(),0.5,0.1);
    check((float)$wpdb->get_var('SELECT SUM(reserved) FROM '.DB::table('budget_buckets'))===0.0,'budget reservations settle');
    // A deterministic provider tests the complete orchestration without consuming external API credit.
    if(!defined('CTAI_OPENAI_KEY'))define('CTAI_OPENAI_KEY','test-placeholder-not-a-real-key');
    Settings::save(['ai_enabled'=>true]);
    $httpCalls = 0; $errorBody = ['error'=>['code'=>'insufficient_quota','message'=>'PRIVATE_PROVIDER_MESSAGE_DO_NOT_DISPLAY']];
    $httpStub = static function($pre,$args,$url)use(&$httpCalls,&$errorBody){
        if($url!=='https://api.openai.com/v1/responses')return $pre;
        $httpCalls++;
        return ['headers'=>[],'body'=>wp_json_encode($errorBody),'response'=>['code'=>429,'message'=>'Too Many Requests'],'cookies'=>[]];
    };
    add_filter('pre_http_request',$httpStub,10,3);
    $retryOverride=static fn($s)=>array_replace($s,['retry_limit'=>1]);add_filter('ctai_settings',$retryOverride);
    try {
        try {(new CrystalTap\ResponsesProvider())->generate(['model'=>'gpt-4.1-mini'],5);throw new RuntimeException('Quota rejection expected');}
        catch(Fault $e){check($e->slug==='ai_quota'&&str_contains($e->getMessage(),'billing'),'provider quota error gives actionable billing guidance');check(!str_contains($e->getMessage(),'PRIVATE_PROVIDER_MESSAGE'),'provider error text is never exposed');}
        check($httpCalls===1,'quota errors are not retried');
        $errorBody=['error'=>['code'=>'rate_limit_exceeded','message'=>'PRIVATE_PROVIDER_MESSAGE_DO_NOT_DISPLAY']];$httpCalls=0;
        try {(new CrystalTap\ResponsesProvider())->generate(['model'=>'gpt-4.1-mini'],5);throw new RuntimeException('Rate rejection expected');}
        catch(Fault $e){check($e->slug==='ai_rate_limit'&&$e->status===429,'temporary rate limits are distinct from quota errors');}
        check($httpCalls===2,'temporary rate limits keep the configured retry bound');
        $errorBody=['error'=>['message'=>'PRIVATE_PROVIDER_MESSAGE_DO_NOT_DISPLAY']];
        try {(new CrystalTap\ResponsesProvider())->generate(['model'=>'gpt-4.1-mini'],5,1);throw new RuntimeException('Generic rejection expected');}
        catch(Fault $e){check($e->slug==='ai_provider_limit'&&!str_contains($e->getMessage(),'PRIVATE_PROVIDER_MESSAGE'),'unclassified 429 has safe quota and rate-limit guidance');}
    } finally {remove_filter('pre_http_request',$httpStub,10);remove_filter('ctai_settings',$retryOverride);}
    $provider = new class implements CrystalTap\StreamingAIProvider {
        public bool $takeover = false;
        public function generate(array $body,int $timeout): array {
            global $aiConversation;
            if(str_starts_with($body['instructions']??'','Summarize'))return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'Customer is comparing water purifiers.']]]],'usage'=>['input_tokens'=>100,'output_tokens'=>15]];
            if($this->takeover)Conversations::handoff($aiConversation);
            return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'{"answer":"Approved test answer","product_ids":[999999],"needs_human":false}']]]],'usage'=>['input_tokens'=>100,'output_tokens'=>20]];
        }
        public function generateStream(array $body,int $timeout,callable $delta):array {$delta('Approved test'); return $this->generate($body,$timeout);}
    };
    add_filter('ctai_ai_provider',static fn()=>$provider);
    $aiConversation=(int)Conversations::start($session)['id'];
    Conversations::customer($aiConversation,'district purifier','aitestmessage1234');
    $answers=array_values(array_filter(Conversations::history($aiConversation),fn($m)=>$m['sender']==='ai'));
    check(count($answers)===1&&$answers[0]['content']==='Approved test answer','Responses orchestration persists a provider answer');
    check($answers[0]['products']===[],'unapproved product IDs cannot create cards');
    Settings::save(['streaming_enabled'=>true]);$chunks=[];
    Conversations::customer($aiConversation,'Tell me more','aistreamtest12345',static function($text)use(&$chunks){$chunks[]=$text;});
    check($chunks===['Approved test'],'streaming transport forwards eligible provider text');
    $beforeRace=count(array_filter(Conversations::history($aiConversation),fn($m)=>$m['sender']==='ai'));
    $provider->takeover=true;
    Conversations::customer($aiConversation,'Please help again','airacetest1234567');
    check(count(array_filter(Conversations::history($aiConversation),fn($m)=>$m['sender']==='ai'))===$beforeRace,'AI response suppressed when human handoff occurs during generation');
    check(Conversations::get($aiConversation)['status']==='waiting','handoff state survives AI completion');
    $provider->takeover=false;
    $summaryConversation=(int)Conversations::start($session)['id'];
    for($i=0;$i<45;$i++){DB::transaction(function()use($summaryConversation,$i){Conversations::message(Conversations::get($summaryConversation,true),'customer','Comparing purifier options '.$i,'summary-fixture-'.$i);});}
    AI::summarize($summaryConversation);
    check(Conversations::get($summaryConversation)['summary']==='Customer is comparing water purifiers.','background context summarization persists bounded memory');
    $spent=(float)$wpdb->get_var($wpdb->prepare('SELECT spent FROM '.DB::table('budget_buckets').' WHERE period=%s',AI::periods()[0]));
    AI::reserve(0.01); Settings::save(['daily_budget'=>$spent+0.01]);
    $budgetConversation=(int)Conversations::start($session)['id'];
    Conversations::customer($budgetConversation,'Cannot exceed budget','budgetreject12345');
    $remaining=(float)$wpdb->get_var($wpdb->prepare('SELECT reserved FROM '.DB::table('budget_buckets').' WHERE period=%s',AI::periods()[0]));
    check(abs($remaining-0.01)<0.000001,'failed reservation cannot consume another request reservation');
    AI::settle(AI::periods(),0.01,0);Settings::save(['daily_budget'=>5]);
    $c=Conversations::get($id); wp_set_current_user($users['agent']); Conversations::action($id,'resolve',(int)$c['version']);
    denied(fn()=>Conversations::agentMessage($id,'Cannot send','resolvedtest1234',false),409,'resolved conversations reject agent replies');
    $reopened=Conversations::action($id,'reopen',(int)Conversations::get($id)['version']);
    check($reopened['status']==='waiting'&&empty($reopened['assigned_agent']),'reopened conversation returns to the unassigned queue');
    check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('notifications')." WHERE event_key=%s AND user_id=%d",'reopen-'.$id.'-'.$reopened['version'],$users['supervisor']))===1,'reopening immediately alerts the support queue');

    // Nested rollback must not commit a parent transaction during handoff.
    $nested=(int)Conversations::start($session)['id'];
    try { DB::transaction(function()use($nested){Conversations::handoff($nested);throw new RuntimeException('rollback probe');}); } catch(RuntimeException $e){}
    check(Conversations::get($nested)['status']==='ai_active','nested transactions preserve outer rollback');
    wp_set_current_user($admin); Privacy::eraseConversation($id);
    check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('messages').' WHERE conversation_id=%d',$id))===0,'erasure removes conversation messages');
    check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('images').' WHERE conversation_id=%d',$id))===0,'erasure removes private image bytes');
    check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('leads').' WHERE conversation_id=%d',$id))===0,'erasure removes linked lead');
    echo "\n".$GLOBALS['ctai_checks']." integration checks passed. No external AI or push service called.\n";
} catch(Throwable $e) { echo $e->getMessage()."\n".$e->getTraceAsString()."\n"; $result=1; }
finally {
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach($users as $u)wp_delete_user($u);
    foreach($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($prefix).'%'))as$t)$wpdb->query('DROP TABLE `'.str_replace('`','``',$t).'`');
    remove_all_filters('ctai_table_prefix');
    if($saved===null)delete_option('ctai_settings');else update_option('ctai_settings',$saved,false);
    update_option('ctai_schema',$schema,false);
    if($savedSecrets===null)delete_option('ctai_secret_store');else update_option('ctai_secret_store',$savedSecrets,false);
    update_option('cron',$savedCron);
    if($savedTick===null)delete_option('ctai_last_tick');else update_option('ctai_last_tick',$savedTick,false);
}
if($result)WP_CLI::halt($result);
