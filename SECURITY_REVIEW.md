# Security review

## v1.0.0-beta-3 patch review

Split-directory selection now checks for a complete public_html entry-point pair before considering the legacy layout, preventing unrelated website-home files from breaking setup or updater/recovery path detection. Web requests still require the selected directory to match DOCUMENT_ROOT. Standalone recovery uses the validated journal layout and canonical public directory, so missing/broken entry points do not prevent rollback. Regression tests cover home placeholders, missing-entry-point recovery and HTTP setup, while retaining legacy-layout protection checks.

The System status release link accepts only this repository's HTTPS stable or supported beta-tag URLs, without query strings or fragments. Tests cover both beta spellings and rejection of invalid/external URLs. Login help now points to the database block records; the permanent-block policy is unchanged.

## v1.0.0-beta-2 split-directory installer

The website package extracts into the website home directory with sibling `public_html` and `quickotp-private` directories. Only `public_html` is the document root, so configuration, source libraries, dependencies, templates and runtime storage are outside direct HTTP access. Entry points stop if both supported private-directory locations contain a bootstrap, avoiding ambiguous configuration after migration. PHP must have filesystem permissions and any required open_basedir access to the sibling directory.

Previous installations with private files inside the document root remain supported. Root rewrite rules and private `.htaccess` deny HTTP access. Setup verifies this older layout using a pinned local HTTP check with harmless temporary canaries before storing credentials. Probe TLS verification is disabled only for this server-pinned credential-free check; external update and IMAP connections still verify TLS certificates.

Config PHP has a direct-access guard and is written exclusively with mode 0600. Setup retains same-origin/CSRF validation, a session expiry, an exclusive installation lock, existing-database checks and the installed configuration guard. The installer uses a same-origin referrer policy so browser form POSTs preserve their Origin; cross-site and opaque Origins remain rejected. Fresh installations require an administrator-defined password of at least 16 UTF-8 characters and at most 256 bytes in the private, ignored `install-password.php` file. Setup is disabled when the file is missing, malformed, a symlink, empty or still contains the documented placeholder. File output is discarded so a missing PHP opening tag cannot expose the secret through setup. OPcache is invalidated for this file so manual password edits take effect even if timestamp validation is disabled. Password comparison occurs before database access. Only an empty guarded example is packaged; the configured file is deleted after success and cannot be an update target. Valid same-origin/CSRF submissions retain bounded, HTML-escaped fields after errors, including passwords, in the no-store response only; setup secrets are not persisted in sessions, browser storage or URLs.

Updates retain virtual package paths, map public files to the active layout and journal that layout for independent rollback. Config and runtime storage are never update targets. The canonical update ZIP retains the existing file whitelist; private `.htaccess` is supplied by the separate website ZIP as additional protection.

## Application protections

Login passwords use bcrypt. Sessions and CSRF tokens are stored in the database; changing a password revokes other sessions and rotates the current session. IMAP secrets and internal update snapshots use authenticated encryption. The former Backup & Restore page and APIs are removed. Sensitive operations require the current Admin password and CSRF validation.

IMAP and update downloads verify TLS certificates. IMAP hosts are restricted to public addresses, recipients are matched exactly, message sizes are bounded and HTML is converted to text. The UI displays email content as text under a restrictive Content Security Policy.

Update ZIPs are checked for checksum/manifest mismatches, traversal, duplicate entries, symlinks, unsupported PHP/schema versions and invalid PHP syntax. Configuration and runtime data are not update targets. Backups and independent source recovery are retained before replacement.

Install on a dedicated HTTPS website, finish setup immediately and keep backups outside the public document root. For the legacy layout inside the document root, private-directory protection depends on the webserver continuing to enforce the supplied rewrite rules.
