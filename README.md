# Join the Cause

Petitions and consent-based newsletters for WordPress. Create a petition, publish
its standalone `/petition/{slug}/` page, or embed it with a block or shortcode.

**Version:** 0.2.0

**Requires:** WordPress 6.3+, PHP 8.0+

**License:** GPL-2.0-or-later; see [LICENSE.txt](LICENSE.txt).

## Using the plugin

Activate the plugin, then open **Join the Cause → Help & Quick Start**. Set the
site's privacy notice and email preferences, create a petition, and publish it.
The **Appearance** screen offers five presets with light and dark variants,
custom colors, typography, buttons, spacing, and section toggles.

Use the **Petition** block for a searchable picker and server-rendered editor
preview, or embed `[jtc_petition id="123"]`. Add `show_title="1"` when the petition
provides the page's H1. Blocks and shortcodes share the same petition renderer;
each rendered instance has distinct DOM IDs. Protected petitions use WordPress's
password form. Recursive petition embeds are stopped before rendering a cycle.

Forms require JavaScript and validate fields again on the server. Names allow
100 characters each and emails 191 characters. Custom fields support text,
email, textarea, checkbox, and select. A petition permits one signature per
email. Repeating a signature returns the same confirmation and does not change
stored names or consent choices. A legacy database with duplicate signature rows
keeps those records; signing is temporarily disabled if its required unique
index cannot be created, and administrators see a recovery notice. Public
supporter names require their own
optional consent; newsletter consent is a separate unchecked checkbox.

Administrators can filter, sort, export, and delete supporters. CSV downloads
neutralize spreadsheet formula prefixes and omit IP addresses. Deleting a
signature decreases the petition's signature count.

## Newsletter operation and recovery

A saved draft can be queued once. Its recipient snapshot contains only addresses
with explicit newsletter consent, deduplicated across petitions. Existing
signatures from versions before 0.2.0 keep their signature data and start with
newsletter consent disabled. Public-name consent never grants newsletter consent.

WordPress cron sends small batches in the background. While the Newsletter
screen remains open, authenticated progress polling also advances one batch at
a time. Configure a real cron runner on low-traffic sites or when
`DISABLE_WP_CRON` is enabled; signature confirmation and administrator notification
emails are also scheduled jobs. Default batches contain at most five recipients,
and each job has a time budget and exclusive lease. SMTP and API requests have
15-second timeouts.

The archive shows progress, failures, and interrupted deliveries, with pause,
resume, and cancel controls. Cancellation prevents future pending sends; a send
already accepted by a provider may still arrive. A stopped preparation recovers
to a draft. A delivery interrupted after claiming its recipient is marked
**unknown** and is not automatically retried, since its provider may already
have accepted it. Inspect provider logs before deciding whether a new draft is
appropriate. Old synchronous sends interrupted before this upgrade are recorded
as **Interrupted legacy send** and are not restarted automatically.

“Sent” means the configured mail transport accepted the message, not that the
recipient opened it or that it reached their inbox. Test sending uses the
configured provider. A signing confirmation is transactional; opted-in signers
also receive an unsubscribe link in it. Newsletter messages always include one.
Unsubscribe links show a confirmation page and require a POST to withdraw
consent, so email scanners do not unsubscribe people just by opening a link.
Unsubscribing keeps petition signatures and withdraws newsletter consent for the
same address across petitions. Current consent is checked again before sending.

## Personal data and security

The plugin stores names, email addresses, signature dates, optional form
responses, and consent choices. New signatures do not store raw IP addresses.
An HMAC of the connection address is kept temporarily in the rate-limit table;
expired buckets are cleaned hourly. Old signatures can retain their previously
stored IP address until erased. Signature limits apply atomically across
petitions to nonce-valid attempts, including rejected fields and bot checks.
Only `REMOTE_ADDR` is trusted by default. Set `JTC_TRUST_PROXY_HEADERS` to `true`
only when a trusted upstream proxy overwrites the supported client-IP headers and
visitors cannot bypass that proxy.

WordPress **Tools → Export Personal Data / Erase Personal Data** includes petition
records. Erasure removes signatures and associated personal delivery data while
retaining anonymous delivery outcomes for progress reports. Privacy-policy
suggestions are available in WordPress's Privacy settings. Configure the notice,
retention period, and provider disclosures for your site. Data remains until an
administrator erases it or deletes the plugin; uninstall removes plugin tables,
options, petition posts/meta, scheduled jobs, and generated QR attachments.

