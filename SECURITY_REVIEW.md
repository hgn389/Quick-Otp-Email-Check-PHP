# Security review

## v1.0.0-beta_1 upload-only installer

The website package places private files under a denied `quickotp-private` directory. The root rewrite rules reject that path before serving existing files, and private `.htaccess` adds its own deny rule. Setup uses a pinned local HTTP check with harmless temporary canaries and fails before storing credentials if protection cannot be verified. Probe TLS verification is disabled only for this server-pinned, credential-free check so an origin-only certificate can be used; external update and IMAP connections still verify TLS certificates.

Config PHP has a direct-access guard and is written exclusively with mode 0600. Setup retains same-origin/CSRF validation, a session expiry, an exclusive installation lock, existing-database checks and the installed configuration guard. The installer uses a same-origin referrer policy so browser form POSTs preserve their Origin; cross-site and opaque Origins remain rejected. Fresh installations require no manual token; existing token files retain their previous protection. As with a public WordPress installer, complete setup immediately on a new dedicated site.

Updates retain virtual package paths, map public files to the active layout and journal that layout for independent rollback. Config and runtime storage are never update targets. The canonical update ZIP retains the existing file whitelist; private `.htaccess` is supplied by the separate website ZIP and the updated root rules remain authoritative.

## Application protections

Login passwords use bcrypt. Sessions and CSRF tokens are stored in the database; changing a password or restoring a backup revokes existing sessions. IMAP secrets and password-protected `.qotp` backups use authenticated encryption. Sensitive operations require the current Admin password and CSRF validation.

IMAP and update downloads verify TLS certificates. IMAP hosts are restricted to public addresses, recipients are matched exactly, message sizes are bounded and HTML is converted to text. The UI displays email content as text under a restrictive Content Security Policy.

Update ZIPs are checked for checksum/manifest mismatches, traversal, duplicate entries, symlinks, unsupported PHP/schema versions and invalid PHP syntax. Configuration and runtime data are not update targets. Backups and independent source recovery are retained before replacement.

Install on a dedicated HTTPS website, finish setup immediately and keep backups outside the public document root. Private-directory protection depends on the webserver continuing to enforce the supplied rewrite rules.
