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
parser.add_argument('--artifact',default='dist/crystaltap-ai-chatbox-0.1.4.zip')
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
wp eval 'global $wpdb;$n=count($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->esc_like($wpdb->prefix."crystaltap_ai_")."%")));if($n!==17||!class_exists("Minishlink\\WebPush\\WebPush")){throw new Exception("ZIP dependencies or tables missing");}echo "PACKAGE_SMOKE_OK\n";'
wp eval '$users=get_users(["role"=>"administrator","number"=>1]);wp_set_current_user($users[0]->ID);$request=new WP_REST_Request("POST","/crystaltap-ai/v1/admin/vapid-generate");$response=rest_do_request($request);$data=$response->get_data();$private=CrystalTap\Settings::secret("vapid_private");$stored=get_option("ctai_secret_store",[]);if($response->get_status()!==200||empty($data["generated"])||strlen($private)<40||strlen($data["public_key"]??"")<80||($stored["vapid_private"]??"")===$private||str_contains(wp_json_encode($data),$private)){throw new Exception("Packaged Web Push key generation or private-key protection failed");}echo "VAPID_SMOKE_OK\n";'
wp eval '$users=get_users(["role"=>"administrator","number"=>1]);wp_set_current_user($users[0]->ID);$request=new WP_REST_Request("PUT","/crystaltap-ai/v1/admin/settings");$request->set_header("Content-Type","application/json");$request->set_body(wp_json_encode(["whatsapp_number"=>"+63 912 345 6789","messenger_url"=>"https://m.me/CrystalTapFixture","viber_number"=>"+63 912 345 6789"]));$response=rest_do_request($request);$links=CrystalTap\Settings::publicConfig()["channels"]??[];if($response->get_status()!==200||count($links)!==3||$links[0]["url"]!=="https://wa.me/639123456789"||$links[2]["url"]!=="viber://chat?number=%2B639123456789"){throw new Exception("Packaged contact configuration failed");}echo "CHANNEL_SMOKE_OK\n";'
'''
r=subprocess.run(['docker','compose','run','--rm','-T','-e','CTAI_ZIP_TEST_PASSWORD','-e','CTAI_ZIP_TEST_PREFIX','-e','CTAI_TEST_ARTIFACT','-e','CTAI_TEST_PLUGIN_SLUG','cli','sh','-c',script],env=env,text=True,capture_output=True)
if r.returncode or 'PACKAGE_SMOKE_OK' not in r.stdout or 'VAPID_SMOKE_OK' not in r.stdout or 'CHANNEL_SMOKE_OK' not in r.stdout:
    # Credentials are intentionally never included in diagnostic output.
    safe=(r.stdout+r.stderr).replace(env['CTAI_ZIP_TEST_PASSWORD'],'[redacted]')
    raise SystemExit('Package installation test failed:\n'+safe[-5000:])
print('PASS: built ZIP installs and activates; 17 tables, bundled push library, actual VAPID generation, encrypted private-key storage and public contact configuration verified.')