Admin mutations require administrator capabilities and action-specific nonces.
Public signing nonces are scoped to the petition. Exclude petition pages from
full-page caches that outlive WordPress's 12–24 hour nonce lifetime.

Credentials saved in settings are ordinary database options with autoload
disabled. They are not encrypted at rest. Prefer these `wp-config.php` constants
when managing credentials outside the database: `JTC_SMTP_PASSWORD`,
`JTC_API_KEY`, and `JTC_SHORTIO_API_KEY`. A defined constant takes precedence over
the corresponding saved setting. Restrict database backups and configuration
access accordingly.

## Optional external services

No vendor account is required. WordPress's configured mail transport is the
default. Enable integrations only after reviewing their terms and privacy policy:

| Service | When contacted and data sent | Policies |
| --- | --- | --- |
| SMTP | When selected for confirmation, admin, newsletter, or test mail; configured server receives sender and recipient addresses, subject, and message content. SMTP credentials authenticate the connection. | Review the policies of your chosen SMTP operator. |
| Mailgun | When selected as the email API provider; receives sender and recipient addresses, subject, message content (including personalized names and unsubscribe URL), and API authentication. | [Terms](https://www.mailgun.com/legal/terms/) · [Privacy](https://www.mailgun.com/legal/privacy-policy/) |
| SendGrid | When selected as the email API provider; receives sender and recipient addresses, subject, message content (including personalized names and unsubscribe URL), and API authentication. | [Terms](https://www.twilio.com/en-us/legal/tos) · [Privacy](https://www.twilio.com/en-us/legal/privacy) |
| Short.io | When enabled and an administrator generates or refreshes a link/QR code; receives the public petition URL/title, configured short-link domain, link identifiers, and API authentication. Signer information is not included. Short links may record clicks according to that service's configuration. | [Terms](https://short.io/terms/) · [Privacy](https://short.io/privacy) |

Public petition rendering uses stored Short.io results and does not refresh
remote links. Share buttons open their named social service only when clicked.

## Content model

- `jtc_petition`: standard WordPress custom posts, exposed through the core REST
  posts controller at `/wp/v2/petitions`.
- `{prefix}jtc_supporters`: signatures and separate public/newsletter consent.
- `{prefix}jtc_newsletters`: drafts, immutable queued messages, job state/leases.
- `{prefix}jtc_deliveries`: deduplicated recipient snapshots and delivery outcomes.
- `{prefix}jtc_rate_limits`: temporary keyed buckets and atomic attempt counts.

## Development and verification

Frontend and admin JavaScript are readable source files without transpilation.
The dynamic block uses `wp.*` globals and `blocks/petition/index.asset.php`.
Compressed CSS is built from the included SCSS source:

```sh
npm ci
npm run build
```

Commit rebuilt CSS with the source. `npm run watch` and `npm run build:dev` are
available during development. Development dependencies are omitted from release
ZIPs by `.distignore`; the SCSS source and these build instructions are included.

```sh
composer install
composer lint
composer compatibility
WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit composer test
```

The WordPress test library must target a dedicated disposable database. It
resets test tables: never point it at LocalWP's development database or a live
site. The suite includes rate limits, duplicate privacy, rendering/access control,
contrast, queue concurrency/recovery, real SQL failure handling, consent,
unsubscribe, and paged erasure. `bin/verify.sh` runs the build, syntax checks,
full coding-standards report, compatibility checks, and that suite. Direct custom
table access intentionally uses current database state for atomic queue locks,
consent, and progress; standards warnings must be reviewed, not treated as proof
of a defect or hidden wholesale.

Regenerate translations without loading WordPress:

```sh
wp i18n make-pot . languages/join-the-cause.pot --domain=join-the-cause --exclude=node_modules,vendor,tests
```

Filters: `jtc_rate_limit_max` (default 5), `jtc_rate_limit_window` (default one
hour), `jtc_newsletter_batch_size` (default 5, clamped to 1–25), and
`jtc_should_output_css_vars` for builder pages that need the petition stylesheet.
The public appearance variables are defined in `assets/scss/_variables.scss`;
`--jtc-primary-rgb` remains a compatibility token for custom themes. Prefer the
appearance controls, which derive text, links, feedback, and focus colors for
contrast. Custom CSS or host themes can change the rendered accessibility result.

Copy `templates/single-jtc_petition.php` into a theme as `single-jtc_petition.php`
for a standalone template override. Keep the shared renderer's access and form
checks intact when customizing it.
