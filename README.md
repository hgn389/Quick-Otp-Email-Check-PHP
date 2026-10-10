# Quick OTP Mail PHP v1.0.0-beta-6

A PHP email and OTP dashboard for CyberPanel Free, OpenLiteSpeed and Apache, using MySQL or MariaDB.

## Quick installation: upload, extract, open your website

**v1.0.0-beta-6** is a beta prerelease. [Download the ready-to-install website ZIP](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/releases/download/v1.0.0-beta-6/Quick-Otp-Email-Check-PHP_v1.0.0-beta-6-website.zip) or open the [release page and SHA-256 checksums](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/releases/tag/v1.0.0-beta-6). Dependencies are bundled. Extract, open your website and enter database details plus your chosen Admin password. No installation-password file or token needs to be created.

Use this version for new installations. Earlier releases remain available for historical reference; beta-3 and earlier used the previous installation-password step. Do not use GitHub's **Source code (zip)** for upload-only installation.

1. Create a website, enable SSL and create a MySQL/MariaDB database in CyberPanel. Select **PHP 8.3 or later**. Keep the website document root at `/home/domain.com/public_html/`.
2. Upload **`Quick-Otp-Email-Check-PHP_v1.0.0-beta-6-website.zip`** into **`/home/domain.com/`**, then extract it **there**, allowing replacement of application files with the same names. Use the website-specific File Manager or upload with the website account, as described below. Keep hidden `.htaccess` files and delete the uploaded ZIP afterwards. The archive merges its `public_html/` into the existing directory and places `quickotp-private/` beside it. It does not add a version-named wrapper.
3. Open **`https://domain.com/`**. Enter the database details and your chosen Admin password twice. Database host is normally `localhost`; database port is normally `3306`.
4. Click **Install**, then log in with **`admin`** and the Admin password you just chose. In **Settings**, add your email domains and configure IMAP/App Password.

