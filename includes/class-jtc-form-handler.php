<?php
/**
 * Handles the AJAX petition signature form submission.
 *
 * Security:  nonce verification + sanitisation + prepared statements.
 * Duplicate: one email address per petition.
 * Rate limit: 5 submissions per IP per hour (via transients).
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JTC_Form_Handler {

	public function register(): void {
		add_action( 'wp_ajax_jtc_sign_petition',        [ $this, 'handle' ] );
		add_action( 'wp_ajax_nopriv_jtc_sign_petition', [ $this, 'handle' ] );
	}

	// ─── Main handler ─────────────────────────────────────────────────────────

	public function handle(): void {
		// Verify nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'jtc_sign_petition' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh and try again.', 'join-the-cause' ) ], 403 );
		}

		// Petition ID.
		$petition_id = isset( $_POST['petition_id'] ) ? absint( $_POST['petition_id'] ) : 0;
		if ( ! $petition_id || JTC_CPT !== get_post_type( $petition_id ) || 'publish' !== get_post_status( $petition_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Petition not found.', 'join-the-cause' ) ], 404 );
		}

		// Rate limit: max 5 submissions per IP per hour.
		$ip          = $this->get_ip();
		$rate_key    = 'jtc_rate_' . md5( $ip );
		$rate_count  = (int) get_transient( $rate_key );

		if ( $rate_count >= 5 ) {
			wp_send_json_error( [ 'message' => __( 'Too many submissions. Please try again later.', 'join-the-cause' ) ], 429 );
		}

		// Core required fields.
		$first_name = sanitize_text_field( wp_unslash( $_POST['jtc_first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( wp_unslash( $_POST['jtc_last_name']  ?? '' ) );
		$email      = sanitize_email( wp_unslash( $_POST['jtc_email'] ?? '' ) );

		$errors = [];

		if ( empty( $first_name ) ) $errors[] = __( 'First name is required.', 'join-the-cause' );
		if ( empty( $last_name ) )  $errors[] = __( 'Last name is required.',  'join-the-cause' );
		if ( ! is_email( $email ) ) $errors[] = __( 'A valid email address is required.', 'join-the-cause' );

		if ( $errors ) {
			wp_send_json_error( [ 'message' => implode( ' ', $errors ) ], 422 );
		}

		// Duplicate check: same email + same petition.
		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}jtc_supporters WHERE email = %s AND petition_id = %d LIMIT 1",
				$email,
				$petition_id
			)
		);

		if ( $existing ) {
			wp_send_json_error( [ 'message' => __( "You've already signed this petition. Thank you for your support!", 'join-the-cause' ) ], 409 );
		}

		// Display name consent (only relevant when show_recent is on).
		$display_consent = ! empty( $_POST['jtc_display_consent'] ) ? 1 : 0;

		// Extra / custom form fields.
		$extra_fields = $this->collect_extra_fields( $petition_id );

		// Insert supporter.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'jtc_supporters',
			[
				'petition_id'     => $petition_id,
				'first_name'      => $first_name,
				'last_name'       => $last_name,
				'email'           => $email,
				'display_consent' => $display_consent,
				'extra_fields'    => wp_json_encode( $extra_fields ),
				'ip_address'      => $ip,
				'signed_at'       => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			wp_send_json_error( [ 'message' => __( 'Could not save your signature. Please try again.', 'join-the-cause' ) ], 500 );
		}

		// Bump rate limiter.
		set_transient( $rate_key, $rate_count + 1, HOUR_IN_SECONDS );

		// Fire emails asynchronously (still synchronous here but isolated via method).
		$supporter = [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'email'      => $email,
		];

		$mailer = new JTC_Mailer();
		$mailer->send_welcome( $supporter, $petition_id );
		$mailer->send_admin_notify( $supporter, $petition_id );

		// Get updated count and recent signers for JS to refresh the UI.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jtc_supporters WHERE petition_id = %d",
				$petition_id
			)
		);

		$recent = $this->get_recent_signers( $petition_id );

		// Determine what to do after signing.
		$settings      = get_post_meta( $petition_id, '_jtc_petition_settings', true );
		$defaults      = get_option( 'jtc_petition_defaults', [] );
		$after_action  = $settings['after_sign_action']   ?? $defaults['after_sign_action']   ?? 'message';
		$after_message = $settings['after_sign_message']  ?? $defaults['after_sign_message']  ?? __( 'Thank you for signing!', 'join-the-cause' );
		$after_url     = $settings['after_sign_redirect'] ?? $defaults['after_sign_redirect'] ?? '';

		wp_send_json_success( [
			'action'         => $after_action,
			'message'        => wp_kses_post( $after_message ),
			'redirect_url'   => esc_url( $after_url ),
			'count'          => $count,
			'recent_signers' => $recent,
		] );
	}

	// ─── Helper: extra custom fields ─────────────────────────────────────────

	/**
	 * Collects values for any extra petition-specific form fields.
	 * Returns an assoc array keyed by field ID.
	 */
	private function collect_extra_fields( int $petition_id ): array {
		$raw    = get_post_meta( $petition_id, '_jtc_form_fields', true );
		$fields = $raw ? json_decode( $raw, true ) : [];

		if ( ! is_array( $fields ) ) {
			return [];
		}

		$data = [];
		foreach ( $fields as $field ) {
			$field_id = $field['id'] ?? '';
			if ( ! $field_id ) continue;

			$post_key = 'jtc_extra_' . $field_id;
			$raw_val  = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : ''; // phpcs:ignore

			$data[ $field_id ] = [
				'label' => sanitize_text_field( $field['label'] ?? '' ),
				'value' => 'checkbox' === ( $field['type'] ?? '' )
					? (bool) $raw_val
					: sanitize_text_field( $raw_val ),
			];
		}

		return $data;
	}

	// ─── Helper: recent public signers ────────────────────────────────────────

	private function get_recent_signers( int $petition_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT first_name, last_name, signed_at
				 FROM {$wpdb->prefix}jtc_supporters
				 WHERE petition_id = %d AND display_consent = 1
				 ORDER BY signed_at DESC LIMIT 5",
				$petition_id
			),
			ARRAY_A
		);

		return array_map( function( array $r ): array {
			return [
				'name'      => esc_html( $r['first_name'] . ' ' . substr( $r['last_name'], 0, 1 ) . '.' ),
				'signed_at' => esc_html( human_time_diff( strtotime( $r['signed_at'] ), time() ) . ' ago' ),
			];
		}, $rows ?: [] );
	}

	// ─── Helper: real IP ─────────────────────────────────────────────────────

	private function get_ip(): string {
		$keys = [
			'HTTP_CF_CONNECTING_IP',    // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		foreach ( $keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// X-Forwarded-For can be a comma-separated list; take the first.
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}
}
