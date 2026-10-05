<?php
/**
 * Settings tab: Petitions (global defaults).
 *
 * These defaults apply to every petition unless overridden in the
 * petition's own "Petition Settings" meta box.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$defaults = get_option( 'jtc_petition_defaults', [] );
?>
<div class="jtc-tab-content">
	<h2><?php esc_html_e( 'Petition Defaults', 'join-the-cause' ); ?></h2>
	<p class="description"><?php esc_html_e( 'These settings apply to all petitions unless overridden on the individual petition.', 'join-the-cause' ); ?></p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Display', 'join-the-cause' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Display options', 'join-the-cause' ); ?></legend>
					<label style="display:block;margin-bottom:6px;">
						<input type="checkbox" name="jtc_show_count" value="1" <?php checked( $defaults['show_count'] ?? 1 ); ?>>
						<?php esc_html_e( 'Show total signature count', 'join-the-cause' ); ?>
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="checkbox" name="jtc_show_recent" value="1" <?php checked( $defaults['show_recent'] ?? 1 ); ?>>
						<?php esc_html_e( 'Show recent signer names (adds opt-in consent checkbox to form)', 'join-the-cause' ); ?>
					</label>
					<label style="display:block;">
						<input type="checkbox" name="jtc_allow_comments" value="1" <?php checked( $defaults['allow_comments'] ?? 0 ); ?>>
						<?php esc_html_e( 'Allow WordPress comments', 'join-the-cause' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="jtc_goal"><?php esc_html_e( 'Signature goal', 'join-the-cause' ); ?></label></th>
			<td>
				<input type="number" id="jtc_goal" name="jtc_goal"
					value="<?php echo esc_attr( $defaults['goal'] ?? 0 ); ?>" min="0" class="small-text">
				<p class="description"><?php esc_html_e( '0 = no goal shown.', 'join-the-cause' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'After signing', 'join-the-cause' ); ?></th>
			<td>
				<label style="display:block;margin-bottom:6px;">
					<input type="radio" name="jtc_after_sign_action" value="message"
						<?php checked( $defaults['after_sign_action'] ?? 'message', 'message' ); ?>>
					<?php esc_html_e( 'Show success message', 'join-the-cause' ); ?>
				</label>
				<label style="display:block;">
					<input type="radio" name="jtc_after_sign_action" value="redirect"
						<?php checked( $defaults['after_sign_action'] ?? 'message', 'redirect' ); ?>>
					<?php esc_html_e( 'Redirect to URL', 'join-the-cause' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="jtc_after_sign_message"><?php esc_html_e( 'Success message', 'join-the-cause' ); ?></label></th>
			<td>
				<textarea id="jtc_after_sign_message" name="jtc_after_sign_message"
					class="large-text" rows="2"><?php echo esc_textarea( $defaults['after_sign_message'] ?? '' ); ?></textarea>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="jtc_after_sign_redirect"><?php esc_html_e( 'Redirect URL', 'join-the-cause' ); ?></label></th>
			<td>
				<input type="url" id="jtc_after_sign_redirect" name="jtc_after_sign_redirect"
					value="<?php echo esc_attr( $defaults['after_sign_redirect'] ?? '' ); ?>"
					class="regular-text" placeholder="https://...">
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Share buttons', 'join-the-cause' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Share services', 'join-the-cause' ); ?></legend>
					<?php
					$saved_shares = (array) ( $defaults['share_buttons'] ?? [] );
					foreach ( [ 'facebook', 'twitter', 'copy', 'embed' ] as $svc ) :
					?>
					<label style="display:inline-block;margin-right:16px;">
						<input type="checkbox" name="jtc_share_buttons[]" value="<?php echo esc_attr( $svc ); ?>"
							<?php checked( in_array( $svc, $saved_shares, true ) ); ?>>
						<?php echo esc_html( ucfirst( $svc ) ); ?>
					</label>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
	</table>
</div>
