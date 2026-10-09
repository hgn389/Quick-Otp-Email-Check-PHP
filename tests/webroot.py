#!/usr/bin/env python3
"""Check split deployment and protection for the supported legacy webroot layout."""
import os
import pathlib
import re
import shutil
import signal
import subprocess
import tempfile
import time
import zipfile

from integration import Client, port

PROJECT = pathlib.Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix='quickotp-webroot-test-') as temporary:
        root = pathlib.Path(temporary)
        package = PROJECT / 'dist' / ('Quick-Otp-Email-Check-PHP_v' + (PROJECT / 'VERSION').read_text().strip() + '-website.zip')
        with zipfile.ZipFile(package) as archive:
            archive.extractall(root)
        # Keep regression coverage for existing installations inside the document root.
        legacy = root / 'legacy/public_html'
        shutil.copytree(root / 'public_html', legacy)
        shutil.copytree(root / 'quickotp-private', legacy / 'quickotp-private')
        # A website home can contain unrelated PHP files outside the document root.
        (root / 'index.php').write_text("<?php throw new RuntimeException('Home placeholder must not execute');")
        (root / 'install.php').write_text("<?php throw new RuntimeException('Home placeholder must not execute');")
        for layout, protected in [('split', False), ('split', True), ('legacy', False), ('legacy', True)]:
            public = root / 'public_html' if layout == 'split' else legacy
            private = root / 'quickotp-private' if layout == 'split' else legacy / 'quickotp-private'
            password_file = private / 'install-password.php'
            ready = layout == 'split' or protected
            base = 'http://127.0.0.1:' + str(port())
            command = ['php', '-d', 'display_errors=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0', '-S', base.removeprefix('http://'), '-t', str(public)]
            if protected:
                command.append(str(PROJECT / 'tools/router.php'))
            with open(root / 'server.log', 'wb') as log:
                server = subprocess.Popen(command, env={**os.environ, 'PHP_CLI_SERVER_WORKERS': '4'}, stdout=log, stderr=log, start_new_session=True)
                try:
                    client = Client(base)
                    for _ in range(100):
                        try:
                            status, page, _ = client.request('GET', '/install.php')
                            if status == 200:
                                break
                        except OSError:
                            pass
                        assert server.poll() is None
                        time.sleep(.05)
                    else:
                        raise AssertionError('Setup page did not start')
                    assert b'disabled' in page, 'Setup must remain locked before the administrator sets a password'
                    if layout == 'split':
                        duplicate = public / 'quickotp-private'
                        duplicate.mkdir()
                        (duplicate / 'bootstrap.php').write_text("<?php throw new RuntimeException('Duplicate bootstrap must not execute');")
                        try:
                            for path in ['/', '/install.php']:
                                code, body, response_headers = client.request('GET', path)
                                assert code == 503 and b'Duplicate bootstrap must not execute' not in body
                                assert 'no-store' in response_headers['Cache-Control']
                        finally:
                            shutil.rmtree(duplicate)
                        bootstrap = private / 'bootstrap.php'
                        saved_bootstrap = private / 'bootstrap.saved'
                        bootstrap.rename(saved_bootstrap)
                        try:
                            for path in ['/', '/install.php']:
                                code, body, response_headers = client.request('GET', path)
                                assert code == 503 and b'open_basedir' in body
                                assert 'no-store' in response_headers['Cache-Control']
                        finally:
                            saved_bootstrap.rename(bootstrap)
                    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page).group(1).decode()
                    assert client.request('POST', '/install.php', form={'csrf': csrf, 'install_password': 'Synthetic-setup-password-2026!'})[0] == 503
                    leaked = 'Synthetic-misformatted-install-secret-2026!'
                    for contents in ["<?php return '';", "<?php return 'too-short';", "<?php return ['invalid'];", "<?php broken syntax {", leaked,
                                     "<?php echo '" + leaked + "'; return 'Synthetic-setup-password-2026!';",
                                     "<?php return 'YOUR_UNIQUE_INSTALLATION_PASSWORD';",
                                     "<?php return '" + ('á' * 8) + "';"]:
                        password_file.write_text(contents)
                        status, failed, _ = client.request('GET', '/install.php')
                        assert status == 200 and b'disabled' in failed
                        assert leaked.encode() not in failed, 'Malformed setup files must never emit secrets'
                    password_file.write_text("<?php return '" + ('á' * 16) + "';")
                    _, valid, _ = client.request('GET', '/install.php')
                    assert (b'disabled' not in valid) == ready, 'A valid UTF-8 password is accepted after manual editing with OPcache enabled'
                    password_file.unlink()
                    password_file.symlink_to(private / 'install-password.example.php')
                    assert b'disabled' in client.request('GET', '/install.php')[1], 'Symlink setup passwords are rejected'
                    password_file.unlink()
                    password_file.write_text("<?php return 'Synthetic-setup-password-2026!';")
                    status, page, _ = client.request('GET', '/install.php')
                    assert status == 200 and (b'disabled' not in page) == ready
                    assert b'name="install_password"' in page
                    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page).group(1).decode()
                    password_file.write_text("<?php return 'Synthetic-changed-setup-password-2026!';")
                    assert client.request('POST', '/install.php', form={'csrf': csrf, 'install_password': 'Synthetic-setup-password-2026!'})[0] == 403, 'The old password must stop working immediately after a manual edit'
                    changed_form = {'csrf': csrf, 'install_password': 'Synthetic-changed-setup-password-2026!', 'admin_password': 'Synthetic-admin-password-2026!', 'admin_password_confirm': 'Synthetic-different-password-2026!'}
                    assert client.request('POST', '/install.php', form=changed_form)[0] == (400 if ready else 503), 'The new password must reach setup validation'
                    password_file.write_text("<?php return 'Synthetic-setup-password-2026!';")
                    if not ready:
                        csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page).group(1).decode()
                        form = {'csrf': csrf, 'install_password': 'Synthetic-setup-password-2026!', 'db_host': '127.0.0.1', 'db_port': '1', 'db_name': 'quickotp_test_webroot',
                                'db_user': 'synthetic_user', 'db_password': 'synthetic_database_password',
                                'admin_password': 'Synthetic-admin-password-2026!', 'admin_password_confirm': 'Synthetic-admin-password-2026!'}
                        assert client.request('POST', '/install.php', form=form, headers={'Origin': base})[0] == 503
                        assert not (private / 'config.php').exists()
                        assert client.request('GET', '/quickotp-private/bootstrap.php')[0] == 403
                    else:
                        for path in ['/quickotp-private/schema.sql', '/quickotp-private/names.json', '/quickotp-private/views/login.html']:
                            code, body, headers = client.request('GET', path)
                            if layout == 'split' and not protected and code == 303:
                                # PHP 8.4's built-in server can route missing files through index.php.
                                assert headers['Location'] == '/install.php' and body == b''
                            else:
                                assert code in (403, 404), (layout, protected, path, code, body)
                    password_file.unlink()
                    assert not list(public.glob('quickotp-probe-*'))
                    assert not list(private.glob('quickotp-probe-*'))
                finally:
                    os.killpg(server.pid, signal.SIGTERM)
                    server.wait(timeout=10)
        print('PASS: split layout keeps private files outside the web root, duplicate deployments are rejected, legacy webroot protection is retained; malformed setup secrets cannot leak; OPcache picks up edits; placeholder and short UTF-8 passwords are rejected; fresh setup requires an administrator-defined password; no manual token, unprotected hosting cannot save configuration, direct bootstrap is denied and temporary probes are cleaned.')


if __name__ == '__main__':
    main()
