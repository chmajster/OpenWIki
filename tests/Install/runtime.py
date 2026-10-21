#!/usr/bin/env python3
"""Extract the distributable into a real Apache document root and install via UI."""
import glob
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import zipfile

PROJECT = Path(__file__).resolve().parents[2]
WINDOWS = os.name == 'nt'
PHP = shutil.which('php')
APACHE = os.getenv('OPENWIKI_APACHE_BINARY', 'C:/xampp/apache/bin/httpd.exe' if WINDOWS else '/usr/sbin/apache2')
MODULES = Path(os.getenv('OPENWIKI_APACHE_MODULES', 'C:/xampp/apache/modules' if WINDOWS else '/usr/lib/apache2/modules'))
PACKAGE = Path(os.getenv('OPENWIKI_SERVER_PACKAGE', PROJECT / 'storage/temp/openwiki.zip'))
NAME = 'openwiki_web_' + os.urandom(6).hex() + '_test'
ENV = dict(os.environ, OPENWIKI_INSTALL_DB=NAME)


def run(arguments, **kwargs):
    subprocess.run(arguments, cwd=PROJECT, env=ENV, check=True, **kwargs)


def fetch(url):
    try:
        with urllib.request.urlopen(url, timeout=4) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as response:
        return response.code, response.read()


def main():
    if not os.getenv('OPENWIKI_TEST_DB_HOST'):
        raise RuntimeError('Configure a disposable OPENWIKI_TEST_DB_* server.')
    expected = PACKAGE.with_name(PACKAGE.name + '.sha256').read_text().split()[0]
    assert hashlib.sha256(PACKAGE.read_bytes()).hexdigest() == expected
    run([PHP, 'tests/Install/database.php', 'create', NAME])
    try:
        with tempfile.TemporaryDirectory(prefix='openwiki web ') as temporary:
            work = Path(temporary)
            root = work / 'server root'
            with zipfile.ZipFile(PACKAGE) as archive:
                archive.extractall(root)
            assert (root / '.htaccess').is_file()
            assert (root / 'vendor/autoload.php').is_file()
            assert not (root / 'node_modules').exists()
            assert not (root / '.env').exists()
            assert not (root / 'storage/installed.lock').exists()
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            url = 'http://127.0.0.1:' + str(port)
            lines = [f'ServerRoot "{MODULES.parent.as_posix()}"', f'Listen 127.0.0.1:{port}',
                     f'ServerName 127.0.0.1:{port}', f'PidFile "{work.as_posix()}/apache.pid"',
                     f'ErrorLog "{work.as_posix()}/apache-error.log"', 'LogLevel warn']
            if not WINDOWS:
                lines.append(f'LoadModule mpm_prefork_module "{MODULES.as_posix()}/mod_mpm_prefork.so"')
            for module in ['authz_core', 'authz_host', 'dir', 'mime', 'rewrite', 'headers', 'env']:
                lines.append(f'LoadModule {module}_module "{MODULES.as_posix()}/mod_{module}.so"')
            if WINDOWS:
                for library in ['php8ts.dll', 'libpq.dll', 'libsqlite3.dll']:
                    lines.append(f'LoadFile "C:/xampp/php/{library}"')
                php_module = os.getenv('OPENWIKI_APACHE_PHP_MODULE', 'C:/xampp/php/php8apache2_4.dll')
                php_ini = os.getenv('PHPRC', 'C:/xampp/php')
            else:
                php_module = os.getenv('OPENWIKI_APACHE_PHP_MODULE', glob.glob(str(MODULES / 'libphp*.so'))[0])
                version = Path(php_module).stem.removeprefix('libphp')
                php_ini = os.getenv('PHPRC', '/etc/php/' + version + '/apache2')
            lines += [f'LoadModule php_module "{Path(php_module).as_posix()}"', f'PHPINIDir "{Path(php_ini).as_posix()}"',
                      'AddType application/javascript .js', 'AddType text/css .css', 'AddType text/html .html',
                      '<FilesMatch "\\.php$">', 'SetHandler application/x-httpd-php', '</FilesMatch>',
                      '<Directory />', 'AllowOverride None', 'Require all denied', '</Directory>',
                      f'DocumentRoot "{root.as_posix()}"', f'<Directory "{root.as_posix()}">',
                      'Options FollowSymLinks', 'AllowOverride All', 'Require all granted', '</Directory>']
            config = work / 'httpd.conf'
            config.write_text('\n'.join(lines) + '\n')
            run([APACHE, '-t', '-f', str(config)])
            process = None
            def start():
                return subprocess.Popen([APACHE, '-f', str(config), '-DFOREGROUND'], cwd=work,
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                    creationflags=subprocess.CREATE_NO_WINDOW if WINDOWS else 0, env=ENV)
            def ready():
                for _ in range(100):
                    if process.poll() is not None:
                        raise RuntimeError((work / 'apache-error.log').read_text())
                    try:
                        if fetch(url + '/health/live')[0] == 200:
                            return
                    except OSError:
                        pass
                    time.sleep(.1)
                raise RuntimeError('Apache did not become ready.')
            try:
                process = start()
                ready()
                # Probe real files; denying an absent path alone proves nothing.
                (root / '.openwiki').mkdir()
                (root / '.openwiki/compose.env').write_text('PRIVATE_CONFIGURATION')
                (root / '.env.tmp-sensitive').write_text('PRIVATE_CONFIGURATION')
                for private in ['/.env.tmp-sensitive', '/.openwiki/compose.env', '/vendor/autoload.php', '/database/migrations/001_initial.php',
                                '/scripts/package-zip.php', '/composer.lock', '/install.sh', '/public/index.php', '/storage/cache/.gitkeep']:
                    status, body = fetch(url + private)
                    assert status in (403, 404) and b'PRIVATE_CONFIGURATION' not in body, private
                # Remove a probe directory that would otherwise signal a managed upgrade.
                shutil.rmtree(root / '.openwiki')
                for asset in ['app.css', 'app.js', 'wiki-editor.js', 'diagram.js', 'document-reader.js']:
                    assert fetch(url + '/assets/' + asset)[0] == 200, asset
                run(['node', 'tests/Install/browser.cjs', url], timeout=240)
                before = (root / '.env').read_bytes()
                marker = (root / 'storage/installed.lock').read_bytes()
                process.terminate()
                process.wait(timeout=15)
                process = start()
                ready()
                assert fetch(url + '/health')[0] == 200
                assert (root / '.env').read_bytes() == before
                assert (root / 'storage/installed.lock').read_bytes() == marker
                (root / '.env').rename(root / '.env.saved')
                try:
                    status, body = fetch(url + '/install')
                    assert status == 503, 'Lost configuration must never reopen installation.'
                finally:
                    (root / '.env.saved').rename(root / '.env')
                print('PASS: extracted ZIP, Apache repository-root rewrites, private paths, static bundles, restart, configuration/key/marker persistence and lost-configuration protection.')
            finally:
                if process is not None and process.poll() is None:
                    process.terminate()
                    process.wait(timeout=15)
                log = work / 'apache-error.log'
                if log.exists():
                    shutil.copyfile(log, PROJECT / 'storage/temp/install-apache.log')
    finally:
        run([PHP, 'tests/Install/database.php', 'drop', NAME])


if __name__ == '__main__':
    main()
