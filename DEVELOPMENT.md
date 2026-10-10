# Development

Quick OTP Mail PHP v1.0.1 requires PHP 8.3+, Composer 2, MySQL or MariaDB, Python 3 and Node.js 20+ for tests.

Install dependencies:

```bash
composer install --working-dir=quickotp-private --no-dev --prefer-dist
```

Run checks:

```bash
php tests/unit.php
php tests/updater.php
node tests/system-ui.js
node tests/i18n.js
python3 tests/maintenance.py
python3 tests/integration.py
```

Integration tests create and stop an isolated MariaDB instance in a temporary directory. They require `mariadb-install-db`, `mariadbd` and `mariadb`. To use an external test database, set `QUICKOTP_TEST_DB_HOST`, `QUICKOTP_TEST_DB_PORT`, `QUICKOTP_TEST_DB_NAME`, `QUICKOTP_TEST_DB_USER` and `QUICKOTP_TEST_DB_PASSWORD`. Its name must start with `quickotp_test_`. `QUICKOTP_TEST_RESET_DATABASE=1` clears application tables in that test database before each run.

Build and verify installer packages:

```bash
python3 tools/package.py
python3 tests/webroot.py
QUICKOTP_TEST_PACKAGE="$PWD/dist/Quick-Otp-Email-Check-PHP_v$(cat VERSION).zip" python3 tests/integration.py
QUICKOTP_TEST_PACKAGE="$PWD/dist/Quick-Otp-Email-Check-PHP_v$(cat VERSION)-website.zip" python3 tests/integration.py
```

The website ZIP extracts into the website home with sibling `public_html/` and `quickotp-private/` directories. The canonical ZIP is the dashboard update package. Packages include production dependencies and user documentation; runtime configuration, storage data and development documents are excluded. Third-party license files are preserved with their dependencies.
