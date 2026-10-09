# Changelog PHP

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
