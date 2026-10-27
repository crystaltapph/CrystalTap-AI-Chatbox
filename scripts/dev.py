#!/usr/bin/env python3
"""Prepare the loopback-only development site. Never use this script on production."""
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
from cloud_runtime import prepare

ROOT = Path(__file__).resolve().parents[1]
os.chdir(ROOT)
compose_args = prepare()

def run(args, *, env=None, check=True):
    result = subprocess.run(args, text=True, capture_output=True, env=env)
    if check and result.returncode:
        # Commands below never print credentials, but suppress output on credential operations anyway.
        raise RuntimeError('Command failed: ' + ' '.join(args[:5]) + '\n' + result.stderr[-3000:])
    return result

def wp(*args, env=None, check=True):
    extra = ['-e', 'CTAI_DEV_PASSWORD'] if env else []
    return run(['docker','compose',*compose_args,'run','--rm',*extra,'cli','wp',*args],env=env,check=check)

ROOT.chmod(0o755)
for directory in ['assets','includes','mobile','scripts','tests','docs']:
    for path in [ROOT/directory, *(ROOT/directory).rglob('*')]:
        if path.exists(): path.chmod(0o755 if path.is_dir() else 0o644)
for name in ['crystaltap-ai-chatbox.php','composer.json','composer.lock','compose.yaml']:
    (ROOT/name).chmod(0o644)
runtime=ROOT/'.runtime'
runtime.mkdir(exist_ok=True,mode=0o700)
runtime.chmod(0o700)
credentials=runtime/'dev-login.json'
if credentials.exists():
    login=json.loads(credentials.read_text())
else:
    login={'username':'crystaltap_dev','password':secrets.token_urlsafe(32)}
    credentials.write_text(json.dumps(login))
    credentials.chmod(0o600)
run(['docker','compose',*compose_args,'up','-d'])
if wp('core','is-installed',check=False).returncode:
    # WP-CLI is given a generated development password; captured output is never printed.
    wp('core','install','--url=http://localhost:8080','--title=CrystalTap Development','--admin_user='+login['username'],'--admin_password='+login['password'],'--admin_email=dev@example.invalid','--skip-email')
if wp('option','get','home').stdout.strip() != 'http://localhost:8080':
    raise RuntimeError('Refusing to change accounts on a site outside the local development URL.')
environment=dict(os.environ,CTAI_DEV_PASSWORD=login['password'])
wp('eval', '$u=get_user_by("login","crystaltap_dev"); if(!$u){throw new Exception("Development administrator missing");} wp_set_password(getenv("CTAI_DEV_PASSWORD"),$u->ID);',env=environment)
wp('rewrite','structure','/%postname%/')
wp('plugin','activate','crystaltap-ai-chatbox')
wp('eval', '$p=get_page_by_path("chat-demo"); if(!$p)wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_title"=>"Chat demo","post_name"=>"chat-demo","post_content"=>"[crystaltap_ai_chat]"]);')
print('Local WordPress is running. Plugin activated; chat demo page created.')
print('Development sign-in is stored in .runtime/dev-login.json (private; never commit it).')
