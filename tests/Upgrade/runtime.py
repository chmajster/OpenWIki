#!/usr/bin/env python3
"""Real Git/Composer/build/migrate/switch/recovery and HTTP forms on isolated data."""
import fcntl
import hashlib
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

PROJECT = Path(__file__).resolve().parents[2]
PHP = shutil.which('php')
DATABASE = 'openwiki_upgrade_' + os.urandom(6).hex() + '_test'
checks = 0


def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1


def run(args, cwd=PROJECT, env=None, timeout=300):
    return subprocess.run([str(x) for x in args], cwd=cwd, env=env, text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)


def fixture(action, root):
    result = run([PHP, PROJECT / 'tests/Upgrade/fixture.php', action, DATABASE, root])
    check(result.returncode == 0, 'Upgrade database fixture: ' + action + ('\n' + result.stdout if result.returncode else ''))
    return result.stdout.strip()


def journal(root):
    return json.loads((root / '.openwiki/status.json').read_text())


def upgrade(root, repo, mode, ref='', env=None):
    result = run(['bash', PROJECT / 'tests/Upgrade/driver.sh', PROJECT, root, mode, ref, repo], env=env)
    (root.parent / ('upgrade-' + mode + '.log')).write_text(result.stdout)
    if result.returncode and mode == 'resume':
        print('\n'.join(line for line in result.stdout.splitlines() if '[FAIL]' in line or '[CAUSE]' in line), flush=True)
    return result


class Server:
    def __init__(self, code, env=None, remote_url=None):
        self.client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(CookieJar()))
        if remote_url:
            self.url = remote_url
            self.process = None
            return
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.url = 'http://127.0.0.1:' + str(port)
        self.log = (code.parent / 'upgrade-http.log').open('w')
        runtime_env = dict(os.environ, APP_URL=self.url, SESSION_SECURE_COOKIE='false')
        if env:
            runtime_env.update(env)
        self.process = subprocess.Popen([PHP, '-d', 'opcache.enable=0', '-S', '127.0.0.1:' + str(port),
            '-t', str(code / 'public'), str(code / 'scripts/local-router.php')], cwd=code, env=runtime_env,
            stdout=self.log, stderr=subprocess.STDOUT)
        for attempt in range(80):
            try:
                if self.http('/health/live')[0] == 200:
                    return
            except (OSError, urllib.error.URLError):
                time.sleep(0.1)
        raise AssertionError('Real PHP HTTP server failed to start')

    def http(self, path, data=None, method=None, token=None):
        headers = {'Authorization': 'Bearer ' + token} if token else {}
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        request = urllib.request.Request(self.url + path, payload, headers, method=method)
        try:
            response = self.client.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read()

    def close(self):
        if self.process is None:
            return
        self.process.terminate()
        self.process.wait(timeout=10)
        self.log.close()


def csrf(body):
    token = re.search(rb'name="_token"\s+value="([^"]+)"', body)
    check(token is not None, 'Real HTML form includes CSRF token')
    return token.group(1).decode()


def verify_http(code, seeded, expected=b'Preserved source', remote_url=None):
    server = Server(code, remote_url=remote_url)
    try:
        status, body = server.http('/health')
        check(status == 200 and json.loads(body)['checks']['schema'], 'HTTP readiness requires completed schema')
        status, body = server.http('/login')
        status, body = server.http('/login', {'_token': csrf(body), 'identifier': 'upgrade-tester', 'password': 'UpgradeRegression!42'})
        check(status == 200 and b'name="identifier"' not in body, 'Existing account logs in through the real form after upgrade')
        status, body = server.http('/spaces/UPGRADE/pages/preserved')
        check(status == 200 and expected in body, 'Existing page reads after upgrade')
        status, body = server.http('/attachments/' + str(seeded['attachment']) + '/download')
        check(status == 200 and body == b'Protected upgrade attachment\n\x00\xff', 'Protected binary attachment is preserved')
        status, body = server.http('/spaces/UPGRADE/pages/preserved/edit')
        version = re.search(rb'name="base_version"\s+value="(\d+)"', body)
        check(status == 200 and version is not None, 'Actual editor loads the migrated document')
        status, body = server.http('/spaces/UPGRADE/pages/preserved', {'_token': csrf(body), 'base_version': version.group(1).decode(),
            'title': 'Preserved page', 'slug': 'preserved', 'content_format': 'visual', 'content_html': '<p>Post upgrade write</p>', 'status': 'published'})
        check(status == 200 and b'Post upgrade write' in body, 'Actual editor form writes and reopens the page')
        status, body = server.http('/api/v1/pages/' + str(seeded['page']), token=seeded['token'])
        check(status == 200 and b'Post upgrade write' in body, 'Existing API token and page read work after upgrade')
    finally:
        server.close()


