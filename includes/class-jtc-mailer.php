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

class JTC_Mailer {

	private string $method;
	private string $from_name;
	private string $from_email;
	private string $last_error = '';

	public function __construct() {
		$this->method     = get_option( 'jtc_email_method', 'wp_mail' );
		$this->from_name  = get_option( 'jtc_from_name',   get_bloginfo( 'name' ) );
		$this->from_email = get_option( 'jtc_from_email',  get_option( 'admin_email' ) );
	}

	public function get_last_error(): string {
		return $this->last_error;
	}

	// ─── Public send methods ──────────────────────────────────────────────────

	/**
	 * Send the welcome/confirmation email to a new signer.
	 *
	 * @param array $supporter  Row data (first_name, last_name, email, petition_id).
	 * @param int   $petition_id
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

		return $this->send( $supporter['email'], $subject, $body );
	}

	/**
	 * Notify the admin when a new signature is received.
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

	/**
	 * Send a newsletter blast to all signers of a petition (or all signers if
	 * petition_id === 0).
	 *
	 * @param int    $newsletter_id  Row ID in jtc_newsletters table.
	 * @param int    $petition_id    0 = all signers.
	 * @param string $subject
	 * @param string $html_content
	 */
	public function send_newsletter( int $newsletter_id, int $petition_id, string $subject, string $html_content ): int {
		global $wpdb;

		$query = ( 0 === $petition_id )
			? "SELECT DISTINCT email, first_name FROM {$wpdb->prefix}jtc_supporters"
			: $wpdb->prepare(
				"SELECT DISTINCT email, first_name FROM {$wpdb->prefix}jtc_supporters WHERE petition_id = %d",
				$petition_id
			);

		$recipients = $wpdb->get_results( $query ); // phpcs:ignore

		$count = 0;
		foreach ( $recipients as $r ) {
			$personalised = str_replace( '{first_name}', esc_html( $r->first_name ), $html_content );
			if ( $this->send( $r->email, $subject, $personalised, true ) ) {
				$count++;
			}
		}

		// Update send record.
		$wpdb->update(
			$wpdb->prefix . 'jtc_newsletters',
			[
				'status'           => 'sent',
				'sent_at'          => current_time( 'mysql' ),
				'recipients_count' => $count,
			],
			[ 'id' => $newsletter_id ],
			[ '%s', '%s', '%d' ],
			[ '%d' ]
		);

		return $count;
	}

	// ─── Core send dispatcher ─────────────────────────────────────────────────

	/**
	 * Dispatch a single email through whichever method is configured.
	 *
	 * @param string $to          Recipient email.
	 * @param string $subject
	 * @param string $body        Plain text or HTML.
	 * @param bool   $is_html     True to send as HTML.
	 */
	public function send( string $to, string $subject, string $body, bool $is_html = false ): bool {
		$this->last_error = '';

		if ( ! is_email( $to ) ) {
			$this->last_error = __( 'Recipient email address is invalid.', 'join-the-cause' );
			return false;
		}

		return match ( $this->method ) {
			'api'  => $this->send_via_api( $to, $subject, $body, $is_html ),
			default => $this->send_via_wp_mail( $to, $subject, $body, $is_html ),
		};
	}

	// ─── wp_mail (default + SMTP override) ───────────────────────────────────

	private function send_via_wp_mail( string $to, string $subject, string $body, bool $is_html ): bool {
		$headers = [
			"From: {$this->from_name} <{$this->from_email}>",
		];

		if ( $is_html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		// If SMTP override is configured, hook into phpmailer_init.
		if ( 'smtp' === $this->method ) {
			add_action( 'phpmailer_init', [ $this, 'configure_smtp' ] );
		}

		$capture_error = function ( WP_Error $error ): void {
			$this->last_error = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture_error );

		$result = wp_mail( $to, $subject, $body, $headers );

		remove_action( 'wp_mail_failed', $capture_error );

		if ( 'smtp' === $this->method ) {
			remove_action( 'phpmailer_init', [ $this, 'configure_smtp' ] );
		}

		if ( ! $result && '' === $this->last_error ) {
			$this->last_error = __( 'WordPress could not send the email.', 'join-the-cause' );
		}

		return $result;
	}

	/**
	 * Configures PHPMailer for SMTP when hooked into phpmailer_init.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer
	 */
	public function configure_smtp( object $mailer ): void {
		$mailer->isSMTP();
		$mailer->Host       = get_option( 'jtc_smtp_host', '' );
		$mailer->Port       = (int) get_option( 'jtc_smtp_port', 587 );
		$mailer->Username   = get_option( 'jtc_smtp_username', '' );
		$mailer->Password   = get_option( 'jtc_smtp_password', '' );
		$encryption         = get_option( 'jtc_smtp_encryption', 'tls' );
		$mailer->SMTPSecure = 'none' === $encryption ? '' : $encryption;
		$mailer->SMTPAuth   = (bool) $mailer->Username;
	}

	// ─── API (Mailgun + SendGrid) ────────────────────────────────────────────

	private function send_via_api( string $to, string $subject, string $body, bool $is_html ): bool {
		$provider = get_option( 'jtc_api_provider', 'mailgun' );
		$api_key  = get_option( 'jtc_api_key', '' );

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

	private function unsupported_api_provider(): bool {
		$this->last_error = __( 'Selected API provider is not supported.', 'join-the-cause' );
		return false;
	}

	private function sendgrid( string $to, string $subject, string $body, bool $is_html, string $api_key ): bool {
		$payload = [
			'personalizations' => [ [ 'to' => [ [ 'email' => $to ] ] ] ],
			'from'             => [ 'email' => $this->from_email, 'name' => $this->from_name ],
			'subject'          => $subject,
			'content'          => [ [
				'type'  => $is_html ? 'text/html' : 'text/plain',
				'value' => $body,
			] ],
		];

		$response = wp_remote_post( 'https://api.sendgrid.com/v3/mail/send', [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		] );

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

		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ),
			],
			'body'    => [
				'from'    => $this->format_from_header(),
				'to'      => $to,
				'subject' => $subject,
				$body_key => $body,
			],
			'timeout' => 15,
		] );

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

	private function normalize_mailgun_domain( string $domain ): string {
		$domain = trim( strtolower( $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = strtok( $domain, '/:' );

		return sanitize_text_field( (string) $domain );
	}

	private function format_from_header(): string {
		$name = trim( str_replace( [ "\r", "\n" ], '', $this->from_name ) );
		return '' === $name ? $this->from_email : sprintf( '%s <%s>', $name, $this->from_email );
	}

	// ─── Token replacement ────────────────────────────────────────────────────

	/**
	 * Replaces {tokens} in email subjects and bodies.
	 *
	 * Available: {first_name}, {last_name}, {email}, {petition_title},
	 *            {petition_url}, {site_name}, {site_url}
	 */
	private function replace_tokens( string $text, array $supporter, WP_Post $petition ): string {
		$tokens = [
			'{first_name}'     => $supporter['first_name'] ?? '',
			'{last_name}'      => $supporter['last_name']  ?? '',
			'{email}'          => $supporter['email']      ?? '',
			'{petition_title}' => $petition->post_title,
			'{petition_url}'   => get_permalink( $petition->ID ),
			'{site_name}'      => get_bloginfo( 'name' ),
			'{site_url}'       => home_url(),
		];

		return str_replace( array_keys( $tokens ), array_values( $tokens ), $text );
	}
}
