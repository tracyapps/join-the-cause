<?php
/**
 * Handles all outgoing email for Join the Cause.
 *
 * Email method is controlled by jtc_email_method:
 *   'wp_mail'  — default, works anywhere WordPress does.
 *   'smtp'     — overrides PHPMailer via wp_mail (hooks into phpmailer_init).
 *   'api'      — direct API call (SendGrid or Mailgun).
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Mailer WordPress component. */
class JTC_Mailer {

	/**
	 * Method.
	 *
	 * @var string
	 */
	private string $method;
	/**
	 * From name.
	 *
	 * @var string
	 */
	private string $from_name;
	/**
	 * From email.
	 *
	 * @var string
	 */
	private string $from_email;
	/**
	 * Last error.
	 *
	 * @var string
	 */
	private string $last_error = '';
	/**
	 * Read the configured email delivery settings.
	 */
	public function __construct() {
		$this->method     = get_option( 'jtc_email_method', 'wp_mail' );
		$this->from_name  = get_option( 'jtc_from_name', get_bloginfo( 'name' ) );
		$this->from_email = get_option( 'jtc_from_email', get_option( 'admin_email' ) );
	}
	/**
	 * Return the last email delivery failure.
	 *
	 * @return string Result value.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	// ─── Public send methods ──────────────────────────────────────────────────

	/**
	 * Send the welcome/confirmation email to a new signer.
	 *
	 * @param array $supporter  Row data (first_name, last_name, email, petition_id).
	 * @param int   $petition_id Petition post ID.
	 * @return bool Result value.
	 */
	public function send_welcome( array $supporter, int $petition_id ): bool {
		if ( ! get_option( 'jtc_welcome_email_enabled' ) ) {
			return false;
		}

		$petition = get_post( $petition_id );
		if ( ! $petition ) {
			return false;
		}

		$subject = $this->replace_tokens(
			get_option( 'jtc_welcome_email_subject', 'Thank you for signing — {petition_title}' ),
			$supporter,
			$petition
		);

		$body = $this->replace_tokens(
			get_option( 'jtc_welcome_email_body', '' ),
			$supporter,
			$petition
		);

		if ( ! empty( $supporter['newsletter_consent'] ) && ! empty( $supporter['id'] ) ) {
			$body .= "\n\n" . __( 'Unsubscribe from newsletters:', 'join-the-cause' ) . ' ' . JTC_Privacy::unsubscribe_url( (int) $supporter['id'] );
		}
		return $this->send( $supporter['email'], $subject, $body );
	}

	/**
	 * Notify the admin when a new signature is received.
	 *
	 * @param array $supporter Supporter row data.
	 * @param int   $petition_id Petition post ID.
	 * @return bool Result value.
	 */
	public function send_admin_notify( array $supporter, int $petition_id ): bool {
		if ( ! get_option( 'jtc_admin_notify_enabled' ) ) {
			return false;
		}

		$petition = get_post( $petition_id );
		if ( ! $petition ) {
			return false;
		}

		$admin_email = get_option( 'jtc_admin_notify_email', get_option( 'admin_email' ) );

		/* translators: %s petition title */
		$subject = sprintf( __( '[JTC] New signature on "%s"', 'join-the-cause' ), $petition->post_title );

		$body = sprintf(
			/* translators: 1 name, 2 email, 3 petition title, 4 admin URL */
			__(
				"%1\$s %2\$s (%3\$s) just signed \"%4\$s\".\n\nView all supporters: %5\$s",
				'join-the-cause'
			),
			$supporter['first_name'],
			$supporter['last_name'],
			$supporter['email'],
			$petition->post_title,
			admin_url( 'admin.php?page=jtc-supporters&petition_id=' . $petition_id )
		);

		return $this->send( $admin_email, $subject, $body );
	}


	// ─── Core send dispatcher ─────────────────────────────────────────────────

