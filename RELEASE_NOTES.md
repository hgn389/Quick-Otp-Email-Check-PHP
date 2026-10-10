Quick OTP Mail PHP **v1.0.0-beta-5** — beta prerelease.

Manage multiple IMAP accounts in **Settings → Email Config – Connect → List email domain**. Add, edit, test or delete connections; the list shows 20 rows per page. Saving `mailbox@example.com` automatically adds `example.com` to generator defaults. Each domain uses one main mailbox receiving aliases/catch-all mail, with up to 100 accounts and 100 generator domains.

Dashboard and Quick OTP select the account by recipient domain, using separate polling locks and caches. With multiple accounts, unmatched domains are reported as unconfigured. Existing single-mailbox alias behavior and encrypted credentials are preserved; no database migration or reinstallation is required. Saved configurations and successful connection tests are shown separately. Deleting a connection keeps domains, generated addresses and saved messages.

The dashboard, Settings, System status and Quick OTP now default to dark appearance on fresh installations. Existing saved appearance choices remain unchanged.

For a new website, download `Quick-Otp-Email-Check-PHP_v1.0.0-beta-5-website.zip` and extract at `/home/domain.com/`. Keep `public_html/` as the only document root, with `quickotp-private/` beside it. Dependencies are bundled; complete setup in the browser without an installation password or token. Use the website-specific File Manager to extract as the website user. Root extraction requires the ownership repair documented in README.

Downloads:

- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-5-website.zip`: bundled dependencies; no wrapper; fresh installation.
- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-5.zip`: canonical update package.
- `checksums-php.txt`: SHA-256 checksums for both ZIPs.

Existing beta-2/beta-3/beta-4 websites can use **System status → Check for updates → Update now**, confirming the current Admin password. Back up the database, config and storage first. Do not run fresh setup again. Beta_1 needs the one-time manual source update documented in README.

Validation includes PHP unit/updater checks, integration tests under PHP 8.3 and 8.4, browser account management and mobile pagination, private-directory protection, and compatibility with the published beta-4 updater. Source and installer archives were scanned for sensitive information. Configuration, databases, runtime storage, backups and local instruction files are excluded from installer packages.
