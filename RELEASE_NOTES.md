Quick OTP Mail PHP **v1.0.0-beta-3** — beta patch prerelease.

This patch fixes split-directory detection when the website home contains unrelated `index.php` or `install.php` files, allows standalone update recovery even when an entry point is missing, restores official beta release links on the System status page, and corrects blocked-login help for the PHP database implementation. CI uses Node 24 actions and Ubuntu 24.04 with regression checks for the fixes.

For a fresh CyberPanel website, download **Quick-Otp-Email-Check-PHP_v1.0.0-beta-3-website.zip** and extract into **`/home/domain.com/`**. There is no wrapper directory. Keep `public_html/` as the only document root, with `quickotp-private/` beside it. Set your own installation password in `quickotp-private/install-password.php` using the empty example before opening setup. See README for the full installation instructions.

Existing beta-2 websites can use **System status → Check for updates → Update now**, with the current Admin password. Configuration, storage and database data are preserved. Beta_1 websites require the one-time manual update described in README. Do not run fresh setup on an existing database.

Downloads:

- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-3-website.zip`: bundled dependencies, no wrapper; fresh installation into the website home.
- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-3.zip`: canonical package used by the built-in updater.
- `checksums-php.txt`: SHA-256 checksums for both ZIPs.

Earlier releases and their downloads remain available for historical reference. Runtime configuration, installation passwords, databases and backups are excluded from both packages.