**Extracting through SSH as root?** Root-owned files can prevent setup from opening and cause `storage_unavailable`. Read the [storage permissions fix](#setup-shows-a-maintenance-error-immediately-after-extraction) before continuing. For a fresh installation without Terminal, follow the website File Manager steps below.

### Install without Terminal

In CyberPanel, open **Websites → List Websites → Manage** for the domain, then its **File Manager**. Navigate to `/home/domain.com/`, upload the website ZIP and extract it into that same directory. Wait until extraction finishes, delete the uploaded ZIP, then open `https://domain.com/` and complete the form. Use the domain's File Manager, rather than a server-wide root File Manager, so extraction runs as the website account. CyberPanel's [website extraction implementation](https://github.com/usmannasir/cyberpanel/blob/stable/filemanager/filemanager.py) selects the website's PHP user for this operation.

If your File Manager cannot access the website home or cannot extract there, unzip the package on your computer. Upload its `public_html/` contents to `/home/domain.com/public_html/` and its `quickotp-private/` directory to `/home/domain.com/quickotp-private/` using a file-transfer account that writes as the website's PHP user. SFTP works only if SSH access is enabled for that account. Keep hidden files. An FTP account restricted to `public_html` cannot upload the sibling private directory; use the website File Manager or ask your host for access.

This workflow needs no Terminal commands, Composer or separate installation password. It applies to a new website where the website account can create both directories. Uploading or extracting as root can recreate the ownership problem; PHP cannot automatically change the owner of root-owned files. Existing root-owned directories need a hosting administrator's ownership repair before they can be reused.

Choose **`/home/domain.com/` itself** as the extraction destination. Turn off any option that creates a folder named after the ZIP. After extraction:

```text
/home/domain.com/
├── public_html/
│   ├── index.php
│   ├── install.php
│   ├── .htaccess
│   └── app.css, app.js, ...
├── quickotp-private/
│   ├── src/, views/, vendor/
│   └── storage/
├── README.md
└── other installation documents
```

`public_html` remains the only web document root. Configuration, libraries, templates and storage stay outside it. Extraction replaces matching files and keeps unrelated existing files; it does not delete and recreate `public_html`. Use a new, dedicated website for initial setup.

If you prefer SSH, log in as the website's PHP user (with SSH access enabled), then upload the website ZIP to `/home/domain.com/` and run:

```bash
cd /home/domain.com/
unzip -o Quick-Otp-Email-Check-PHP_v1.0.0-beta-6-website.zip
```

The `-o` option overwrites matching files. If you extract as root, files can remain owned by root and PHP will not be able to create its storage lock. Correct ownership for both directories using the [storage permissions instructions below](#setup-shows-a-maintenance-error-immediately-after-extraction), then continue with step 3. Set both `public_html` and `quickotp-private` to the website owner; if PHP uses `open_basedir`, it must allow `/home/domain.com/quickotp-private/`. Do not make `/home/domain.com/` the public document root.

Composer is not needed for this package. Terminal commands are optional when your file manager can extract into the website home directory and set the correct ownership. There is no separate installation password or token. Browser setup keeps same-origin/CSRF checks, an installation lock and an installed-configuration guard. Existing installations with `config.php` do not run setup again.

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

The page may return this error instead of the installation form (the message is currently in Vietnamese):

```json
{"error":"PHP không mở/tạo được quickotp-private/storage/maintenance.lock. Kiểm tra chủ sở hữu, quyền ghi và dung lượng đĩa. Nếu vừa cài mới, mở File Manager của website trong CyberPanel và bấm Fix Permissions, rồi tải lại trang.","code":"storage_unavailable"}
```

`storage_unavailable` means PHP could not create/open `quickotp-private/storage/maintenance.lock`. A common cause is extracting the ZIP as root: the PHP website user cannot write to the root-owned private directory. CyberPanel's **File Manager → Fix Permissions** repairs `public_html` but may leave its sibling `quickotp-private` unchanged. For future fresh installations, use the [no-Terminal workflow above](#install-without-terminal) to create the private directory as the website user from the start.

Directory mode `755` lets the website user read root-owned files but does not let it create files in a root-owned directory. Changing permissions to `755` again will not fix the owner. PHP runs with the website user's privileges and cannot change ownership of root-owned files, so the application cannot repair this automatically from a button in the browser.

**If the files are already root-owned:** ask your hosting administrator to set both application directories to the website's PHP user, or use the root SSH repair below. The standard Fix Permissions action may not cover the private directory outside `public_html`. Do not delete configuration/storage, reinstall the database or manually create an empty lock file to bypass this error.

For a fresh installation, first confirm in CyberPanel that the owner of `public_html` is the website's PHP user. Then SSH as root and run the following, replacing `domain.com`. The commands stop if `public_html` is still root-owned; correct its owner in the panel before continuing. They change ownership/permissions without deleting configuration, storage or lock files:

```bash
(
  set -euo pipefail
  cd /home/domain.com/
  [[ -d public_html && ! -L public_html && -d quickotp-private && ! -L quickotp-private ]] || { echo 'Check the extraction directory and avoid symlinked application directories.' >&2; exit 1; }
  [[ $(id -u) == 0 ]] || { echo 'Run this ownership repair as root.' >&2; exit 1; }
  [[ $(stat -c '%u' public_html) != 0 ]] || { echo 'public_html is root-owned. Set it to the website PHP user in CyberPanel first.' >&2; exit 1; }
  website_owner=$(stat -c '%u:%g' public_html)
  chown -hR -- "$website_owner" public_html quickotp-private
  find public_html -type d -exec chmod 0755 {} +
  find public_html -type f -exec chmod 0644 {} +
  find quickotp-private -type d -exec chmod 0700 {} +
  find quickotp-private -type f -exec chmod 0600 {} +
)
```

Reload `/install.php` after the repair. PHP creates `maintenance.lock` automatically when storage is writable; a missing lock file on a fresh installation is expected. Ownership repair normally needs to be done only once, but uploading/extracting as root again can recreate the problem. Do not use `777`. If the repair was already applied and the error remains, compare the actual website PHP process user with the owners of **both storage and its existing lock file**, rather than repeating chmod. Run these read-only checks from the website home:

```bash
pwd
stat -c '%U:%G mode=%a %n' public_html quickotp-private quickotp-private/storage quickotp-private/storage/maintenance.lock
ps -eo user,group,comm | awk '$3 ~ /lsphp/ {print}'
df -h .
df -i .
```

A missing lock file means PHP must be able to create it in storage; an existing lock needs read/write access for the website PHP user. On servers hosting several websites, process output can show several PHP users: confirm the correct site's PHP/SuEXEC user in its hosting configuration before changing ownership. The owner of public_html alone does not establish that user. Check the website's PHP error log for `open_basedir` restrictions, a read-only filesystem or disk/inode exhaustion. Allow `/home/domain.com/quickotp-private/` in the website's `open_basedir` configuration when enabled, retaining other required allowed paths. The CLI PHP configuration can differ from the website's PHP configuration.

If the message appeared after an update, check whether `quickotp-private/storage/update-pending.json` exists and follow the recovery instructions in CYBERPANEL.md. Do not remove this marker or delete lock files while an update may be running.

## My Account, language and appearance

Click the avatar in the upper-right header to open **My Account** or **Logout**. **My Account** is a separate page at `/my-account.html`, where you can save your full name, contact email and Telegram contact (`@username` or `https://t.me/username`), or change your login password. The login username stays unchanged. Contact details are optional and separate from IMAP credentials. Password changes require the current password and sign out other sessions. Old `/settings.html#user` links redirect to this page.

At the bottom of the sidebar, **EN | VI** selects English or Vietnamese. The choice is remembered in your browser and the page reloads to apply it. The adjacent appearance button switches light/dark mode and saves the choice on the server. Quick OTP uses the same language and appearance. Message content and personal contact details are never translated.

Existing installations create a small `qotp_user_profiles` table automatically the first time an authenticated profile is loaded. The configured database user needs `CREATE` permission for that first request, as during installation. The base schema and existing data stay compatible with beta-5's updater. Update snapshots include contact profiles once the table exists.

## Multiple email domains

Open **Settings → Email Config – Connect** and click **+ Thêm email** in **List email domain**. Enter the provider, IMAP host/port, full mailbox address, App Password and folder. Test the connection, then save the account. For example, saving `mailbox@example.com` automatically adds `example.com` to **DOMAIN MẶC ĐỊNH** in **Gen Email Username Setting**, without a separate generator save. The first real mailbox replaces the initial `yourdomain.com` placeholder if that is still the only domain.

The list shows the mailbox, domain, server/folder and connection-test result, with **Edit**, **Test** and **Delete** actions and 20 rows per page. Saved configuration and successful connection tests are distinguished; test results belong to the current page session and do not promise continuous connectivity. App Passwords are encrypted separately and are not included in the account-list response. Editing an account retains the existing password unless it is replaced; changing its server or username requires its password again.

Each domain has one main mailbox, receiving aliases or catch-all mail for that domain. Quick OTP and Dashboard select the mailbox by the requested email's domain; accounts use separate polling locks and caches. With multiple accounts, a domain without a matching connection is reported as unconfigured. A single existing mailbox retains the previous fallback for additional alias domains. Generating addresses does not create provider mailboxes or configure forwarding/catch-all.

Up to 100 accounts and 100 generator domains are supported. Domains belonging to saved connections remain in the generator list, including when an older form is saved. Delete a connection first if you want to remove its domain. Deleting a connection preserves generator domains, generated addresses and saved messages; removing a domain is a separate explicit action. The existing mailbox configuration is preserved when upgrading; no database migration or reinstallation is required.

## Features

- Dashboard, Quick OTP, generated address history, Settings, User, System status.
- Vietnamese/USA/Canada usernames, random usernames, 16-character Crypto-style usernames and custom prefixes.
- Domain lists with pagination; email history defaults to 10 rows with 10/20/50/100 choices.
- Email content and OTP copying, with automatic checks every 5 seconds while the page is open.
- Dark appearance by default for the dashboard, Settings, System status and Quick OTP; light/system options and password changes. Saved appearance choices are preserved.
- Bcrypt login passwords, encrypted IMAP App Passwords, authenticated sessions and CSRF protection.
- Authenticated updates from this project's PHP releases, with checksums, a recovery copy and rollback on failure.

Email domains must have catch-all or aliases at your mail provider. Generating an address does not create a mailbox. Neither the `imap` nor `mailparse` PHP extension is required.

## Unlocking a blocked login IP

After five failed logins, the PHP edition stores a permanent block in the database, not in a `blacklist.txt` file. In CyberPanel/phpMyAdmin, open this website's database and identify the blocked address in `qotp_blocked_ips`. Replace `YOUR_BLOCKED_IP` below with that exact address, then run:

```sql
START TRANSACTION;
DELETE FROM qotp_blocked_ips WHERE ip_address = 'YOUR_BLOCKED_IP';
DELETE FROM qotp_login_failures WHERE ip_address = 'YOUR_BLOCKED_IP';
COMMIT;
```

Both records must be cleared so the old failure count does not immediately block the IP again. This does not change the Admin password. Behind a reverse proxy, the recorded address is the IP passed to PHP as `REMOTE_ADDR`; configure trusted client-IP handling in your webserver rather than trusting arbitrary request headers in application code.

## Updates and manual backups

Backup & Restore has been removed from the menu, pages and application APIs. For a complete manual backup, export the database with your hosting tools and securely copy **`quickotp-private/config.php`** and **`quickotp-private/storage/`** beside `public_html`. Config contains the key used to decrypt IMAP credentials. Store these copies outside the public website and never publish them.

To update, use **System status → Check for updates → Update now** and confirm your current Admin password. The application verifies the package before briefly entering maintenance and replacing the source. Database contents, configuration and storage are preserved. An encrypted database snapshot and source recovery copy are kept internally for update recovery; there is no user-facing backup/restore page. The recommended split layout and previous installations with `quickotp-private` inside the document root are both supported.

**Upgrading from beta_1:** its old release parser does not recognize the new `-beta-2` tag spelling. Apply this update manually once, preserving `config.php` and `storage`; subsequent versions understand both spellings. With the canonical ZIP, copy the contents of `public_html/` into your existing document root and merge `quickotp-private/` into your existing private directory. Take a full manual backup first; do not recreate the database or run setup again. Remove obsolete `backup.js` and `quickotp-private/views/backup.html` if they remain from the old version. Refresh PHP/OPcache after replacement.

The ZIP without `-website` is the canonical update package and contains a version-named wrapper. The `-website.zip` has no wrapper and extracts directly into `/home/domain.com/`. For fresh installations, use the **`-website.zip`** package.

### Moving an existing private directory outside public_html

For an existing installation, take a full backup and temporarily stop website requests through your hosting controls. Move the **entire** `public_html/quickotp-private/` directory, including `config.php` and `storage/`, to `/home/domain.com/quickotp-private/`. Preserve ownership and permissions and allow the new path in PHP's `open_basedir` if enabled. Then merge the updated application files into the two directories and restart the website's PHP/LSAPI workers to refresh OPcache. Keep the web document root at `public_html`; do not rerun setup. The database itself stays in MySQL/MariaDB.

Do not leave two private directories. If both contain `bootstrap.php`, the entry points return a maintenance error instead of choosing one. Before removing a duplicate, identify and preserve the directory containing the current configuration and runtime data.

If you update manually, back up first, replace only application files and preserve `config.php` and `storage`. Do not run setup again on an existing database. See [CYBERPANEL.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/CYBERPANEL.md) for recovery steps and the older layout.

Developer instructions are in [DEVELOPMENT.md](https://github.com/hgn389/Quick-Otp-Email-Check-PHP/blob/main/DEVELOPMENT.md).
