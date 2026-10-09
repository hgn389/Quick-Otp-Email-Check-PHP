# Changelog PHP

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
