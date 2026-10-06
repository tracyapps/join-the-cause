<?php
/**
 * Settings tab: Email.
 *
 * Sending method (wp_mail / SMTP / API), provider settings, transactional
 * email templates, admin notifications, and the test-email button.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="jtc-tab-content">
	<h2><?php esc_html_e( 'Email Settings', 'join-the-cause' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Signatures are always saved, even when an email fails to send. The last failure is shown under Help & Quick Start → Debug information.', 'join-the-cause' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Sending method', 'join-the-cause' ); ?></th>
			<td>
				<?php
				$method  = get_option( 'jtc_email_method', 'wp_mail' );
				$methods = array(
					'wp_mail' => __( 'wp_mail (WordPress default)', 'join-the-cause' ),
					'smtp'    => __( 'SMTP (override PHPMailer)', 'join-the-cause' ),
					'api'     => __( 'API (Mailgun or SendGrid)', 'join-the-cause' ),
				);
				foreach ( $methods as $val => $lbl ) :
					?>
				<label style="display:block;margin-bottom:6px;">
					<input type="radio" name="jtc_email_method" value="<?php echo esc_attr( $val ); ?>"
						<?php checked( $method, $val ); ?> class="jtc-email-method-radio">
					<?php echo esc_html( $lbl ); ?>
				</label>
				<?php endforeach; ?>
				<p class="description">
					<?php esc_html_e( 'Not sure? wp_mail works everywhere; SMTP and API give better deliverability. See the decision table under Help & Quick Start.', 'join-the-cause' ); ?>
				</p>
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
				<p class="description"><?php esc_html_e( 'Use an address on a domain you control (it should pass SPF/DKIM).', 'join-the-cause' ); ?></p>
			</td>
		</tr>

		<!-- SMTP fields -->
		<tr class="jtc-smtp-row">
			<th scope="row" colspan="2"><h3 style="margin:0;"><?php esc_html_e( 'SMTP Settings', 'join-the-cause' ); ?></h3></th>
		</tr>
		<tr class="jtc-smtp-row">
			<th scope="row"><label for="jtc_smtp_host"><?php esc_html_e( 'SMTP host', 'join-the-cause' ); ?></label></th>
			<td><input type="text" id="jtc_smtp_host" name="jtc_smtp_host"
				value="<?php echo esc_attr( get_option( 'jtc_smtp_host', '' ) ); ?>" class="regular-text"
				placeholder="smtp.example.org"></td>
		</tr>
		<tr class="jtc-smtp-row">
			<th scope="row"><label for="jtc_smtp_port"><?php esc_html_e( 'SMTP port', 'join-the-cause' ); ?></label></th>
			<td>
				<input type="number" id="jtc_smtp_port" name="jtc_smtp_port"
					value="<?php echo esc_attr( get_option( 'jtc_smtp_port', 587 ) ); ?>"
					min="1" max="65535" step="1" class="small-text">
				<p class="description"><?php esc_html_e( 'Valid range 1–65535. Typical: 587 (TLS), 465 (SSL), 25 (plain, often blocked).', 'join-the-cause' ); ?></p>
			</td>
		</tr>
		<tr class="jtc-smtp-row">
			<th scope="row"><label for="jtc_smtp_encryption"><?php esc_html_e( 'Encryption', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_smtp_encryption" name="jtc_smtp_encryption">
					<?php
					foreach ( array(
						'tls'  => 'TLS',
						'ssl'  => 'SSL',
						'none' => __( 'None', 'join-the-cause' ),
					) as $v => $l ) :
						?>
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
				<p class="description">
					<?php
					echo '' !== (string) jtc_get_secret( 'jtc_smtp_password' )
						? esc_html__( 'A password is saved — leave blank to keep it.', 'join-the-cause' )
						: esc_html__( 'No password saved yet.', 'join-the-cause' );
					?>
				</p>
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
					<option value="mailgun" <?php selected( get_option( 'jtc_api_provider', 'mailgun' ), 'mailgun' ); ?>>Mailgun</option>
					<option value="sendgrid" <?php selected( get_option( 'jtc_api_provider', 'mailgun' ), 'sendgrid' ); ?>>SendGrid</option>
				</select>
				<p class="description"><?php esc_html_e( 'Mailgun needs your verified sending domain; SendGrid only needs the API key.', 'join-the-cause' ); ?></p>
			</td>
		</tr>
		<tr class="jtc-api-row jtc-mailgun-api-row">
			<th scope="row"><label for="jtc_mailgun_domain"><?php esc_html_e( 'Mailgun domain', 'join-the-cause' ); ?></label></th>
			<td>
				<input type="text" id="jtc_mailgun_domain" name="jtc_mailgun_domain"
					value="<?php echo esc_attr( get_option( 'jtc_mailgun_domain', '' ) ); ?>"
					placeholder="mg.example.org" class="regular-text">
				<p class="description"><?php esc_html_e( 'Use the verified sending domain from Mailgun.', 'join-the-cause' ); ?></p>
			</td>
		</tr>
		<tr class="jtc-api-row jtc-mailgun-api-row">
			<th scope="row"><label for="jtc_mailgun_region"><?php esc_html_e( 'Mailgun region', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_mailgun_region" name="jtc_mailgun_region">
					<option value="us" <?php selected( get_option( 'jtc_mailgun_region', 'us' ), 'us' ); ?>>US</option>
					<option value="eu" <?php selected( get_option( 'jtc_mailgun_region', 'us' ), 'eu' ); ?>>EU</option>
				</select>
			</td>
		</tr>
		<tr class="jtc-api-row">
			<th scope="row"><label for="jtc_api_key"><?php esc_html_e( 'API key', 'join-the-cause' ); ?></label></th>
			<td>
				<input type="password" id="jtc_api_key" name="jtc_api_key"
					value="" autocomplete="new-password" class="regular-text">
				<p class="description">
					<?php
					echo '' !== (string) jtc_get_secret( 'jtc_api_key' )
						? esc_html__( 'A key is saved. Leave blank to keep it.', 'join-the-cause' )
						: esc_html__( 'No key saved yet.', 'join-the-cause' );
					?>
				</p>
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
				<textarea id="jtc_welcome_body" name="jtc_welcome_email_body" class="large-text" rows="6">
				<?php
					echo esc_textarea( get_option( 'jtc_welcome_email_body', '' ) );
				?>
				</textarea>
				<p class="description">
					<?php esc_html_e( 'Available tokens: {first_name}, {last_name}, {email}, {petition_title}, {petition_url}, {petition_short_url}, {site_name}, {site_url}', 'join-the-cause' ); ?>
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

	<p>
		<?php submit_button( __( 'Save and Send Test Email', 'join-the-cause' ), 'secondary', 'jtc_email_test_submit', false ); ?>
		<span class="description"><?php esc_html_e( 'Sends a test message to your admin email and shows the result as a notice.', 'join-the-cause' ); ?></span>
	</p>
</div>