def main():
    check(bool(os.getenv('OPENWIKI_TEST_DB_HOST')), 'Explicit isolated database environment required')
    with tempfile.TemporaryDirectory(prefix='openwiki-upgrade-e2e-') as temporary:
        directory = Path(temporary)
        repo = directory / 'repository'
        root = directory / 'instance'
        repo.mkdir()
        archive = subprocess.check_output(['git', 'archive', 'HEAD'], cwd=PROJECT)
        subprocess.run(['tar', '-x', '-C', str(repo)], input=archive, check=True)
        for args in [['init', '-q'], ['config', 'user.email', 'upgrade@example.invalid'], ['config', 'user.name', 'Upgrade tests'], ['add', '.'], ['commit', '-qm', 'Verified target']]:
            check(run(['git', *args], repo).returncode == 0, 'Prepare actual Git target')
        root.mkdir()
        subprocess.run(['tar', '-x', '-C', str(root)], input=archive, check=True)
        shutil.copytree(PROJECT / 'vendor', root / 'vendor')
        for name in ['wiki-editor.js', 'diagram.js', 'document-reader.js', 'editor-manifest.json']:
            shutil.copyfile(PROJECT / 'public/assets' / name, root / 'public/assets' / name)
        try:
            seeded = json.loads(fixture('seed', root))
            original_env = (root / '.env').read_bytes()
            original_marker = (root / 'storage/installed.lock').read_bytes()
            original_data = fixture('snapshot', root)
            server = Server(root)
            try:
                check(server.http('/health/live')[0] == 200, 'Process is alive with an old schema')
                status, body = server.http('/health')
                check(status == 503 and not json.loads(body)['checks']['schema'], 'Old schema is not reported as ready')
            finally:
                server.close()
            server = Server(root, env={'DB_PORT': '1'})
            try:
                status, body = server.http('/health')
                check(server.http('/health/live')[0] == 200 and status == 503 and not json.loads(body)['checks']['database'], 'Process liveness does not imply database availability')
            finally:
                server.close()
            print('Upgrade E2E: fetch failure, dependency failure and resume', flush=True)
            failed = upgrade(root, repo, 'upgrade', 'delayed-release')
            check(failed.returncode != 0 and journal(root)['status'] == 'failed', 'Fetch failure cannot be success')
            check(not (root / '.openwiki/maintenance').exists(), 'Fetch failure leaves active instance unpaused')
            run(['git', 'branch', 'delayed-release'], repo)
            fault = directory / 'dependency-fault'; fault.mkdir()
            # Inject a failed dependency process, then run the real Composer on resume.
            (fault / 'composer').write_text('#!/bin/sh\necho "Dependency process unavailable" >&2\nexit 72\n')
            (fault / 'composer').chmod(0o755)
            failed = upgrade(root, repo, 'resume', env=dict(os.environ, PATH=str(fault) + os.pathsep + os.environ['PATH']))
            check(failed.returncode != 0 and journal(root)['status'] == 'failed', 'Dependency failure cannot be success')
            check((root / '.openwiki/maintenance').exists(), 'Failed prepared release keeps writes paused')
            server = Server(root)
            try:
                check(server.http('/login')[0] == 503, 'HTTP writes and session reads are blocked during upgrade')
                check(server.http('/health/live')[0] == 200 and server.http('/health')[0] == 503, 'Liveness differs from maintenance readiness')
            finally:
                server.close()
            backup = next((root / '.openwiki/backups').glob('*.tar'))
            backup_hash = hashlib.sha256(backup.read_bytes()).hexdigest()
            backup_time = backup.stat().st_mtime_ns
            with (root / '.openwiki/requests.lock').open('rb') as active_request:
                fcntl.flock(active_request, fcntl.LOCK_SH)
                with (directory / 'upgrade-drain.log').open('w') as output:
                    process = subprocess.Popen(['bash', str(PROJECT / 'tests/Upgrade/driver.sh'), str(PROJECT), str(root), 'resume', '', str(repo)], stdout=output, stderr=subprocess.STDOUT)
                    time.sleep(0.5)
                    check(process.poll() is None, 'Upgrade waits for an active request holding the write gate')
                    concurrent = upgrade(root, repo, 'resume')
                    check(concurrent.returncode != 0, 'Concurrent upgrade is refused')
                    fcntl.flock(active_request, fcntl.LOCK_UN)
                    check(process.wait(timeout=300) == 0, 'Resume proceeds after the active request drains')
            succeeded = journal(root)
            check(succeeded['status'] == 'completed', 'Resume completes the pinned release')
            check(backup.stat().st_mtime_ns == backup_time and hashlib.sha256(backup.read_bytes()).hexdigest() == backup_hash, 'Resume does not replace completed backup')
            check((root / '.env').read_bytes() == original_env and (root / 'storage/installed.lock').read_bytes() == original_marker, 'APP_KEY, exact configuration and installation marker are preserved')
            check(fixture('snapshot', root) == original_data, 'Real accounts, authorization, pages, revisions and attachments are preserved')
            check(fixture('verify-draft', root) == 'preserved', 'Legacy server draft retains its original base version')
            saved = journal(root)
            check(upgrade(root, repo, 'upgrade', 'delayed-release').returncode == 0, 'Repeating the same version succeeds without changes')
            check(journal(root)['result'] == 'unchanged' and journal(root)['id'] == saved['id'], 'No-op retains the previous verified recovery point')
            # Reproduce the durable state of a process interrupted after the atomic switch.
            run([PHP, PROJECT / 'scripts/upgrade-tool.php', 'set', root, 'status', 'failed'])
            (root / '.openwiki/maintenance').touch()
            check(upgrade(root, repo, 'resume').returncode == 0, 'Resume after code switch verifies and reopens the same release')
            check(hashlib.sha256(backup.read_bytes()).hexdigest() == backup_hash, 'Post-switch resume retains the original backup')
            verify_http(root / 'current', seeded)

            print('Upgrade E2E: partial DDL, resume and controlled restore', flush=True)
            migration = repo / 'database/migrations/009_upgrade_test.php'
            migration.write_text("<?php return ['version' => '009_upgrade_test', 'up' => [OpenWiki\\Database\\MigrationStep::column('pages', 'upgrade_probe', 'VARCHAR(30) NULL'), 'INSERT INTO upgrade_dependency VALUES (1)']];\n")
            run(['git', 'add', '.'], repo); run(['git', 'commit', '-qm', 'Recovery target'], repo); run(['git', 'branch', 'recovery-release'], repo)
            before_failure = fixture('snapshot', root)
            failed = upgrade(root, repo, 'upgrade', 'recovery-release')
            check(failed.returncode != 0 and journal(root)['status'] == 'failed', 'Partial DDL failure cannot be success')
            check('[CAUSE]' in failed.stdout and 'upgrade_dependency' in failed.stdout, 'Private migration log identifies the actual database failure')
            backup_receipt = journal(root)['backup_sha256']
            check(upgrade(root, repo, 'rollback').returncode != 0, 'Code rollback is refused after incompatible/incomplete migration')
            fixture('fix-migration', root)
            check(upgrade(root, repo, 'resume').returncode == 0, 'Resume completes committed DDL without duplicate-column errors')
            check(journal(root)['backup_sha256'] == backup_receipt, 'Migration resume preserves the original recovery snapshot')
            check(upgrade(root, repo, 'rollback').returncode != 0, 'Unknown future migration prevents rolling old code back')
            check(upgrade(root, repo, 'restore', DATABASE).returncode == 0, 'Controlled verified restore reestablishes the compatible release')
            check(journal(root)['status'] == 'restored' and fixture('snapshot', root) == before_failure, 'Restore returns original data and compatible code together')
            check((root / '.env').read_bytes() == original_env, 'Restore retains the original application key and secrets')
            verify_http(root / 'current', seeded, b'Post upgrade write')
        finally:
            fixture('drop', root)
    print('Upgrade E2E passed: ' + str(checks) + ' checks.', flush=True)


if __name__ == '__main__':
    main()
