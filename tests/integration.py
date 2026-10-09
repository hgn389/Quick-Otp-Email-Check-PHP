#!/usr/bin/env python3
"""Install and exercise the PHP app against an isolated MySQL/MariaDB database."""
import base64
import concurrent.futures
import hashlib
import http.cookiejar
import json
import os
import pathlib
import re
import secrets
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

from update_integration import exercise_update, prepare_network_boundaries

PROJECT = pathlib.Path(__file__).resolve().parents[1]
ADMIN = 'Synthetic-admin-password-2026!'
NEW_ADMIN = 'Synthetic-new-admin-password-2026!'
BACKUP_PASSWORD = 'Synthetic-backup-password-2026!'
MAIL_PASSWORD = 'Synthetic-mail-app-password-2026!'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.csrf = ''

    def request(self, method, path, data=None, form=None, upload=None, csrf=True, headers=None):
        request_headers = dict(headers or {})
        body = None
        if data is not None:
            body = json.dumps(data).encode()
            request_headers['Content-Type'] = 'application/json'
        if form is not None:
            body = urllib.parse.urlencode(form).encode()
            request_headers['Content-Type'] = 'application/x-www-form-urlencoded'
        if upload is not None:
            boundary = 'quickotp-' + secrets.token_hex(12)
            parts = []
            for key, value in upload[0].items():
                parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode())
            parts.extend([(f'--{boundary}\r\nContent-Disposition: form-data; name="backup"; filename="fixture.qotp"\r\nContent-Type: application/octet-stream\r\n\r\n').encode(), upload[1], (f'\r\n--{boundary}--\r\n').encode()])
            body = b''.join(parts)
            request_headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        if csrf and self.csrf and method not in ('GET', 'HEAD'):
            request_headers['X-CSRF-Token'] = self.csrf
        request = urllib.request.Request(self.base + path, data=body, headers=request_headers, method=method)
        try:
            response = self.opener.open(request, timeout=35)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw = response.read()
            result = json.loads(raw) if response.headers.get_content_type() == 'application/json' else raw
            return response.status, result, response.headers

    def login(self, password):
        status, _, _ = self.request('POST', '/api/v1/auth/login', {'username': 'admin', 'password': password})
        assert status == 200, 'Fixture login failed'
        status, auth, _ = self.request('GET', '/api/v1/auth/session')
        assert status == 200
        self.csrf = auth['csrf_token']


