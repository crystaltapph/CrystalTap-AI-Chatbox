"""Real competing PHP/database processes, using disposable plugin tables and users."""
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import os
import secrets
import subprocess

os.chdir(Path(__file__).resolve().parents[1])
prefix='wp_ctai_race_'+secrets.token_hex(4)+'_'
bootstrap='require "/var/www/html/wp-load.php"; add_filter("ctai_table_prefix",static fn()=>getenv("CTAI_TEST_PREFIX")); add_filter("ctai_settings",static fn($s)=>array_replace($s,["ai_enabled"=>false,"daily_budget"=>1,"monthly_budget"=>1])); '
def php(code):
    code=code.replace(chr(92)*2,chr(92))
    r=subprocess.run(['docker','compose','exec','-T','-e','CTAI_TEST_PREFIX='+prefix,'wordpress','php','-r',bootstrap+code],text=True,capture_output=True)
    if r.returncode:raise RuntimeError(r.stderr+r.stdout)
    return json.loads(r.stdout)
fixture=None
try:
    fixture=php(r'''CrystalTap\DB::migrate(); $users=[]; foreach([1,2]as$i){$users[]=wp_insert_user(["user_login"=>"ctai_race_".bin2hex(random_bytes(4)),"user_pass"=>wp_generate_password(32),"role"=>"ctai_agent"]);} $sid=CrystalTap\DB::insert("sessions",["token_hash"=>hash("sha256",random_bytes(32)),"csrf_hash"=>hash("sha256",random_bytes(32)),"expires_at"=>gmdate("Y-m-d H:i:s",time()+3600),"created_at"=>CrystalTap\DB::now()]); $c=CrystalTap\Conversations::start($sid);$c=CrystalTap\Conversations::handoff((int)$c["id"]); echo wp_json_encode(["users"=>$users,"conversation"=>$c]);''')
    c=fixture['conversation']
    def claim(user):return php(f'wp_set_current_user({user});try{{$c=CrystalTap\\Conversations::action({c["id"]},"claim",{c["version"]});echo wp_json_encode(["status"=>200]);}}catch(CrystalTap\\Fault $e){{echo wp_json_encode(["status"=>$e->status]);}}')
    with ThreadPoolExecutor(2)as pool:results=list(pool.map(claim,fixture['users']))
    assert sorted(r['status']for r in results)==[200,409],results
    print('PASS: competing database claims yield one winner and one conflict')
    def reserve(_):return php('try{CrystalTap\\AI::reserve(0.6);echo wp_json_encode(["status"=>200]);}catch(CrystalTap\\Fault $e){echo wp_json_encode(["status"=>$e->status]);}')
    with ThreadPoolExecutor(2)as pool:results=list(pool.map(reserve,range(2)))
    assert sorted(r['status']for r in results)==[200,429],results
    print('PASS: competing budget reservations cannot overspend the configured limit')
    def send(_):return php(f'$r=CrystalTap\\Conversations::customer({c["id"]},"Concurrent duplicate fixture","raceduplicate1234");echo wp_json_encode(["id"=>$r["message"]["id"]]);')
    with ThreadPoolExecutor(2)as pool:results=list(pool.map(send,range(2)))
    assert results[0]['id']==results[1]['id'],results
    print('PASS: competing duplicate sends persist exactly one customer message')
finally:
    if fixture:
        ids=','.join(str(u)for u in fixture['users'])
        php('require_once ABSPATH."wp-admin/includes/user.php";foreach(['+ids+']as$u)wp_delete_user($u);global $wpdb;foreach($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->esc_like(getenv("CTAI_TEST_PREFIX"))."%"))as$t)$wpdb->query("DROP TABLE `".$t."`");echo "{}";')
