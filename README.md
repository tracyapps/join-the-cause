# Join the Cause

Petition and newsletter management for WordPress. Create, manage, and share
petitions with a change.org-style front end — signature forms with AJAX
submission and server-side validation, per-petition counts and goals, share
tools, QR codes, and an appearance system driven by CSS custom properties.

**Requires:** PHP 8.0+, WordPress 6.0+
**Version:** 0.1.0

---

## What It Does

- **Petitions** as a `jtc_petition` custom post type with a full public layout (hero, dek, sticky signature panel, progress bar, recent supporters) and a standalone `/petition/{slug}/` URL.
- **Gutenberg block** (`jtc/petition`) — insert a petition with a searchable picker and a live editor preview; renders byte-identical markup to the shortcode.
- **Shortcode** (`[jtc_petition id="123"]`) for widgets, page builders, and PHP templates.
- **Signature form** with AJAX submission, client + server validation, duplicate detection (one email per petition), honeypot and minimum-fill-time bot protection, and filterable per-IP rate limiting.
- **Custom form fields** per petition — text, email, textarea, checkbox, and select — enforced server-side.
- **Share tools** — Facebook / X / copy-link / embed, printable QR codes, and optional Short.io short links.
- **Newsletter** tool with recipient counts, per-batch progress, drafts, and test sends.
- **Email delivery** via wp_mail, SMTP, or the Mailgun/SendGrid APIs.
- **Appearance system** — five presets (each with a dark variant), custom colours, corner radius, shadows, button style, typography scale, hero styles, and per-section toggles.

---

## Content Model

- `jtc_petition` — the petition CPT (`show_in_rest: true`, `rest_base: petitions`, rewrite slug `petition`).
- `{prefix}jtc_supporters` — signatures (petition id, name, email, consent, IP, timestamp; unique key on petition + email).
- `{prefix}jtc_newsletter` — newsletter drafts and sends.

---

## Embedding

### Gutenberg block

Add the **Petition** block (search "petition", or look in the **Join the Cause**
category), then pick a petition in the block sidebar. Toggle **Show title** to
render the petition title as an H1. The editor preview is rendered server-side
and matches the front end.

### Shortcode

```text
[jtc_petition id="123"]
[jtc_petition id="123" show_title="1"]
```

`show_title="1"` renders the petition title as an H1 — use it when the petition
replaces the page title (the standalone `/petition/…` URL already does this).

Multiple petitions can live on one page; each instance reads its own config
from data attributes on its wrapper and is wired independently
(`public/js/jtc-public.js`).

---

## Block architecture (no build step)

The block is a dynamic block registered from `blocks/petition/block.json`
(`apiVersion: 3`) by `includes/class-jtc-block.php` on `init`:

```text
blocks/petition/
├─ block.json      Block metadata (attributes: petitionId, showTitle; supports: anchor, multiple, html: false)
├─ index.js        Editor UI — hand-written against wp.* globals (no JSX, no transpile)
├─ index.asset.php Hand-written dependency list (wp-blocks, wp-element, wp-components, wp-block-editor,
│                  wp-i18n, wp-server-side-render, wp-api-fetch) + version
└─ editor.css      Editor-only affordances (static-preview pointer handling, hints)
```

- **Server render** delegates to `JTC_Shortcode::render()` — the block and the
  shortcode produce identical output for the same inputs, and all per-instance
  behavior (nonce, share URL, count updates) is inherited unchanged.
- **Petition picker** fetches `/wp/v2/petitions?per_page=100&status=publish&_fields=id,title`
  via `wp.apiFetch`; the preview uses `wp.serverSideRender`.
- **Editor styles**: `JTC_Block::enqueue_editor_canvas_assets()` hooks
  `enqueue_block_assets` and — only in admin requests editing content that
  contains the block — registers/enqueues the existing `jtc-public` stylesheet
  and attaches the `--jtc-*` CSS variables inline, so the block-editor iframe
  canvas looks like the front end. No block.json `style` file is used, so
  nothing is double-enqueued on the front end.

---

## REST API

The petition CPT is exposed at `/wp/v2/petitions` (standard WP REST posts
controller). Examples:

```bash
curl https://example.com/wp-json/wp/v2/petitions?per_page=10
```

---

## CSS Custom Properties

All public styles read from `--jtc-*` variables (printed on `wp_head` for
pages that render a petition, and attached to the `jtc-public` handle as a
safety net for late renders):

`--jtc-primary`, `--jtc-primary-dark`, `--jtc-primary-light`, `--jtc-primary-rgb`,
`--jtc-hero-from`, `--jtc-hero-to`, `--jtc-hero-text`, `--jtc-page-bg`,
`--jtc-surface`, `--jtc-surface-alt`, `--jtc-text`, `--jtc-text-strong`,
`--jtc-text-muted`, `--jtc-border`, `--jtc-input-bg`, `--jtc-button-text`,
`--jtc-button-bg`, `--jtc-button-fg`, `--jtc-button-border`,
`--jtc-button-hover-bg`, `--jtc-button-hover-fg`, `--jtc-radius`, `--jtc-radius-lg`,
`--jtc-shadow-sm`, `--jtc-shadow-md`, `--jtc-shadow-lg`, `--jtc-font-base`,
`--jtc-font-scale`, `--jtc-panel-width`, `--jtc-content-max`.

---

## Developer Notes

- **No JS build step** for the block (hand-written, `wp.*` globals). The public
  JS is vanilla jQuery; the admin JS is hand-written too.
- **SCSS build** (public + admin CSS are compiled and committed):

  ```bash
  npm install        # once (sass only)
  npm run build      # compiles assets/scss/*.scss → assets/css/*.css (compressed)
  ```

  The SCSS sources are the source of truth; commit the rebuilt CSS alongside.
- **Proxy-aware rate limiting**: only `REMOTE_ADDR` is trusted by default.
  Behind Cloudflare or another reverse proxy, define
  `JTC_TRUST_PROXY_HEADERS` as `true` in `wp-config.php`.
- **Template override**: copy `templates/single-jtc_petition.php` into your
  theme as `single-jtc_petition.php` to customize the standalone petition page.
- **Filters**: `jtc_rate_limit_max`, `jtc_rate_limit_window`
  (signature rate limiting), `jtc_should_output_css_vars` (force CSS-variable
  output on pages the detection cannot see, e.g. builders).
- **i18n**: text domain `join-the-cause`; regenerate the POT with
  `wp i18n make-pot . languages/join-the-cause.pot --domain=join-the-cause`.
