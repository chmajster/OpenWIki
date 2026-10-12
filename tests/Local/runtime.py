#!/usr/bin/env python3
"""Real non-root Bash -> PHP -> browser wizard -> MySQL -> restart verification."""
import fcntl
import hashlib
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import pty
import re
import shlex
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import termios
import time
import urllib.error
import urllib.parse
import urllib.request

PROJECT = Path(__file__).resolve().parents[2]
PHP = shutil.which('php')
DB_NAME = 'openwiki_local_' + os.urandom(6).hex()
ENV = dict(os.environ, NO_COLOR='1', OPENWIKI_INSTALL_DIR='/')
INITIAL_PORT = int(os.getenv('OPENWIKI_LOCAL_TEST_START_PORT', '8080'))
NEXT_PORT = INITIAL_PORT + 10
checks = 0


def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1


def command(args, cwd, env=ENV, timeout=180):
    return subprocess.run(args, cwd=cwd, env=env, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.STDOUT, timeout=timeout)


def fixture(action, root):
    result = command([PHP, str(PROJECT / 'tests/Local/fixture.php'), action, DB_NAME, str(root)], PROJECT)
    check(result.returncode == 0, 'Database fixture operation failed: ' + action)
    return result.stdout.strip()


def opener():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}),
                                      urllib.request.HTTPCookieProcessor(CookieJar()))


def http(client, url, data=None, token=None, timeout=3):
    headers = {'Authorization': 'Bearer ' + token} if token else {}
    if data is not None:
        data = urllib.parse.urlencode(data).encode()
    request = urllib.request.Request(url, data=data, headers=headers)
    try:
        response = client.open(request, timeout=timeout)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read(), response.headers


def csrf(body):
    match = re.search(rb'name="_token"\s+value="([^"]+)"', body)
    check(match is not None, 'A real form CSRF token is available')
    return match.group(1).decode()


class Server:
    def __init__(self, root, elsewhere, suffix, args=(), env=ENV):
        self.log_path = root.parent / ('local-' + suffix + '.log')
        self.log = self.log_path.open('w')
        self.master, slave = pty.openpty()

        def terminal():
            os.setsid()
            fcntl.ioctl(slave, termios.TIOCSCTTY, 0)

        self.process = subprocess.Popen(['bash', str(root / 'install.sh'), '--local', *args],
            cwd=elsewhere, env=env, stdin=slave, stdout=self.log, stderr=subprocess.STDOUT, preexec_fn=terminal)
        os.close(slave)

    def output(self):
        self.log.flush()
        return self.log_path.read_text()

    def ready(self):
        deadline = time.monotonic() + 180
        while time.monotonic() < deadline:
            if self.process.poll() is not None:
                # Report CLI diagnostics, never configuration or credentials.
                shutil.copyfile(self.log_path, PROJECT / 'storage/temp/local-runtime-last.log')
                messages = [line for line in self.output().splitlines() if '[FAIL]' in line]
                raise AssertionError('Local server exited: ' + '\n'.join(messages))
            if '[ OK ] Serwer lokalny działa.' in self.output():
                return
            time.sleep(0.1)
        raise AssertionError('Local runtime preparation exceeded the test deadline')

    def stop(self, keyboard=False, sig=signal.SIGTERM):
        if self.process.poll() is None:
            if keyboard:
                os.write(self.master, b'\x03')
            else:
                self.process.send_signal(sig)
        result = self.process.wait(timeout=8)
        self.log.close()
        os.close(self.master)
        return result

    def cleanup(self):
        if self.process.poll() is None:
            self.process.send_signal(signal.SIGTERM)
            try:
                self.process.wait(timeout=8)
            except subprocess.TimeoutExpired:
                os.killpg(self.process.pid, signal.SIGKILL)
                self.process.wait()
        if not self.log.closed:
            self.log.close()
            os.close(self.master)


def free_port(port):
    with socket.socket() as listener:
        listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        listener.bind(('127.0.0.1', port))


