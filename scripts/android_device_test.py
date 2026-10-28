#!/usr/bin/env python3
"""Run three instrumented checks only on the dedicated disposable CrystalTap emulator."""
from pathlib import Path
import argparse,os,subprocess
from android_setup import ROOT,SDK,prepare
parser=argparse.ArgumentParser();parser.add_argument('--container',help='Existing disposable emulator container');parser.add_argument('--skip-build',action='store_true');args=parser.parse_args()
env,gradle=prepare()
if not args.skip_build:subprocess.run([str(gradle),'-p',str(ROOT/'android'),'--no-daemon','--max-workers=2',':app:assembleDebug',':app:assembleDebugAndroidTest'],env=env,check=True)
adb=[str(SDK/'platform-tools/adb')]
if args.container:adb=['docker','exec',args.container,'/work/.runtime/android-sdk/platform-tools/adb']
def run(parts,timeout=90,check=True):
    r=subprocess.run(adb+parts,env=env,capture_output=True,text=True,timeout=timeout)
    if check and r.returncode:raise RuntimeError('Emulator command failed: '+r.stderr[-800:]+r.stdout[-800:])
    return r.stdout
serials=[line.split()[0]for line in run(['devices']).splitlines()if line.startswith('emulator-') and '\tdevice' in line]
if len(serials)!=1:raise SystemExit('Exactly one disposable Android emulator is required.')
adb+=['-s',serials[0]]
name=run(['shell','getprop','ro.boot.qemu.avd_name'],timeout=20).strip()
if not name:name=run(['emu','avd','name'],timeout=20).splitlines()[0].strip()
if name!='CrystalTap_Api28':raise SystemExit('Refusing to alter an emulator other than CrystalTap_Api28.')
if run(['shell','getprop','sys.boot_completed'],timeout=20).strip()!='1':raise SystemExit('Disposable emulator has not completed boot.')
run(['uninstall','com.crystaltap.agent.test'],check=False);run(['uninstall','com.crystaltap.agent'],check=False)
for relative in ['android/app/build/outputs/apk/debug/app-debug.apk','android/app/build/outputs/apk/androidTest/debug/app-debug-androidTest.apk']:
    path=('/work/'+relative)if args.container else str(ROOT/relative)
    print(run(['install','-r',path],timeout=180).strip(),flush=True)
run(['shell','input','keyevent','82'],check=False)
output=run(['shell','am','instrument','-w','com.crystaltap.agent.test/androidx.test.runner.AndroidJUnitRunner'],timeout=240)
print(output)
if 'OK (3 tests)' not in output:raise SystemExit('Instrumented Android checks did not all pass.')
print('Three Android device smoke checks passed: native sign-in, encrypted storage, and local notification channel/tone configuration. No physical speaker or Firebase delivery was tested.')
