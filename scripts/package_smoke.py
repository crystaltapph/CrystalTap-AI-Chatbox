#!/usr/bin/env python3
"""Install the built ZIP into a disposable WordPress schema, then remove only that schema."""
from pathlib import Path
import os
import secrets
import subprocess
import argparse
import zipfile

os.chdir(Path(__file__).resolve().parents[1])
parser=argparse.ArgumentParser()
parser.add_argument('--artifact',default='dist/crystaltap-ai-chatbox-0.2.1.zip')
artifact=Path(parser.parse_args().artifact).resolve()
root=Path.cwd()
relative=artifact.relative_to(root)
with zipfile.ZipFile(artifact)as archive:
    entry=next(n for n in archive.namelist() if n.endswith('/crystaltap-ai-chatbox.php'))
    slug=entry.split('/')[0]
if not artifact.is_file():raise SystemExit('Build the release ZIP first.')
artifact.parent.chmod(0o755)
artifact.chmod(0o644)
env=dict(os.environ,CTAI_ZIP_TEST_PASSWORD=secrets.token_urlsafe(32),CTAI_ZIP_TEST_PREFIX='ctai_zip_'+secrets.token_hex(4)+'_',CTAI_TEST_ARTIFACT='/var/www/html/wp-content/plugins/crystaltap-ai-chatbox/'+str(relative),CTAI_TEST_PLUGIN_SLUG=slug)
script=r'''
set -eu
mkdir -p /tmp/ctai-zip-site/wp-content/plugins
cp -R /var/www/html/wp-admin /var/www/html/wp-includes /tmp/ctai-zip-site/
cp /var/www/html/*.php /tmp/ctai-zip-site/
rm /tmp/ctai-zip-site/wp-config.php
cd /tmp/ctai-zip-site
wp config create --dbname=wordpress --dbuser=wordpress --dbpass=local-development-only --dbhost=db --dbprefix="$CTAI_ZIP_TEST_PREFIX" --skip-check
wp core install --url=http://localhost:8081 --title='Disposable ZIP test' --admin_user=zip_fixture --admin_password="$CTAI_ZIP_TEST_PASSWORD" --admin_email=fixture@example.invalid --skip-email
cleanup() {
  wp eval 'global $wpdb;$p=getenv("CTAI_ZIP_TEST_PREFIX");foreach($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->esc_like($p)."%"))as$t){$wpdb->query("DROP TABLE `".$t."`");}'
}
trap cleanup EXIT
wp plugin install "$CTAI_TEST_ARTIFACT" --activate
wp plugin is-active "$CTAI_TEST_PLUGIN_SLUG"
wp eval 'global $wpdb;$n=count($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->esc_like($wpdb->prefix."crystaltap_ai_")."%")));if($n!==20||!class_exists("CrystalTap\\Native")){throw new Exception("ZIP dependencies or tables missing");}echo "PACKAGE_SMOKE_OK\n";'
wp eval '$users=get_users(["role"=>"administrator","number"=>1]);wp_set_current_user($users[0]->ID);$verifier=bin2hex(random_bytes(32));$pair=CrystalTap\Native::begin(hash("sha256",$verifier));CrystalTap\Native::approve($pair["id"],$pair["code"]);$login=CrystalTap\Native::complete($pair["id"],$verifier);$device=CrystalTap\Native::authenticateToken($login["token"]);if(get_current_user_id()!==$users[0]->ID||strlen($login["token"])!==64){throw new Exception("Packaged Android sign-in failed");}CrystalTap\Native::revoke((int)$device["id"],$users[0]->ID);echo "NATIVE_PAIR_SMOKE_OK\n";'
wp eval '$users=get_users(["role"=>"administrator","number"=>1]);wp_set_current_user($users[0]->ID);$request=new WP_REST_Request("PUT","/crystaltap-ai/v1/admin/settings");$request->set_header("Content-Type","application/json");$request->set_body(wp_json_encode(["whatsapp_number"=>"+63 912 345 6789","messenger_url"=>"https://m.me/CrystalTapFixture","viber_number"=>"+63 912 345 6789"]));$response=rest_do_request($request);$links=CrystalTap\Settings::publicConfig()["channels"]??[];if($response->get_status()!==200||count($links)!==3||$links[0]["url"]!=="https://wa.me/639123456789"||$links[2]["url"]!=="viber://chat?number=%2B639123456789"){throw new Exception("Packaged contact configuration failed");}echo "CHANNEL_SMOKE_OK\n";'
wp eval '$path=tempnam(sys_get_temp_dir(),"ctai-image");try{$pixel=imagecreatetruecolor(4,4);imagepng($pixel,$path);imagedestroy($pixel);$image=CrystalTap\Images::clean($path,"fixture.png");if($image["mime"]!=="image/png"||!is_readable(CTAI_DIR."assets/chat-ui.js")){throw new Exception("Packaged image processing or shared controls unavailable");}}finally{unlink($path);}echo "CHAT_FEATURES_SMOKE_OK\n";'
'''
r=subprocess.run(['docker','compose','run','--rm','-T','-e','CTAI_ZIP_TEST_PASSWORD','-e','CTAI_ZIP_TEST_PREFIX','-e','CTAI_TEST_ARTIFACT','-e','CTAI_TEST_PLUGIN_SLUG','cli','sh','-c',script],env=env,text=True,capture_output=True)
if r.returncode or 'PACKAGE_SMOKE_OK' not in r.stdout or 'NATIVE_PAIR_SMOKE_OK' not in r.stdout or 'CHANNEL_SMOKE_OK' not in r.stdout or 'CHAT_FEATURES_SMOKE_OK' not in r.stdout:
    # Credentials are intentionally never included in diagnostic output.
    safe=(r.stdout+r.stderr).replace(env['CTAI_ZIP_TEST_PASSWORD'],'[redacted]')
    raise SystemExit('Package installation test failed:\n'+safe[-5000:])
print('PASS: built ZIP installs and activates; 20 tables, native device pairing and public contacts and image processing verified.')
