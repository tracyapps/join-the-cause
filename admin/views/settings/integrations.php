<?php
/**
 * Settings tab: Integrations (Short.io).
 *
 * Enable, short domain, masked API key with "saved" indicator, auto-create,
 * sharing toggle, and a "Test connection" AJAX button.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$shortio_key        = (string) get_option( 'jtc_shortio_api_key', '' );
$shortio_key_saved  = '' !== $shortio_key;
$shortio_key_hint   = $shortio_key_saved ? str_repeat( '•', 8 ) . substr( $shortio_key, -4 ) : '';
$shortio_configured = ( new JTC_Short_IO() )->is_configured();
?>
<div class="jtc-tab-content">
	<h2><?php esc_html_e( 'Short.io Links', 'join-the-cause' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Create branded short links and QR codes for petitions using your Short.io custom domain.', 'join-the-cause' ); ?></p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Short.io', 'join-the-cause' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="jtc_shortio_enabled" value="1"
						<?php checked( get_option( 'jtc_shortio_enabled', 0 ) ); ?>>
					<?php esc_html_e( 'Enable Short.io short links for petitions', 'join-the-cause' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_shortio_domain"><?php esc_html_e( 'Short domain', 'join-the-cause' ); ?></label></th>
			<td>
				<input type="text" id="jtc_shortio_domain" name="jtc_shortio_domain"
					value="<?php echo esc_attr( get_option( 'jtc_shortio_domain', '' ) ); ?>"
					placeholder="go.example.org" class="regular-text">
				<p class="description"><?php esc_html_e( 'Use the custom domain already connected in Short.io, without https://.', 'join-the-cause' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_shortio_api_key"><?php esc_html_e( 'Secret API key', 'join-the-cause' ); ?></label></th>
			<td>
				<input type="password" id="jtc_shortio_api_key" name="jtc_shortio_api_key"
					value="" autocomplete="new-password" class="regular-text">
				<p class="description">
					<?php if ( $shortio_key_saved ) : ?>
						<span class="jtc-key-saved">
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php
							printf(
								/* translators: %s: last 4 characters of the saved key */
								esc_html__( 'A key is saved (%s). Leave blank to keep it.', 'join-the-cause' ),
								esc_html( $shortio_key_hint )
							);
							?>
						</span><br>
					<?php else : ?>
						<?php esc_html_e( 'No key saved yet.', 'join-the-cause' ); ?><br>
					<?php endif; ?>
					<?php
					printf(
						/* translators: %s: link to the Short.io API keys page */
						esc_html__( 'Create a secret API key in %s.', 'join-the-cause' ),
						'<a href="https://app.short.io/settings/integrations/api-key" target="_blank" rel="noopener noreferrer">Short.io → Integrations &amp; API</a>'
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'New petitions', 'join-the-cause' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="jtc_shortio_auto_create" value="1"
						<?php checked( get_option( 'jtc_shortio_auto_create', 0 ) ); ?>>
					<?php esc_html_e( 'Automatically create a short link when a petition is published', 'join-the-cause' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'If no custom slug is set, Short.io will generate one automatically.', 'join-the-cause' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Default sharing URL', 'join-the-cause' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="jtc_shortio_use_for_sharing" value="1"
						<?php checked( get_option( 'jtc_shortio_use_for_sharing', 0 ) ); ?>>
					<?php esc_html_e( 'Use the Short.io URL for public share buttons, signer success sharing, and petition email URL tokens when available', 'join-the-cause' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Only used while Short.io stays enabled and configured.', 'join-the-cause' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Connection', 'join-the-cause' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Save your settings first, then test the connection. The test only lists your domains — nothing is changed.', 'join-the-cause' ); ?>
	</p>
	<p>
		<button type="button" class="button jtc-shortio-test" <?php disabled( ! $shortio_configured ); ?>>
			<?php esc_html_e( 'Test connection', 'join-the-cause' ); ?>
		</button>
		<span id="jtc-shortio-test-result" class="jtc-test-result" role="status" aria-live="polite"></span>
		<?php if ( ! $shortio_configured ) : ?>
			<span class="description"><?php esc_html_e( 'Enable the integration and save a domain + API key to test.', 'join-the-cause' ); ?></span>
		<?php endif; ?>
	</p>
</div>