def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def fixture(site, action, data=None):
    result = subprocess.run(['php', str(PROJECT / 'tests/fixture.php'), str(site), action], input=json.dumps(data or {}).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    return result.stdout


def exercise(site, base, database, install_token):
    anonymous = Client(base)
    assert anonymous.request('GET', '/')[0] == 303
    code, html, headers = anonymous.request('GET', '/install.php')
    assert code == 200 and ((b'install_token' in html) == bool(install_token))
    assert headers['Referrer-Policy'] == 'same-origin'
    assert b'disabled' not in html, html.decode()
    application_version = re.search(r"VERSION = '([^']+)'", (site / 'quickotp-private/src/System.php').read_text()).group(1)
    assert ('Quick OTP Mail · v' + application_version + '-php').encode() in html, 'Installer footer must match application version'
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', html).group(1).decode()
    form = dict(database, csrf=csrf, install_token=install_token, admin_password=ADMIN, admin_password_confirm=ADMIN)
    assert anonymous.request('POST', '/install.php', form={**form, 'csrf': 'wrong'})[0] == 403
    if install_token:
        assert anonymous.request('POST', '/install.php', form={**form, 'install_token': 'wrong-install-token-1234567890'})[0] == 403
    assert anonymous.request('POST', '/install.php', form=form, headers={'Origin': 'https://untrusted.example'})[0] == 403
    assert not (site / 'quickotp-private/config.php').exists()
    assert anonymous.request('POST', '/install.php', form=form)[0] == 303
    config_path = site / 'quickotp-private/config.php'
    assert config_path.exists() and (config_path.stat().st_mode & 0o777) == 0o600
    assert not (site / 'quickotp-private/install-token.txt').exists()
    assert anonymous.request('POST', '/install.php', form=form)[0] == 303
    assert not list((site / 'quickotp-private').glob('quickotp-probe-*'))
    assert not list(site.glob('quickotp-probe-*'))
    assert anonymous.request('GET', '/health/ready')[0] == 200
    for path in ['/', '/settings.html', '/quick-otp.html', '/backup.html', '/system.html']:
        assert anonymous.request('GET', path)[0] == 303
    assert anonymous.request('POST', '/api/v1/settings/mail/password')[0] == 401
    for path in ['/quickotp-private/config.php', '/quickotp-private/storage/backups/a.qotp', '/.env', '/../../quickotp-private/config.php']:
        code, body, _ = anonymous.request('GET', path)
        assert code in (401, 403, 404)
        assert database['db_password'].encode() not in (body if isinstance(body, bytes) else json.dumps(body).encode())
    client = Client(base)
    client.login(ADMIN)
    assert client.request('GET', '/index.php')[0] == 200
    second = Client(base)
    second.login(ADMIN)
    code, _, headers = client.request('GET', '/settings.html')
    assert code == 200 and headers['Cache-Control'] == 'no-store' and "script-src 'self'" in headers['Content-Security-Policy']
    assert client.request('POST', '/api/v1/auth/login', {'username': 'admin', 'password': ADMIN}, headers={'Origin': 'https://untrusted.example'})[0] == 403
    settings = client.request('GET', '/api/v1/settings')[1]
    settings.update(default_domain='example.com', default_domains=['example.com', 'example.org'], generator_type='random_crypto', appearance='dark')
    assert client.request('PUT', '/api/v1/settings', settings, csrf=False)[0] == 403
    assert client.request('PUT', '/api/v1/settings', {**settings, 'default_domain': 'missing.example'})[0] == 400
    assert client.request('PUT', '/api/v1/settings', {**settings, 'auto_fill_watch': 'false'})[0] == 400
    assert client.request('PUT', '/api/v1/settings', settings)[0] == 200
    assert client.request('GET', '/api/v1/settings')[1]['default_domains'] == ['example.com', 'example.org']
    assert client.request('POST', '/api/v1/generator/email', {'domain': "x'; DROP TABLE qotp_users;--", 'type': 'random_crypto'})[0] == 400
    assert client.request('POST', '/api/v1/generator/email', {'domain': 'example.com', 'type': 'random_crypto', 'unexpected': 'x' * 65536})[0] == 413
    for i in range(25):
        code, address, _ = client.request('POST', '/api/v1/generator/email', {'domain': 'example.com', 'type': 'random_crypto'})
        assert code == 200 and re.fullmatch(r'[a-z0-9]{16}@example\.com', address['email'])
    history = client.request('GET', '/api/v1/history/page')[1]
    assert history['total'] == 25 and history['page_size'] == 10 and len(history['items']) == 10 and history['total_pages'] == 3
    assert len(client.request('GET', '/api/v1/history/page?page=3')[1]['items']) == 5
    assert client.request('GET', '/api/v1/history/page?page=999')[1]['page'] == 3
    for rows in (10, 20, 50, 100):
        assert len(client.request('GET', '/api/v1/history/page?page_size=' + str(rows))[1]['items']) == min(rows, 25)
    for query in ('page=0', 'page_size=99', 'page[]=1', 'day_start=wrong', 'day_start=2026-10-07T00:00:00Z&day_end=2026-10-09T00:00:00Z'):
        assert client.request('GET', '/api/v1/history/page?' + query)[0] == 400
    assert client.request('GET', '/api/v1/messages/latest?email=target@example.com')[1]['mail_connection'] == 'not_configured'
    assert client.request('POST', '/api/v1/watches', {'email': 'target@example.com'})[0] == 200
    assert client.request('DELETE', '/api/v1/watches?email=target@example.com')[0] == 204
    mail = {'provider': 'custom', 'host': 'imap.example.com', 'port': 993, 'username': 'fixture@example.com', 'folder': 'INBOX', 'password': MAIL_PASSWORD}
    code, saved, _ = client.request('PUT', '/api/v1/settings/mail', mail)
    assert code == 200 and saved['has_password'] and 'password' not in saved
    assert 'password' not in client.request('GET', '/api/v1/settings/mail')[1]
    assert client.request('POST', '/api/v1/settings/mail/password', csrf=False)[0] == 403
    assert client.request('GET', '/api/v1/settings/mail/password')[0] == 405
    assert client.request('POST', '/api/v1/settings/mail/password')[1]['password'] == MAIL_PASSWORD
    assert client.request('PUT', '/api/v1/settings/mail', {**mail, 'password': '', 'folder': 'Archive'})[0] == 200
    assert client.request('PUT', '/api/v1/settings/mail', {**mail, 'password': '', 'host': 'other.example.com'})[0] == 400
    assert client.request('PUT', '/api/v1/settings/mail', {**mail, 'password': 'bad\r\nLOGOUT'})[0] == 400
    assert client.request('POST', '/api/v1/settings/mail/test', {**mail, 'host': 'localhost'})[0] == 400
    fixture(site, 'seed')
    code, stored, _ = client.request('GET', '/api/v1/messages/latest?email=target@example.com')
    assert code == 200 and stored['message']['otp'] == '123456' and stored['message']['recipient'] == 'target@example.com'
    assert client.request('GET', '/api/v1/otp/latest?email=target@example.com')[1]['otp'] == '123456'
    assert client.request('GET', '/api/v1/messages/latest?email=unmatched@example.com')[1]['message'] is None
    exercise_update(site, client, anonymous, ADMIN, PROJECT)
    if os.environ.get('QUICKOTP_TEST_UPDATE_ONLY') == '1':
        return

    code, original_backup, headers = client.request('POST', '/api/v1/backups/export', {'current_password': ADMIN, 'backup_password': BACKUP_PASSWORD})
    assert code == 200 and original_backup.startswith(b'QOTPPH01') and MAIL_PASSWORD.encode() not in original_backup and database['db_password'].encode() not in original_backup
    assert 'filename=' in headers['Content-Disposition']
    assert client.request('POST', '/api/v1/backups/export', {'current_password': 'Synthetic-wrong-password', 'backup_password': BACKUP_PASSWORD})[0] == 401
    assert client.request('POST', '/api/v1/backups/export', {'current_password': ADMIN, 'backup_password': 'short'})[0] == 400
    for i in range(3):
        assert client.request('POST', '/api/v1/generator/email', {'domain': 'example.com', 'type': 'random_crypto'})[0] == 200
    assert client.request('POST', '/api/v1/auth/password', {'current_password': ADMIN, 'new_password': NEW_ADMIN})[0] == 200
    assert second.request('GET', '/api/v1/auth/session')[0] == 401
    client.csrf = client.request('GET', '/api/v1/auth/session')[1]['csrf_token']
    fields = {'current_password': NEW_ADMIN, 'backup_password': BACKUP_PASSWORD}
    code, summary, _ = client.request('POST', '/api/v1/backups/inspect', upload=(fields, original_backup))
    assert code == 200 and summary['counts']['generated_emails'] == 25 and summary['counts']['messages'] == 2
    assert client.request('POST', '/api/v1/backups/restore', upload=(fields, original_backup))[0] == 400
    fixture(site, 'rotate-key')
    rotated = client.request('POST', '/api/v1/settings/mail/password')
    assert rotated[0] == 200 and rotated[1].get('password') == MAIL_PASSWORD, 'Re-encrypted fixture credential must be readable after key rotation'
    fixture(site, 'block-extra')
    code, restored, _ = client.request('POST', '/api/v1/backups/restore', upload=({**fields, 'confirmation': 'RESTORE'}, original_backup))
    assert code == 200 and restored['recovery_file'].endswith('.qotp')
    assert client.request('GET', '/api/v1/auth/session')[0] == 401
    client.login(ADMIN)
    assert client.request('GET', '/api/v1/history/page')[1]['total'] == 25
    loaded = client.request('POST', '/api/v1/settings/mail/password')
    assert loaded[0] == 200 and loaded[1].get('password') == MAIL_PASSWORD, 'Restored credential must decrypt with destination key'
    assert json.loads(fixture(site, 'check-mail', {'password': MAIL_PASSWORD})) == {'valid': True, 'encrypted': True, 'blocked': True}
    code, files, _ = client.request('GET', '/api/v1/backups')
    assert code == 200 and len(files['recovery_files']) == 1
    assert client.request('POST', '/api/v1/backups/recovery', {'current_password': ADMIN, 'name': '../../config.php'})[0] == 400
    code, recovery_backup, _ = client.request('POST', '/api/v1/backups/recovery', {'current_password': ADMIN, 'name': restored['recovery_file']})
    assert code == 200 and recovery_backup.startswith(b'QOTPPH01')
    mutated = bytearray(original_backup)
    mutated[40] ^= 1
    assert client.request('POST', '/api/v1/backups/inspect', upload=({'current_password': ADMIN, 'backup_password': BACKUP_PASSWORD}, bytes(mutated)))[0] == 400
    for mutation in ('id', 'table', 'column', 'email', 'time'):
        bad = base64.b64decode(fixture(site, 'mutate-backup', {'backup': base64.b64encode(original_backup).decode(), 'password': BACKUP_PASSWORD, 'mutation': mutation}))
        assert client.request('POST', '/api/v1/backups/restore', upload=({'current_password': ADMIN, 'backup_password': BACKUP_PASSWORD, 'confirmation': 'RESTORE'}, bad))[0] == 400
        assert client.request('GET', '/api/v1/history/page')[1]['total'] == 25
    assert client.request('POST', '/api/v1/backups/restore', upload=({'current_password': ADMIN, 'backup_password': BACKUP_PASSWORD, 'confirmation': 'RESTORE'}, recovery_backup))[0] == 200
    client.login(NEW_ADMIN)
    assert client.request('GET', '/api/v1/history/page')[1]['total'] == 28
    code, system, _ = client.request('GET', '/api/v1/system/status')
    assert code == 200 and system['version'].endswith('-php') and 'php_version' in system
    config_digest = hashlib.sha256(config_path.read_bytes()).hexdigest()
    assert anonymous.request('POST', '/install.php', form=form)[0] == 303
    assert hashlib.sha256(config_path.read_bytes()).hexdigest() == config_digest
    if os.environ.get('QUICKOTP_BROWSER_SCRIPT'):
        environment = {**os.environ, 'REVIEW_BASE': base, 'REVIEW_PASSWORD': NEW_ADMIN}
        subprocess.run([os.environ.get('QUICKOTP_BROWSER_NODE', 'node'), os.environ['QUICKOTP_BROWSER_SCRIPT']], env=environment, check=True)
    token = next(cookie.value for cookie in client.jar if cookie.name == 'quickotp_php_session')
    fixture(site, 'expire-session', {'token': token})
    assert client.request('GET', '/api/v1/auth/session')[0] == 401
    client.login(NEW_ADMIN)
    assert client.request('POST', '/api/v1/auth/logout', csrf=False)[0] == 403
    assert client.request('POST', '/api/v1/auth/logout')[0] == 204
    assert client.request('GET', '/api/v1/auth/session')[0] == 401
    def fail_login(_):
        return Client(base).request('POST', '/api/v1/auth/login', {'username': 'admin', 'password': 'Synthetic-wrong-password'})[0]
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as executor:
        statuses = list(executor.map(fail_login, range(5)))
    assert sorted(statuses) == [401, 401, 401, 401, 403]
    assert Client(base).request('POST', '/api/v1/auth/login', {'username': 'admin', 'password': NEW_ADMIN})[0] == 403
    print('PASS: PHP web installation, private files, authentication/CSRF/session rotation, pagination, encrypted mail settings, exact recipient mail lookup, cross-key backup/restore/recovery, malformed backups, expired sessions and concurrent login blocking.')


def main():
    with tempfile.TemporaryDirectory(prefix='quickotp-php-test-') as temporary:
        root = pathlib.Path(temporary)
        site = root / 'site'
        if os.environ.get('QUICKOTP_TEST_PACKAGE'):
            unpacked = root / 'unpacked'
            with zipfile.ZipFile(os.environ['QUICKOTP_TEST_PACKAGE']) as archive:
                for member in archive.infolist():
                    path = pathlib.PurePosixPath(member.filename)
                    assert not path.is_absolute() and '..' not in path.parts
                archive.extractall(unpacked)
            packages = list(unpacked.iterdir())
            if (unpacked / 'index.php').exists():
                unpacked.rename(site)
            else:
                assert len(packages) == 1 and packages[0].is_dir()
                packages[0].rename(site)
            (site / 'tools').mkdir()
            shutil.copyfile(PROJECT / 'tools/router.php', site / 'tools/router.php')
        else:
            shutil.copytree(PROJECT, site, ignore=shutil.ignore_patterns('config.php', 'install-token.txt', 'storage', 'dist', '__pycache__'))
        (site / 'quickotp-private/storage').mkdir(mode=0o700, exist_ok=True)
        public = site if (site / 'index.php').exists() else site / 'public_html'
        token = secrets.token_hex(24) if public != site else ''
        if token:
            (site / 'quickotp-private/install-token.txt').write_text(token)
        mysql = None
        php = None
        log = open(root / 'servers.log', 'wb')
        try:
            if os.environ.get('QUICKOTP_TEST_DB_NAME'):
                database = {'db_host': os.environ.get('QUICKOTP_TEST_DB_HOST', '127.0.0.1'), 'db_port': os.environ.get('QUICKOTP_TEST_DB_PORT', '3306'), 'db_name': os.environ['QUICKOTP_TEST_DB_NAME'], 'db_user': os.environ['QUICKOTP_TEST_DB_USER'], 'db_password': os.environ['QUICKOTP_TEST_DB_PASSWORD']}
                assert database['db_name'].startswith('quickotp_test_')
            else:
                mysql_directory = root / 'mysql'
                mysql_directory.mkdir()
                subprocess.run(['mariadb-install-db', '--no-defaults', '--datadir=' + str(mysql_directory), '--auth-root-authentication-method=normal', '--skip-test-db', '--user=' + ('root' if os.geteuid() == 0 else os.environ.get('USER', 'mysql'))], stdout=log, stderr=log, check=True)
                mysql_socket = str(root / 'mysql.sock')
                mysql_port = port()
                command = ['mariadbd', '--no-defaults', '--datadir=' + str(mysql_directory), '--socket=' + mysql_socket, '--pid-file=' + str(root / 'mysql.pid'), '--bind-address=127.0.0.1', '--port=' + str(mysql_port), '--skip-name-resolve', '--innodb-buffer-pool-size=64M']
                if os.geteuid() == 0:
                    command.append('--user=root')
                mysql = subprocess.Popen(command, stdout=log, stderr=log)
                for _ in range(200):
                    if pathlib.Path(mysql_socket).exists():
                        probe = subprocess.run(['mariadb', '--no-defaults', '--socket=' + mysql_socket, '-u', 'root', '-e', 'SELECT 1'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                        if probe.returncode == 0:
                            break
                    if mysql.poll() is not None:
                        raise RuntimeError('Isolated MariaDB failed to start')
                    time.sleep(.1)
                else:
                    raise RuntimeError('Isolated MariaDB did not become ready')
                name = 'quickotp_test_' + secrets.token_hex(5)
                password = secrets.token_hex(24)
                sql = f"CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'qotp_test'@'127.0.0.1' IDENTIFIED BY '{password}'; GRANT ALL ON `{name}`.* TO 'qotp_test'@'127.0.0.1';"
                subprocess.run(['mariadb', '--no-defaults', '--socket=' + mysql_socket, '-u', 'root'], input=sql.encode(), stdout=log, stderr=log, check=True)
                database = {'db_host': '127.0.0.1', 'db_port': str(mysql_port), 'db_name': name, 'db_user': 'qotp_test', 'db_password': password}
            if os.environ.get('QUICKOTP_TEST_RESET_DATABASE') == '1':
                fixture(site, 'reset-database', {**database, 'db_port': int(database['db_port'])})
            http_port = port()
            base = f'http://127.0.0.1:{http_port}'
            prepare_network_boundaries(site)
            environment = {**os.environ, 'PHP_CLI_SERVER_WORKERS': '4'}
            php = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'opcache.enable=' + ('1' if os.environ.get('QUICKOTP_TEST_OPCACHE') == '1' else '0'), '-d', 'opcache.enable_cli=' + ('1' if os.environ.get('QUICKOTP_TEST_OPCACHE') == '1' else '0'), '-d', 'opcache.validate_timestamps=0', '-d', 'upload_max_filesize=32M', '-d', 'post_max_size=34M', '-d', 'memory_limit=256M', '-S', f'127.0.0.1:{http_port}', '-t', str(public), str(site / 'tools/router.php')], env=environment, stdout=log, stderr=log, start_new_session=True)
            for _ in range(100):
                try:
                    code = Client(base).request('GET', '/install.php')[0]
                    if code == 200:
                        break
                except OSError:
                    pass
                if php.poll() is not None:
                    raise RuntimeError('PHP server failed to start')
                time.sleep(.1)
            else:
                raise RuntimeError('PHP web installer not ready')
            exercise(site, base, database, token)
        except Exception:
            log.flush()
            # Print only server error classes/locations, never configuration or form values.
            lines = (root / 'servers.log').read_text(errors='replace').splitlines()
            for line in lines:
                if 'Quick OTP' in line or 'Fatal error' in line or 'Warning:' in line:
                    print(line)
            raise
        finally:
            if php is not None:
                os.killpg(php.pid, signal.SIGTERM)
                php.wait(timeout=15)
            if mysql is not None:
                mysql.terminate()
                mysql.wait(timeout=20)
            log.close()


if __name__ == '__main__':
    main()
