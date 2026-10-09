# Quick OTP Mail PHP v1.0.0-beta-2

A PHP email and OTP dashboard for CyberPanel Free, OpenLiteSpeed and Apache, using MySQL or MariaDB.

## Quick installation: upload, extract, open your website

**v1.0.0-beta-2** is a beta prerelease. [Download the ready-to-install website ZIP](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/releases/download/v1.0.0-beta-2/Quick-Otp-Email-Check-PHP_v1.0.0-beta-2-website.zip) or open the [release page and SHA-256 checksums](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/releases/tag/v1.0.0-beta-2). Use this version for new installations; beta_1 is retained for historical reference. Do not use GitHub's **Source code (zip)** for upload-only installation.

1. Create a website, enable SSL and create a MySQL/MariaDB database in CyberPanel. Select **PHP 8.3 or later**. Keep the website document root at `/home/domain.com/public_html/`.
2. Upload **`Quick-Otp-Email-Check-PHP_v1.0.0-beta-2-website.zip`** into **`/home/domain.com/`**, then extract it **there**, allowing replacement of application files with the same names. Use SFTP or a file manager that can access the website's home directory. Keep hidden `.htaccess` files and delete the uploaded ZIP afterwards. The archive merges its `public_html/` into the existing directory and places `quickotp-private/` beside it. It does not add a version-named wrapper.
3. **Set your installation password before opening setup.** Copy `/home/domain.com/quickotp-private/install-password.example.php` to **`install-password.php`** in the same directory. Edit the last line, replacing `return '';` with `return 'YOUR_UNIQUE_INSTALLATION_PASSWORD';`. Choose your own unique, preferably randomly generated password of **at least 16 characters and at most 256 bytes** (16–256 characters when using ASCII); the uppercase value is a placeholder and will be rejected if left unchanged. Use letters, numbers and symbols without a single quote or backslash to keep PHP editing simple. Keep the PHP access guard above the last line. If your file manager allows it, set this configured file to permission `600` under the website owner.
4. Open **`https://domain.com/`**. Enter that installation password, the database details and your chosen Admin password twice. Database host is normally `localhost`; database port is normally `3306`. The installation password and Admin password are separate.
5. Click **Install**, then log in with **`admin`** and the Admin password you just chose. In **Settings**, add your email domains and configure IMAP/App Password.

Choose **`/home/domain.com/` itself** as the extraction destination. Turn off any option that creates a folder named after the ZIP. After extraction:

```text
/home/domain.com/
├── public_html/
│   ├── index.php
│   ├── install.php
│   ├── .htaccess
│   └── app.css, app.js, ...
├── quickotp-private/
│   ├── install-password.example.php
│   ├── src/, views/, vendor/
│   └── storage/
├── README.md
└── other installation documents
```

`public_html` remains the only web document root. Configuration, libraries, templates and storage stay outside it. Extraction replaces matching files and keeps unrelated existing files; it does not delete and recreate `public_html`. Use a new, dedicated website for initial setup.

If you prefer SSH, after uploading the website ZIP to `/home/domain.com/`, run:

```bash
cd /home/domain.com/
unzip -o Quick-Otp-Email-Check-PHP_v1.0.0-beta-2-website.zip
```

The `-o` option overwrites matching files. Continue with step 3. Set both `public_html` and `quickotp-private` to the website owner; if PHP uses `open_basedir`, it must allow `/home/domain.com/quickotp-private/`. Do not make `/home/domain.com/` the public document root.

Composer is not needed for this package. Terminal commands are optional when your file manager can extract into the website home directory. Setup is locked until a valid installation-password file exists, and the password is verified before attempting a database connection. The configured file is deleted after successful installation; never upload it to GitHub or include it in a shared ZIP. An existing installation with `config.php` does not need this password again.

If setup fails, the form retains all valid-length entries, including password fields, so you can correct the error and try again. Entries are only retained for a valid same-origin setup session; passwords are not saved to the session, browser storage or URL. A rejected or expired setup session requires a fresh submission.

Install on a new, dedicated website; do not extract a fresh-install ZIP over an existing installation.

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
- The website's PHP user must be able to read/write the application files and `quickotp-private/storage` outside `public_html`. Set both directories to the website owner and allow the private directory in `open_basedir` when enabled. Do not set permissions to `777`.
- Outbound HTTPS and IMAP/TLS access, normally port **993**.

