<?php
/**
 * Settings page view — rendered by JTC_Admin::page_settings().
 * Tabs: Appearance | General | Petition Defaults | Email
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Not allowed.', 'join-the-cause' ) );

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'appearance';
$valid_tabs = [ 'appearance', 'general', 'defaults', 'email' ];
if ( ! in_array( $active_tab, $valid_tabs, true ) ) $active_tab = 'appearance';

$presets  = jtc_get_preset_themes();
$defaults = get_option( 'jtc_petition_defaults', [] );
?>
<div class="wrap jtc-settings-wrap">
	<h1><?php esc_html_e( 'Join the Cause — Settings', 'join-the-cause' ); ?></h1>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings tabs', 'join-the-cause' ); ?>">
		<?php
		$tabs = [
			'appearance' => __( 'Appearance',         'join-the-cause' ),
			'general'    => __( 'General Language',   'join-the-cause' ),
			'defaults'   => __( 'Petition Defaults',  'join-the-cause' ),
			'email'      => __( 'Email',              'join-the-cause' ),
		];
		foreach ( $tabs as $slug => $label ) :
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( [ 'page' => 'join-the-cause', 'tab' => $slug ], admin_url( 'admin.php' ) ) ),
				$active_tab === $slug ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		endforeach;
		?>
	</nav>

	<form method="post" action="" class="jtc-settings-form">
		<?php wp_nonce_field( 'jtc_save_settings', 'jtc_settings_nonce' ); ?>
		<input type="hidden" name="jtc_settings_submit" value="1">
		<input type="hidden" name="jtc_tab" value="<?php echo esc_attr( $active_tab ); ?>">

		<!-- ═══════════════ TAB: APPEARANCE ═══════════════ -->
		<?php if ( 'appearance' === $active_tab ) : ?>
		<div class="jtc-tab-content">
			<h2><?php esc_html_e( 'Colour Theme', 'join-the-cause' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Choose how colours are applied to your petition pages. Selecting "None" lets your active theme handle all styling.', 'join-the-cause' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Colour mode', 'join-the-cause' ); ?></th>
					<td>
						<?php
						$mode = get_option( 'jtc_color_mode', 'preset' );
						$modes = [
							'preset' => __( 'Preset theme', 'join-the-cause' ),
							'custom' => __( 'Custom colours (colour picker)', 'join-the-cause' ),
							'none'   => __( 'None (use theme styles only)', 'join-the-cause' ),
						];
						foreach ( $modes as $val => $lbl ) :
						?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="jtc_color_mode" value="<?php echo esc_attr( $val ); ?>"
								<?php checked( $mode, $val ); ?> class="jtc-mode-radio">
							<?php echo esc_html( $lbl ); ?>
						</label>
						<?php endforeach; ?>
					</td>
				</tr>

				<!-- Preset swatches -->
				<tr class="jtc-show-when-preset">
					<th scope="row"><label><?php esc_html_e( 'Choose preset', 'join-the-cause' ); ?></label></th>
					<td>
						<div class="jtc-preset-swatches" role="group" aria-label="<?php esc_attr_e( 'Colour preset options', 'join-the-cause' ); ?>">
							<?php
							$current_preset = get_option( 'jtc_preset_theme', 'wcfjip' );
							if ( ! isset( $presets[ $current_preset ] ) ) {
								$current_preset = 'wcfjip';
							}
							foreach ( $presets as $slug => $colors ) :
								$label = $colors['label'] ?? ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
							?>
							<label class="jtc-swatch-label" title="<?php echo esc_attr( $label ); ?>">
								<input type="radio" name="jtc_preset_theme" value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( $current_preset, $slug ); ?>>
								<span class="jtc-swatch"
									style="background:linear-gradient(135deg, <?php echo esc_attr( $colors['hero_from'] ?? $colors['primary'] ); ?>, <?php echo esc_attr( $colors['hero_to'] ?? $colors['primary_dark'] ); ?>);"
									aria-label="<?php echo esc_attr( $label ); ?>">
								</span>
								<span class="jtc-swatch-name"><?php echo esc_html( $label ); ?></span>
							</label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>

				<!-- Custom colour pickers -->
				<tr class="jtc-show-when-custom">
					<th scope="row"><?php esc_html_e( 'Custom colours', 'join-the-cause' ); ?></th>
					<td>
						<div class="jtc-color-pickers">
							<?php
							$pickers = [
								'jtc_custom_primary'   => [ __( 'Primary colour',    'join-the-cause' ), '#2d6a2d' ],
								'jtc_custom_secondary' => [ __( 'Dark variant',       'join-the-cause' ), '#1a3d1a' ],
								'jtc_custom_accent'    => [ __( 'Light accent / bg',  'join-the-cause' ), '#f0faf0' ],
								'jtc_custom_hero_from' => [ __( 'Hero gradient start','join-the-cause' ), '#245e2b' ],
								'jtc_custom_hero_to'   => [ __( 'Hero gradient end',  'join-the-cause' ), '#4f8d33' ],
								'jtc_custom_page_bg'   => [ __( 'Page background',    'join-the-cause' ), '#f6f8f4' ],
								'jtc_custom_surface'   => [ __( 'Content background', 'join-the-cause' ), '#ffffff' ],
								'jtc_custom_surface_alt' => [ __( 'Accent background', 'join-the-cause' ), '#f3f7f0' ],
							];
							foreach ( $pickers as $key => [$label, $default] ) :
								$val = get_option( $key, $default );
							?>
							<div class="jtc-color-picker-row">
								<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
								<input
									type="text"
									id="<?php echo esc_attr( $key ); ?>"
									name="<?php echo esc_attr( $key ); ?>"
									value="<?php echo esc_attr( $val ); ?>"
									class="jtc-color-picker"
									data-default-color="<?php echo esc_attr( $default ); ?>"
								>
							</div>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<!-- ═══════════════ TAB: GENERAL LANGUAGE ═══════════════ -->
		<?php elseif ( 'general' === $active_tab ) : ?>
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
		</div>

		<!-- ═══════════════ TAB: PETITION DEFAULTS ═══════════════ -->
		<?php elseif ( 'defaults' === $active_tab ) : ?>
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

		<!-- ═══════════════ TAB: EMAIL ═══════════════ -->
		<?php elseif ( 'email' === $active_tab ) : ?>
		<div class="jtc-tab-content">
			<h2><?php esc_html_e( 'Email Settings', 'join-the-cause' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Sending method', 'join-the-cause' ); ?></th>
					<td>
						<?php
						$method  = get_option( 'jtc_email_method', 'wp_mail' );
						$methods = [
							'wp_mail' => __( 'wp_mail (WordPress default)', 'join-the-cause' ),
							'smtp'    => __( 'SMTP (override PHPMailer)',    'join-the-cause' ),
							'api'     => __( 'API (SendGrid, etc.)',         'join-the-cause' ),
						];
						foreach ( $methods as $val => $lbl ) :
						?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="jtc_email_method" value="<?php echo esc_attr( $val ); ?>"
								<?php checked( $method, $val ); ?> class="jtc-email-method-radio">
							<?php echo esc_html( $lbl ); ?>
						</label>
						<?php endforeach; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="jtc_from_name"><?php esc_html_e( 'From name', 'join-the-cause' ); ?></label></th>
					<td>
						<input type="text" id="jtc_from_name" name="jtc_from_name"
							value="<?php echo esc_attr( get_option( 'jtc_from_name', get_bloginfo( 'name' ) ) ); ?>"
							class="regular-text">
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="jtc_from_email"><?php esc_html_e( 'From email', 'join-the-cause' ); ?></label></th>
					<td>
						<input type="email" id="jtc_from_email" name="jtc_from_email"
							value="<?php echo esc_attr( get_option( 'jtc_from_email', get_option( 'admin_email' ) ) ); ?>"
							class="regular-text">
					</td>
				</tr>

				<!-- SMTP fields -->
				<tr class="jtc-smtp-row">
					<th scope="row" colspan="2"><h3 style="margin:0;"><?php esc_html_e( 'SMTP Settings', 'join-the-cause' ); ?></h3></th>
				</tr>
				<tr class="jtc-smtp-row">
					<th scope="row"><label for="jtc_smtp_host"><?php esc_html_e( 'SMTP host', 'join-the-cause' ); ?></label></th>
					<td><input type="text" id="jtc_smtp_host" name="jtc_smtp_host"
						value="<?php echo esc_attr( get_option( 'jtc_smtp_host', '' ) ); ?>" class="regular-text"></td>
				</tr>
				<tr class="jtc-smtp-row">
					<th scope="row"><label for="jtc_smtp_port"><?php esc_html_e( 'SMTP port', 'join-the-cause' ); ?></label></th>
					<td><input type="number" id="jtc_smtp_port" name="jtc_smtp_port"
						value="<?php echo esc_attr( get_option( 'jtc_smtp_port', 587 ) ); ?>" class="small-text"></td>
				</tr>
				<tr class="jtc-smtp-row">
					<th scope="row"><label for="jtc_smtp_encryption"><?php esc_html_e( 'Encryption', 'join-the-cause' ); ?></label></th>
					<td>
						<select id="jtc_smtp_encryption" name="jtc_smtp_encryption">
							<?php foreach ( [ 'tls' => 'TLS', 'ssl' => 'SSL', 'none' => __( 'None', 'join-the-cause' ) ] as $v => $l ) : ?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( get_option( 'jtc_smtp_encryption', 'tls' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr class="jtc-smtp-row">
					<th scope="row"><label for="jtc_smtp_username"><?php esc_html_e( 'SMTP username', 'join-the-cause' ); ?></label></th>
					<td><input type="text" id="jtc_smtp_username" name="jtc_smtp_username"
						value="<?php echo esc_attr( get_option( 'jtc_smtp_username', '' ) ); ?>" autocomplete="off" class="regular-text"></td>
				</tr>
				<tr class="jtc-smtp-row">
					<th scope="row"><label for="jtc_smtp_password"><?php esc_html_e( 'SMTP password', 'join-the-cause' ); ?></label></th>
					<td>
						<input type="password" id="jtc_smtp_password" name="jtc_smtp_password"
							value="" autocomplete="new-password" class="regular-text">
						<p class="description"><?php esc_html_e( 'Leave blank to keep the current password.', 'join-the-cause' ); ?></p>
					</td>
				</tr>

				<!-- API fields -->
				<tr class="jtc-api-row">
					<th scope="row" colspan="2"><h3 style="margin:0;"><?php esc_html_e( 'API Settings', 'join-the-cause' ); ?></h3></th>
				</tr>
				<tr class="jtc-api-row">
					<th scope="row"><label for="jtc_api_provider"><?php esc_html_e( 'Provider', 'join-the-cause' ); ?></label></th>
					<td>
						<select id="jtc_api_provider" name="jtc_api_provider">
							<option value="sendgrid" <?php selected( get_option( 'jtc_api_provider', 'sendgrid' ), 'sendgrid' ); ?>>SendGrid</option>
						</select>
						<p class="description"><?php esc_html_e( 'Additional providers can be added via the jtc_api_providers filter.', 'join-the-cause' ); ?></p>
					</td>
				</tr>
				<tr class="jtc-api-row">
					<th scope="row"><label for="jtc_api_key"><?php esc_html_e( 'API key', 'join-the-cause' ); ?></label></th>
					<td>
						<input type="password" id="jtc_api_key" name="jtc_api_key"
							value="" autocomplete="new-password" class="regular-text">
						<p class="description"><?php esc_html_e( 'Leave blank to keep the current key.', 'join-the-cause' ); ?></p>
					</td>
				</tr>

				<!-- Welcome email -->
				<tr>
					<th scope="row" colspan="2"><h3 style="margin:16px 0 0;"><?php esc_html_e( 'Welcome Email', 'join-the-cause' ); ?></h3></th>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Send welcome email', 'join-the-cause' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="jtc_welcome_email_enabled" value="1"
								<?php checked( get_option( 'jtc_welcome_email_enabled', 1 ) ); ?>>
							<?php esc_html_e( 'Send a thank-you email to each new signer', 'join-the-cause' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jtc_welcome_subject"><?php esc_html_e( 'Subject', 'join-the-cause' ); ?></label></th>
					<td>
						<input type="text" id="jtc_welcome_subject" name="jtc_welcome_email_subject"
							value="<?php echo esc_attr( get_option( 'jtc_welcome_email_subject', '' ) ); ?>" class="large-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jtc_welcome_body"><?php esc_html_e( 'Body', 'join-the-cause' ); ?></label></th>
					<td>
						<textarea id="jtc_welcome_body" name="jtc_welcome_email_body" class="large-text" rows="6"><?php
							echo esc_textarea( get_option( 'jtc_welcome_email_body', '' ) );
						?></textarea>
						<p class="description">
							<?php esc_html_e( 'Available tokens: {first_name}, {last_name}, {email}, {petition_title}, {petition_url}, {site_name}, {site_url}', 'join-the-cause' ); ?>
						</p>
					</td>
				</tr>

				<!-- Admin notification -->
				<tr>
					<th scope="row" colspan="2"><h3 style="margin:16px 0 0;"><?php esc_html_e( 'Admin Notification', 'join-the-cause' ); ?></h3></th>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notify admin', 'join-the-cause' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="jtc_admin_notify_enabled" value="1"
								<?php checked( get_option( 'jtc_admin_notify_enabled', 1 ) ); ?>>
							<?php esc_html_e( 'Send me an email each time someone signs a petition', 'join-the-cause' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jtc_admin_notify_email"><?php esc_html_e( 'Notify email address', 'join-the-cause' ); ?></label></th>
					<td>
						<input type="email" id="jtc_admin_notify_email" name="jtc_admin_notify_email"
							value="<?php echo esc_attr( get_option( 'jtc_admin_notify_email', get_option( 'admin_email' ) ) ); ?>"
							class="regular-text">
					</td>
				</tr>
			</table>
		</div>
		<?php endif; ?>

		<?php submit_button( __( 'Save Settings', 'join-the-cause' ) ); ?>
	</form>
</div>
