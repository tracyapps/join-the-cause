=== Join the Cause ===
Contributors: tracyapps
Tags: petitions, signatures, advocacy, campaigns, newsletter
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Petition and newsletter management for WordPress — create, manage, and share petitions with a change.org-style front end.

== Description ==

Join the Cause turns any WordPress site into a petition platform:

* **Petitions** as a custom post type with a change.org-style public layout (hero, dek, signature panel, progress bar, recent supporters).
* **Gutenberg block** — insert a petition in the block editor with a searchable picker, live preview, and an optional title toggle; renders exactly the same markup as the shortcode.
* **Signature form** with AJAX submission, per-field validation on both client and server, AJAX-based duplicate detection (one email per petition), honeypot and minimum-fill-time bot protection, and filterable rate limiting.
* **Custom form fields** per petition — text, email, textarea, checkbox, and select (with option lists) — all enforced server-side.
* **Share tools** — Facebook / X / copy-link / embed, printable QR codes, and optional interface Short.io short links and QR generation.
* **Newsletter** tool with recipient counts, per-batch progress, drafts, and test sends.
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

Either — they render identical output, because the block delegates to the same renderer as the shortcode. Use the block inside the block editor for the picker and live preview; use the shortcode for widgets, page builders, and PHP templates.

= The form needs JavaScript. What happens without it? =

Anonymous signatures are submitted over AJAX; without JavaScript a notice is shown in the form. All inputs are validated again on the server.

= Signers behind Cloudflare get the wrong rate limit. =

Only the direct connection address is trusted by default. If your site sits behind Cloudflare or another reverse proxy, define `JTC_TRUST_PROXY_HEADERS` as `true` in `wp-config.php` so the real visitor address is used.

= Signing fails after a while on cached pages. =

Security tokens expire after 12–24 hours. Exclude petition pages from full-page HTML caches that are served longer than that.

= Where is my data stored? =

Signatures and newsletters live in two custom tables; settings are normal WordPress options. Deleting the plugin removes all of its data (including generated QR cards).

== Changelog ==

= 0.1.0 =
* Initial release: petitions, a Gutenberg block (jtc/petition), signature form with server-side validation and bot mitigation, share tools, QR codes, Short.io integration, newsletters, email providers, appearance system, help & quick start docs.
