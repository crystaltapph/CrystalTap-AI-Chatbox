#!/usr/bin/env python3
"""Build an installable ZIP with bundled dependencies and no development credentials."""
from pathlib import Path
import hashlib
import json
import zipfile

root=Path(__file__).resolve().parents[1]
if not (root/'vendor/autoload.php').is_file():
    raise SystemExit('Install locked Composer production dependencies before packaging.')
out=root/'dist'
out.mkdir(exist_ok=True)
target=out/'crystaltap-ai-chatbox-0.1.2.zip'
files=[root/p for p in ['crystaltap-ai-chatbox.php','uninstall.php','readme.txt','LICENSE','.htaccess']]
for directory in ['includes','assets','mobile','vendor']:
    for path in (root/directory).rglob('*'):
        relative=path.relative_to(root)
        if path.is_file() and not any(part.startswith('.') or part.lower() in ['tests','test','node_modules'] for part in relative.parts):
            files.append(path)
with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(files):
        relative=path.relative_to(root)
        info=zipfile.ZipInfo('crystaltap-ai-chatbox/'+str(relative))
        info.external_attr=0o100644<<16
        info.compress_type=zipfile.ZIP_DEFLATED
        archive.writestr(info,path.read_bytes())
checksum=hashlib.sha256(target.read_bytes()).hexdigest()
(out/'SHA256SUMS').write_text(checksum+'  '+target.name+'\n')
with zipfile.ZipFile(target) as archive:
    assert archive.testzip() is None
    assert not any('/.git/' in n or '/.runtime/' in n or n.endswith('/.env') for n in archive.namelist())
print(f'Built {target.name}: {target.stat().st_size:,} bytes; SHA-256 {checksum}')
