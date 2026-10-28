#!/usr/bin/env python3
"""Build a signed candidate APK. Keep its local signing identity private and backed up."""
from pathlib import Path
import argparse,hashlib,json,os,secrets,shutil,subprocess
from android_setup import ROOT,prepare
parser=argparse.ArgumentParser();parser.add_argument('--test',action='store_true');args=parser.parse_args()
env,gradle=prepare()
private=ROOT/'.runtime/android-signing.json';keystore=ROOT/'.runtime/android-signing.jks'
if private.exists()!=keystore.exists():raise SystemExit('Incomplete signing identity. Restore the matching private keystore and password before building updates.')
if not private.exists():
    private.write_text(json.dumps({'password':secrets.token_urlsafe(32)}));private.chmod(0o600)
password=json.loads(private.read_text())['password'];env['CTAI_ANDROID_STORE_FILE']=str(keystore);env['CTAI_ANDROID_STORE_PASSWORD']=password
if not keystore.exists():
    result=subprocess.run([env['JAVA_HOME']+'/bin/keytool','-genkeypair','-keystore',str(keystore),'-storepass:env','CTAI_ANDROID_STORE_PASSWORD','-keypass:env','CTAI_ANDROID_STORE_PASSWORD','-alias','crystaltap','-keyalg','RSA','-keysize','3072','-validity','10000','-dname','CN=CrystalTap Android Candidate'],env=env,capture_output=True,text=True)
    if result.returncode:raise SystemExit('Could not create private Android signing identity.')
    keystore.chmod(0o600)
tasks=[':app:assembleRelease']
if args.test:tasks+=[':app:testDebugUnitTest',':app:lintDebug']
subprocess.run([str(gradle),'-p',str(ROOT/'android'),'--no-daemon','--max-workers=2',*tasks],env=env,check=True)
out=ROOT/'dist';out.mkdir(exist_ok=True);target=out/'CrystalTap-Agent-0.1.0.apk';shutil.copyfile(ROOT/'android/app/build/outputs/apk/release/app-release.apk',target);target.chmod(0o644)
verify=subprocess.run([str(ROOT/'.runtime/android-sdk/build-tools/35.0.0/apksigner'),'verify','--verbose',str(target)],env=env,capture_output=True,text=True)
if verify.returncode:raise SystemExit('APK signature verification failed.')
digest=hashlib.sha256(target.read_bytes()).hexdigest();(out/'ANDROID-SHA256SUMS').write_text(digest+'  '+target.name+'\n')
print('Signed candidate APK verified: '+target.name+'; SHA-256 '+digest)
print('Retain .runtime/android-signing.jks and android-signing.json privately for future updates. Never commit them.')
