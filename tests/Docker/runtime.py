#!/usr/bin/env python3
"""Isolated real Compose -> FPM -> wizard/forms -> database/files -> lifecycle test."""
import hashlib
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

PROJECT = Path(__file__).resolve().parents[2]
BASE_PORT = int(os.getenv('OPENWIKI_DOCKER_TEST_PORT', '18390'))
CHECKS = 0


def check(condition, message):
    global CHECKS
    if not condition:
        raise AssertionError(message)
    CHECKS += 1
    print('[ OK ] ' + message, flush=True)


def http(client, url, data=None, headers=None):
    headers = headers or {}
    if isinstance(data, dict):
        data = urllib.parse.urlencode(data, doseq=True).encode()
    request = urllib.request.Request(url, data=data, headers=headers)
    try:
        response = client.open(request, timeout=120)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read(), response.headers


def client():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(CookieJar()))


def csrf(body):
    match = re.search(rb'name="_token"\s+value="([^"]+)"', body)
    check(match is not None, 'Real form exposes its CSRF token')
    return match.group(1).decode()


class Instance:
    def __init__(self, root, elsewhere, path):
        self.root, self.elsewhere = root, elsewhere
        self.environment = dict(os.environ, PATH=str(path), NO_COLOR='1', OPENWIKI_WORKER_INTERVAL='2')
        self.log = (root.parent / 'runtime.log').open('w')
        self.project = 'openwiki-' + hashlib.sha256(str(root).encode()).hexdigest()[:20]

    def command(self, args, success=True, timeout=900):
        result = subprocess.run(args, cwd=self.elsewhere, env=self.environment, text=True,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)
        # Compose config contains generated passwords; inspect it in memory only.
        self.log.write('[INFO] Compose configuration inspected privately.\n' if 'config' in args and '--format' in args else result.stdout)
        self.log.flush()
        if success and result.returncode != 0:
            # Preserve diagnostics privately; do not dump environment or credentials into CI.
            destination = PROJECT / 'storage/temp/docker-runtime-last.log'
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(self.log.name, destination)
            failures = [line for line in result.stdout.splitlines() if '[FAIL]' in line or 'Error' in line]
            raise AssertionError('Runtime command failed: ' + '\n'.join(failures[-5:]))
        return result

    def cli(self, *args, success=True):
        return self.command([shutil.which('bash'), str(self.root / 'install.sh'), '--docker', *args], success)

    def compose(self, *args, success=True):
        settings = self.root / '.openwiki/docker/compose.env'
        if not settings.exists():
            return None
        values = dict(line.split('=', 1) for line in settings.read_text().splitlines() if '=' in line)
        values.update(OPENWIKI_PROJECT_DIR=str(self.root), OPENWIKI_RUNTIME_DIR=str(self.root / '.openwiki/docker/runtime'),
            OPENWIKI_URL='http://127.0.0.1:' + values['OPENWIKI_PORT'], OPENWIKI_COOKIE='OPENWIKI_DOCKER_' + values['OPENWIKI_STATE_ID'][:20])
        previous = self.environment
        self.environment = dict(previous, **values)
        try:
            return self.command(['docker', 'compose', '--project-name', self.project, '--project-directory', str(self.root),
                '--env-file', str(settings), '-f', str(self.root / 'docker/compose.yml'), *args], success)
        finally:
            self.environment = previous

    def php(self, code):
        return self.compose('exec', '-T', 'php', 'php', '-r', code).stdout.strip()

    def cleanup(self):
        if (self.root / '.openwiki/docker/compose.env').exists():
            self.compose('down', '--volumes', '--remove-orphans', success=False)
        self.command(['docker', 'image', 'rm', self.project + '-php:local'], success=False)
        self.log.close()


def copy_project(root):
    root.mkdir(parents=True)
    files = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=PROJECT).decode().split('\0')
    for name in filter(None, files):
        source = PROJECT / name
        if source.is_file():
            destination = root / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, destination)


