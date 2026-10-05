<?php
/**
 * Handles the AJAX petition signature form submission.
 *
 * Security:  nonce verification + sanitisation + prepared statements.
 * Duplicate: one email address per petition (SELECT fast-path + unique index).
 * Rate limit: 5 submissions per IP per hour (via transients, filterable).
 * Bot checks: honeypot field + minimum fill time (fails open for humans).
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

		// Bot mitigation: honeypot + minimum fill time. Friendly, non-specific
		// message; generous thresholds so real people never trip it.
		if ( ! $this->passes_bot_checks() ) {
			wp_send_json_error( [ 'message' => __( 'Could not process the form. Please try again.', 'join-the-cause' ) ], 403 );
		}

		// Rate limit: max 5 submissions per IP per hour (filterable).
		$ip          = $this->get_ip();
		$rate_key    = 'jtc_rate_' . md5( $ip );
		$rate_count  = (int) get_transient( $rate_key );
		$rate_max    = (int) apply_filters( 'jtc_rate_limit_max', 5, $petition_id );
		$rate_window = (int) apply_filters( 'jtc_rate_limit_window', HOUR_IN_SECONDS, $petition_id );

		if ( $rate_count >= max( 1, $rate_max ) ) {
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

		// Extra / custom form fields — required flags enforced server-side.
		$extra_fields = $this->validate_extra_fields( $petition_id, $errors );

		if ( $errors ) {
			wp_send_json_error( [ 'message' => implode( ' ', $errors ) ], 422 );
		}

		// Duplicate check: same email + same petition (fast path; the unique
		// index from schema v2 is the authority under concurrency).
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

		// Insert supporter.
		$wpdb->suppress_errors( true );
		$inserted     = $wpdb->insert(
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
		$insert_error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( false );

		if ( ! $inserted ) {
			if ( false !== stripos( $insert_error, 'duplicate' ) ) {
				// Lost a race with a concurrent identical sign.
				wp_send_json_error( [ 'message' => __( "You've already signed this petition. Thank you for your support!", 'join-the-cause' ) ], 409 );
			}

			// Re-check in case the insert failed for a duplicate reason we
			// could not classify (legacy installs without the unique index).
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

			wp_send_json_error( [ 'message' => __( 'Could not save your signature. Please try again.', 'join-the-cause' ) ], 500 );
		}

		// Bump rate limiter.
		set_transient( $rate_key, $rate_count + 1, $rate_window );

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
			'share_url'      => esc_url( jtc_get_petition_share_url( $petition_id ) ),
			'count'          => $count,
			'recent_signers' => $recent,
		] );
	}

	// ─── Helper: bot checks ──────────────────────────────────────────────────

	/**
	 * Bot mitigation: hidden honeypot field + minimum fill time.
	 * Both checks fail open when their inputs are missing, so cached pages
	 * and slow connections never block real signers; the honeypot is the
	 * primary signal and the time check only rejects sub-2-second fills.
	 */
	private function passes_bot_checks(): bool {
		// Honeypot: off-screen field humans never see or fill.
		$honeypot = isset( $_POST['jtc_website'] ) ? sanitize_text_field( wp_unslash( $_POST['jtc_website'] ) ) : '';
		if ( '' !== $honeypot ) {
			return false;
		}

		// Minimum fill time (2s). Missing timestamps pass through.
		$form_time = isset( $_POST['jtc_form_time'] ) ? absint( $_POST['jtc_form_time'] ) : 0;
		if ( $form_time ) {
			$elapsed = time() - $form_time;
			if ( $elapsed >= 0 && $elapsed < 2 ) {
				return false;
			}
			if ( $elapsed < 0 ) {
				return false; // Timestamp in the future: tampered payload.
			}
		}

		return true;
	}

	// ─── Helper: extra custom fields ─────────────────────────────────────────

	/**
	 * Validates and collects values for extra petition-specific form fields,
	 * enforcing `required` server-side. Field-specific errors are appended to
	 * $errors (by reference). Returns an assoc array keyed by field ID.
	 *
	 * @param int   $petition_id
	 * @param array $errors      Validation errors (by reference).
	 * @return array<string, array{label:string,value:mixed}>
	 */
	private function validate_extra_fields( int $petition_id, array &$errors ): array {
		$raw    = get_post_meta( $petition_id, '_jtc_form_fields', true );
		$fields = $raw ? json_decode( $raw, true ) : [];

		if ( ! is_array( $fields ) ) {
			return [];
		}

		$data = [];

		foreach ( $fields as $field ) {
			$field_id = isset( $field['id'] ) ? sanitize_key( $field['id'] ) : '';
			if ( '' === $field_id ) {
				continue;
			}

			$type     = (string) ( $field['type'] ?? 'text' );
			$required = ! empty( $field['required'] );
			$label    = sanitize_text_field( (string) ( $field['label'] ?? $field_id ) );
			$post_key = 'jtc_extra_' . $field_id;
			$raw_val  = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			// Non-scalar input (e.g. `jtc_extra_x[]=…`) is invalid for every
			// branch below; cast it to empty rather than letting `(string)`
			// warn and store the literal "Array".
			$raw_val = is_array( $raw_val ) ? '' : $raw_val;

			if ( 'checkbox' === $type ) {
				$value = ! empty( $raw_val ) ? 1 : 0;

				if ( $required && ! $value ) {
					/* translators: %s form field label */
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			} elseif ( 'select' === $type ) {
				$value   = sanitize_text_field( (string) $raw_val );
				$options = array_map( 'sanitize_text_field', (array) ( $field['options'] ?? [] ) );

				if ( '' !== $value && ! in_array( $value, $options, true ) ) {
					$value = ''; // Reject values that are not offered by the field.
				}
				if ( $required && '' === $value ) {
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			} elseif ( 'textarea' === $type ) {
				$value = sanitize_textarea_field( (string) $raw_val );

				if ( $required && '' === trim( $value ) ) {
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			} elseif ( 'email' === $type ) {
				$value = sanitize_text_field( (string) $raw_val );

				if ( '' !== $value && ! is_email( $value ) ) {
					/* translators: %s form field label */
					$errors[] = sprintf( __( '%s must be a valid email address.', 'join-the-cause' ), $label );
				} elseif ( $required && '' === $value ) {
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			} else {
				$value = sanitize_text_field( (string) $raw_val );

				if ( $required && '' === $value ) {
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			}

			$data[ $field_id ] = [
				'label' => $label,
				'value' => $value,
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
				// JSON-only payload (the client escapes once on render), so no
				// HTML escaping here — escaping twice showed literal entities.
				'name'            => $r['first_name'] . ' ' . substr( $r['last_name'], 0, 1 ) . '.',
				// Raw signup datetime for the client's <time datetime="…">…
				'signed_at'       => $r['signed_at'],
				// …plus the humanised label matching the initial server render.
				'signed_at_human' => human_time_diff( strtotime( $r['signed_at'] ), time() ) . ' ' . __( 'ago', 'join-the-cause' ),
			];
		}, $rows ?: [] );
	}

	// ─── Helper: real IP ─────────────────────────────────────────────────────

	/**
	 * Returns the client IP used for rate limiting.
	 *
	 * REMOTE_ADDR is used by default. Proxy/edge headers (Cloudflare,
	 * X-Forwarded-For, X-Real-IP) are only honored when the site explicitly
	 * opts in by defining JTC_TRUST_PROXY_HEADERS as true — otherwise any
	 * visitor could spoof the header and get a fresh rate-limit bucket.
	 */
	private function get_ip(): string {
		$ip = '';

		if ( defined( 'JTC_TRUST_PROXY_HEADERS' ) && JTC_TRUST_PROXY_HEADERS ) {
			$forwarded_keys = [
				'HTTP_CF_CONNECTING_IP', // Cloudflare.
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_REAL_IP',
			];

			foreach ( $forwarded_keys as $key ) {
				if ( empty( $_SERVER[ $key ] ) ) {
					continue;
				}

				$candidate = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// X-Forwarded-For can be a comma-separated list; take the first.
				$candidate = trim( explode( ',', $candidate )[0] );

				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}

		if ( '' === $ip && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}

		return '' !== $ip ? $ip : '0.0.0.0';
	}
}
