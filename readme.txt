=== Join the Cause ===
Contributors: tracyapps
Tags: petitions, signatures, advocacy, campaigns, newsletter
Requires at least: 6.3
Tested up to: 7.1
Stable tag: 0.2.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Petition and newsletter management for WordPress — create, manage, and share petitions with a change.org-style front end.

== Description ==

Join the Cause turns any WordPress site into a petition platform:

* **Petitions** as a custom post type with a change.org-style public layout (hero, dek, signature panel, progress bar, recent supporters).
* **Gutenberg block** — insert a petition in the block editor with a searchable picker, live preview, and an optional title toggle; shares its petition renderer with the shortcode.
* **Signature form** with AJAX submission, per-field validation on both client and server, private duplicate handling (one email per petition), honeypot and minimum-fill-time bot protection, and filterable rate limiting.
* **Custom form fields** per petition — text, email, textarea, checkbox, and select (with option lists) — all enforced server-side.
* **Share tools** — Facebook / X / copy-link / embed, printable QR codes, and optional Short.io short links and QR generation.
* **Newsletter** tool with separate opt-in, background batches, progress, pause/resume/cancel, unsubscribe, drafts, and test sends.
* **Email delivery** via wp_mail, SMTP, or the Mailgun/SendGrid APIs, with welcome emails and admin notifications.
* **Appearance system** — five presets (each with a dark variant), custom colours, corner radius, shadow, button style, typography scale, hero styles, and section toggles, all driven by CSS custom properties.
* **Accessibility-minded** — labelled fields, described-by error messages, keyboard-friendly admin tools, reduced-motion support.

Embedding a petition:

* **Block** — add the "Petition" block (Join the Cause category), pick a petition in the sidebar, and toggle "Show title" to render the petition H1. The editor preview matches the front end.
* **Shortcode** — `[jtc_petition id="123"]` embeds a petition anywhere; `show_title="1"` renders its title as an H1. Use it in widgets, page builders, and PHP templates.

Block and shortcode render the same petition either way, and the shortcode keeps working unchanged.

== Installation ==

1. Upload the `join-the-cause` folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New → Upload.
2. Activate the plugin.
3. Open Join the Cause → Help & Quick Start for the five-step setup guide.

== Frequently Asked Questions ==

= Can I show two petitions on one page? =

Yes. Each petition (shortcode or block) is fully independent — its own signature form, counts, and share tools.

= Should I use the block or the shortcode? =

Either — they share the same petition layout, with unique IDs for each rendered instance. Use the block inside the block editor for the picker and live preview; use the shortcode for widgets, page builders, and PHP templates.

= The form needs JavaScript. What happens without it? =

Anonymous signatures are submitted over AJAX; without JavaScript a notice is shown in the form. All inputs are validated again on the server.

= Signers behind Cloudflare get the wrong rate limit. =

Only the direct connection address is trusted by default. Enable `JTC_TRUST_PROXY_HEADERS` only when a trusted upstream proxy overwrites client-IP headers and visitors cannot bypass it. Otherwise forwarded headers can be spoofed.

= Signing fails after a while on cached pages. =

Security tokens expire after 12–24 hours. Exclude petition pages from full-page HTML caches that are served longer than that.

= Where is my data stored? =

Four custom tables store signatures, newsletters, delivery outcomes, and temporary keyed rate-limit buckets. Settings use WordPress options. New signatures do not retain raw IP addresses; old records may retain their legacy address until erased. WordPress privacy tools can export or erase signature data. Deleting the plugin removes its data and generated QR cards.

= Do existing signers receive newsletters after upgrading? =

No. Newsletter consent is separate from public-name consent and starts disabled for existing signatures. New signers can choose the unchecked newsletter option. Unsubscribing withdraws newsletter consent across petitions while keeping signatures.

= How do background sends run? =

WordPress cron sends bounded batches; keeping the Newsletter screen open also advances jobs through authenticated progress polling. Configure cron for low-traffic sites or when WP cron is disabled. Pause/resume/cancel affects future sends; a provider may already have accepted an in-flight message. Interrupted deliveries are marked unknown and are not retried automatically. Check provider logs before creating another send. “Sent” indicates transport acceptance, not inbox delivery.

= How are credentials stored? =

Saved credentials are database options with autoload disabled, not encrypted at rest. Optional `JTC_SMTP_PASSWORD`, `JTC_API_KEY`, and `JTC_SHORTIO_API_KEY` constants in wp-config.php override the saved values. Protect configuration and backups.

== External Services ==

Integrations are optional and contacted only when enabled/configured. WordPress mail is the default.

* SMTP: the selected SMTP operator receives sender and recipient addresses, subject, and message content when mail is sent. Review your operator's terms and privacy policy.
* Mailgun: when selected for email, receives sender/recipient addresses, subject, message content including personalized names and unsubscribe URL, and API authentication. Terms: https://www.mailgun.com/legal/terms/ — Privacy: https://www.mailgun.com/legal/privacy-policy/
* SendGrid: when selected for email, receives sender/recipient addresses, subject, message content including personalized names and unsubscribe URL, and API authentication. Terms: https://www.twilio.com/en-us/legal/tos — Privacy: https://www.twilio.com/en-us/legal/privacy
* Short.io: when enabled and an administrator generates or refreshes a short link or QR code, receives the public petition URL/title, configured domain, link identifiers, and API authentication. Signer details are not sent. Short-link clicks are subject to the service's settings. Public renders use stored links without remote refreshes. Terms: https://short.io/terms/ — Privacy: https://short.io/privacy

== Development ==

Readable JavaScript and SCSS source are included. Rebuild compressed CSS using `npm ci` followed by `npm run build` in a source checkout. See README.md for dependencies, test/database setup, and verification commands.

== Changelog ==

= 0.2.0 =
* Atomic submission limits, scoped nonces, private duplicate responses, robust validation, and no raw IP storage for new signatures.
* Consent-based newsletter queue with bounded batches, progress, pause/resume/cancel, and interrupted-job recovery.
* Confirmed unsubscribe, WordPress personal-data export/erasure, privacy suggestions, and service disclosures; legacy signatures preserved and excluded from newsletters.
* Protected petition access, recursive-embed guard, unique IDs, accessible color/focus feedback and admin controls, safe CSV exports, and improved social metadata.
* WordPress 6.3 minimum, updated translations, reproducible build/standards tooling, and security/privacy/queue regression coverage.


= 0.1.0 =
* Initial release: petitions, a Gutenberg block (jtc/petition), signature form with server-side validation and bot mitigation, share tools, QR codes, Short.io integration, newsletters, email providers, appearance system, help & quick start docs.
