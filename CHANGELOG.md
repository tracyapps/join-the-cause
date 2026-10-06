# Changelog

## 0.2.0 — 2026-10-06

- Make public signing limits atomic and count rejected attempts; scope signing
  nonces to petitions, keep duplicate confirmations private, reject malformed
  inputs, and stop storing new raw IP addresses.
- Protect password-gated petitions and prevent recursive embeds. Give repeated
  petition instances unique IDs and use multibyte-safe supporter initials.
- Replace synchronous newsletters with consent-based, deduplicated background
  batches, progress, pause/resume/cancel, lease recovery, and safe handling of
  interrupted deliveries and unavailable database reads.
- Add separate unchecked newsletter consent, confirmed unsubscribe links,
  WordPress personal-data export/erasure, and privacy-policy suggestions.
  Existing signatures remain intact and are excluded from newsletters until
  consent is obtained.
- Harden CSV exports and credential handling; support configuration constants
  for secrets and disable credential autoloading.
- Improve light/dark and custom-color contrast, public focus/error feedback,
  repeated-embed semantics, admin table sorting, and editor toolbar semantics.
- Keep Short.io refreshes out of public rendering; detect nested petition blocks
  for social metadata and frontend assets.
- Require WordPress 6.3, update translations and service disclosures, include CSS
  source/build instructions, and add reproducible standards/compatibility tooling
  and security, privacy, rendering, and queue regression tests.

## 0.1.0

- Initial development version: petitions, block/shortcode embedding, signature
  forms, sharing and QR tools, email providers, newsletters, and appearance.
