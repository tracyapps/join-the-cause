<?php
/**
 * Settings tab: Help & Quick Start (read-only).
 *
 * Contents: 5-step setup checklist, petition how-to, embedding guide,
 * mailer setup guide (decision table), Short.io guide, FAQ / troubleshooting,
 * and a copyable debug info card.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Not allowed.', 'join-the-cause' ) );
}

$jtc_shortio          = new JTC_Short_IO();
$jtc_new_petition_url = admin_url( 'post-new.php?post_type=' . JTC_CPT );
$jtc_petitions_url    = admin_url( 'edit.php?post_type=' . JTC_CPT );
$jtc_supporters_url   = admin_url( 'admin.php?page=jtc-supporters' );
$jtc_appearance_url   = admin_url( 'admin.php?page=join-the-cause&tab=appearance' );
$jtc_email_url        = admin_url( 'admin.php?page=join-the-cause&tab=email' );
$jtc_integrations_url = admin_url( 'admin.php?page=join-the-cause&tab=integrations' );
$jtc_shortio_api_url  = 'https://app.short.io/settings/integrations/api-key';

$jtc_debug_lines = array(
	'WordPress: ' . get_bloginfo( 'version' ),
	'PHP: ' . PHP_VERSION,
	'Plugin: Join the Cause ' . JTC_VERSION,
	'GD image library: ' . ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' ) ? 'available' : 'NOT available' ),
	'Mailer: ' . get_option( 'jtc_email_method', 'wp_mail' ),
	'Last mailer error: ' . ( get_option( 'jtc_last_mailer_error' )
		? get_option( 'jtc_last_mailer_error' ) . ' (' . get_option( 'jtc_last_mailer_error_time' ) . ')'
		: 'none' ),
	'Short.io: ' . ( $jtc_shortio->is_configured() ? 'configured (' . $jtc_shortio->get_domain() . ')' : 'not configured' ),
	'Proxy headers trusted: ' . ( defined( 'JTC_TRUST_PROXY_HEADERS' ) && JTC_TRUST_PROXY_HEADERS ? 'yes' : 'no' ),
	'Color mode: ' . get_option( 'jtc_color_mode', 'preset' ) . ' / preset: ' . get_option( 'jtc_preset_theme', 'evergreen' ),
);
?>
<div class="jtc-tab-content jtc-help">

	<h2><?php esc_html_e( 'Quick Start', 'join-the-cause' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Five steps from install to a live petition.', 'join-the-cause' ); ?>
	</p>
	<ol class="jtc-checklist">
		<li>
			<strong><?php esc_html_e( 'Create a petition', 'join-the-cause' ); ?></strong> —
			<a href="<?php echo esc_url( $jtc_new_petition_url ); ?>"><?php esc_html_e( 'Add New Petition', 'join-the-cause' ); ?></a>.
			<?php esc_html_e( 'The title is the headline; the excerpt becomes the dek (intro paragraph).', 'join-the-cause' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Choose what to collect', 'join-the-cause' ); ?></strong> —
			<?php esc_html_e( 'the "Signature Form Fields" box on the petition editor. First name, last name, and email are always collected; add extra fields as needed (select fields take one option per line).', 'join-the-cause' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Embed it', 'join-the-cause' ); ?></strong> —
			<?php esc_html_e( 'paste the shortcode shown in the Petitions list into any page, insert the Petition block, or use the dedicated petition URL.', 'join-the-cause' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Connect email', 'join-the-cause' ); ?></strong> —
			<a href="<?php echo esc_url( $jtc_email_url ); ?>"><?php esc_html_e( 'set up your mailer', 'join-the-cause' ); ?></a>
			<?php esc_html_e( 'and send yourself a test email so signers receive confirmations.', 'join-the-cause' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Optional: short links + QR codes', 'join-the-cause' ); ?></strong> —
			<a href="<?php echo esc_url( $jtc_integrations_url ); ?>"><?php esc_html_e( 'connect Short.io', 'join-the-cause' ); ?></a>
			<?php esc_html_e( 'for branded share URLs and printable QR codes.', 'join-the-cause' ); ?>
		</li>
	</ol>

	<h2><?php esc_html_e( 'Making a petition', 'join-the-cause' ); ?></h2>
	<ul class="jtc-help-list">
		<li><?php esc_html_e( 'Where: Dashboard → Join the Cause → Petitions → Add New (or the Add New link above).', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Featured image = hero image: it fills the petition header and doubles as the social-sharing image (a 1200×630 card is generated from the site logo when a petition has no image).', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Excerpt = dek. Write one short intro sentence; without an excerpt the first words of the content are used.', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Signature fields: the box below the editor. "Required" fields are validated on the server as well as in the browser.', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Petition Settings (side panel): goal, count, recent signers, post-sign behavior, share buttons — per-petition overrides of the defaults.', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Publish, then embed the shortcode or block anywhere — multiple petitions can live on one page, each works independently.', 'join-the-cause' ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'Embedding guide', 'join-the-cause' ); ?></h2>
	<p><?php esc_html_e( 'In the block editor: add the "Petition" block (search for it, or look under the Join the Cause category), pick a petition in the block sidebar, and publish. The editor preview matches the front end.', 'join-the-cause' ); ?></p>
	<p><?php esc_html_e( 'Everywhere else — widgets, page builders, PHP templates — paste the shortcode from the Petitions list ("Shortcode" column, click to copy):', 'join-the-cause' ); ?></p>
	<p><code>[jtc_petition id="123"]</code></p>
	<ul class="jtc-help-list">
		<li><?php esc_html_e( 'Block vs. shortcode: both render the same petition (identical markup); use the block when you are already in the block editor, and the shortcode for widgets, page builders, and templates.', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( '"Show title" (block) and show_title="1" (shortcode) render the petition title as an H1 — use it when the petition replaces the page title (the standalone /petition/… URL already does this).', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Petition URLs like /petition/your-slug/ work on their own and can be shared directly.', 'join-the-cause' ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'Mailer setup guide', 'join-the-cause' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Pick a sending method on the Email tab. The signature is always saved even when email fails — check the debug card below for the last mailer error.', 'join-the-cause' ); ?></p>
	<table class="widefat striped jtc-help-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Method', 'join-the-cause' ); ?></th>
				<th><?php esc_html_e( 'Use when', 'join-the-cause' ); ?></th>
				<th><?php esc_html_e( 'Requires', 'join-the-cause' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong>wp_mail</strong></td>
				<td><?php esc_html_e( 'Quick start. Your host sends mail through PHP.', 'join-the-cause' ); ?></td>
				<td><?php esc_html_e( 'Nothing extra (deliverability depends on your host).', 'join-the-cause' ); ?></td>
			</tr>
			<tr>
				<td><strong>SMTP</strong></td>
				<td><?php esc_html_e( 'You have SMTP credentials (Mailgun, SendGrid, Postmark, your provider…).', 'join-the-cause' ); ?></td>
				<td><?php esc_html_e( 'Host, port, encryption, username, password.', 'join-the-cause' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'API', 'join-the-cause' ); ?></strong></td>
				<td><?php esc_html_e( 'Mailgun or SendGrid API key — skips SMTP configuration entirely.', 'join-the-cause' ); ?></td>
				<td><?php esc_html_e( 'API key (+ Mailgun domain).', 'join-the-cause' ); ?></td>
			</tr>
		</tbody>
	</table>
	<ul class="jtc-help-list">
		<li><?php esc_html_e( 'Deliverability checklist: send From an address on a domain you control, publish SPF and DKIM records, then add a DMARC policy.', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Large lists: newsletters send synchronously and save progress per batch. For thousands of signers, prefer sending from a dedicated mailing tool.', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Tip: after saving email settings, use “Save and Send Test Email” (Email tab) — the result notice tells you exactly what happened.', 'join-the-cause' ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'Short.io guide', 'join-the-cause' ); ?></h2>
	<ul class="jtc-help-list">
		<li><?php esc_html_e( 'Requirements: a Short.io account with a domain connected (branded short domains need a paid plan; the free plan can use the shared short.io domain with the API).', 'join-the-cause' ); ?></li>
		<li>
			<?php
			printf(
				/* translators: %s: link to the Short.io API keys page */
				esc_html__( 'Get a secret API key from %s, enter your short domain (without https://), enable the integration, then use “Test connection”.', 'join-the-cause' ),
				'<a href="' . esc_url( $jtc_shortio_api_url ) . '" target="_blank" rel="noopener noreferrer">Short.io → Integrations &amp; API</a>'
			);
			?>
		</li>
		<li><?php esc_html_e( 'Auto-create makes a short link when a petition is first published; the custom slug field (in the petition’s Short.io box) overrides it. “Sync with the WordPress slug” keeps the two aligned on save.', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'Use for sharing: when enabled, share buttons, signer success messages, and {petition_url} email tokens use the short URL. It is only used while Short.io stays configured.', 'join-the-cause' ); ?></li>
		<li><?php esc_html_e( 'QR codes are generated by Short.io on demand (“Refresh QR” in the petition’s Short.io box) and can be downloaded or printed.', 'join-the-cause' ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'Troubleshooting & FAQ', 'join-the-cause' ); ?></h2>
	<dl class="jtc-faq">
		<dt><?php esc_html_e( '“You’ve already signed this petition.”', 'join-the-cause' ); ?></dt>
		<dd><?php esc_html_e( 'One email address can sign each petition once. Manage records under Supporters.', 'join-the-cause' ); ?></dd>

		<dt><?php esc_html_e( '“Too many submissions. Please try again later.”', 'join-the-cause' ); ?></dt>
		<dd><?php esc_html_e( 'Rate limit: 5 signatures per hour per IP address. A hidden bot-protection field and a minimum fill time also guard the form. Developers can tune both with the jtc_rate_limit_max / jtc_rate_limit_window filters.', 'join-the-cause' ); ?></dd>

		<dt><?php esc_html_e( 'Signing fails after a while on cached pages.', 'join-the-cause' ); ?></dt>
		<dd><?php esc_html_e( 'Security tokens expire after 12–24 hours. If your site serves full-page caches for longer than that, exclude petition pages from HTML caching.', 'join-the-cause' ); ?></dd>

		<dt><?php esc_html_e( 'Behind a proxy/CDN and the rate limit treats everyone as one visitor?', 'join-the-cause' ); ?></dt>
		<dd><?php esc_html_e( 'By default only REMOTE_ADDR is trusted (spoof-proof). For Cloudflare or a reverse proxy, define JTC_TRUST_PROXY_HEADERS as true in wp-config.php so the real visitor IP is used.', 'join-the-cause' ); ?></dd>

		<dt><?php esc_html_e( 'Dark mode isn’t showing.', 'join-the-cause' ); ?></dt>
		<dd><?php esc_html_e( 'Every preset ships a dark palette, applied automatically for prefers-color-scheme: dark and for themes that set body.dark / body[data-theme="dark"].', 'join-the-cause' ); ?></dd>

		<dt><?php esc_html_e( 'The petition clashes with my theme’s fonts.', 'join-the-cause' ); ?></dt>
		<dd>
			<?php
			printf(
				/* translators: %s: link to the Appearance tab */
				esc_html__( 'Font source defaults to “inherit” so your theme’s typography wins. Override it under %s (Layout & Style).', 'join-the-cause' ),
				'<a href="' . esc_url( $jtc_appearance_url ) . '">Appearance</a>'
			);
			?>
		</dd>

		<dt><?php esc_html_e( 'Customizing the petition layout more deeply.', 'join-the-cause' ); ?></dt>
		<dd><?php esc_html_e( 'Copy templates/single-jtc_petition.php into your theme as single-jtc_petition.php and edit freely; all styling uses --jtc-* CSS variables.', 'join-the-cause' ); ?></dd>
	</dl>

	<h2><?php esc_html_e( 'Debug information', 'join-the-cause' ); ?></h2>
	<div class="card jtc-debug-card">
		<pre id="jtc-debug-info"><?php echo esc_html( implode( "\n", $jtc_debug_lines ) ); ?></pre>
		<p>
			<button type="button" class="button jtc-copy-debug" data-target="jtc-debug-info">
				<?php esc_html_e( 'Copy debug info', 'join-the-cause' ); ?>
			</button>
		</p>
	</div>

</div>
