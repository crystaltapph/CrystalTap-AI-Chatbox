"""Configure the local Linux cloud proxy without persisting credential values."""
from pathlib import Path
import json
import os
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]

def prepare():
    if not sys.platform.startswith('linux') or not (os.getenv('HTTPS_PROXY') or os.getenv('HTTP_PROXY')):
        return []
    base = ['docker', 'compose', '-f', str(ROOT / 'compose.yaml')]
    result = subprocess.run(base + ['up', '-d', 'db'], capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('Could not start the local database.')
    database = subprocess.run(base + ['ps', '-q', 'db'], capture_output=True, text=True, check=True).stdout.strip()
    networks = json.loads(subprocess.run(['docker', 'inspect', '--format', '{{json .NetworkSettings.Networks}}', database], capture_output=True, text=True, check=True).stdout)
    address = next(value['IPAddress'] for value in networks.values() if value.get('IPAddress'))
    runtime = ROOT / '.runtime'
    runtime.mkdir(mode=0o700, exist_ok=True)
    runtime.chmod(0o700)
    ports, site = runtime / 'apache-ports.conf', runtime / 'apache-site.conf'
    ports.write_text('Listen 127.0.0.1:8080\n')
    site.write_text('<VirtualHost 127.0.0.1:8080>\nServerName localhost\nDocumentRoot /var/www/html\n<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\nErrorLog /proc/self/fd/2\nCustomLog /proc/self/fd/1 combined\n</VirtualHost>\n')
    environment = '      WORDPRESS_DB_HOST: "' + address + '"\n'
    for name in ['HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY']:
        environment += '      ' + name + ': "${' + name + ':-}"\n'
    environment += "      WORDPRESS_CONFIG_EXTRA: |\n        $$p = parse_url(getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY'));\n        if (!empty($$p['host'])) {\n            define('WP_PROXY_HOST', $$p['host']);\n            define('WP_PROXY_PORT', $$p['port'] ?? 8080);\n            define('WP_PROXY_BYPASS_HOSTS', 'localhost,127.0.0.1');\n            if (!empty($$p['user'])) define('WP_PROXY_USERNAME', rawurldecode($$p['user']));\n            if (!empty($$p['pass'])) define('WP_PROXY_PASSWORD', rawurldecode($$p['pass']));\n        }\n        unset($$p);\n"
    override = runtime / 'compose.cloud.yaml'
    override.write_text('services:\n  wordpress:\n    network_mode: host\n    ports: !reset []\n    environment:\n' + environment + f'    volumes:\n      - {ports}:/etc/apache2/ports.conf:ro\n      - {site}:/etc/apache2/sites-available/000-default.conf:ro\n  cli:\n    network_mode: host\n    environment:\n' + environment)
    for path in [ports, site, override]:
        path.chmod(0o644)
    return ['-f', str(ROOT / 'compose.yaml'), '-f', str(override)]

if __name__ == '__main__':
    os.chdir(ROOT)
    result = subprocess.run(['docker', 'compose', *prepare(), 'up', '-d'], capture_output=True, text=True)
    if result.returncode:
        raise SystemExit('Cloud runtime startup failed. No credential values were printed.')
    print('Local WordPress networking configured; Apache remains bound to loopback.')
