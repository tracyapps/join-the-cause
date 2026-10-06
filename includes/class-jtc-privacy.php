<?php
/**
 * Newsletter unsubscribe and WordPress personal-data tools.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Privacy WordPress component. */
class JTC_Privacy {
	/**
	 * Register WordPress hooks for this component.
	 */
	public function register(): void {
		add_action( 'admin_post_jtc_unsubscribe', array( $this, 'unsubscribe' ) );
		add_action( 'admin_post_nopriv_jtc_unsubscribe', array( $this, 'unsubscribe' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}
	/**
	 * Unsubscribe url.
	 *
	 * @param int $id Newsletter or supporter row ID.
	 * @return string Result value.
	 */
	public static function unsubscribe_url( int $id ): string {
		global $wpdb;
		$email = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$wpdb->prefix}jtc_supporters WHERE id = %d", $id ) );
		if ( ! $email ) {
			return '';
		}
		return add_query_arg(
			array(
				'action'    => 'jtc_unsubscribe',
				'supporter' => $id,
				'token'     => wp_hash( 'jtc_unsubscribe_' . $id . '_' . $email ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * GET renders a confirmation so email scanners never unsubscribe recipients.
	 */
	public function unsubscribe(): void {
		global $wpdb;
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		$id    = isset( $_REQUEST['supporter'] ) && is_scalar( $_REQUEST['supporter'] ) ? absint( wp_unslash( $_REQUEST['supporter'] ) ) : 0;
		$token = sanitize_text_field( wp_unslash( $_REQUEST['token'] ?? '' ) );
		$email = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$wpdb->prefix}jtc_supporters WHERE id = %d", $id ) );
		if ( ! $email || ! hash_equals( wp_hash( 'jtc_unsubscribe_' . $id . '_' . $email ), $token ) ) {
			wp_die( esc_html__( 'This unsubscribe link is invalid. Please contact the site administrator.', 'join-the-cause' ), '', array( 'response' => 403 ) );
		}
		if ( 'POST' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			check_admin_referer( 'jtc_unsubscribe_' . $id );
			if ( ! $this->withdraw_consent( $email ) ) {
				wp_die( esc_html__( 'Could not update your newsletter preference. Please try again.', 'join-the-cause' ), '', array( 'response' => 503 ) );
			}
			wp_die( esc_html__( 'You have unsubscribed from newsletters. Your petition signatures remain saved.', 'join-the-cause' ), esc_html__( 'Unsubscribed', 'join-the-cause' ), array( 'response' => 200 ) );
		}
		$form = '<p>' . esc_html__( 'Stop newsletter emails from this site? Your signatures will remain saved.', 'join-the-cause' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jtc_unsubscribe"><input type="hidden" name="supporter" value="' . esc_attr( $id ) . '"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">' . wp_nonce_field( 'jtc_unsubscribe_' . $id, '_wpnonce', false, false ) . '<button type="submit">' . esc_html__( 'Unsubscribe', 'join-the-cause' ) . '</button></form>';
		wp_die( $form, esc_html__( 'Newsletter preferences', 'join-the-cause' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped form markup built above.
	}
	/**
	 * Withdraw newsletter consent and cancel pending mail to an address.
	 *
	 * @param string $email Email address.
	 * @return bool Whether consent and pending-delivery updates succeeded.
	 */
	public function withdraw_consent( string $email ): bool {
		global $wpdb;
		$consent = $wpdb->update( $wpdb->prefix . 'jtc_supporters', array( 'newsletter_consent' => 0 ), array( 'email' => $email ), array( '%d' ), array( '%s' ) );
		$pending = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_deliveries SET status = 'cancelled' WHERE email = %s AND status = 'pending'", $email ) );
		return false !== $consent && false !== $pending;
	}
	/**
	 * Exporters.
	 *
	 * @param array $exporters Exporters.
	 * @return array Result value.
	 */
	public function exporters( array $exporters ): array {
		$exporters['join-the-cause'] = array(
			'exporter_friendly_name' => __( 'Join the Cause signatures', 'join-the-cause' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}
	/**
	 * Erasers.
	 *
	 * @param array $erasers Erasers.
	 * @return array Result value.
	 */
	public function erasers( array $erasers ): array {
		$erasers['join-the-cause'] = array(
			'eraser_friendly_name' => __( 'Join the Cause signatures', 'join-the-cause' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}
	/**
	 * Export one page of petition signature personal data.
	 *
	 * @param string $email Email address.
	 * @param int    $page One-based result page.
	 * @return array Result value.
	 */
	public function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jtc_supporters WHERE email = %s ORDER BY id ASC LIMIT 100 OFFSET %d", $email, ( max( 1, $page ) - 1 ) * 100 ), ARRAY_A );
		$data = array();
		foreach ( $rows as $row ) {
			$items = array(
				array(
					'name'  => __( 'Petition', 'join-the-cause' ),
					'value' => get_the_title( $row['petition_id'] ),
				),
				array(
					'name'  => __( 'First name', 'join-the-cause' ),
					'value' => $row['first_name'],
				),
				array(
					'name'  => __( 'Last name', 'join-the-cause' ),
					'value' => $row['last_name'],
				),
				array(
					'name'  => __( 'Email', 'join-the-cause' ),
					'value' => $row['email'],
				),
				array(
					'name'  => __( 'Signed at', 'join-the-cause' ),
					'value' => $row['signed_at'],
				),
				array(
					'name'  => __( 'Public name consent', 'join-the-cause' ),
					'value' => $row['display_consent'] ? __( 'Yes', 'join-the-cause' ) : __( 'No', 'join-the-cause' ),
				),
				array(
					'name'  => __( 'Newsletter consent', 'join-the-cause' ),
					'value' => $row['newsletter_consent'] ? __( 'Yes', 'join-the-cause' ) : __( 'No', 'join-the-cause' ),
				),
			);
			$extra = json_decode( jtc_fallback( $row['extra_fields'], '{}' ), true );
			foreach ( is_array( $extra ) ? $extra : array() as $field ) {
				if ( is_array( $field ) ) {
					$items[] = array(
						'name'  => jtc_scalar( $field['label'] ?? '' ),
						'value' => jtc_scalar( $field['value'] ?? '' ),
					);
				}
			}
			if ( $row['ip_address'] ) {
				$items[] = array(
					'name'  => __( 'Legacy IP address', 'join-the-cause' ),
					'value' => $row['ip_address'],
				);
			}
			$data[] = array(
				'group_id'    => 'jtc-signatures',
				'group_label' => __( 'Petition signatures', 'join-the-cause' ),
				'item_id'     => 'jtc-' . $row['id'],
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 100,
		);
	}

	/**
	 * Delete the next batch, without offset: previously deleted rows cannot be skipped.
	 *
	 * @param string $email Email address.
	 * @param int    $page One-based result page.
	 * @return array Result value.
	 */
	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$failed  = ! $this->withdraw_consent( $email );
		$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jtc_supporters WHERE email = %s ORDER BY id ASC LIMIT 100", $email ) );
		$failed  = $failed || '' !== $wpdb->last_error;
		$removed = false;
		foreach ( $ids as $id ) {
			wp_clear_scheduled_hook( 'jtc_signature_mail', array( (int) $id ) );
			$result  = $wpdb->delete( $wpdb->prefix . 'jtc_supporters', array( 'id' => $id ), array( '%d' ) );
			$failed  = $failed || false === $result;
			$removed = $removed || 1 === $result;
		}
		// SQL NULL removes personal data without colliding with the unique audience
		// index when several people in the same newsletter request erasure.
		$erased    = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_deliveries SET email = NULL, first_name = '', supporter_id = 0 WHERE email = %s", $email ) );
		$failed    = $failed || false === $erased;
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jtc_supporters WHERE email = %s", $email ) );
		$failed    = $failed || '' !== $wpdb->last_error || null === $remaining;
		return array(
			'items_removed'  => $removed,
			'items_retained' => $failed,
			'messages'       => $failed ? array( __( 'Some petition data could not be erased. Please retry this request.', 'join-the-cause' ) ) : array(),
			'done'           => ! $failed && 0 === (int) $remaining,
		);
	}
	/**
	 * Suggest site-specific privacy policy language.
	 */
	public function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'Join the Cause', '<p>' . esc_html__( 'When you sign a petition, we store your name, email, signature date, optional form responses and consent choices. Public supporter names appear only with your consent. Newsletter emails require a separate opt-in and contain an unsubscribe link. We use a temporary keyed hash of your IP address to limit abusive submissions; new signatures do not retain your IP address. Older signatures may contain an IP address until removed. Administrators can export or erase your signature data using WordPress privacy tools. Signing may send a confirmation and an administrator notification. If configured, our email provider (SMTP, Mailgun or SendGrid) processes your address and message; Short.io processes public petition links to create short links and QR codes. Contact the site administrator for retention details and the providers used by this site.', 'join-the-cause' ) . '</p>' );
	}
}
