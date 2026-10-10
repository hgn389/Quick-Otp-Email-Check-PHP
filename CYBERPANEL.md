# CyberPanel installation and recovery

## Upload-only installation (v1.0.0-beta-6)

Use `Quick-Otp-Email-Check-PHP_v1.0.0-beta-6-website.zip` and extract directly into **`/home/domain.com/`**, not `public_html`. The archive has no wrapper directory. Allow replacement of matching files so its `public_html/` merges into the existing website directory. It does not delete unrelated files. Keep the website document root at `/home/domain.com/public_html/`.

For installation without Terminal, open **Websites → List Websites → Manage → File Manager** for this domain. Navigate to `/home/domain.com/`, upload the website ZIP and extract it there. Use the website-specific File Manager so files are created as the website's PHP user; avoid extraction through a server-wide root File Manager. Wait for extraction to finish before opening the website.

If your panel cannot access or extract into the website home, extract locally and upload the two application directories with an account that writes as the website's PHP user and can access `/home/domain.com/`. SFTP requires SSH access for that account; an FTP account restricted to `public_html` cannot upload its sibling private directory. Ask your host for access if needed. Root uploads/extraction can leave the private directory unwritable, and PHP cannot change ownership of root-owned files. This workflow does not repair pre-existing root-owned directories.

The package includes dependencies; Composer is not needed. Download the beta-6 website ZIP and checksums from the release links in README. See [README's no-Terminal installation steps](https://github.com/hgn389/Quick-Otp-Email-Check-PHP#install-without-terminal) for the full procedure.

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

Create a database/user in CyberPanel, then open the website and enter the database settings and your chosen Admin password twice. No installation-password file, installation token or manual configuration editing is required. Valid setup errors retain form entries.

Use PHP 8.3+ with `pdo_mysql`, `openssl`, `mbstring`, `iconv`, `zlib` and `curl`; add `zip` and `tokenizer` for updates. Grant the website's PHP user read/write access to both extracted application directories, especially private storage. If `open_basedir` is enabled, include `/home/domain.com/quickotp-private/`. Do not use `777`.

OpenLiteSpeed needs rewrite and Auto Load `.htaccess` for application routes. Private files are outside the document root. For the older layout inside `public_html`, keep both `.htaccess` files; setup verifies HTTP denial with temporary random canaries before saving credentials.

Use HTTPS. For Cloudflare, use Full (strict) with a valid origin certificate. If setup rejects an Origin, verify that the browser URL, request Origin, server scheme and hostname match; clear stale setup cookies and try again. Do not disable origin or CSRF validation.

Delete the uploaded ZIP after extraction. Store backups outside the public document root.

## Storage permissions and maintenance messages

A maintenance message on a new installation can mean PHP cannot create or open `quickotp-private/storage/maintenance.lock`. For a fresh installation, check ownership and permissions for **both** `public_html` and its sibling `quickotp-private`, then retry setup. The panel's File Manager **Fix Permissions** action may help; verify that private storage outside `public_html` is writable as well. CyberPanel documents this action in its [permissions troubleshooting](https://cyberpanel.net/KnowledgeBase/home/how-to-fix-ssl-issues-in-cyberpanel/).

If the ZIP was extracted as root, the files can remain root-owned even with mode 755/644; the website PHP user still cannot write to storage. Use the guarded ownership repair in [README](https://github.com/hgn389/Quick-Otp-Email-Check-PHP#setup-shows-a-maintenance-error-immediately-after-extraction), after verifying that public_html belongs to the website PHP user. The repair includes both directories and keeps private files at 600/private directories at 700. A panel repair limited to public_html does not repair its sibling. If ownership is already correct, inspect the website PHP error log and its open_basedir setting, filesystem mount and available disk/inodes; CLI PHP may use different settings.

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

Fresh installations do not use `install-password.php` or `install-token.txt`. Files left over from previous versions are ignored. Existing installed websites keep `config.php` and do not run setup again.

GitHub's Source code ZIP is a developer checkout without bundled dependencies. Use the ready-made website ZIP for browser installation. Building from source with Composer is documented in [DEVELOPMENT.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/DEVELOPMENT.md).
