# CyberPanel installation and recovery

## Upload-only installation (v1.0.0-beta-3)

Use `Quick-Otp-Email-Check-PHP_v1.0.0-beta-3-website.zip` and extract directly into **`/home/domain.com/`**, not `public_html`. The archive has no wrapper directory. Allow replacement of matching files so its `public_html/` merges into the existing website directory. It does not delete unrelated files. Keep the website document root at `/home/domain.com/public_html/`.

Use SFTP or a file manager with access to the website home directory. If your panel only exposes `public_html`, use SFTP or the two-command SSH extraction in README. The package includes dependencies; Composer is not needed. Download the beta prerelease and checksums from the release link in README.

The resulting files are:

```text
/home/domain.com/
├── public_html/
│   ├── index.php
│   ├── install.php
│   ├── .htaccess
│   └── app.js, app.css and other assets
├── quickotp-private/
│   ├── .htaccess
│   ├── src/, views/ and vendor/
│   ├── config.php                (created by setup)
│   └── storage/                  (runtime files and update recovery)
├── README.md
└── other installation documents
```

Before opening setup, copy `/home/domain.com/quickotp-private/install-password.example.php` to `install-password.php` in the same directory and set your own unique password of at least 16 characters and at most 256 bytes in its final `return` statement. Keep the PHP access guard. Setup stays locked without a configured password. Create a database/user in CyberPanel, then open the website and enter the installation password, database settings and a separate Admin password. The password file is deleted after success and must never be published. Valid setup errors retain form entries.

Use PHP 8.3+ with `pdo_mysql`, `openssl`, `mbstring`, `iconv`, `zlib` and `curl`; add `zip` and `tokenizer` for updates. Grant the website's PHP user read/write access to both extracted application directories, especially private storage. If `open_basedir` is enabled, include `/home/domain.com/quickotp-private/`. Do not use `777`.

OpenLiteSpeed needs rewrite and Auto Load `.htaccess` for application routes. Private files are outside the document root. For the older layout inside `public_html`, keep both `.htaccess` files; setup verifies HTTP denial with temporary random canaries before saving credentials.

Use HTTPS. For Cloudflare, use Full (strict) with a valid origin certificate. If setup rejects an Origin, verify that the browser URL, request Origin, server scheme and hostname match; clear stale setup cookies and try again. Do not disable origin or CSRF validation.

Delete the uploaded ZIP after extraction. Store backups outside the public document root.

## Storage permissions and maintenance messages

A maintenance message on a new installation can mean PHP cannot create or open `quickotp-private/storage/maintenance.lock`. For a fresh installation, check ownership and permissions for **both** `public_html` and its sibling `quickotp-private`, then retry setup. The panel's File Manager **Fix Permissions** action may help; verify that private storage outside `public_html` is writable as well. CyberPanel documents this action in its [permissions troubleshooting](https://cyberpanel.net/KnowledgeBase/home/how-to-fix-ssl-issues-in-cyberpanel/).

For an existing installation, inspect the owner and permissions of storage and its lock file using the website's PHP user; preserve restrictive permissions on configuration and runtime files. A leftover `update-pending.json` means source recovery is required. Keep the marker and use the matching `restore.php` described below. The existence of `maintenance.lock` alone does not imply maintenance: the operating-system lock is released when the worker exits.

## Backup and recovery

Back up the database with `quickotp-private/config.php` and `quickotp-private/storage`. Config contains the database credentials and IMAP encryption key. The Backup & Restore page and APIs have been removed. The updater still keeps its own encrypted application-data snapshot and source recovery files; these do not replace a full manual database/configuration backup.

When upgrading from beta_1, replace application files manually once as described in README: its old parser cannot discover the hyphenated beta-2 tag. Later versions support both tag spellings. The built-in updater supports both layouts. It downloads the canonical ZIP without `-website`, validates it, keeps the site available during download, then locks requests while replacing files. Configuration, runtime storage and database data are preserved. It requires PHP `zip`, `tokenizer`, writable application files and permission to invalidate OPcache.

Recovery copies are stored in `quickotp-private/storage/updates/UPDATE_ID/backup/`. The encrypted `database.qotp` uses the Admin password confirmed for that update. Source files and a standalone `restore.php` are stored alongside it.

After an interrupted update, use the website owner to run the matching recovery script. For the upload-only layout:

```bash
sudo -u WEBSITE_USER /usr/local/lsws/lsphp83/bin/php /home/domain.com/quickotp-private/storage/updates/UPDATE_ID/backup/restore.php
```

For the previous layout inside the web document root, add `public_html/` before `quickotp-private/` in that path. Replace sample values and the PHP binary as needed. CLI recovery restores source files; it preserves configuration and current data. Restart the website's PHP/LSAPI workers afterwards if OPcache is enabled. Do not remove the maintenance marker before recovery is verified.

## Existing installations inside public_html

Existing websites remain supported. To move private files outside the document root, follow the migration section in README: stop website requests, move the complete private directory with its configuration and storage, preserve permissions, then merge new application source and restart PHP/LSAPI. Do not run setup again or leave two copies of `quickotp-private`. The entry points reject two copies containing bootstrap.php to avoid using the wrong configuration.

Fresh installations require `quickotp-private/install-password.php`. The old optional `install-token.txt` is replaced by this mandatory password mechanism. Existing installed websites keep `config.php` and do not run setup again.

GitHub's Source code ZIP is a developer checkout without bundled dependencies. Use the ready-made website ZIP for browser installation. Building from source with Composer is documented in [DEVELOPMENT.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/DEVELOPMENT.md).