def run():
    check(os.name == 'posix' and Path('/proc/self').exists(), 'Linux/WSL test host')
    for port in [BASE_PORT, BASE_PORT + 1, BASE_PORT + 2]:
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', port))
    parent = PROJECT / 'storage/temp' if '--workspace-temp' in os.sys.argv else None
    instances = []
    with tempfile.TemporaryDirectory(prefix='openwiki docker ', dir=parent) as temporary:
        temporary = Path(temporary)
        tools = temporary / 'host tools'; tools.mkdir()
        # The installer receives core OS tools and Docker, with no PHP/Composer/Node/npm.
        for name in ['bash', 'docker', 'dirname', 'sha256sum', 'id', 'od', 'tr', 'mkdir', 'chmod', 'mv', 'flock', 'ss', 'sleep', 'tail']:
            binary = shutil.which(name)
            if binary:
                (tools / name).symlink_to(binary)
        check(not any((tools / name).exists() for name in ['php', 'composer', 'node', 'npm']), 'No application toolchain on installer PATH')
        elsewhere = temporary / 'another directory'; elsewhere.mkdir()
        root = temporary / 'first/Open Wiki'; copy_project(root)
        local_env = b'APP_KEY="local-instance-must-remain-unchanged"\nDB_DATABASE="local_database"\n'
        (root / '.env').write_bytes(local_env)
        (root / 'storage/installed.lock').write_bytes(b'{"local":"preserved"}')
        (root / 'storage/attachments/local.txt').write_bytes(b'Local attachment unchanged')
        for name in ['vendor', 'node_modules']:
            (root / name).mkdir()
            (root / name / 'host-native.txt').write_bytes(b'Host dependency remains unchanged')
        instance = Instance(root, elsewhere, tools); instances.append(instance)
        try:
            print('Docker test: first preparation and real wizard', flush=True)
            result = instance.cli('--port', str(BASE_PORT))
            url = 'http://127.0.0.1:' + str(BASE_PORT)
            check('stan: setup' in result.stdout and str(root) in result.stdout and url in result.stdout, 'Fresh start from another directory with spaces reaches setup')
            check((root / '.env').read_bytes() == local_env and (root / 'storage/installed.lock').read_bytes() == b'{"local":"preserved"}', 'Local key and installation marker remain isolated')
            check(all((root / name / 'host-native.txt').read_bytes() == b'Host dependency remains unchanged' for name in ['vendor', 'node_modules'])
                and not (root / 'vendor/autoload.php').exists() and not (root / 'node_modules/esbuild').exists(), 'Container dependencies do not replace host dependency directories')
            instance.compose('config', '--quiet')
            config = json.loads(instance.compose('config', '--format', 'json').stdout)
            mounts = config['services']['php']['volumes']
            check(any(m['type'] == 'bind' and m['source'] == str(root) and m['target'] == '/app' and not m.get('read_only', False) for m in mounts), 'PHP uses the exact RW source directory')
            check(not config['services']['db'].get('ports'), 'Database has no published host port')
            browser = client()
            status, body, _ = http(browser, url + '/health')
            check(status == 503 and json.loads(body)['status'] == 'setup_required', 'Unconfigured application distinguishes setup from failure')
            if os.getenv('OPENWIKI_DOCKER_BROWSER_TEST') == '1':
                subprocess.run(['node', str(PROJECT / 'tests/Docker/wizard-browser.cjs'), url], cwd=PROJECT, check=True)
            status, body, _ = http(browser, url + '/install')
            check(status == 200 and b'PHP' in body and b'value="db"' in body, 'Existing wizard has prepared Docker database defaults')
            (root / '.openwiki/docker/runtime/.env').write_text('APP_NAME="Preinstallation configuration"\n')
            secret = dict(line.split('=', 1) for line in (root / '.openwiki/docker/compose.env').read_text().splitlines())['OPENWIKI_DB_PASSWORD']
            check(secret.encode() not in body, 'Wizard does not disclose the generated database password')
            status, payload, _ = http(browser, url + '/install/test-database', {'_token': csrf(body), 'db_host': 'untrusted.invalid', 'db_password': 'ignored'})
            check(status == 200 and json.loads(payload)['data']['ok'], 'Database test uses server-managed credentials')
            password = secrets.token_hex(16)
            status, body, _ = http(browser, url + '/install', {'_token': csrf(body), 'app_name': 'Docker runtime verification',
                'app_url': url, 'timezone': 'UTC', 'db_host': 'untrusted.invalid', 'create_database': '1',
                'admin_username': 'docker-tester', 'admin_email': 'docker@example.invalid', 'admin_password': password})
            check(status == 200 and b'name="identifier"' in body, 'Real wizard creates an administrator and redirects to login')
            status, body, _ = http(browser, url + '/login', {'_token': csrf(body), 'identifier': 'docker-tester', 'password': password})
            check(status == 200 and b'name="identifier"' not in body, 'New administrator logs in through the real form')
            status, body, _ = http(browser, url + '/spaces/create')
            status, body, _ = http(browser, url + '/spaces', {'_token': csrf(body), 'space_key': 'RUNTIME', 'name': 'Runtime docs', 'visibility': 'private'})
            check(status == 200 and b'Runtime docs' in body, 'Real space form writes to the container database')
            status, body, _ = http(browser, url + '/spaces/RUNTIME/pages/create')
            status, body, _ = http(browser, url + '/spaces/RUNTIME/pages', {'_token': csrf(body), 'base_version': '0', 'title': 'Runtime page', 'slug': 'runtime-page',
                'content_format': 'visual', 'content_html': '<h2>Stored in Docker</h2><p>Preserved runtime document</p>', 'status': 'published'})
            check(status == 200 and b'Preserved runtime document' in body, 'Real editor form writes and reopens a page')
            boundary = 'OpenWikiRuntime' + secrets.token_hex(8)
            upload = ('--' + boundary + '\r\nContent-Disposition: form-data; name="_token"\r\n\r\n' + csrf(body)
                + '\r\n--' + boundary + '\r\nContent-Disposition: form-data; name="attachment"; filename="runtime.txt"\r\nContent-Type: text/plain\r\n\r\nPreserved Docker attachment\r\n--' + boundary + '--\r\n').encode()
            status, body, _ = http(browser, url + '/spaces/RUNTIME/pages/runtime-page/attachments', upload, {'Content-Type': 'multipart/form-data; boundary=' + boundary})
            attachment = re.search(rb'/attachments/(\d+)/download', body)
            check(status == 200 and attachment is not None, 'Real multipart form uploads a protected attachment')
            attachment_path = '/attachments/' + attachment.group(1).decode() + '/download'
            check(http(browser, url + attachment_path)[1] == b'Preserved Docker attachment', 'Attachment bytes can be read after upload')
            check(http(client(), url + attachment_path)[1] != b'Preserved Docker attachment', 'Anonymous requests cannot read the private attachment')
            status, body, _ = http(browser, url + '/account/api-tokens')
            status, body, _ = http(browser, url + '/account/api-tokens', {'_token': csrf(body), 'name': 'Runtime verification', 'scopes[]': ['pages:read', 'attachments:read']})
            token = re.search(rb'owk_[a-f0-9]{64}', body)
            check(status == 200 and token is not None, 'Real account form creates an API token')
            headers = {'Authorization': 'Bearer ' + token.group().decode()}
            check(http(client(), url + '/api/v1/pages', headers=headers)[0] == 200, 'API uses the same mounted application and installed data')
            saved_env = (root / '.openwiki/docker/runtime/.env').read_bytes()
            saved_lock = (root / '.openwiki/docker/runtime/storage/installed.lock').read_bytes()
            saved_compose = (root / '.openwiki/docker/compose.env').read_bytes()
            check(b'APP_KEY=' in saved_env, 'Wizard atomically persists the Docker application key')
            deadline = time.monotonic() + 30
            while time.monotonic() < deadline:
            cron = instance.php('require "/app/bootstrap/autoload.php"; $a=OpenWiki\\Core\\Application::boot("/app",false); echo $a->database()->fetchOne("SELECT setting_value FROM settings WHERE setting_key = \'system.cron_last_run\'")["setting_value"] ?? "";')
                if cron:
                    break
                time.sleep(1)
            check(bool(cron), 'Separate worker executes actual cron:run after installation')
            for path in ['/.env', '/storage/installed.lock', '/vendor/autoload.php', '/scripts/local-runtime.php', '/.openwiki/docker/compose.env']:
                status, body, _ = http(client(), url + path)
                check(status in [403, 404] and secret.encode() not in body and b'APP_KEY=' not in body, 'Private HTTP path blocked: ' + path)
            (root / 'public/assets/private.txt').symlink_to(root / '.env')
            check(http(client(), url + '/assets/private.txt')[0] in [403, 404], 'Nginx does not serve a symlink outside public')
            login = root / 'resources/views/auth/login.php'
            login.write_text(login.read_text() + '\n<span>Host PHP mutation</span>\n')
            check(b'Host PHP mutation' in http(client(), url + '/login')[1], 'Host PHP edits appear without rebuilding or restarting')
            instance.php('file_put_contents("/app/resources/views/auth/login.php", "<span>Container PHP mutation</span>", FILE_APPEND);')
            check('Container PHP mutation' in login.read_text() and b'Container PHP mutation' in http(client(), url + '/login')[1], 'Container writes through the code bind are visible on the host and HTTP')
            print('Docker test: lifecycle, data persistence and asset rebuild', flush=True)
            check('Stan aplikacji: ready' in instance.cli('--status').stdout, 'Status reports application readiness without host services')
            check(instance.cli('--logs').returncode == 0, 'Logs belong to this Compose project')
            instance.cli('--stop')
            check('Stan aplikacji: stopped' in instance.cli('--status').stdout, 'Stopped status uses saved configuration')
            instance.cli('--restart')
            check(http(browser, url + '/spaces/RUNTIME/pages/runtime-page')[1].find(b'Preserved runtime document') >= 0, 'Page survives container recreation')
            check(http(browser, url + attachment_path)[1] == b'Preserved Docker attachment', 'Attachment and session survive restart')
            instance.cli()
            check((root / '.openwiki/docker/runtime/.env').read_bytes() == saved_env and (root / '.openwiki/docker/runtime/storage/installed.lock').read_bytes() == saved_lock, 'Repeat preserves key, configuration and installation lock')
            check((root / '.openwiki/docker/compose.env').read_bytes() == saved_compose, 'Repeat preserves generated database passwords')
            reordered = instance.command([shutil.which('bash'), str(root / 'install.sh'), '--status', '--docker'])
            check('Stan aplikacji: ready' in reordered.stdout, 'Flag order selects Docker status without system diagnostics')
            manifest = root / 'public/assets/editor-manifest.json'
            before = json.loads(manifest.read_text())['input']
            source = root / 'resources/js/document-reader.js'; source.write_text(source.read_text() + '\n// runtime source change\n')
            instance.cli()
            check(json.loads(manifest.read_text())['input'] != before, 'Repeated docker preparation detects a changed editor source')
            before = json.loads(manifest.read_text())['input']
            source.write_text(source.read_text() + '\n// second source change\n')
            instance.cli('--rebuild')
            check(json.loads(manifest.read_text())['input'] != before, 'Changed editor source is included in rebuild')
            check(http(browser, url + attachment_path)[1] == b'Preserved Docker attachment' and (root / '.openwiki/docker/runtime/.env').read_bytes() == saved_env, 'Rebuild preserves database, files and key')
            instance.cli('--no-start')
            check('Stan aplikacji: stopped' in instance.cli('--status').stdout, 'no-start leaves application services stopped')
            instance.cli('--start')
            check(http(browser, url + '/health')[0] == 200, 'start restores ready services')
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', BASE_PORT + 2)); listener.listen()
                collision = instance.cli('--port', str(BASE_PORT + 2), success=False)
                check(collision.returncode != 0 and 'Port' in collision.stdout, 'Occupied port is rejected with a specific diagnostic')
                check(listener.fileno() >= 0 and http(browser, url + '/health')[0] == 200, 'Port conflict leaves foreign listener and current instance running')
            for args in [['--local'], ['--start', '--stop'], ['--port', '65536'], ['--host', '127.0.0.1;id'], ['--host', '127.0.0.999'], ['--host', '127.0.0.1.'], ['--host', '1::2:'], ['--install-dir', '/tmp/forbidden']]:
                check(instance.cli(*args, success=False).returncode != 0, 'Conflicting or invalid parameters rejected: ' + ' '.join(args))
            configuration = root / '.openwiki/docker/runtime/.env'
            backup = configuration.with_suffix('.test-backup')
            configuration.rename(backup)
            try:
                check('configuration_error' in instance.cli('--status').stdout, 'Lost configuration is a recoverable error, not a fresh installation')
                check(http(client(), url + '/install')[0] == 503, 'A completed instance with a lost key cannot reopen the wizard')
            finally:
                backup.rename(configuration)
            second_root = temporary / 'second/Open Wiki'; copy_project(second_root)
            second = Instance(second_root, elsewhere, tools); instances.append(second)
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', BASE_PORT + 2)); listener.listen()
                failed = second.cli('--port', str(BASE_PORT + 2), success=False)
                check(failed.returncode != 0 and 'Port' in failed.stdout, 'First preparation rejects an occupied port without starting services')
            credentials = dict(line.split('=', 1) for line in (second_root / '.openwiki/docker/compose.env').read_text().splitlines())['OPENWIKI_DB_PASSWORD']
            second.cli('--no-start', '--port', str(BASE_PORT + 1))
            check(dict(line.split('=', 1) for line in (second_root / '.openwiki/docker/compose.env').read_text().splitlines())['OPENWIKI_DB_PASSWORD'] == credentials,
                'Retry after initial port failure preserves the first generated credentials')
            check(second.project != instance.project and not second.compose('ps', '-q', '--status', 'running').stdout.strip(), 'Same directory name has separate identity and no-start leaves no running services')
            second.cli('--start')
            check(json.loads(http(client(), 'http://127.0.0.1:' + str(BASE_PORT + 1) + '/health')[1])['status'] == 'setup_required', 'Second copy has a new database and independent installation state')
            second.cli('--stop')
            check(http(browser, url + '/health')[0] == 200, 'Stopping another copy does not stop this instance')
            check((root / '.env').read_bytes() == local_env and (root / 'storage/attachments/local.txt').read_bytes() == b'Local attachment unchanged', 'All Docker actions preserve local configuration and files')
            print('Docker runtime end-to-end passed: ' + str(CHECKS) + ' checks.', flush=True)
        finally:
            for item in reversed(instances):
                item.cleanup()


if __name__ == '__main__':
    run()
