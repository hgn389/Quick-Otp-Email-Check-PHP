"""Exercise the authenticated updater on an isolated test website, with local release assets."""
import concurrent.futures
import hashlib
import json
import os
import pathlib
import subprocess
import time
import zipfile


def exercise_update(site, client, anonymous, admin_password, project):
    private = site / 'quickotp-private'
    original_config = (private / 'config.php').read_bytes()
    public = site if (site / 'index.php').exists() else site / 'public_html'
    original_assets = (public / 'app.js').read_bytes()
    original_version = client.request('GET', '/api/v1/system/status')[1]['version']
    initial_history = client.request('GET', '/api/v1/history/page')[1]['total']
    assets = private / 'storage/test-update-assets'
    assets.mkdir(mode=0o700)
    version = '1.0.0-beta-3'
    package_name = f'Quick-Otp-Email-Check-PHP_v{version}'
    payload = {}
    for directory in ['public_html', 'quickotp-private/src', 'quickotp-private/views', 'quickotp-private/vendor']:
        for path in (project / directory).rglob('*'):
            if path.is_file() and not path.is_symlink():
                payload[path.relative_to(project).as_posix()] = path.read_bytes()
    for name in ['bootstrap.php', 'update-recovery.php', 'schema.sql', 'names.json', 'composer.json', 'composer.lock', 'config.example.php']:
        payload['quickotp-private/' + name] = (project / 'quickotp-private' / name).read_bytes()
    source = payload['quickotp-private/src/System.php'].decode()
    current_version = (project / 'VERSION').read_text().strip()
    payload['quickotp-private/src/System.php'] = source.replace(f"const VERSION = '{current_version}';", f"const VERSION = '{version}';").encode()
    payload['public_html/app.js'] += b'\n// Isolated update integration asset.\n'
    manifest = {'format': 1, 'version': version, 'php_min': 80300,
                'schema_sha256': hashlib.sha256(payload['quickotp-private/schema.sql']).hexdigest(),
                'files': {name: hashlib.sha256(value).hexdigest() for name, value in payload.items()}}
    package = assets / (package_name + '.zip')
    with zipfile.ZipFile(package, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
        for name, value in payload.items():
            archive.writestr(package_name + '/' + name, value)
        archive.writestr(package_name + '/update.json', json.dumps(manifest))
    (assets / 'checksums-php.txt').write_text(hashlib.sha256(package.read_bytes()).hexdigest() + '  ' + package.name + '\n')
    base = f'https://github.com/hgn389/Quick-Otp-Email-Check-PHP/releases/download/v{version}/'
    releases = [{'tag_name': 'v' + version, 'draft': False, 'prerelease': True, 'assets': [
        {'name': package.name, 'size': package.stat().st_size, 'browser_download_url': base + package.name},
        {'name': 'checksums-php.txt', 'browser_download_url': base + 'checksums-php.txt'}]}]
    (private / 'storage/releases.json').write_text(json.dumps(releases))
    prepare_network_boundaries(site)
    install = '/api/v1/system/update/install'
    body = {'version': version, 'current_password': admin_password, 'confirmation': 'UPDATE'}
    assert anonymous.request('POST', install, body)[0] == 401
    assert client.request('POST', install, body, csrf=False)[0] == 403
    assert client.request('GET', install)[0] == 405
    assert client.request('POST', install, {**body, 'current_password': 'Synthetic-wrong-update-password'})[0] == 401
    assert client.request('GET', '/api/v1/auth/session')[0] == 200
    assert client.request('POST', install, {**body, 'confirmation': ''})[0] == 400
    assert client.request('POST', install, {**body, 'version': '9.9.9'})[0] == 409
    updates = client.request('GET', '/api/v1/system/update')[1]
    assert updates['update_available'] and updates['can_install'] and updates['mode'] == 'source_zip_auto'
    # Revoking a session during download must stop before replacing any source.
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        cancelled = pool.submit(client.request, 'POST', install, body)
        deadline = time.monotonic() + 12
        while not (assets / 'download-ready').exists():
            assert not cancelled.done()
            assert time.monotonic() < deadline
            time.sleep(.02)
        assert client.request('POST', '/api/v1/auth/logout')[0] == 204
        (assets / 'download-continue').touch()
        assert cancelled.result()[0] == 401
    assert (public / 'app.js').read_bytes() == original_assets
    assert not (private / 'storage/update-pending.json').exists()
    for signal in ['download-ready', 'download-continue']:
        (assets / signal).unlink()
    client.login(admin_password)
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        job = pool.submit(client.request, 'POST', install, body)
        for phase, expected in [('download', 200), ('replace', 503)]:
            deadline = time.monotonic() + 12
            while not (assets / (phase + '-ready')).exists():
                assert not job.done(), job.result() if job.done() else 'Update ended before phase'
                assert time.monotonic() < deadline, 'Update phase rendezvous timed out'
                time.sleep(.02)
            assert anonymous.request('GET', '/health/ready')[0] == expected
            (assets / (phase + '-continue')).touch()
        code, result, _ = job.result()
    assert code == 200 and result['version'] == 'v1.0.0-beta-3-php', result
    assert client.request('GET', '/api/v1/system/status')[1]['version'] == 'v1.0.0-beta-3-php'
    assert anonymous.request('GET', '/health/ready')[0] == 200
    assert (private / 'config.php').read_bytes() == original_config
    assert client.request('GET', '/api/v1/history/page')[1]['total'] == initial_history
    assert (public / 'app.js').read_bytes() != original_assets
    backup = private / result['recovery_directory']
    decrypted = subprocess.run(['php', '-r', r'require $argv[1]; $data=\QuickOtp\Backup::open(file_get_contents($argv[2]),$argv[3]); echo json_encode($data);', str(private / 'bootstrap.php'), str(backup / 'database.qotp'), admin_password], capture_output=True, text=True, check=True)
    snapshot = json.loads(decrypted.stdout)
    assert len(snapshot['tables']['generated_emails']) == initial_history
    # Simulate an interrupted installation: the gate must stop before loading even broken classes.
    (private / 'src/App.php').write_text('<?php broken syntax {')
    (private / 'storage/update-pending.json').write_text(json.dumps({'backup': result['recovery_directory']}))
    assert anonymous.request('GET', '/health/ready')[0] == 503
    recovered = subprocess.run(['php', str(backup / 'restore.php')], capture_output=True, text=True, timeout=30)
    assert recovered.returncode == 0, recovered.stderr
    assert anonymous.request('GET', '/health/ready')[0] == 200
    if os.environ.get('QUICKOTP_TEST_OPCACHE') != '1':
        assert client.request('GET', '/api/v1/system/status')[1]['version'] == original_version
    else:
        # CLI recovery needs a worker restart for timestamp-disabled OPcache, as documented.
        assert original_version.split('-php')[0].lstrip('v') in (private / 'src/System.php').read_text()
    assert (private / 'config.php').read_bytes() == original_config
    assert (public / 'app.js').read_bytes() == original_assets
    assert client.request('GET', '/api/v1/history/page')[1]['total'] == initial_history
    print('PASS: authenticated PHP web update, CSRF/password/version/session-revocation guards, live download, maintenance before loading classes, encrypted database backup, source rollback and unchanged configuration/history.')

def prepare_network_boundaries(site):
    private = site / 'quickotp-private'
    system_path = private / 'src/System.php'
    system_path.write_text(system_path.read_text().replace('if (!$force && is_file($path)', 'if (is_file($path)'))
    updater_path = private / 'src/Updater.php'
    source = updater_path.read_text()
    if "test-update-assets/replace" not in source:
        source = source.replace('                if ($this->afterReplace !== null) {', '''                if ($relative === \'public_html/app.js\') {
                    $signal = $this->private . \'/storage/test-update-assets/replace\';
                    touch($signal . \'-ready\');
                    $deadline = microtime(true) + 12;
                    while (!is_file($signal . \'-continue\') && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
                }
                if ($this->afterReplace !== null) {''')
    updater_path.write_text(source.replace('$fetch = $this->fetch ?? self::download(...);', '''$fetch = static function (string $url, string $destination, int $maximum): void {
                if (str_ends_with($url, '.zip')) {
                    $signal = __DIR__ . '/../storage/test-update-assets/download';
                    touch($signal . '-ready');
                    $deadline = microtime(true) + 12;
                    while (!is_file($signal . '-continue') && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
                }
                copy(__DIR__ . '/../storage/test-update-assets/' . basename(parse_url($url, PHP_URL_PATH)), $destination);
            };'''))
