<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

/** Firebase carries opaque change hints, never messages or contact details. */
final class Realtime {
    private static array $pending=[];
    private static bool $flushing=false;
    public static function config(): array {return json_decode(Settings::secret('firebase_realtime'),true)?:['enabled'=>false,'database_url'=>'','firebase'=>[]];}
    public static function enabled(): bool {$c=self::config();return !empty($c['enabled'])&&!empty($c['database_url']);}
    public static function site(): string {return substr(hash_hmac('sha256',untrailingslashit(home_url()),wp_salt('auth')),0,32);}
    private static function feed(string $actor): string {return hash_hmac('sha256',$actor,wp_salt('auth'));}
    private static function visitor(array $session): string {return self::feed('visitor:'.$session['id'].':'.$session['token_hash']);}
    private static function staff(int $user): string {return self::feed('agent:'.$user);}
    public static function status(): array {return ['enabled'=>self::enabled(),'last_publish'=>(int)get_option('ctai_rt_last_publish',0),'last_error'=>(string)get_option('ctai_rt_error','')];}
    public static function save(array $input): array {
        $web=$input['firebase']??[];if(!is_array($web)||!is_string($input['database_url']??null))throw new Fault('Enter a valid Firebase web project configuration.');$url=rtrim($input['database_url'],'/');$parts=wp_parse_url($url);
        $project=$web['projectId']??'';
        if(!is_array($web)||!is_string($project)||!preg_match('/^[a-z][a-z0-9-]{4,60}$/',$project))throw new Fault('Enter a valid Firebase web project configuration.');
        $host=$parts['host']??'';
        if(($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||isset($parts['port'])||!empty($parts['path'])||!preg_match('/^'.preg_quote($project,'/').'(?:-[a-z0-9-]+)?\.(?:[a-z0-9-]+\.firebasedatabase\.app|firebaseio\.com)$/',$host))throw new Fault('Use the HTTPS Realtime Database URL for this Firebase project.');
        if(!is_string($web['apiKey']??null)||!preg_match('/^AIza[A-Za-z0-9_-]{35}$/',$web['apiKey'])||!is_string($web['appId']??null)||!preg_match('/^1:[0-9]+:web:[a-f0-9]+$/',$web['appId']))throw new Fault('Paste the Firebase web app configuration, including apiKey and web appId.');
        $service=NativePush::config()['service'];
        if(empty($service['private_key'])||($service['project_id']??'')!==$project)throw new Fault('Save the matching Firebase service-account file under Android Firebase notifications first.');
        $config=['enabled'=>($input['enabled']??false)===true,'database_url'=>$url,'firebase'=>['apiKey'=>$web['apiKey'],'appId'=>$web['appId'],'projectId'=>$project,'authDomain'=>$project.'.firebaseapp.com','databaseURL'=>$url]];
        Settings::storeSecret('firebase_realtime',wp_json_encode($config));delete_transient('ctai_rt_oauth');update_option('ctai_rt_error','',false);return $config+self::status();
    }
    private static function b64(string $text): string {return rtrim(strtr(base64_encode($text),'+/','-_'),'=');}
    private static function jwt(array $claims): string {
        $service=NativePush::config()['service'];$body=self::b64(wp_json_encode(['alg'=>'RS256','typ'=>'JWT'])).'.'.self::b64(wp_json_encode($claims));
        if(empty($service['private_key'])||!openssl_sign($body,$signature,$service['private_key'],OPENSSL_ALGO_SHA256))throw new Fault('Firebase realtime signing is unavailable.',503);
        return $body.'.'.self::b64($signature);
    }
    public static function grant(bool $visitor): array {
        $session=$visitor?Auth::session():null;if(!$visitor)Auth::agent();
        if(!self::enabled())return ['enabled'=>false];
        $feed=$visitor?self::visitor($session):self::staff(get_current_user_id());
        Auth::rate('realtime_grant',120,600,$feed);$now=time();$end=$now+180;
        if($session)$end=min($end,strtotime($session['expires_at'].' UTC'));
        elseif(Native::isNative())$end=min($end,strtotime(Native::current()['expires_at'].' UTC'));
        if($end<=$now)throw new Fault('Chat access expired. Sign in or start a new chat.',401);
        $service=NativePush::config()['service'];$config=self::config();$web=$config['firebase'];
        if(!$visitor&&Native::isNative()){
            $android=NativePush::config()['client'];if(empty($android['app_id'])||($android['project_id']??'')!==$web['projectId'])throw new Fault('Matching Android Firebase configuration is required.',503);
            $web=['apiKey'=>$android['api_key'],'appId'=>$android['app_id'],'projectId'=>$android['project_id'],'databaseURL'=>$config['database_url']];
        }
        $token=self::jwt(['iss'=>$service['client_email'],'sub'=>$service['client_email'],'aud'=>'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit','iat'=>$now,'exp'=>$now+300,'uid'=>$feed,'claims'=>['ctai_site'=>self::site(),'ctai_feed'=>$feed,'ctai_until'=>$end*1000]]);
        return ['enabled'=>true,'firebase'=>$web,'token'=>$token,'path'=>'ctai/'.self::site().'/'.$feed.'/signal','renew_after'=>min(120,max(1,$end-$now-30)),'lease_seconds'=>$end-$now];
    }
    private static function access(): string {
        $cached=get_transient('ctai_rt_oauth');if(is_string($cached)){try{return Push::decrypt($cached);}catch(\Throwable $e){delete_transient('ctai_rt_oauth');}}
        $s=NativePush::config()['service'];$now=time();
        $assertion=self::jwt(['iss'=>$s['client_email']??'','scope'=>'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]);
        $r=wp_remote_post('https://oauth2.googleapis.com/token',['timeout'=>3,'redirection'=>0,'sslverify'=>true,'sslcertificates'=>getenv('CTAI_CA_BUNDLE')?:ABSPATH.WPINC.'/certificates/ca-bundle.crt','body'=>['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$assertion]]);
        $j=is_wp_error($r)?[]:json_decode(wp_remote_retrieve_body($r),true);
        if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200||!is_string($j['access_token']??null))throw new Fault('Firebase realtime authentication failed. Check the service account and server clock.',502);
        set_transient('ctai_rt_oauth',Push::encrypt($j['access_token']),max(1,min(3000,(int)($j['expires_in']??3600)-60)));return $j['access_token'];
    }
    private static function request(string $method,string $path,?array $data=null,array $query=[]): array {
        $url=self::config()['database_url'].'/'.$path.'.json';if($query)$url=add_query_arg($query,$url);
        $args=['method'=>$method,'timeout'=>3,'redirection'=>0,'sslverify'=>true,'sslcertificates'=>getenv('CTAI_CA_BUNDLE')?:ABSPATH.WPINC.'/certificates/ca-bundle.crt','headers'=>['Authorization'=>'Bearer '.self::access(),'Content-Type'=>'application/json']];if($data!==null)$args['body']=wp_json_encode($data);
        $r=wp_remote_request($url,$args);$code=is_wp_error($r)?0:wp_remote_retrieve_response_code($r);
        if($code!==200){if($code===401)delete_transient('ctai_rt_oauth');throw new Fault('Realtime database request failed. Check the database URL, outbound HTTPS and service-account database permissions.',502);}
        return ['data'=>json_decode(wp_remote_retrieve_body($r),true)];
    }
    public static function test(): array {
        $c=self::config();if(empty($c['database_url']))throw new Fault('Save realtime configuration first.');
        // An unauthenticated root read must fail. Never display its contents.
        $r=wp_remote_get($c['database_url'].'/.json',['timeout'=>3,'redirection'=>0,'sslverify'=>true,'sslcertificates'=>getenv('CTAI_CA_BUNDLE')?:ABSPATH.WPINC.'/certificates/ca-bundle.crt']);
        if(is_wp_error($r)||!in_array(wp_remote_retrieve_response_code($r),[401,403],true))throw new Fault('The database must deny public reads. Publish the supplied rules and retry.',409);
        $feed=self::staff(get_current_user_id());self::request('PATCH','ctai/'.self::site(),[$feed.'/signal'=>['change'=>wp_generate_uuid4(),'at'=>time()*1000],$feed.'/expires'=>(time()+DAY_IN_SECONDS)*1000]);
        return ['writer_accepted'=>true,'public_root_denied'=>true,'message'=>'Database writer test passed. Open the chat and verify its Live status; this test alone does not verify a client listener.'];
    }
    public static function changed(int $conversation,string $type,int $previousAgent=0): void {
        if(!self::enabled())return;
        $payload=['conversation'=>$conversation,'visitor'=>!in_array($type,['note','typing-customer'],true),'previous_agent'=>$previousAgent,'change'=>wp_generate_uuid4()];
        $id=DB::insert('jobs',['dedup_key'=>'rt-'.$payload['change'],'type'=>'realtime','payload'=>wp_json_encode($payload),'available_at'=>DB::now(),'created_at'=>DB::now()]);self::$pending[$id]=DB::table('jobs');
    }
    private static function updates(array $payload): array {
        global $wpdb;$c=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('conversations').' WHERE id=%d',(int)$payload['conversation']),ARRAY_A);if(!$c)return [];
        $result=[];$value=['change'=>$payload['change'],'at'=>time()*1000];
        if(!empty($payload['visitor'])){$s=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('sessions').' WHERE id=%d AND expires_at>%s',$c['session_id'],DB::now()),ARRAY_A);if($s){$feed=self::visitor($s);$result[$feed.'/signal']=$value;$result[$feed.'/expires']=strtotime($s['expires_at'].' UTC')*1000;}}
        foreach(get_users(['capability'=>'ctai_agent','fields'=>'ID'])as$user){
            if(user_can($user,'ctai_supervise')||(int)$c['assigned_agent']===(int)$user||(!empty($payload['previous_agent'])&&(int)$payload['previous_agent']===(int)$user)||(!$c['assigned_agent']&&in_array($c['status'],['waiting','ai_active'],true))){$feed=self::staff((int)$user);$result[$feed.'/signal']=$value;$result[$feed.'/expires']=(time()+DAY_IN_SECONDS)*1000;}
        }
        return $result;
    }
    public static function deliver(array $payload): void {
        if(!self::enabled())return;$data=self::updates($payload);if($data)self::request('PATCH','ctai/'.self::site(),$data);
        update_option('ctai_rt_last_publish',time(),false);update_option('ctai_rt_error','',false);
    }
    public static function flush(): void {
        if(self::$flushing||DB::inTransaction()||!self::$pending)return;
        global $wpdb;self::$flushing=true;$pending=self::$pending;self::$pending=[];
        try{
            $rows=[];$updates=[];$table=DB::table('jobs');
            foreach($pending as$id=>$origin){if($origin!==$table)continue;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND type='realtime' AND locked_until IS NULL",$id),ARRAY_A);if(!$row)continue;
                $locked=$wpdb->query($wpdb->prepare("UPDATE $table SET locked_until=%s WHERE id=%d AND locked_until IS NULL",gmdate('Y-m-d H:i:s',time()+60),$id));if(!$locked)continue;$rows[]=$row;
            }
            if(!$rows)return;
            try{if(self::enabled()){foreach($rows as$row)$updates=array_replace($updates,self::updates(json_decode($row['payload'],true)));if($updates)self::request('PATCH','ctai/'.self::site(),$updates);update_option('ctai_rt_last_publish',time(),false);update_option('ctai_rt_error','',false);}foreach($rows as$row)$wpdb->delete($table,['id'=>$row['id']]);}
            catch(\Throwable $e){foreach($rows as$row)$wpdb->update($table,['locked_until'=>null,'available_at'=>gmdate('Y-m-d H:i:s',time()+60)],['id'=>$row['id']]);update_option('ctai_rt_error','Realtime publish delayed; regular refresh and background retries remain available.',false);}
        }finally{self::$flushing=false;}
    }
    public static function shutdown(): void {if(!self::$pending||DB::inTransaction())return;if(!defined('WP_CLI')||!WP_CLI){if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();elseif(function_exists('litespeed_finish_request'))litespeed_finish_request();}self::flush();}
    public static function cleanup(): void {
        if(!self::enabled())return;
        try{$expired=self::request('GET','ctai/'.self::site(),null,['orderBy'=>wp_json_encode('expires'),'endAt'=>time()*1000,'limitToFirst'=>100])['data'];if(is_array($expired)&&$expired){$remove=[];foreach($expired as$key=>$value)if(preg_match('/^[a-f0-9]{64}$/',(string)$key))$remove[$key]=null;if($remove)self::request('PATCH','ctai/'.self::site(),$remove);}}catch(\Throwable $e){update_option('ctai_rt_error','Realtime signal cleanup will retry during the next maintenance run.',false);}
    }
    public static function rules(): array {return ['rules'=>['.read'=>false,'.write'=>false,'ctai'=>['$site'=>['.indexOn'=>['expires'],'$feed'=>['.read'=>'auth != null && auth.uid === $feed && auth.token.ctai_site === $site && auth.token.ctai_feed === $feed && auth.token.ctai_until > now','.write'=>false]]]]];}
}
