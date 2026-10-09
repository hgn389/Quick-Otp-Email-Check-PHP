Quick OTP Mail PHP **v1.0.0-beta-2** — beta prerelease.

For a fresh CyberPanel website, use **Quick-Otp-Email-Check-PHP_v1.0.0-beta-2-website.zip**. Create the website, SSL and database; extract directly into `/home/domain.com/`, keeping hidden `.htaccess` files. The archive creates sibling `public_html/` and `quickotp-private/` directories, merging and replacing matching public application files. Keep only `public_html` as the web document root. Copy `quickotp-private/install-password.example.php` to `install-password.php` and set a unique password of at least 16 characters and at most 256 bytes before opening setup. Enter it with the database details and your chosen Admin password.

- Split-directory deployment keeps configuration and runtime storage outside the web document root; ambiguous duplicate private directories are rejected.
- Malformed installation-password files cannot disclose their contents through setup; manual password edits take effect with OPcache enabled.
- Required installation password prevents unauthorized database setup on a fresh website. Only an empty template is distributed; the configured secret is ignored and deleted after successful installation.
- Failed valid setup submissions retain form values, including passwords, without session or URL persistence.
- Header navigation links to Quick OTP and Settings.
- Backup & Restore pages and APIs are removed. Internal update snapshots and source recovery remain available for update failures.
- Clear bootstrap errors distinguish storage permissions, active maintenance and unfinished update recovery.
- Hyphenated beta versions remain compatible with legacy beta release discovery.

Packages:

- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-2-website.zip`: bundled dependencies, no wrapper, extract into the website home directory; private files stay outside public_html.
- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-2.zip`: canonical package for the built-in updater and split-directory layout.
- `checksums-php.txt`: SHA-256 for both packages.

Existing websites retain database data, configuration and storage. Never run fresh setup on an existing database. GitHub's automatic Source code archives do not bundle dependencies.

The beta_1 updater cannot discover the newly hyphenated beta-2 tag. Existing beta_1 websites need a one-time manual source update, preserving configuration and storage, before automatic discovery supports the new spelling.
