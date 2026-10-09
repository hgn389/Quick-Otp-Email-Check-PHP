#!/usr/bin/env python3
"""Require working HTTP protection before accepting a webroot installation."""
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
        for protected in (False, True):
            base = 'http://127.0.0.1:' + str(port())
            command = ['php', '-d', 'display_errors=0', '-S', base.removeprefix('http://'), '-t', str(root)]
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
                    assert (b'disabled' not in page) == protected
                    assert b'install_token' not in page
                    if not protected:
                        csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', page).group(1).decode()
                        form = {'csrf': csrf, 'db_host': '127.0.0.1', 'db_port': '1', 'db_name': 'quickotp_test_webroot',
                                'db_user': 'synthetic_user', 'db_password': 'synthetic_database_password',
                                'admin_password': 'Synthetic-admin-password-2026!', 'admin_password_confirm': 'Synthetic-admin-password-2026!'}
                        assert client.request('POST', '/install.php', form=form, headers={'Origin': base})[0] == 503
                        assert not (root / 'quickotp-private/config.php').exists()
                        assert client.request('GET', '/quickotp-private/bootstrap.php')[0] == 403
                    else:
                        for path in ['/quickotp-private/schema.sql', '/quickotp-private/names.json', '/quickotp-private/views/login.html']:
                            assert client.request('GET', path)[0] == 403
                    assert not list(root.glob('quickotp-probe-*'))
                    assert not list((root / 'quickotp-private').glob('quickotp-probe-*'))
                finally:
                    os.killpg(server.pid, signal.SIGTERM)
                    server.wait(timeout=10)
        print('PASS: fresh setup has no manual token, unprotected hosting cannot save configuration, direct bootstrap is denied and temporary probes are cleaned.')


if __name__ == '__main__':
    main()
