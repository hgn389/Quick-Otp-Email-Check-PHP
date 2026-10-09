#!/usr/bin/env python3
"""Distinguish storage permissions from active locks and interrupted updates."""
import fcntl
import os
import pathlib
import shutil
import signal
import subprocess
import tempfile
import time

from integration import Client, port

PROJECT = pathlib.Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix='quickotp-maintenance-test-') as temporary:
        public = pathlib.Path(temporary)
        public.chmod(0o755)
        private = public / 'quickotp-private'
        private.mkdir(mode=0o755)
        shutil.copyfile(PROJECT / 'quickotp-private/bootstrap.php', private / 'bootstrap.php')
        (private / 'src').mkdir(mode=0o755)
        # Unavailable requests must stop before parsing application code.
        (private / 'src/Core.php').write_text('<?php broken syntax {')
        (public / 'index.php').write_text("<?php define('QUICKOTP_RUNTIME', true); require __DIR__ . '/quickotp-private/bootstrap.php';")
        storage = private / 'storage'
        # Start read-only so the readiness request cannot create a reusable lock.
        storage.mkdir(mode=0o555)
        base = 'http://127.0.0.1:' + str(port())
        def drop_privileges():
            if os.geteuid() == 0:
                os.setgroups([])
                os.setgid(65534)
                os.setuid(65534)
        with (public / 'server.log').open('wb') as log:
            server = subprocess.Popen(['php', '-d', 'display_errors=0', '-S', base.removeprefix('http://'), '-t', str(public)],
                                      preexec_fn=drop_privileges, stdout=log, stderr=log, start_new_session=True)
            try:
                client = Client(base)
                for _ in range(100):
                    try:
                        client.request('GET', '/')
                        break
                    except OSError:
                        time.sleep(.05)
                    assert server.poll() is None
                def expect(code, retry=False):
                    status, data, headers = client.request('GET', '/')
                    assert status == 503 and data['code'] == code, (status, data)
                    assert ('Retry-After' in headers) == retry
                    assert headers['Cache-Control'] == 'no-store'
                storage.chmod(0o555)
                expect('storage_unavailable')
                storage.chmod(0o777)
                lock = storage / 'maintenance.lock'
                lock.write_text('')
                lock.chmod(0o400)
                expect('storage_unavailable')
                lock.chmod(0o666)
                with lock.open('r+b') as held:
                    fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    expect('maintenance_busy', True)
                    fcntl.flock(held, fcntl.LOCK_UN)
                marker = storage / 'update-pending.json'
                marker.write_text('{"phase":"replacing"}')
                expect('update_recovery_required')
                assert marker.read_text() == '{"phase":"replacing"}', 'Never remove an unfinished update marker'
                marker.unlink()
                lock.unlink()
                lock.symlink_to(public / 'server.log')
                expect('invalid_storage_path')
                lock.unlink()
                storage.rmdir()
                storage.symlink_to(private / 'src', target_is_directory=True)
                expect('invalid_storage_path')
                storage.unlink()
                private.chmod(0o555)
                expect('storage_unavailable')
                private.chmod(0o755)
            finally:
                os.killpg(server.pid, signal.SIGTERM)
                server.wait(timeout=10)
                private.chmod(0o755)
        print('PASS: missing/read-only storage, wrong lock permissions, active maintenance, unfinished update and symlinks are distinguished before loading app code; recovery markers are preserved.')


if __name__ == '__main__':
    main()
