Quick OTP Mail PHP **v1.0.0-beta-4** — beta prerelease.

Fresh setup no longer needs an installation password, token or manual PHP configuration edit. Extract the website ZIP, open the website, enter database details and choose the Admin password. Existing setup session/CSRF checks, installation locking and installed-configuration safeguards remain. Valid setup errors continue to retain form values.

For a new website, use `Quick-Otp-Email-Check-PHP_v1.0.0-beta-4-website.zip` and extract at `/home/domain.com/`, keeping `public_html/` as the only document root and `quickotp-private/` beside it. When extracting as root, set both directories to the website PHP user as documented in README.

Downloads:

- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-4-website.zip`: bundled dependencies; no wrapper; fresh installation.
- `Quick-Otp-Email-Check-PHP_v1.0.0-beta-4.zip`: canonical source-update package.
- `checksums-php.txt`: SHA-256 checksums for both ZIPs.

Configuration, databases, runtime storage and legacy installation secrets are excluded from packages. Existing websites preserve configuration and data and must not run fresh setup again.

Existing beta-2/beta-3 websites can use **System status → Check for updates → Update now** with their current Admin password. Beta_1 websites need the one-time manual source update documented in README.
