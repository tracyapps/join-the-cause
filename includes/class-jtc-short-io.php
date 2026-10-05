<?php
/**
 * Short.io API client and petition short-link helpers.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JTC_Short_IO {

	private const API_BASE = 'https://api.short.io';

	public function is_configured(): bool {
		return (bool) get_option( 'jtc_shortio_enabled', 0 )
			&& '' !== $this->get_api_key()
			&& '' !== $this->get_domain();
	}

	public function get_domain(): string {
		return self::normalize_domain( (string) get_option( 'jtc_shortio_domain', '' ) );
	}

	public function get_api_key(): string {
		return trim( (string) get_option( 'jtc_shortio_api_key', '' ) );
	}

	public function get_domain_id() {
		$cached = absint( get_option( 'jtc_shortio_domain_id', 0 ) );
		if ( $cached ) {
			return $cached;
		}

		if ( ! $this->is_configured() ) {
			return new WP_Error( 'jtc_shortio_not_configured', __( 'Short.io is not configured.', 'join-the-cause' ) );
		}

		$response = $this->request( 'GET', '/api/domains' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$domain = $this->get_domain();
		foreach ( $response as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$hostname = self::normalize_domain( (string) ( $item['hostname'] ?? '' ) );
			$unicode  = self::normalize_domain( (string) ( $item['unicodeHostname'] ?? '' ) );

			if ( $domain === $hostname || $domain === $unicode ) {
				$domain_id = absint( $item['id'] ?? 0 );
				if ( $domain_id ) {
					update_option( 'jtc_shortio_domain_id', $domain_id );
					return $domain_id;
				}
			}
		}

		return new WP_Error( 'jtc_shortio_domain_missing', __( 'The configured Short.io domain was not found for this API key.', 'join-the-cause' ) );
	}

	public static function normalize_domain( string $domain ): string {
		$domain = trim( strtolower( $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = strtok( (string) $domain, '/:' );

		return sanitize_text_field( (string) $domain );
	}

	public static function sanitize_path( string $path ): string {
		$path = trim( wp_unslash( $path ) );
		$path = trim( $path, "/ \t\n\r\0\x0B" );

		return sanitize_title( $path );
	}

	public function sync_petition_link( int $petition_id, string $path = '', bool $force_auto_path = false ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'jtc_shortio_not_configured', __( 'Short.io is not configured.', 'join-the-cause' ) );
		}

		if ( JTC_CPT !== get_post_type( $petition_id ) ) {
			return new WP_Error( 'jtc_shortio_invalid_petition', __( 'Petition not found.', 'join-the-cause' ) );
		}

		$original_url = get_permalink( $petition_id );
		if ( ! $original_url ) {
			return new WP_Error( 'jtc_shortio_no_permalink', __( 'Could not determine the petition URL.', 'join-the-cause' ) );
		}

		$link_id = (string) get_post_meta( $petition_id, '_jtc_shortio_link_id', true );
		$path    = $force_auto_path ? '' : self::sanitize_path( $path );

		$body = [
			'originalURL'     => $original_url,
			'title'           => get_the_title( $petition_id ),
			'skipQS'          => false,
			'archived'        => false,
		];

		if ( '' !== $path ) {
			$body['path'] = $path;
		}

		if ( $link_id ) {
			$response = $this->request( 'POST', '/links/' . rawurlencode( $link_id ), $body );
		} else {
			$body['domain']          = $this->get_domain();
			$body['allowDuplicates'] = false;
			$response                = $this->request( 'POST', '/links', $body );
		}

		if ( is_wp_error( $response ) ) {
			update_post_meta( $petition_id, '_jtc_shortio_last_error', $response->get_error_message() );
			return $response;
		}

		$this->store_link_response( $petition_id, $response, $original_url );

		$qr = $this->refresh_petition_qr( $petition_id );
		if ( is_wp_error( $qr ) ) {
			update_post_meta( $petition_id, '_jtc_shortio_last_error', $qr->get_error_message() );
		}

		return $response;
	}

	public function refresh_petition_qr( int $petition_id ) {
		$link_id = (string) get_post_meta( $petition_id, '_jtc_shortio_link_id', true );
		if ( ! $link_id ) {
			return new WP_Error( 'jtc_shortio_missing_link_id', __( 'Create a Short.io link before fetching a QR code.', 'join-the-cause' ) );
		}

		$response = wp_remote_post(
			self::API_BASE . '/links/qr/' . rawurlencode( $link_id ),
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => $this->get_api_key(),
					'Accept'        => 'image/png, image/svg+xml, application/octet-stream, application/json',
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [
					'type'              => 'png',
					'useDomainSettings' => true,
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 || '' === $body ) {
			return new WP_Error(
				'jtc_shortio_qr_failed',
				$this->response_error_message( $response, __( 'Short.io could not generate a QR code.', 'join-the-cause' ) )
			);
		}

		return $this->store_qr_attachment( $petition_id, $body );
	}

	public function refresh_petition_link_from_remote( int $petition_id, bool $force = false ) {
		static $refreshed = [];

		if ( isset( $refreshed[ $petition_id ] ) ) {
			return $refreshed[ $petition_id ];
		}

		$link_id = (string) get_post_meta( $petition_id, '_jtc_shortio_link_id', true );
		if ( ! $link_id || ! $this->is_configured() ) {
			$refreshed[ $petition_id ] = false;
			return false;
		}

		// Front-end calls are throttled through a short transient so page views
		// never fire a blocking remote request on every hit. Manual actions
		// ("Pull from Short.io") pass $force = true to bypass the cache.
		$cache_key = 'jtc_shortio_refresh_' . $petition_id;
		if ( ! $force && get_transient( $cache_key ) ) {
			$refreshed[ $petition_id ] = false;
			return false;
		}

		set_transient( $cache_key, 1, 10 * MINUTE_IN_SECONDS );

		$domain_id = $this->get_domain_id();
		if ( is_wp_error( $domain_id ) ) {
			update_post_meta( $petition_id, '_jtc_shortio_last_error', $domain_id->get_error_message() );
			$refreshed[ $petition_id ] = $domain_id;
			return $domain_id;
		}

		$response = $this->request(
			'GET',
			add_query_arg(
				[
					'domain_id' => absint( $domain_id ),
					'idString'  => $link_id,
					'limit'     => 1,
				],
				'/api/links'
			)
		);

		if ( is_wp_error( $response ) ) {
			update_post_meta( $petition_id, '_jtc_shortio_last_error', $response->get_error_message() );
			$refreshed[ $petition_id ] = $response;
			return $response;
		}

		$link = $this->extract_first_link( $response, $link_id );
		if ( ! $link ) {
			$error = new WP_Error( 'jtc_shortio_link_missing', __( 'Short.io could not find the saved short link.', 'join-the-cause' ) );
			update_post_meta( $petition_id, '_jtc_shortio_last_error', $error->get_error_message() );
			$refreshed[ $petition_id ] = $error;
			return $error;
		}

		$this->store_link_response( $petition_id, $link, (string) get_permalink( $petition_id ) );
		$refreshed[ $petition_id ] = $link;

		return $link;
	}

	/**
	 * Tests the connection with a harmless read-only request (list domains)
	 * and reports whether the configured short domain is available.
	 *
	 * @return array|WP_Error { message: string } on success.
	 */
	public function test_connection() {
		$response = $this->request( 'GET', '/api/domains' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$domain    = $this->get_domain();
		$domain_id = 0;
		$count     = 0;

		foreach ( $response as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$count++;
			$hostname = self::normalize_domain( (string) ( $item['hostname'] ?? '' ) );
			$unicode  = self::normalize_domain( (string) ( $item['unicodeHostname'] ?? '' ) );

			if ( $domain === $hostname || $domain === $unicode ) {
				$domain_id = absint( $item['id'] ?? 0 );
			}
		}

		if ( $domain_id ) {
			update_option( 'jtc_shortio_domain_id', $domain_id );

			return [
				'message' => sprintf(
					/* translators: 1 short domain, 2 number of domains on the account */
					__( 'Connected. Domain "%1$s" found (account has %2$d domain(s)).', 'join-the-cause' ),
					$domain,
					$count
				),
			];
		}

		return new WP_Error(
			'jtc_shortio_domain_missing',
			sprintf(
				/* translators: 1 short domain, 2 number of domains on the account */
				__( 'Connected, but the domain "%1$s" was not found on this account (%2$d domain(s) visible).', 'join-the-cause' ),
				$domain,
				$count
			)
		);
	}

	public function get_petition_data( int $petition_id ): array {
		return [
			'link_id'       => (string) get_post_meta( $petition_id, '_jtc_shortio_link_id', true ),
			'short_url'     => (string) get_post_meta( $petition_id, '_jtc_shortio_short_url', true ),
			'secure_url'    => (string) get_post_meta( $petition_id, '_jtc_shortio_secure_short_url', true ),
			'path'          => (string) get_post_meta( $petition_id, '_jtc_shortio_path', true ),
			'custom_path'   => (string) get_post_meta( $petition_id, '_jtc_shortio_custom_path', true ),
			'sync_slug'     => (bool) get_post_meta( $petition_id, '_jtc_shortio_sync_slug', true ),
			'last_synced'   => (string) get_post_meta( $petition_id, '_jtc_shortio_last_synced', true ),
			'last_error'    => (string) get_post_meta( $petition_id, '_jtc_shortio_last_error', true ),
			'qr_attachment' => (int) get_post_meta( $petition_id, '_jtc_shortio_qr_attachment_id', true ),
		];
	}

	private function request( string $method, string $path, array $body = [] ) {
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization' => $this->get_api_key(),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			],
		];

		if ( 'GET' !== strtoupper( $method ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request(
			self::API_BASE . $path,
			$args
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'jtc_shortio_api_error',
				$this->response_error_message( $response, __( 'Short.io API request failed.', 'join-the-cause' ) ),
				[ 'status' => $code ]
			);
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'jtc_shortio_bad_response', __( 'Short.io returned an unexpected response.', 'join-the-cause' ) );
		}

		return $json;
	}

	private function extract_first_link( array $response, string $link_id ): array {
		$candidates = [];

		if ( isset( $response['links'] ) && is_array( $response['links'] ) ) {
			$candidates = $response['links'];
		} elseif ( isset( $response[0] ) && is_array( $response[0] ) ) {
			$candidates = $response;
		} elseif ( isset( $response['idString'] ) || isset( $response['id'] ) ) {
			$candidates = [ $response ];
		}

		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			if ( $link_id === (string) ( $candidate['idString'] ?? $candidate['id'] ?? '' ) ) {
				return $candidate;
			}
		}

		return [];
	}

	private function response_error_message( array $response, string $fallback ): string {
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $json ) ) {
			foreach ( [ 'message', 'error', 'errorMessage' ] as $key ) {
				if ( ! empty( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
					return sanitize_text_field( $json[ $key ] );
				}
			}
		}

		return $fallback;
	}

	private function store_link_response( int $petition_id, array $response, string $original_url ): void {
		$link_id   = $response['idString'] ?? $response['id'] ?? '';
		$short_url = $response['shortURL'] ?? '';
		$secure    = $response['secureShortURL'] ?? $short_url;

		update_post_meta( $petition_id, '_jtc_shortio_link_id', sanitize_text_field( (string) $link_id ) );
		update_post_meta( $petition_id, '_jtc_shortio_short_url', esc_url_raw( (string) $short_url ) );
		update_post_meta( $petition_id, '_jtc_shortio_secure_short_url', esc_url_raw( (string) $secure ) );
		update_post_meta( $petition_id, '_jtc_shortio_path', sanitize_text_field( (string) ( $response['path'] ?? '' ) ) );
		update_post_meta( $petition_id, '_jtc_shortio_original_url', esc_url_raw( (string) ( $response['originalURL'] ?? $original_url ) ) );
		update_post_meta( $petition_id, '_jtc_shortio_last_synced', current_time( 'mysql' ) );
		delete_post_meta( $petition_id, '_jtc_shortio_last_error' );
	}

	private function store_qr_attachment( int $petition_id, string $contents ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Validate bytes before storing: only genuine PNG data is accepted
		// (rejects HTML error pages or SVG returned by the API).
		if ( "\x89PNG\r\n\x1a\n" !== substr( $contents, 0, 8 ) ) {
			return new WP_Error( 'jtc_shortio_qr_invalid', __( 'Short.io returned a file that is not a PNG image.', 'join-the-cause' ) );
		}

		$filename = 'jtc-shortio-qr-' . $petition_id . '.png';
		$upload   = wp_upload_bits( $filename, null, $contents );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'jtc_shortio_qr_upload_failed', $upload['error'] );
		}

		$size = @getimagesize( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size || IMAGETYPE_PNG !== (int) $size[2] ) {
			@unlink( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'jtc_shortio_qr_invalid', __( 'Short.io returned a file that is not a PNG image.', 'join-the-cause' ) );
		}

		$old_id = (int) get_post_meta( $petition_id, '_jtc_shortio_qr_attachment_id', true );
		if ( $old_id ) {
			wp_delete_attachment( $old_id, true );
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/png',
				'post_title'     => sprintf(
					/* translators: %s petition title */
					__( 'Short link QR - %s', 'join-the-cause' ),
					get_the_title( $petition_id )
				),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$upload['file'],
			$petition_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		update_post_meta( $petition_id, '_jtc_shortio_qr_attachment_id', $attachment_id );

		return $attachment_id;
	}
}

function jtc_get_petition_short_url( int $petition_id, bool $allow_refresh = true ): string {
	$client = new JTC_Short_IO();

	if ( $allow_refresh && $client->is_configured() ) {
		// Internally throttled by a short transient: at most one remote
		// refresh per petition per ~10 minutes, never on every view.
		$client->refresh_petition_link_from_remote( $petition_id );
	}

	$data = $client->get_petition_data( $petition_id );

	return $data['secure_url'] ?: $data['short_url'];
}

function jtc_get_petition_share_url( int $petition_id, bool $allow_refresh = true ): string {
	$client = new JTC_Short_IO();

	// The short URL is only ever used when Short.io is fully configured,
	// so a disabled integration can never serve a stale short link.
	if ( $client->is_configured() && get_option( 'jtc_shortio_use_for_sharing', 0 ) ) {
		$short = jtc_get_petition_short_url( $petition_id, $allow_refresh );
		if ( $short ) {
			return $short;
		}
	}

	return (string) get_permalink( $petition_id );
}

function jtc_get_petition_qr_url( int $petition_id ): string {
	$attachment_id = (int) get_post_meta( $petition_id, '_jtc_shortio_qr_attachment_id', true );

	return $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '';
}