	/**
	 * Dispatch a single email through whichever method is configured.
	 *
	 * @param string $to          Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body        Plain text or HTML.
	 * @param bool   $is_html     True to send as HTML.
	 * @return bool Result value.
	 */
	public function send( string $to, string $subject, string $body, bool $is_html = false ): bool {
		$this->last_error = '';

		if ( ! is_email( $to ) ) {
			$this->last_error = __( 'Recipient email address is invalid.', 'join-the-cause' );
			return false;
		}

		$sent = match ( $this->method ) {
			'api'   => $this->send_via_api( $to, $subject, $body, $is_html ),
			default => $this->send_via_wp_mail( $to, $subject, $body, $is_html ),
		};

		// Persist the last outcome so the Help tab can show diagnostics.
		// The static keeps bulk sends (newsletters) from doing option
		// lookups/writes on every single recipient.
		static $mail_error_exists = null;

		if ( $sent ) {
			if ( null === $mail_error_exists ) {
				$mail_error_exists = '' !== (string) get_option( 'jtc_last_mailer_error', '' );
			}
			if ( $mail_error_exists ) {
				delete_option( 'jtc_last_mailer_error' );
				delete_option( 'jtc_last_mailer_error_time' );
				$mail_error_exists = false;
			}
		} elseif ( '' !== $this->last_error ) {
			update_option( 'jtc_last_mailer_error', $this->last_error, true );
			update_option( 'jtc_last_mailer_error_time', current_time( 'mysql' ), true );
			$mail_error_exists = true;
		}

		return $sent;
	}

