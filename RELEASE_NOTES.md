Quick OTP Mail PHP **v1.0.0-beta-6** — beta prerelease.

The header avatar opens **My Account** and **Logout**. Manage your full name, contact email, Telegram contact and login password on the dedicated `/my-account.html` page. The Settings User tab is removed; old links redirect to My Account. Contact details are optional and independent of IMAP accounts. The login username stays unchanged.

The lower sidebar contains **EN | VI** language controls with the light/dark button beside them. Language is remembered in the browser; appearance is saved on the server. Workspace, Quick OTP and authentication screens use the selected language. Labels, status messages, pagination and dates are translated; mail content and personal details remain unchanged.

Existing websites create the additive `qotp_user_profiles` table automatically when an authenticated profile is first loaded. The database user needs CREATE permission for this initial request. Existing schema manifests remain compatible with beta-5 updates, and recovery snapshots include profiles when present. Password changes retain current-password verification, rate limits and session/CSRF rotation, and invalidate other login sessions.

Downloads:

- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-6-website.zip`: bundled dependencies; fresh installation; extract into `/home/domain.com/`.
- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-6.zip`: canonical update package.
- `checksums-php.txt`: SHA-256 checksums for both ZIPs.

Keep `public_html/` as the only document root, with `quickotp-private/` beside it. Root extraction requires the ownership repair documented in README. Existing beta-2/beta-3/beta-4/beta-5 websites can use **System status → Check for updates → Update now**, confirming the current Admin password. Back up the database, configuration and storage first. Do not run fresh setup again. Beta_1 needs the one-time manual update documented in README.

Validation includes PHP unit/updater checks, isolated PHP 8.3 and 8.4 integration, real-browser account/profile/password/logout and multiple-mailbox tests, English/Vietnamese translation checks, mobile layout and private-directory protection. Source and installer archives are scanned for sensitive information. Runtime configuration, databases, storage data, backups and local instruction files are excluded from packages.
