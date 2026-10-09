#!/usr/bin/env python3
"""Create the uploadable PHP source installer without runtime configuration/data."""
import hashlib
import json
import pathlib
import re
import shutil
import stat
import tempfile
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
version = (root / 'VERSION').read_text().strip()
assert re.fullmatch(r'\d+\.\d+\.\d+(?:-beta_[1-9]\d*)?', version), 'Invalid version'
source_version = re.search(r"VERSION = '([^']+)'", (root / 'quickotp-private/src/System.php').read_text()).group(1)
assert version == source_version, 'Version file and application must match'
assert (root / 'README.md').read_text().splitlines()[0] == '# Quick OTP Mail PHP v' + version, 'README version must match application'
assert '\n## v' + version + ' ' in (root / 'CHANGELOG.md').read_text(), 'Changelog must include application version'
assert 'esc(System::VERSION)' in (root / 'public_html/install.php').read_text(), 'Installer footer must use application version'
for view in (root / 'quickotp-private/views').glob('*.html'):
    asset_versions = re.findall(r'\?v=php-(\d+\.\d+\.\d+(?:-beta_[1-9]\d*)?)', view.read_text())
    assert all(asset == version for asset in asset_versions), 'Asset version mismatch: ' + view.name
assert (root / 'quickotp-private/vendor/autoload.php').is_file(), 'Run composer install first'
excluded_patterns = (
    '.git', '.github', 'AGENTS.md', '.env', '.env.*', '*.env', 'config.php', 'install-token.txt',
    '__pycache__', '.vscode', '.idea', 'tests', 'Tests', 'test', 'docs', 'examples',
    '*.log', '*.pem', '*.key', '*.p12', '*.pfx', '*.jks', '*.keystore',
    '*.db', '*.db-*', '*.sqlite', '*.sqlite-*', '*.sqlite3', '*.sqlite3-*',
    '*.qotp', '*.bak', '*.backup', '*.dump', '*.sql', '*.sql.gz', '*.initial-password',
    '*.zip', '*.tar', '*.tar.gz', '*.tgz', '*~',
)
for source in [root / 'public_html', root / 'quickotp-private/src', root / 'quickotp-private/views', root / 'quickotp-private/vendor']:
    assert not any(file.is_symlink() for file in source.rglob('*')), 'Source symlinks are not supported'
for file in (root / 'public_html').iterdir():
    assert file.is_file() and (file.suffix in ('.css', '.js') or file.name in ('index.php', 'install.php', '.htaccess', '.user.ini')), 'Unexpected public file'
def zip_entry(archive, name, data):
    info = zipfile.ZipInfo(name)
    info.create_system = 3
    info.external_attr = (stat.S_IFREG | 0o644) << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    archive.writestr(info, data)


name = 'Quick-Otp-Email-Check-PHP_v' + version
dist = root / 'dist'
dist.mkdir(exist_ok=True)

with tempfile.TemporaryDirectory(prefix='quickotp-php-package-') as temporary:
    package = pathlib.Path(temporary) / name
    package.mkdir()
    shutil.copytree(root / 'public_html', package / 'public_html')
    private = package / 'quickotp-private'
    private.mkdir()
    for directory in ['src', 'views', 'vendor']:
        shutil.copytree(root / 'quickotp-private' / directory, private / directory,
                        ignore=shutil.ignore_patterns(*excluded_patterns))
    for file in ['bootstrap.php', 'update-recovery.php', 'schema.sql', 'names.json', 'composer.json', 'composer.lock', 'config.example.php']:
        source = root / 'quickotp-private' / file
        assert source.is_file() and not source.is_symlink(), 'Invalid private source file'
        shutil.copyfile(source, private / file)
    (private / 'storage').mkdir()
    (private / 'storage/.gitkeep').touch()
    for file in ['README.md', 'CYBERPANEL.md', 'SECURITY_REVIEW.md', 'CHANGELOG.md', 'VERSION']:
        shutil.copyfile(root / file, package / file)
    for file in package.rglob('*'):
        if not file.is_file():
            continue
        assert file.name not in ('config.php', 'install-token.txt', 'AGENTS.md'), 'Unexpected configuration/instructions in package'
        assert file.name != '.env' and not file.name.startswith('.env.'), 'Unexpected environment configuration in package'
        assert not file.is_symlink(), 'Symlinks not supported in installer'
        assert not any(file.match(pattern) for pattern in excluded_patterns
                       if not (pattern == '*.sql' and file == private / 'schema.sql')), 'Unexpected runtime data in package'
    manifest = {'format': 1, 'version': version, 'php_min': 80300,
                'schema_sha256': hashlib.sha256((private / 'schema.sql').read_bytes()).hexdigest(),
                'files': {str(file.relative_to(package)): hashlib.sha256(file.read_bytes()).hexdigest()
                          for file in sorted(package.rglob('*')) if file.is_file()}}
    (package / 'update.json').write_text(json.dumps(manifest, ensure_ascii=True, sort_keys=True))
    destination = dist / (name + '.zip')
    with zipfile.ZipFile(destination, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for file in sorted(package.rglob('*')):
            if file.is_file():
                zip_entry(archive, str(file.relative_to(package.parent)), file.read_bytes())
    website_destination = dist / (name + '-website.zip')
    with zipfile.ZipFile(website_destination, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        # No wrapper: extract the contents directly into the website document root.
        for file in sorted(package.rglob('*')):
            if not file.is_file() or file.parent == package:
                continue
            relative = file.relative_to(package).as_posix()
            if relative.startswith('public_html/'):
                relative = relative[len('public_html/'):]
            zip_entry(archive, relative, file.read_bytes())
        deny = root / 'quickotp-private/.htaccess'
        assert deny.is_file() and not deny.is_symlink(), 'Private deny rules are required'
        zip_entry(archive, 'quickotp-private/.htaccess', deny.read_bytes())
        # Keep installer documentation out of the public URL space.
        for document in ['README.md', 'CYBERPANEL.md', 'SECURITY_REVIEW.md', 'CHANGELOG.md', 'VERSION']:
            zip_entry(archive, 'quickotp-private/' + document, (root / document).read_bytes())
    destinations = [destination, website_destination]
    (dist / 'checksums-php.txt').write_text(''.join(
        hashlib.sha256(file.read_bytes()).hexdigest() + '  ' + file.name + '\n' for file in destinations))
    for file in destinations:
        print('Built ' + file.name + ' with dependencies and no runtime configuration/data.')
