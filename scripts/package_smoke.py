#!/usr/bin/env python3
"""Install the built ZIP into a disposable WordPress schema, then remove only that schema."""
from pathlib import Path
import os
import secrets
import subprocess

os.chdir(Path(__file__).resolve().parents[1])
artifact=Path('dist/crystaltap-ai-chatbox-0.1.1.zip')
if not artifact.is_file():raise SystemExit('Build the release ZIP first.')
artifact.parent.chmod(0o755)
artifact.chmod(0o644)
env=dict(os.environ,CTAI_ZIP_TEST_PASSWORD=secrets.token_urlsafe(32),CTAI_ZIP_TEST_PREFIX='ctai_zip_'+secrets.token_hex(4)+'_')
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
wp plugin install /var/www/html/wp-content/plugins/crystaltap-ai-chatbox/dist/crystaltap-ai-chatbox-0.1.1.zip --activate
wp plugin is-active crystaltap-ai-chatbox
wp eval 'global $wpdb;$n=count($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->esc_like($wpdb->prefix."crystaltap_ai_")."%")));if($n!==17||!class_exists("Minishlink\\WebPush\\WebPush")){throw new Exception("ZIP dependencies or tables missing");}echo "PACKAGE_SMOKE_OK\n";'
wp eval '$users=get_users(["role"=>"administrator","number"=>1]);wp_set_current_user($users[0]->ID);$request=new WP_REST_Request("POST","/crystaltap-ai/v1/admin/vapid-generate");$response=rest_do_request($request);$data=$response->get_data();$private=CrystalTap\Settings::secret("vapid_private");$stored=get_option("ctai_secret_store",[]);if($response->get_status()!==200||empty($data["generated"])||strlen($private)<40||strlen($data["public_key"]??"")<80||($stored["vapid_private"]??"")===$private||str_contains(wp_json_encode($data),$private)){throw new Exception("Packaged Web Push key generation or private-key protection failed");}echo "VAPID_SMOKE_OK\n";'
'''
r=subprocess.run(['docker','compose','run','--rm','-T','-e','CTAI_ZIP_TEST_PASSWORD','-e','CTAI_ZIP_TEST_PREFIX','cli','sh','-c',script],env=env,text=True,capture_output=True)
if r.returncode or 'PACKAGE_SMOKE_OK' not in r.stdout or 'VAPID_SMOKE_OK' not in r.stdout:
    # Credentials are intentionally never included in diagnostic output.
    safe=(r.stdout+r.stderr).replace(env['CTAI_ZIP_TEST_PASSWORD'],'[redacted]')
    raise SystemExit('Package installation test failed:\n'+safe[-5000:])
print('PASS: built ZIP installs and activates; 17 tables, bundled push library, actual VAPID generation and encrypted private-key storage verified.')
