#!/usr/bin/env python3
"""Run isolated Firebase rules tests against the local demo-project emulator."""
from pathlib import Path
import os,subprocess
from android_setup import prepare,ROOT
runtime=ROOT/'.runtime';env,_=prepare()
for key,relative in [('XDG_CONFIG_HOME','firebase-config'),('XDG_CACHE_HOME','firebase-cache'),('FIREBASE_EMULATORS_PATH','firebase-emulators')]:
 path=runtime/relative;path.mkdir(exist_ok=True);env[key]=str(path)
for key in ['GOOGLE_APPLICATION_CREDENTIALS','FIREBASE_TOKEN','GOOGLE_CLOUD_PROJECT','GCLOUD_PROJECT']:env.pop(key,None)
env['GCE_METADATA_HOST']='127.0.0.1:9'
env['FIREBASE_CLI_DISABLE_USAGE_REPORTING']='true'
subprocess.run(['node','node_modules/firebase-tools/lib/bin/firebase.js','emulators:exec','--config','firebase.emulators.json','--project','demo-crystaltap-realtime','--only','database','node --test tests/realtime-rules.cjs'],cwd=ROOT,env=env,check=True)