def run():
    check(os.geteuid() != 0, 'This verification must run as a non-root user')
    check(bool(os.getenv('OPENWIKI_TEST_DB_HOST')), 'Configure an isolated OPENWIKI_TEST_DB_* instance')
    temp_parent = PROJECT / 'storage/temp' if '--workspace-temp' in sys.argv else None
    servers = []
    with tempfile.TemporaryDirectory(prefix='openwiki local ', dir=temp_parent) as temporary:
        root = Path(temporary) / 'project with spaces'
        elsewhere = Path(temporary) / 'other working directory'
        root.mkdir(); elsewhere.mkdir()
        files = command(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], PROJECT).stdout.split('\0')
        for name in files:
            if not name or name.startswith('.git/'):
                continue
            source = PROJECT / name
            if not source.is_file():
                continue
            target = root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
        command(['git', 'init', '-q'], root)
        command(['git', 'add', '.'], root)
        committed = command(['git', '-c', 'user.name=Local test', '-c', 'user.email=local@example.invalid', 'commit', '-qm', 'fixture'], root)
        check(committed.returncode == 0, 'The isolated checkout is initialized')
        login_view = root / 'resources/views/auth/login.php'
        login_view.write_text(login_view.read_text() + '\n<p data-local-edit>Local PHP change visible</p>\n')
        dirty = command(['git', 'diff'], root).stdout
        print('Local test: prepare the fresh checkout and run the real installer', flush=True)
        first_args = [] if INITIAL_PORT == 8080 else ['--port', str(INITIAL_PORT)]
        server = Server(root, elsewhere, 'fresh', first_args); servers.append(server)
        try:
            server.ready()
            check('Katalog projektu: ' + str(root) in server.output(), 'Full path selects this checkout')
            url = 'http://127.0.0.1:' + str(INITIAL_PORT)
            browser = opener()
            status, body, headers = http(browser, url + '/health')
            check(status == 503 and json.loads(body)['status'] == 'setup_required', 'Fresh health is setup_required, not startup failure')
            status, body, headers = http(browser, url + '/install')
            check(status == 200 and b'name="db_host"' in body, 'The production installer wizard is available')
            setup = {'_token': csrf(body), 'app_name': 'Local runtime test', 'app_url': url, 'timezone': 'UTC',
                'db_host': os.getenv('OPENWIKI_TEST_DB_HOST'), 'db_port': os.getenv('OPENWIKI_TEST_DB_PORT', '3306'),
                'db_database': DB_NAME, 'db_username': os.getenv('OPENWIKI_TEST_DB_USERNAME'),
                'db_password': os.getenv('OPENWIKI_TEST_DB_PASSWORD', ''), 'create_database': '1',
                'admin_username': 'local-tester', 'admin_email': 'local@example.invalid', 'admin_password': 'Local-runtime-test-123!'}
            # Fresh DDL plus durable recovery checkpoints can exceed a normal
            # request budget on CI disks. Keep startup/read probes at 3 seconds.
            status, body, headers = http(browser, url + '/install', setup, timeout=30)
            check(status == 200 and b'name="identifier"' in body, 'Graphical installation completed and redirects to login')
            status, body, headers = http(browser, url + '/health')
            check(status == 200 and json.loads(body)['status'] == 'ok', 'Installed health checks the real database')
            seeded = json.loads(fixture('seed', root))
            before = fixture('snapshot', root)
            status, body, headers = http(browser, url + '/login')
            check(b'Local PHP change visible' in body, 'Local source changes remain visible')
            status, body, headers = http(browser, url + '/login', {'_token': csrf(body), 'identifier': 'local-tester', 'password': setup['admin_password']})
            check(status == 200 and b'name="identifier"' not in body, 'Login and the local session work')
            for asset, mime in [('app.js', 'application/javascript'), ('app.css', 'text/css'), ('wiki-editor.js', 'application/javascript'),
                                ('diagram.js', 'application/javascript'), ('document-reader.js', 'application/javascript'), ('diagram.html', 'text/html')]:
                status, body, headers = http(browser, url + '/assets/' + asset)
                check(status == 200 and len(body) > 0 and headers['Content-Type'].startswith(mime), 'Static asset MIME: ' + asset)
            (root / 'public/assets/escape.txt').symlink_to(root / '.env')
            (root / 'public/assets/execute.php').write_text('<?php echo "must not execute";')
            for path in ['/.env', '/.git/config', '/storage/installed.lock', '/vendor/autoload.php', '/scripts/local-runtime.php',
                         '/assets/%2e%2e/%2e%2e/.env', '/assets/escape.txt', '/assets/execute.php']:
                status, body, headers = http(browser, url + path)
                check(status == 404 and b'APP_KEY=' not in body, 'Private path blocked: ' + path)
            status, body, headers = http(browser, url + '/spaces/local/pages/' + seeded['slug'])
            check(status == 200 and b'Preserved content' in body, 'Authenticated wiki routing works')
            status, body, headers = http(browser, url + '/api/v1/pages/' + str(seeded['page']), token=seeded['token'])
            check(status == 200 and json.loads(body)['data']['version'] == 1, 'Real API routing and data work')
            login_view.write_text(login_view.read_text().replace('Local PHP change visible', 'Updated PHP visible after refresh'))
            status, body, headers = http(opener(), url + '/login')
            check(b'Updated PHP visible after refresh' in body, 'PHP updates without copying or restarting')
            check(server.stop(keyboard=True) == 130, 'Ctrl+C stops the foreground invocation')
            free_port(INITIAL_PORT); check(True, 'Ctrl+C frees the initial port')

            env_file = root / '.env'
            configured = re.sub(r'^APP_URL=.*$', 'APP_URL="https://production.example.invalid"', env_file.read_text(), flags=re.MULTILINE)
            configured = re.sub(r'^SESSION_SECURE_COOKIE=.*$', 'SESSION_SECURE_COOKIE="true"', configured, flags=re.MULTILINE)
            env_file.write_text(configured)
            saved_env = env_file.read_bytes(); saved_lock = (root / 'storage/installed.lock').read_bytes()
            dirty = command(['git', 'diff'], root).stdout
            # Complete bundles must work without invoking either Node or npm again.
            guard = Path(temporary) / 'node guard'; guard.mkdir()
            for tool in ['node', 'npm']:
                executable = guard / tool; executable.write_text('#!/bin/sh\nexit 99\n'); executable.chmod(0o755)
            reuse_env = dict(ENV, PATH=str(guard) + os.pathsep + ENV['PATH'])
            print('Local test: restart, authentication, assets and preserved data', flush=True)
            server = Server(root, elsewhere, 'configured', ['--host', '0.0.0.0', '--port', str(NEXT_PORT)], reuse_env); servers.append(server); server.ready()
            check('Adres aplikacji: http://127.0.0.1:' + str(NEXT_PORT) in server.output(), 'Wildcard listen prints an openable loopback URL')
            check('pomijam ponowną instalację' in server.output() and 'pomijam npm ci i build' in server.output(), 'Repeated startup skips compatible dependencies and assets')
            url = 'http://127.0.0.1:' + str(NEXT_PORT); browser = opener()
            status, body, headers = http(browser, url + '/login')
            check('secure' not in headers.get('Set-Cookie', '').lower() and 'OPENWIKI_LOCAL_' in headers.get('Set-Cookie', ''), 'Local cookie overrides are confined to this process')
            status, body, headers = http(browser, url + '/login', {'_token': csrf(body), 'identifier': 'local-tester', 'password': setup['admin_password']})
            check(status == 200 and b'name="identifier"' not in body, 'HTTPS production configuration still permits local login')
            status, body, headers = http(browser, url + '/attachments/' + str(seeded['attachment']) + '/download')
            check(status == 200 and body == b'Preserved attachment bytes', 'Protected attachment survives restart')
            collision = command(['bash', str(root / 'install.sh'), '--local', '--port', str(NEXT_PORT)], elsewhere)
            check(collision.returncode != 0 and 'zajęty' in collision.stdout, 'An occupied port has a specific error')
            check(server.process.poll() is None and http(browser, url + '/health')[0] == 200, 'The occupied-port process remains untouched')
            check(server.stop() == 143, 'SIGTERM stops only the owned server'); free_port(NEXT_PORT)

            invalid = [['--local', '--uninstall'], ['--local', '--status'], ['--install', '--local'], ['--local', '--install-dir', '/tmp/forbidden'],
                       ['--port', '8090'], ['--local', '--port', '80'], ['--local', '--port', '65536'], ['--local', '--host', '127.0.0.1;id'], ['--local', '--host', 'http://localhost'], ['--local', '--port']]
            for args in invalid:
                result = command(['bash', str(root / 'install.sh'), *args], elsewhere)
                check(result.returncode != 0 and '[FAIL]' in result.stdout, 'Invalid arguments rejected: ' + ' '.join(args))
            check(env_file.read_bytes() == saved_env and (root / 'storage/installed.lock').read_bytes() == saved_lock, 'Environment and installation key/lock are unchanged')
            check(fixture('snapshot', root) == before, 'Passwords, roles, pages, revisions and attachment bytes are unchanged')
            check(command(['git', 'diff'], root).stdout == dirty, 'Existing uncommitted Git changes are preserved')

            source = root / 'resources/js/document-reader.js'; source.write_text(source.read_text() + '\n// local source edit\n')
            server = Server(root, elsewhere, 'rebuild', ['--port', str(NEXT_PORT)]); servers.append(server); server.ready()
            check('Buduję zasoby edytora' in server.output() and 'Przygotowuję zależności edytora' not in server.output(), 'Changed sources rebuild without an unnecessary npm ci')
            check(server.stop(sig=signal.SIGINT) == 130, 'Direct SIGINT stops the owned server'); free_port(NEXT_PORT)
            print('Local test: failed health and the port bind race', flush=True)
            invalid_key = Server(root, elsewhere, 'invalid-key', ['--port', str(NEXT_PORT)], dict(ENV, APP_KEY='')); servers.append(invalid_key)
            check(invalid_key.process.wait(timeout=40) != 0 and 'APP_KEY' in invalid_key.output(), 'An installed instance with an invalid key is not offered a destructive reinstall')
            invalid_key.cleanup(); free_port(NEXT_PORT)
            degraded = Server(root, elsewhere, 'degraded', ['--port', str(NEXT_PORT)], dict(ENV, DB_HOST='127.0.0.1', DB_PORT='1')); servers.append(degraded)
            check(degraded.process.wait(timeout=40) != 0 and 'problem z bazą' in degraded.output(), 'Installed database failure is distinguished from setup_required')
            degraded.cleanup(); free_port(NEXT_PORT)

            # Force the bind race after the real preflight; still run the actual PHP server.
            gate = Path(temporary) / 'start gate'; gate.mkdir()
            wrapper = gate / 'php'
            marker = gate / 'starting'; release = gate / 'release'
            wrapper.write_text('#!/usr/bin/env bash\nfor value in "$@"; do\n if [[ "$value" == -S ]]; then\n  touch ' + shlex.quote(str(marker)) + '\n  while [[ ! -f ' + shlex.quote(str(release)) + ' ]]; do sleep 0.05; done\n fi\ndone\nexec ' + shlex.quote(PHP) + ' "$@"\n')
            wrapper.chmod(0o755)
            race = Server(root, elsewhere, 'bind-race', ['--port', str(NEXT_PORT)], dict(ENV, PATH=str(gate) + os.pathsep + ENV['PATH'])); servers.append(race)
            deadline = time.monotonic() + 30
            while not marker.exists() and race.process.poll() is None and time.monotonic() < deadline:
                time.sleep(0.05)
            check(marker.exists(), 'The real server reached the bind race gate')
            with socket.socket() as listener:
                listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                listener.bind(('127.0.0.1', NEXT_PORT)); listener.listen()
                release.touch()
                check(race.process.wait(timeout=15) != 0 and 'nie wystartował' in race.output(), 'A bind error during actual startup propagates nonzero')
                check(listener.fileno() >= 0, 'The competing listener is not stopped')
            race.cleanup(); free_port(NEXT_PORT)
            # Interrupt a delayed real Composer invocation and all of its owned children.
            prep = Path(temporary) / 'preparation gate'; prep.mkdir()
            child_file = prep / 'child'; composer = shutil.which('composer')
            command_wrapper = prep / 'composer'
            command_wrapper.write_text('#!/usr/bin/env bash\nsleep 120 &\nchild=$!\nprintf "%s" "$child" > ' + shlex.quote(str(child_file)) + '\nwait "$child"\nexec ' + shlex.quote(composer) + ' "$@"\n')
            command_wrapper.chmod(0o755)
            interrupted = Server(root, elsewhere, 'preparation-stop', ['--port', str(NEXT_PORT)], dict(ENV, PATH=str(prep) + os.pathsep + ENV['PATH'])); servers.append(interrupted)
            deadline = time.monotonic() + 30
            while not child_file.exists() and interrupted.process.poll() is None and time.monotonic() < deadline:
                time.sleep(0.05)
            check(child_file.exists(), 'Dependency preparation started the controlled delay')
            child_id = int(child_file.read_text())
            check(interrupted.stop() == 143, 'SIGTERM interrupts dependency preparation')
            process_state = Path('/proc/' + str(child_id) + '/stat')
            check(not process_state.exists() or process_state.read_text().split()[2] == 'Z', 'Only the owned preparation process group is stopped')
            free_port(NEXT_PORT)
            check(env_file.read_bytes() == saved_env and (root / 'storage/installed.lock').read_bytes() == saved_lock, 'Failed runs preserve the configured instance')
            print('Local runtime end-to-end passed: ' + str(checks) + ' checks; non-root, real wizard/MySQL, spaces, routing, restart, signals and bind race.')
        finally:
            for process in servers:
                process.cleanup()
            fixture('drop', root)


if __name__ == '__main__':
    run()
