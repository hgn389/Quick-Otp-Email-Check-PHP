# Quick OTP Mail PHP v1.0.0-beta_1

A PHP email and OTP dashboard for CyberPanel Free, OpenLiteSpeed and Apache, using MySQL or MariaDB.

## Quick installation: upload, extract, open your website

**v1.0.0 beta_1** is a beta release. [Download the ready-to-upload website ZIP](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/releases/download/v1.0.0-beta_1/Quick-Otp-Email-Check-PHP_v1.0.0-beta_1-website.zip) or open the [release page and checksums](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/releases/tag/v1.0.0-beta_1). Do not use GitHub's **Source code (zip)** for this installation method.

1. Create a website, enable SSL and create a MySQL/MariaDB database in CyberPanel. Select **PHP 8.3 or later**.
2. Upload **`Quick-Otp-Email-Check-PHP_v1.0.0-beta_1-website.zip`** into `/home/domain.com/public_html/` with File Manager and extract it there. Keep the hidden `.htaccess` files. The resulting `index.php` must be directly inside `public_html`, without an extra folder. Delete the uploaded ZIP after extraction.
3. Open **`https://domain.com/`**. The installation page opens automatically. Enter the database name, database user and password, then choose and confirm your Admin password. Database host is normally `localhost`; database port is normally `3306`.
4. Click **Install**, then log in with **`admin`** and the password you just chose. In **Settings**, add your email domains and configure IMAP/App Password.

No terminal commands, Composer or manually created installation token are needed for this package. Install on a new, dedicated website; do not extract it over an existing installation. Complete setup immediately after uploading.

Replace `domain.com` with your own domain. This PHP edition uses the website's port: **443 for HTTPS** or **80 for HTTP**, unless you configured another port. It does not run a separate Go service.

```text
https://domain.com:443/
user: admin
Password: YOUR_ADMIN_PASSWORD
```

There is no default Admin password. Choose one with at least 10 characters.

### Hosting requirements

- PHP **8.3+** with `pdo_mysql`, `openssl`, `mbstring`, `iconv`, `zlib` and `curl`. Web updates also need `zip` and `tokenizer`.
- MySQL **8.0+** or MariaDB **10.6+**, using InnoDB.
- HTTPS and rewrite/Auto Load `.htaccess` enabled. Use the root of a dedicated website or subdomain.
- The website's PHP user must be able to write to the extracted files and `quickotp-private/storage`. Use the website owner; do not set permissions to `777`.
- Outbound HTTPS and IMAP/TLS access, normally port **993**.

The installer verifies that the webserver blocks access to `quickotp-private` before saving database credentials. If this check fails, keep both `.htaccess` files, enable rewrite/Auto Load `.htaccess` and restart OpenLiteSpeed. Detailed hosting troubleshooting is in [CYBERPANEL.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/CYBERPANEL.md).

Cloudflare can remain enabled. Use **Full (strict)** with a valid certificate on the origin and open the website through HTTPS.

## Features

- Dashboard, Quick OTP, generated address history, Settings, User, System status and Backup & Restore.
- Vietnamese/USA/Canada usernames, random usernames, 16-character Crypto-style usernames and custom prefixes.
- Domain lists with pagination; email history defaults to 10 rows with 10/20/50/100 choices.
- Email content and OTP copying, with automatic checks every 5 seconds while the page is open.
- Light/dark appearance and password changes.
- Bcrypt login passwords, encrypted IMAP App Passwords, authenticated sessions and CSRF protection.
- Password-protected `.qotp` backups and restore previews.
- Authenticated updates from this project's PHP releases, with checksums, a recovery copy and rollback on failure.

Email domains must have catch-all or aliases at your mail provider. Generating an address does not create a mailbox. Neither the `imap` nor `mailparse` PHP extension is required.

## Backup and updates

In **Backup & Restore**, enter your current Admin password and choose a backup password to download a `.qotp` file. To restore, select the file, enter its password, check the preview and confirm. You will need to log in again.

For a complete backup, save the database together with **`public_html/quickotp-private/config.php`** and **`public_html/quickotp-private/storage/`**. Keep the configuration file: it contains the key used to decrypt IMAP credentials. Store backups outside the public website. PHP backups are limited to 50,000 records, 16 MiB of data and 32 MiB per file; Go backups use a different format.

To update, use **System status → Check for updates → Update now** and confirm your current Admin password. The application verifies the package before briefly entering maintenance and replacing the source. Database contents, configuration and storage are preserved. Both the upload-only layout and installations with `quickotp-private` beside `public_html` are supported.

The ZIP without `-website` is kept for the built-in updater and previous installation layout. For fresh installations, use the **`-website.zip`** package.

If you update manually, back up first, replace only application files and preserve `config.php` and `storage`. Do not run setup again on an existing database. See [CYBERPANEL.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/CYBERPANEL.md) for recovery steps and the older layout.

Developer instructions are in [DEVELOPMENT.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/DEVELOPMENT.md).