The recommended package keeps `quickotp-private` outside the web document root. Rewrite/Auto Load `.htaccess` is still needed for application routes. Existing installations with the private directory inside the document root retain an additional HTTP protection check before setup can save credentials. Detailed hosting troubleshooting is in [CYBERPANEL.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/CYBERPANEL.md).

Cloudflare can remain enabled. Use **Full (strict)** with a valid certificate on the origin and open the website through HTTPS.

### Setup shows a maintenance error immediately after extraction

The published beta may show this message when PHP cannot open `quickotp-private/storage/maintenance.lock`, including a permissions problem. For a **fresh installation**, open the website's **File Manager → Fix Permissions** in CyberPanel, then reload `/install.php`. The website's PHP user must own or be able to write to the extracted application files and storage.

If the message appeared after an update, check whether `quickotp-private/storage/update-pending.json` exists and follow the recovery instructions in CYBERPANEL.md. Do not remove this marker or delete lock files while an update may be running.

## Features

- Dashboard, Quick OTP, generated address history, Settings, User, System status.
- Vietnamese/USA/Canada usernames, random usernames, 16-character Crypto-style usernames and custom prefixes.
- Domain lists with pagination; email history defaults to 10 rows with 10/20/50/100 choices.
- Email content and OTP copying, with automatic checks every 5 seconds while the page is open.
- Light/dark appearance and password changes.
- Bcrypt login passwords, encrypted IMAP App Passwords, authenticated sessions and CSRF protection.
- Authenticated updates from this project's PHP releases, with checksums, a recovery copy and rollback on failure.

Email domains must have catch-all or aliases at your mail provider. Generating an address does not create a mailbox. Neither the `imap` nor `mailparse` PHP extension is required.

## Updates and manual backups

Backup & Restore has been removed from the menu, pages and application APIs. For a complete manual backup, export the database with your hosting tools and securely copy **`quickotp-private/config.php`** and **`quickotp-private/storage/`** beside `public_html`. Config contains the key used to decrypt IMAP credentials. Store these copies outside the public website and never publish them.

To update, use **System status → Check for updates → Update now** and confirm your current Admin password. The application verifies the package before briefly entering maintenance and replacing the source. Database contents, configuration and storage are preserved. An encrypted database snapshot and source recovery copy are kept internally for update recovery; there is no user-facing backup/restore page. The recommended split layout and previous installations with `quickotp-private` inside the document root are both supported.

**Upgrading from beta_1:** its old release parser does not recognize the new `-beta-2` tag spelling. Apply this update manually once, preserving `config.php` and `storage`; subsequent versions understand both spellings. With the canonical ZIP, copy the contents of `public_html/` into your existing document root and merge `quickotp-private/` into your existing private directory. Take a full manual backup first; do not recreate the database or run setup again. Remove obsolete `backup.js` and `quickotp-private/views/backup.html` if they remain from the old version. Refresh PHP/OPcache after replacement.

The ZIP without `-website` is the canonical update package and contains a version-named wrapper. The `-website.zip` has no wrapper and extracts directly into `/home/domain.com/`. For fresh installations, use the **`-website.zip`** package.

### Moving an existing private directory outside public_html

For an existing installation, take a full backup and temporarily stop website requests through your hosting controls. Move the **entire** `public_html/quickotp-private/` directory, including `config.php` and `storage/`, to `/home/domain.com/quickotp-private/`. Preserve ownership and permissions and allow the new path in PHP's `open_basedir` if enabled. Then merge the updated application files into the two directories and restart the website's PHP/LSAPI workers to refresh OPcache. Keep the web document root at `public_html`; do not rerun setup. The database itself stays in MySQL/MariaDB.

Do not leave two private directories. If both contain `bootstrap.php`, the entry points return a maintenance error instead of choosing one. Before removing a duplicate, identify and preserve the directory containing the current configuration and runtime data.

If you update manually, back up first, replace only application files and preserve `config.php`, any locally configured `install-password.php` and `storage`. Do not run setup again on an existing database. See [CYBERPANEL.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/CYBERPANEL.md) for recovery steps and the older layout.

Developer instructions are in [DEVELOPMENT.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/DEVELOPMENT.md).
