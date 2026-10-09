# CyberPanel installation and recovery

## Upload-only installation (v1.0.0-beta_1)

Use `Quick-Otp-Email-Check-PHP_v1.0.0-beta_1-website.zip`, extract directly into a new website's `public_html`, then open the website. The package includes dependencies. No Composer, SSH commands or installation token are required.

The resulting files are:

```text
/home/domain.com/public_html/
├── index.php
├── install.php
├── .htaccess
├── app.js, app.css and other assets
└── quickotp-private/
    ├── .htaccess
    ├── src/, views/ and vendor/
    ├── config.php                (created by setup)
    └── storage/                  (runtime files and encrypted backups)
```

Create a database and user in CyberPanel before opening setup. Use PHP 8.3+ with `pdo_mysql`, `openssl`, `mbstring`, `iconv`, `zlib` and `curl`; add `zip` and `tokenizer` for updates. Grant write access to the website's PHP user. Do not use `777`.

Keep the root and private `.htaccess` files. OpenLiteSpeed must have rewrite and Auto Load `.htaccess` enabled. If setup reports that private storage is not protected, enable these options and perform a graceful restart through CyberPanel. The check connects directly to the website's local listener and will stop setup if it cannot verify the public file or if a private test file can be downloaded. The test files contain random bytes and are removed after the check.

Use HTTPS. For Cloudflare, use Full (strict) with a valid origin certificate. If setup rejects an Origin, verify that the browser URL, request Origin, server scheme and hostname match; clear stale setup cookies and try again. Do not disable origin or CSRF validation.

Delete the uploaded ZIP after extraction. Finish setup immediately and retain secure backups outside public_html.

## Backup and recovery

Back up the database with `quickotp-private/config.php` and `quickotp-private/storage`. Config contains the database credentials and IMAP encryption key. `.qotp` files are encrypted application-data backups; they do not contain database connection details or application source.

The built-in updater supports both layouts. It downloads the canonical ZIP without `-website`, validates it, keeps the site available during download, then locks requests while replacing files. Configuration, runtime storage and database data are preserved. It requires PHP `zip`, `tokenizer`, writable application files and permission to invalidate OPcache.

Recovery copies are stored in `quickotp-private/storage/updates/UPDATE_ID/backup/`. The encrypted `database.qotp` uses the Admin password confirmed for that update. Source files and a standalone `restore.php` are stored alongside it.

After an interrupted update, use the website owner to run the matching recovery script. For the upload-only layout:

```bash
sudo -u WEBSITE_USER /usr/local/lsws/lsphp83/bin/php /home/domain.com/public_html/quickotp-private/storage/updates/UPDATE_ID/backup/restore.php
```

For the previous layout, omit `public_html/` in that path. Replace sample values and the PHP binary as needed. CLI recovery restores source files; it preserves configuration and current data. Restart the website's PHP/LSAPI workers afterwards if OPcache is enabled. Do not remove the maintenance marker before recovery is verified.

## Split-directory installations

The private directory may also stay beside `public_html`, outside the document root. The updater supports this layout and preserves configuration and storage. Do not extract a fresh website ZIP over an existing installation.

A pre-existing `install-token.txt` still requires its private 20–256 character token during setup. Fresh website ZIP installations do not need this file.

GitHub's Source code ZIP is a developer checkout without bundled dependencies. Use the ready-made website ZIP for browser installation. Building from source with Composer is documented in [DEVELOPMENT.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/DEVELOPMENT.md).