	// ─── wp_mail (default + SMTP override) ───────────────────────────────────
	/**
	 * Send via wp mail.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body Message body or request payload.
	 * @param bool   $is_html Whether the body is HTML.
	 * @return bool Result value.
	 */
	private function send_via_wp_mail( string $to, string $subject, string $body, bool $is_html ): bool {
		$headers = array(
			'From: ' . $this->format_from_header(),
		);

		if ( $is_html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		// If SMTP override is configured, hook into phpmailer_init.
		if ( 'smtp' === $this->method ) {
			add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
		}

		$capture_error = function ( WP_Error $error ): void {
			$this->last_error = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture_error );

		$result = wp_mail( $to, $subject, $body, $headers );

		remove_action( 'wp_mail_failed', $capture_error );

		if ( 'smtp' === $this->method ) {
			remove_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
		}

		if ( ! $result && '' === $this->last_error ) {
			$this->last_error = __( 'WordPress could not send the email.', 'join-the-cause' );
		}

		return $result;
	}

	/**
	 * Configures PHPMailer for SMTP when hooked into phpmailer_init.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer PHPMailer instance.
	 */
	public function configure_smtp( \PHPMailer\PHPMailer\PHPMailer $mailer ): void {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer defines these external property names.
		$mailer->isSMTP();
		$mailer->Host                         = get_option( 'jtc_smtp_host', '' );
		$mailer->Port                         = (int) get_option( 'jtc_smtp_port', 587 );
		$mailer->Username                     = get_option( 'jtc_smtp_username', '' );
		$mailer->Password                     = jtc_get_secret( 'jtc_smtp_password' );
		$encryption                           = get_option( 'jtc_smtp_encryption', 'tls' );
		$mailer->SMTPSecure                   = 'none' === $encryption ? '' : $encryption;
		$mailer->Timeout                      = 15;
		$mailer->getSMTPInstance()->Timelimit = 15;
		$mailer->SMTPAuth                     = (bool) $mailer->Username;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	// ─── API (Mailgun + SendGrid) ────────────────────────────────────────────
	/**
	 * Send via api.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body Message body or request payload.
	 * @param bool   $is_html Whether the body is HTML.
	 * @return bool Result value.
	 */
	private function send_via_api( string $to, string $subject, string $body, bool $is_html ): bool {
		$provider = get_option( 'jtc_api_provider', 'mailgun' );
		$api_key  = jtc_get_secret( 'jtc_api_key' );

		if ( empty( $api_key ) ) {
			$this->last_error = __( 'API key is missing.', 'join-the-cause' );
			return false;
		}

		return match ( $provider ) {
			'sendgrid' => $this->sendgrid( $to, $subject, $body, $is_html, $api_key ),
			'mailgun'  => $this->mailgun( $to, $subject, $body, $is_html, $api_key ),
			default    => $this->unsupported_api_provider(),
		};
	}
	/**
	 * Unsupported api provider.
	 *
	 * @return bool Result value.
	 */
	private function unsupported_api_provider(): bool {
		$this->last_error = __( 'Selected API provider is not supported.', 'join-the-cause' );
		return false;
	}
	/**
	 * Sendgrid.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body Message body or request payload.
	 * @param bool   $is_html Whether the body is HTML.
	 * @param string $api_key Api key.
	 * @return bool Result value.
	 */
	private function sendgrid( string $to, string $subject, string $body, bool $is_html, string $api_key ): bool {
		$payload = array(
			'personalizations' => array( array( 'to' => array( array( 'email' => $to ) ) ) ),
			'from'             => array(
				'email' => $this->from_email,
				'name'  => $this->from_name,
			),
			'subject'          => $subject,
			'content'          => array(
				array(
					'type'  => $is_html ? 'text/html' : 'text/plain',
					'value' => $body,
				),
			),
		);

		$response = wp_remote_post(
			'https://api.sendgrid.com/v3/mail/send',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$this->last_error = sprintf(
			/* translators: %d HTTP response code */
			__( 'SendGrid returned HTTP %d.', 'join-the-cause' ),
			$code
		);
		return false;
	}
	/**
	 * Mailgun.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body Message body or request payload.
	 * @param bool   $is_html Whether the body is HTML.
	 * @param string $api_key Api key.
	 * @return bool Result value.
	 */
	private function mailgun( string $to, string $subject, string $body, bool $is_html, string $api_key ): bool {
		$domain = $this->normalize_mailgun_domain( get_option( 'jtc_mailgun_domain', '' ) );
		if ( '' === $domain ) {
			$this->last_error = __( 'Mailgun domain is missing.', 'join-the-cause' );
			return false;
		}

		$region   = get_option( 'jtc_mailgun_region', 'us' );
		$base_url = 'eu' === $region ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
		$endpoint = $base_url . '/v3/' . rawurlencode( $domain ) . '/messages';
		$body_key = $is_html ? 'html' : 'text';

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication encoding required by Mailgun.
				),
				'body'    => array(
					'from'    => $this->format_from_header(),
					'to'      => $to,
					'subject' => $subject,
					$body_key => $body,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$message = wp_remote_retrieve_body( $response );
		$data    = json_decode( $message, true );
		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			$message = $data['message'];
		}

		$this->last_error = sprintf(
			/* translators: 1 HTTP response code, 2 API response message */
			__( 'Mailgun returned HTTP %1$d. %2$s', 'join-the-cause' ),
			$code,
			wp_strip_all_tags( (string) $message )
		);
		return false;
	}
	/**
	 * Normalize mailgun domain.
	 *
	 * @param string $domain Domain.
	 * @return string Result value.
	 */
	private function normalize_mailgun_domain( string $domain ): string {
		$domain = trim( strtolower( $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = strtok( $domain, '/:' );

		return sanitize_text_field( (string) $domain );
	}
	/**
	 * Format from header.
	 *
	 * @return string Result value.
	 */
	private function format_from_header(): string {
		$name = trim( str_replace( array( "\r", "\n" ), '', $this->from_name ) );
		return '' === $name ? $this->from_email : sprintf( '%s <%s>', $name, $this->from_email );
	}

	// ─── Token replacement ────────────────────────────────────────────────────

	/**
	 * Replaces {tokens} in email subjects and bodies.
	 *
	 * Available: {first_name}, {last_name}, {email}, {petition_title},
	 * {petition_url}, {petition_short_url}, {site_name}, {site_url}
	 *
	 * @param string  $text Template text.
	 * @param array   $supporter Supporter row data.
	 * @param WP_Post $petition Petition post object.
	 * @return string Result value.
	 */
	private function replace_tokens( string $text, array $supporter, WP_Post $petition ): string {
		$canonical_url = (string) get_permalink( $petition->ID );
		$share_url     = jtc_get_petition_share_url( $petition->ID, false );
		$short_url     = jtc_fallback( jtc_get_petition_short_url( $petition->ID ), $canonical_url );

		$tokens = array(
			'{first_name}'         => $supporter['first_name'] ?? '',
			'{last_name}'          => $supporter['last_name'] ?? '',
			'{email}'              => $supporter['email'] ?? '',
			'{petition_title}'     => $petition->post_title,
			'{petition_url}'       => $share_url,
			'{petition_short_url}' => $short_url,
			'{site_name}'          => get_bloginfo( 'name' ),
			'{site_url}'           => home_url(),
		);

		return str_replace( array_keys( $tokens ), array_values( $tokens ), $text );
	}
}
