# Changelog PHP

## v1.0.0-beta-6 — 2026-10-10

- Add a header avatar dropdown with My Account and Logout, plus a dedicated account page for full name, email, Telegram and password changes.
- Store optional contact profiles per authenticated user; preserve existing login usernames and password/session security.
- Replace the sidebar Admin block with English/Vietnamese controls and move the light/dark switch beside them. Remember browser language and save appearance across pages.
- Translate workspace and Quick OTP labels, statuses, pagination and dates without modifying user profile details or message content.
- Redirect legacy Settings User links to My Account and include profiles in update recovery snapshots.

## v1.0.0-beta-5 — 2026-10-10

- Manage up to 100 encrypted IMAP connections in List email domain, with add/edit/test/delete actions and 20-row pagination.
- Add mailbox domains to generator defaults atomically, deduplicate domains and retain active connection domains when saving Settings.
- Route mailbox polling by recipient domain and isolate account locks/caches; preserve legacy single-mailbox alias behavior and stored configuration without a schema change.
- Keep account passwords out of list responses and retain CSRF, TLS and server-side network validation.
- Default the dashboard, Settings, System status and Quick OTP to dark appearance on fresh installations and when no appearance preference is available. Preserve saved light, dark and system choices.
- Render these pages dark before scripts initialize and refresh theme-script asset URLs.

## v1.0.0-beta-4 — 2026-10-10

- Remove the separate installation password and its manual file/template setup. Fresh installations use database details and the chosen Admin password directly in the browser.
- Retain same-origin/CSRF validation, form retention, installation locking, existing-database checks and private-directory protection.
- Ignore obsolete local installation-password files without executing them; exclude legacy secrets from source packages.
- Clarify root extraction ownership repair for both public_html and sibling private storage.
- Update installer regressions, assets, footer version and installation documentation.

## v1.0.0-beta-3 — 2026-10-09

- Fix split-directory detection when unrelated index.php/install.php files exist in the website home.
- Restore interrupted updates using the saved layout even if a public entry point is missing; preserve unrelated website-home files.
- Show official beta release links on the System status page, including both supported beta tag spellings.
- Correct blocked-login help for the PHP database implementation and document clearing both the permanent block and failure count.
- Run CI with Node 24 actions on Ubuntu 24.04 and disable the unused Go module cache.
- Add regression coverage for layout selection, missing-entry-point recovery and release-link validation. Preserve the beta-2 permission and PHP 8.4 routing test fixes.

## v1.0.0-beta-2 — 2026-10-09

- Discard malformed installation-password file output, reject the documented placeholder, align UTF-8 password length with the form and invalidate this file in OPcache after manual edits.
- Package the ready-to-upload ZIP without a wrapper for extraction into the website home directory, merging public_html and keeping quickotp-private beside it. Document ownership/open_basedir requirements and migration from the legacy layout.
- Reject ambiguous or unreadable private-directory deployments before loading application code.

- Remove the Backup & Restore page, sidebar links, public script and user-facing APIs; keep internal update recovery.
- Replace header breadcrumbs with Quick OTP and Settings navigation.
- Require an administrator-configured installation password before database access; ship only an empty template and exclude the configured secret from source/update packages.
- Preserve bounded, escaped form values after valid setup submissions fail, including password fields, without storing them in sessions or URLs.
- Support hyphenated beta versions while retaining compatibility with legacy underscore beta tags.
- Update upload-only installation and recovery documentation.

- Distinguish unwritable storage, active update locks and unfinished update recovery in bootstrap errors; preserve maintenance and recovery safeguards.
- Document the fresh-install permissions fix in CyberPanel and test the failure cases with an unprivileged PHP worker.

## v1.0.0-beta_1 — 2026-10-09

First beta release of the upload-only PHP installer.

- Ready-to-upload website ZIP includes dependencies and extracts directly into public_html; complete setup in the browser.
- No manually created installation token for fresh installs; existing token-protected setups remain supported.
- Fix browser setup submissions rejected as an invalid Origin by preserving the form's same-origin header.
- Verify private-directory HTTP protection before saving database credentials; retain CSRF checks, setup locking and reinstallation protection.
- Dashboard, Quick OTP, email history, username/domain settings, IMAP, Admin password changes, and encrypted backup/restore.
- Authenticated web updates, source recovery and rollback with both supported directory layouts.
- Beta installations can discover later beta releases and stable releases; stable installations skip beta releases.
- English README with upload/extract/browser installation steps and sample configuration values.
