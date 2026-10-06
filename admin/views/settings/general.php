<?php
/**
 * Settings tab: General.
 *
 * Global petition text (privacy notice + terms) shown below every
 * signature form, plus environment notes.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="jtc-tab-content">
	<h2><?php esc_html_e( 'Global Petition Text', 'join-the-cause' ); ?></h2>
	<p class="description"><?php esc_html_e( 'This text appears on every petition. Leave blank to omit.', 'join-the-cause' ); ?></p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="jtc_privacy_notice"><?php esc_html_e( 'Privacy notice', 'join-the-cause' ); ?></label></th>
			<td>
				<textarea id="jtc_privacy_notice" name="jtc_privacy_notice"
					class="large-text" rows="4"><?php echo esc_textarea( get_option( 'jtc_privacy_notice', '' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Displayed below the signature form. Basic HTML allowed.', 'join-the-cause' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_terms_of_service"><?php esc_html_e( 'Terms of service', 'join-the-cause' ); ?></label></th>
			<td>
				<textarea id="jtc_terms_of_service" name="jtc_terms_of_service"
					class="large-text" rows="4"><?php echo esc_textarea( get_option( 'jtc_terms_of_service', '' ) ); ?></textarea>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Environment', 'join-the-cause' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Proxy headers', 'join-the-cause' ); ?></th>
			<td>
				<p class="description">
					<?php
					if ( defined( 'JTC_TRUST_PROXY_HEADERS' ) && JTC_TRUST_PROXY_HEADERS ) {
						esc_html_e( 'JTC_TRUST_PROXY_HEADERS is defined as true: Cloudflare / X-Forwarded-For / X-Real-IP headers are trusted for rate limiting.', 'join-the-cause' );
					} else {
						esc_html_e( 'Only the direct connection address (REMOTE_ADDR) is trusted for rate limiting, which is spoof-proof. Behind Cloudflare or a reverse proxy, define JTC_TRUST_PROXY_HEADERS as true in wp-config.php to limit by the real visitor address instead.', 'join-the-cause' );
					}
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Email failures', 'join-the-cause' ); ?></th>
			<td>
				<p class="description">
					<?php esc_html_e( 'Signatures are always stored even if notification emails fail. The most recent error is shown under Help & Quick Start → Debug information.', 'join-the-cause' ); ?>
				</p>
			</td>
		</tr>
	</table>
</div>
