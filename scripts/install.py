#!/usr/bin/env python3
"""Install locked development dependencies without disabling TLS verification."""
from pathlib import Path
import os
import subprocess
import sys

root=Path(__file__).resolve().parents[1]
os.chdir(root)
command=['docker','run','--rm','-u',f'{os.getuid()}:{os.getgid()}','-v',str(root)+':/app','-w','/app']
if sys.platform.startswith('linux') and (os.environ.get('HTTPS_PROXY') or os.environ.get('HTTP_PROXY')):
    command+=['--network','host']
    for name in ['HTTPS_PROXY','HTTP_PROXY','NO_PROXY']:
        if os.environ.get(name): command+=['-e',name]
trust=Path('/etc/ssl/certs/ca-certificates.crt')
if trust.is_file():
    command+=['-v',str(trust)+':/trusted-ca.pem:ro','-e','COMPOSER_CAFILE=/trusted-ca.pem','-e','GIT_SSL_CAINFO=/trusted-ca.pem']
command+=['composer:2','install','--no-dev','--prefer-source','--no-interaction','--no-progress']
subprocess.run(command,check=True)
subprocess.run(['npm','ci','--cache','/tmp/ctai-npm-cache','--no-audit','--no-fund'],check=True)
subprocess.run(['npm','run','build:realtime'],check=True)
print('Locked PHP and browser-test dependencies installed.')
