<?php
/** Run using wp eval-file inside the local development site; all plugin tables are isolated. */
use CrystalTap\{DB,Auth,Conversations,Settings,Content,AI,AIProvider,Notifications,Push,Leads,Privacy,Fault};
if (!defined('WP_CLI') || !WP_CLI) { exit; }
global $wpdb;
$prefix = $wpdb->prefix . 'ctai_test_' . bin2hex(random_bytes(4)) . '_';
add_filter('ctai_table_prefix', static fn() => $prefix);
$saved = get_option('ctai_settings', null); $schema = get_option('ctai_schema'); $users = []; $GLOBALS['ctai_checks'] = 0;
function check(bool $condition, string $name): void { if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } $GLOBALS['ctai_checks']++; echo "PASS: $name\n"; }
function denied(callable $fn, int $status, string $name): void { try { $fn(); } catch (Fault $e) { check($e->status === $status, $name); return; } throw new RuntimeException('Expected rejection: ' . $name); }
function req(string $method, string $path, array $body = []): WP_REST_Response { $r = new WP_REST_Request($method, '/crystaltap-ai/v1' . $path); if ($body) { $r->set_header('Content-Type','application/json'); $r->set_body(wp_json_encode($body)); } return rest_do_request($r); }
$result = 0;
try {
    DB::migrate();
    check(count($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'))) === 17, '17 isolated plugin tables created');
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
    denied(fn()=>Push::validate(['endpoint'=>'https://127.0.0.1/private','keys'=>[]]),400,'push SSRF endpoint rejected');
    denied(fn()=>Push::validate(['endpoint'=>'https://fcm.googleapis.com@127.0.0.1/private','keys'=>[]]),400,'push endpoint userinfo attack rejected');
    check(Push::decrypt(Push::encrypt('subscription-secret'))==='subscription-secret','authenticated subscription encryption round trip');
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
    // Nested rollback must not commit a parent transaction during handoff.
    $nested=(int)Conversations::start($session)['id'];
    try { DB::transaction(function()use($nested){Conversations::handoff($nested);throw new RuntimeException('rollback probe');}); } catch(RuntimeException $e){}
    check(Conversations::get($nested)['status']==='ai_active','nested transactions preserve outer rollback');
    wp_set_current_user($admin); Privacy::eraseConversation($id);
    check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('messages').' WHERE conversation_id=%d',$id))===0,'erasure removes conversation messages');
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
}
if($result)WP_CLI::halt($result);
