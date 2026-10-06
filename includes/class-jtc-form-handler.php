<?php
/**
 * Handles the AJAX petition signature form submission.
 *
 * Security:  nonce verification + sanitisation + prepared statements.
 * Duplicate: one email address per petition (SELECT fast-path + unique index).
 * Rate limit: atomic database buckets, counting every nonce-valid attempt.
 * Bot checks: honeypot field + minimum fill time (fails open for humans).
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Form Handler WordPress component. */
class JTC_Form_Handler {
	/**
	 * Register WordPress hooks for this component.
	 */
	public function register(): void {
		add_action( 'wp_ajax_jtc_sign_petition', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_jtc_sign_petition', array( $this, 'handle' ) );
		add_action( 'jtc_signature_mail', array( $this, 'send_signature_mail' ) );
		add_action( 'jtc_cleanup_rates', array( $this, 'cleanup_rates' ) );
	}

	// ─── Main handler ─────────────────────────────────────────────────────────
	/**
	 * Validate and save one petition signature.
	 */
	public function handle(): void {
		$petition_id = absint( jtc_post_input( 'petition_id' ) );
		$nonce       = sanitize_text_field( jtc_post_input( 'nonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'jtc_sign_petition_' . $petition_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'join-the-cause' ) ), 403 );
		}
		$petition = get_post( $petition_id );
		if ( ! $petition || JTC_CPT !== $petition->post_type || 'publish' !== $petition->post_status || post_password_required( $petition ) ) {
			wp_send_json_error( array( 'message' => __( 'Petition not found.', 'join-the-cause' ) ), 404 );
		}
		if ( '1' !== (string) get_option( 'jtc_signature_index_ready', '0' ) ) {
			wp_send_json_error( array( 'message' => __( 'Signing is temporarily unavailable. Please try again later.', 'join-the-cause' ) ), 503 );
		}

		$allowed = $this->consume_attempt( $this->get_ip(), $petition_id );
		if ( is_wp_error( $allowed ) ) {
			wp_send_json_error( array( 'message' => $allowed->get_error_message() ), 503 );
		}
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'Too many submissions. Please try again later.', 'join-the-cause' ) ), 429 );
		}
		if ( ! $this->passes_bot_checks() ) {
			wp_send_json_error( array( 'message' => __( 'Could not process the form. Please try again.', 'join-the-cause' ) ), 403 );
		}
		$first_name = sanitize_text_field( jtc_post_input( 'jtc_first_name' ) );
		$last_name  = sanitize_text_field( jtc_post_input( 'jtc_last_name' ) );
		$email      = strtolower( sanitize_email( jtc_post_input( 'jtc_email' ) ) );
		$errors     = array();
		if ( '' === $first_name ) {
			$errors[] = __( 'First name is required.', 'join-the-cause' );
		}
		if ( '' === $last_name ) {
			$errors[] = __( 'Last name is required.', 'join-the-cause' );
		}
		if ( ! is_email( $email ) || strlen( $email ) > 191 ) {
			$errors[] = __( 'A valid email address of at most 191 characters is required.', 'join-the-cause' );
		}
		if ( mb_strlen( $first_name, 'UTF-8' ) > 100 || mb_strlen( $last_name, 'UTF-8' ) > 100 ) {
			$errors[] = __( 'Names must contain at most 100 characters.', 'join-the-cause' );
		}
		$extra_fields = $this->validate_extra_fields( $petition_id, $errors );
		if ( $errors ) {
			wp_send_json_error( array( 'message' => implode( ' ', $errors ) ), 422 );
		}
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jtc_supporters WHERE email = %s AND petition_id = %d LIMIT 1", $email, $petition_id ) );
		if ( ! $existing ) {
			$previous     = $wpdb->suppress_errors( true );
			$inserted     = $wpdb->insert(
				$wpdb->prefix . 'jtc_supporters',
				array(
					'petition_id'        => $petition_id,
					'first_name'         => $first_name,
					'last_name'          => $last_name,
					'email'              => $email,
					'display_consent'    => '1' === jtc_post_input( 'jtc_display_consent' ) ? 1 : 0,
					'newsletter_consent' => '1' === jtc_post_input( 'jtc_newsletter_consent' ) ? 1 : 0,
					'extra_fields'       => wp_json_encode( $extra_fields ),
					'ip_address'         => '',
					'signed_at'          => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
			);
			$supporter_id = (int) $wpdb->insert_id;
			$wpdb->suppress_errors( $previous );
			if ( ! $inserted ) {
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jtc_supporters WHERE email = %s AND petition_id = %d LIMIT 1", $email, $petition_id ) );
				if ( ! $existing ) {
					wp_send_json_error( array( 'message' => __( 'Could not save your signature. Please try again.', 'join-the-cause' ) ), 500 );
				}
			} else {
				// Schedule only an ID; never copy personal data into the cron option.
				wp_schedule_single_event( time() + 1, 'jtc_signature_mail', array( $supporter_id ) );
			}
		}
		// An existing address receives the same public response. It never changes
		// the original name, consent, or triggers another confirmation email.
		$count    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jtc_supporters WHERE petition_id = %d", $petition_id ) );
		$settings = get_post_meta( $petition_id, '_jtc_petition_settings', true );
		$defaults = get_option( 'jtc_petition_defaults', array() );
		$settings = array_merge( is_array( $defaults ) ? $defaults : array(), is_array( $settings ) ? $settings : array() );
		wp_send_json_success(
			array(
				'action'          => $settings['after_sign_action'] ?? 'message',
				'message'         => wp_kses_post( $settings['after_sign_message'] ?? __( 'Thank you for signing! Your name has been added to the petition.', 'join-the-cause' ) ),
				'redirect_url'    => esc_url( $settings['after_sign_redirect'] ?? '' ),
				'share_url'       => esc_url( jtc_get_petition_share_url( $petition_id, false ) ),
				'count'           => $count,
				'count_formatted' => number_format_i18n( $count ),
				'recent_signers'  => ! empty( $settings['show_recent'] ) ? $this->get_recent_signers( $petition_id ) : array(),
			)
		);
	}

	/**
	 * Atomically increment a fixed-window bucket; database failures fail closed.
	 *
	 * @param string $ip Client connection IP.
	 * @param int    $petition_id Petition post ID.
	 */
	public function consume_attempt( string $ip, int $petition_id ) {
		global $wpdb;
		$now    = time();
		$max    = max( 1, (int) apply_filters( 'jtc_rate_limit_max', 5, $petition_id ) );
		$window = max( 1, (int) apply_filters( 'jtc_rate_limit_window', HOUR_IN_SECONDS, $petition_id ) );
		$bucket = hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}jtc_rate_limits (bucket, attempts, expires_at)
			 VALUES (%s, LAST_INSERT_ID(1), %d)
			 ON DUPLICATE KEY UPDATE
			 attempts = LAST_INSERT_ID(IF(expires_at <= %d, 1, LEAST(attempts + 1, 4294967295))),
			 expires_at = IF(expires_at <= %d, %d, expires_at)",
				$bucket,
				$now + $window,
				$now,
				$now,
				$now + $window
			)
		);
		if ( false === $result ) {
			return new WP_Error( 'rate_unavailable', __( 'The form is temporarily unavailable. Please try again later.', 'join-the-cause' ) );
		}
		return (int) $wpdb->insert_id <= $max;
	}

	/**
	 * Remove expired, keyed IP hashes; active buckets contain no plaintext IPs.
	 */
	public function cleanup_rates(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}jtc_rate_limits WHERE expires_at < %d", time() ) );
	}

	/**
	 * Deliver transactional mail after the signature response has returned.
	 *
	 * @param int $supporter_id Supporter row ID.
	 */
	public function send_signature_mail( int $supporter_id ): void {
		global $wpdb;
		$supporter = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jtc_supporters WHERE id = %d", $supporter_id ), ARRAY_A );
		if ( ! $supporter ) {
			return;
		}
		$mailer = new JTC_Mailer();
		$mailer->send_welcome( $supporter, (int) $supporter['petition_id'] );
		$mailer->send_admin_notify( $supporter, (int) $supporter['petition_id'] );
	}

	// ─── Helper: bot checks ──────────────────────────────────────────────────

	/**
	 * Bot mitigation: hidden honeypot field + minimum fill time.
	 * Both checks fail open when their inputs are missing, so cached pages
	 * and slow connections never block real signers; the honeypot is the
	 * primary signal and the time check only rejects sub-2-second fills.
	 *
	 * @return bool Result value.
	 */
	private function passes_bot_checks(): bool {
		// Honeypot: off-screen field humans never see or fill.
		$honeypot = jtc_post_input( 'jtc_website' );
		if ( '' !== $honeypot ) {
			return false;
		}

		// Minimum fill time (2s). Missing timestamps pass through.
		$form_time = absint( jtc_post_input( 'jtc_form_time' ) );
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
	 * Validates and collects values for extra petition-specific form fields,.
	 * enforcing `required` server-side. Field-specific errors are appended to
	 * $errors (by reference). Returns an assoc array keyed by field ID.
	 *
	 * @param int   $petition_id Petition post ID.
	 * @param array $errors      Validation errors (by reference).
	 * @return array<string, array{label:string,value:mixed}>
	 */
	private function validate_extra_fields( int $petition_id, array &$errors ): array {
		$raw    = get_post_meta( $petition_id, '_jtc_form_fields', true );
		$fields = is_string( $raw ) ? json_decode( $raw, true ) : array();

		if ( ! is_array( $fields ) ) {
			return array();
		}

		$data = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$field_id = isset( $field['id'] ) ? sanitize_key( $field['id'] ) : '';
			if ( '' === $field_id ) {
				continue;
			}

			$type     = (string) ( $field['type'] ?? 'text' );
			$required = ! empty( $field['required'] );
			$label    = sanitize_text_field( (string) ( $field['label'] ?? $field_id ) );
			$post_key = 'jtc_extra_' . $field_id;
			$raw_val  = jtc_post_input( $post_key, 'textarea' );

			// Non-scalar input (e.g. `jtc_extra_x[]=…`) is invalid for every
			// branch below; cast it to empty rather than letting `(string)`
			// warn and store the literal "Array".
			$raw_val = is_array( $raw_val ) ? '' : $raw_val;

			if ( 'checkbox' === $type ) {
				$value = ! empty( $raw_val ) ? 1 : 0;

				if ( $required && ! $value ) {
					/* translators: %s: Form field label. */
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			} elseif ( 'select' === $type ) {
				$value   = sanitize_text_field( (string) $raw_val );
				$options = array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) );

				if ( '' !== $value && ! in_array( $value, $options, true ) ) {
					$value = ''; // Reject values that are not offered by the field.
				}
				if ( $required && '' === $value ) {
					/* translators: %s: Form field label. */
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			} elseif ( 'textarea' === $type ) {
				$value = sanitize_textarea_field( (string) $raw_val );

				if ( $required && '' === trim( $value ) ) {
					/* translators: %s: Form field label. */
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			} elseif ( 'email' === $type ) {
				$value = sanitize_text_field( (string) $raw_val );

				if ( '' !== $value && ! is_email( $value ) ) {
					/* translators: %s: Form field label. */
					$errors[] = sprintf( __( '%s must be a valid email address.', 'join-the-cause' ), $label );
				} elseif ( $required && '' === $value ) {
					/* translators: %s: Form field label. */
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			} else {
				$value = sanitize_text_field( (string) $raw_val );

				if ( $required && '' === $value ) {
					/* translators: %s: Form field label. */
					$errors[] = sprintf( __( '%s is required.', 'join-the-cause' ), $label );
				}
			}

			$data[ $field_id ] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		return $data;
	}

	// ─── Helper: recent public signers ────────────────────────────────────────
	/**
	 * Get recent signers.
	 *
	 * @param int $petition_id Petition post ID.
	 * @return array Result value.
	 */
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

		return array_map(
			function ( array $r ): array {
				return array(
					// JSON-only payload (the client escapes once on render), so no
					// HTML escaping here — escaping twice showed literal entities.
					'name'            => $r['first_name'] . ' ' . jtc_name_initial( $r['last_name'] ) . '.',
					// Raw signup datetime for the client's time element.
					'signed_at'       => $r['signed_at'],
					// …plus the humanised label matching the initial server render.
					'signed_at_human' => human_time_diff( (int) get_gmt_from_date( $r['signed_at'], 'U' ), time() ) . ' ' . __( 'ago', 'join-the-cause' ),
				);
			},
			jtc_fallback( $rows, array() )
		);
	}

	// ─── Helper: real IP ─────────────────────────────────────────────────────

	/**
	 * Returns the client IP used for rate limiting.
	 *
	 * REMOTE_ADDR is used by default. Proxy/edge headers (Cloudflare,
	 * X-Forwarded-For, X-Real-IP) are only honored when the site explicitly
	 * opts in by defining JTC_TRUST_PROXY_HEADERS as true — otherwise any
	 * visitor could spoof the header and get a fresh rate-limit bucket.
	 *
	 * @return string Result value.
	 */
	private function get_ip(): string {
		$ip = '';

		if ( defined( 'JTC_TRUST_PROXY_HEADERS' ) && JTC_TRUST_PROXY_HEADERS ) {
			$forwarded_keys = array(
				'HTTP_CF_CONNECTING_IP', // Cloudflare.
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_REAL_IP',
			);

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
