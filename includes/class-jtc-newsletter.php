<?php
/**
 * Persistent newsletter snapshots and bounded, mutually exclusive delivery batches.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Newsletter WordPress component. */
class JTC_Newsletter {
	/**
	 * Register WordPress hooks for this component.
	 */
	public function register(): void {
		add_action( 'jtc_newsletter_batch', array( $this, 'process' ) );
		add_action( 'wp_ajax_jtc_newsletter_progress', array( $this, 'ajax_progress' ) );
		add_action( 'wp_ajax_jtc_newsletter_control', array( $this, 'ajax_control' ) );
	}

	/**
	 * Snapshot consenting addresses once. Duplicate start requests cannot resubmit.
	 *
	 * @param int $id Newsletter or supporter row ID.
	 */
	public function start( int $id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'jtc_newsletters';
		$token   = wp_generate_uuid4();
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_newsletters SET status = 'preparing', lock_token = %s, locked_until = %d WHERE id = %d AND status = 'draft'", $token, time() + 120, $id ) );
		if ( 1 !== $claimed ) {
			return new WP_Error( 'not_draft', __( 'Only a saved draft can be queued.', 'join-the-cause' ) );
		}
		$this->schedule( $id, 121 );
		$newsletter = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jtc_newsletters WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $newsletter ) ) {
			$wpdb->update(
				$table,
				array(
					'status'       => 'draft',
					'lock_token'   => '',
					'locked_until' => 0,
				),
				array(
					'id'         => $id,
					'lock_token' => $token,
					'status'     => 'preparing',
				),
				array( '%s', '%s', '%d' ),
				array( '%d', '%s', '%s' )
			);
			return new WP_Error( 'read_failed', __( 'Could not read the saved newsletter. Please try again.', 'join-the-cause' ) );
		}
		$petition_id = (int) $newsletter['petition_id'];
		$result      = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}jtc_deliveries (newsletter_id, supporter_id, email, first_name)
			 SELECT %d, MIN(s.id), s.email, MIN(s.first_name) FROM {$wpdb->prefix}jtc_supporters s
			 INNER JOIN {$wpdb->prefix}jtc_newsletters nl ON nl.id = %d AND nl.status = 'preparing' AND nl.lock_token = %s
			 WHERE s.newsletter_consent = 1 AND (s.petition_id = %d OR %d = 0) GROUP BY s.email",
				$id,
				$id,
				$token,
				$petition_id,
				$petition_id
			)
		);
		if ( false === $result ) {
			$wpdb->query( $wpdb->prepare( "DELETE d FROM {$wpdb->prefix}jtc_deliveries d INNER JOIN {$wpdb->prefix}jtc_newsletters nl ON nl.id = d.newsletter_id WHERE nl.id = %d AND nl.lock_token = %s AND nl.status = 'preparing'", $id, $token ) );
			$wpdb->update(
				$table,
				array(
					'status'       => 'draft',
					'lock_token'   => '',
					'locked_until' => 0,
				),
				array(
					'id'         => $id,
					'lock_token' => $token,
					'status'     => 'preparing',
				),
				array( '%s', '%s', '%d' ),
				array( '%d', '%s', '%s' )
			);
			return new WP_Error( 'queue_failed', __( 'Could not prepare the recipient list. Please try again.', 'join-the-cause' ) );
		}
		$queued = $wpdb->update(
			$table,
			array(
				'status'       => 'queued',
				'lock_token'   => '',
				'locked_until' => 0,
			),
			array(
				'id'         => $id,
				'lock_token' => $token,
				'status'     => 'preparing',
			),
			array( '%s', '%s', '%d' ),
			array( '%d', '%s', '%s' )
		);
		if ( 1 !== $queued ) {
			return new WP_Error( 'queue_failed', __( 'Could not queue the newsletter. Its preparation will recover to a draft automatically.', 'join-the-cause' ) );
		}
		wp_clear_scheduled_hook( 'jtc_newsletter_batch', array( $id ) );
		$this->schedule( $id );
		return true;
	}
	/**
	 * Schedule the next delivery or recovery event.
	 *
	 * @param int $id Newsletter or supporter row ID.
	 * @param int $delay Delay in seconds.
	 */
	private function schedule( int $id, int $delay = 60 ): void {
		if ( ! wp_next_scheduled( 'jtc_newsletter_batch', array( $id ) ) ) {
			wp_schedule_single_event( time() + $delay, 'jtc_newsletter_batch', array( $id ) );
		}
	}

	/**
	 * No recipient is retried automatically after an ambiguous mail-provider outcome.
	 *
	 * @param int $id Newsletter or supporter row ID.
	 */
	public function process( int $id ): void {
		global $wpdb;
		$table      = $wpdb->prefix . 'jtc_newsletters';
		$deliveries = $wpdb->prefix . 'jtc_deliveries';
		$now        = time();
		$token      = wp_generate_uuid4();
		$recover    = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_newsletters SET status = 'recovering', lock_token = %s, locked_until = %d WHERE id = %d AND status IN ('preparing','recovering') AND locked_until <= %d", $token, $now + 120, $id, $now ) );
		if ( 1 === $recover ) {
			$this->schedule( $id, 121 );
			$cleared = $wpdb->query( $wpdb->prepare( "DELETE d FROM {$wpdb->prefix}jtc_deliveries d INNER JOIN {$wpdb->prefix}jtc_newsletters nl ON nl.id = d.newsletter_id WHERE nl.id = %d AND nl.status = 'recovering' AND nl.lock_token = %s", $id, $token ) );
			if ( false !== $cleared ) {
				$wpdb->update(
					$table,
					array(
						'status'       => 'draft',
						'lock_token'   => '',
						'locked_until' => 0,
					),
					array(
						'id'         => $id,
						'lock_token' => $token,
					),
					array( '%s', '%s', '%d' ),
					array( '%d', '%s' )
				);
			}
			return;
		}
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}jtc_newsletters SET lock_token = %s, locked_until = %d, status = 'sending'
			 WHERE id = %d AND status IN ('queued','sending') AND locked_until <= %d",
				$token,
				$now + 120,
				$id,
				$now
			)
		);
		if ( 1 !== $claimed ) {
			$active = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}jtc_newsletters WHERE id = %d", $id ) );
			if ( in_array( $active, array( 'queued', 'sending', 'preparing', 'recovering' ), true ) ) {
				$this->schedule( $id, 121 );
			}
			return;
		}
		wp_clear_scheduled_hook( 'jtc_newsletter_batch', array( $id ) );
		$this->schedule( $id, 121 ); // Recovery event survives a killed PHP request.
		$newsletter = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jtc_newsletters WHERE id = %d AND lock_token = %s", $id, $token ), ARRAY_A );
		if ( ! is_array( $newsletter ) ) {
			$this->release_lock( $id, $token );
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_deliveries d INNER JOIN {$wpdb->prefix}jtc_newsletters nl ON nl.id = d.newsletter_id SET d.status = 'unknown' WHERE nl.id = %d AND nl.lock_token = %s AND d.status = 'sending'", $id, $token ) );
		$batch_size = max( 1, min( 25, (int) apply_filters( 'jtc_newsletter_batch_size', 5 ) ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jtc_deliveries WHERE newsletter_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d", $id, $batch_size ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			$this->release_lock( $id, $token );
			return;
		}
		$mailer = new JTC_Mailer();
		foreach ( $rows as $row ) {
			if ( time() - $now >= 10 ) {
				break;
			}
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}jtc_newsletters WHERE id = %d AND lock_token = %s", $id, $token ) );
			if ( 'sending' !== $status ) {
				break;
			}
			// Check current consent, including erasures/unsubscriptions since snapshot.
			$consented = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jtc_supporters WHERE email = %s AND newsletter_consent = 1 LIMIT 1", $row['email'] ) );
			if ( ! $consented ) {
				if ( '' !== $wpdb->last_error ) {
					break; // Unavailable consent data never becomes a send or cancellation.
				}
				$this->delivery_state( (int) $row['id'], $id, $token, 'pending', 'cancelled' );
				continue;
			}
			$claim = $this->delivery_state( (int) $row['id'], $id, $token, 'pending', 'sending' );
			if ( 1 !== $claim ) {
				continue;
			}

			$body        = str_replace( '{first_name}', esc_html( $row['first_name'] ), $newsletter['content'] );
			$unsubscribe = JTC_Privacy::unsubscribe_url( (int) $consented );
			$body       .= '<p><a href="' . esc_url( $unsubscribe ) . '">' . esc_html__( 'Unsubscribe from newsletters', 'join-the-cause' ) . '</a></p>';
			$sent        = $mailer->send( $row['email'], $newsletter['subject'], $body, true );
			$this->delivery_state( (int) $row['id'], $id, $token, 'sending', $sent ? 'sent' : 'failed' );
		}
		$progress = $this->progress( $id );
		if ( ! $progress['available'] ) {
			$this->release_lock( $id, $token );
			return;
		}
		$values  = array(
			'lock_token'       => '',
			'locked_until'     => 0,
			'recipients_count' => $progress['sent'],
		);
		$formats = array( '%s', '%d', '%d' );
		if ( 0 === $progress['pending'] && 0 === $progress['sending'] && 'sending' === $progress['status'] ) {
			$values['status']  = $progress['failed'] || $progress['unknown'] ? 'completed' : 'sent';
			$values['sent_at'] = current_time( 'mysql' );
			$formats[]         = '%s';
			$formats[]         = '%s';
		}
		$updated = $wpdb->update(
			$table,
			$values,
			array(
				'id'         => $id,
				'lock_token' => $token,
			),
			$formats,
			array( '%d', '%s' )
		);
		if ( 1 === $updated && isset( $values['status'] ) ) {
			wp_clear_scheduled_hook( 'jtc_newsletter_batch', array( $id ) );
		}
	}
	/**
	 * Change one delivery only while this worker still owns the newsletter.
	 *
	 * @param int    $delivery_id Delivery row ID.
	 * @param int    $id Newsletter row ID.
	 * @param string $token Current worker token.
	 * @param string $from Expected delivery state.
	 * @param string $to Next delivery state.
	 * @return int|false Affected rows or database failure.
	 */
	private function delivery_state( int $delivery_id, int $id, string $token, string $from, string $to ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_deliveries d INNER JOIN {$wpdb->prefix}jtc_newsletters nl ON nl.id = d.newsletter_id SET d.status = %s WHERE d.id = %d AND nl.id = %d AND nl.lock_token = %s AND d.status = %s", $to, $delivery_id, $id, $token, $from ) );
	}

	/**
	 * Release only this worker's lease after an unavailable database read.
	 *
	 * @param int    $id Newsletter row ID.
	 * @param string $token Worker generation token.
	 */
	private function release_lock( int $id, string $token ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'jtc_newsletters',
			array(
				'lock_token'   => '',
				'locked_until' => 0,
			),
			array(
				'id'         => $id,
				'lock_token' => $token,
			),
			array( '%s', '%d' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Read the persisted newsletter delivery outcomes.
	 *
	 * @param int $id Newsletter or supporter row ID.
	 * @return array Result value.
	 */
	public function progress( int $id ): array {
		global $wpdb;
		$status              = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}jtc_newsletters WHERE id = %d", $id ) );
		$status_error        = $wpdb->last_error;
		$counts              = array(
			'pending'   => 0,
			'sending'   => 0,
			'sent'      => 0,
			'failed'    => 0,
			'unknown'   => 0,
			'cancelled' => 0,
		);
		$rows                = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$wpdb->prefix}jtc_deliveries WHERE newsletter_id = %d GROUP BY status", $id ), ARRAY_A );
		$counts['total']     = 0;
		$counts['processed'] = 0;
		$counts['status']    = $status ? $status : 'missing';
		$counts['available'] = '' === $status_error && '' === $wpdb->last_error && is_string( $status ) && is_array( $rows );
		if ( ! $counts['available'] ) {
			$counts['label'] = __( 'Progress is temporarily unavailable. No delivery will be marked complete until it can be verified.', 'join-the-cause' );
			return $counts;
		}
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		$counts['total']     = array_sum( array_intersect_key( $counts, array_flip( array( 'pending', 'sending', 'sent', 'failed', 'unknown', 'cancelled' ) ) ) );
		$counts['processed'] = $counts['total'] - $counts['pending'] - $counts['sending'];
		$counts['status']    = jtc_fallback( $status, 'missing' );
		/* translators: 1: processed recipients, 2: total recipients, 3: sent, 4: failed, 5: uncertain, 6: skipped. */
		$counts['label'] = sprintf( __( '%1$s of %2$s processed; %3$s sent, %4$s failed, %5$s uncertain, %6$s skipped.', 'join-the-cause' ), number_format_i18n( $counts['processed'] ), number_format_i18n( $counts['total'] ), number_format_i18n( $counts['sent'] ), number_format_i18n( $counts['failed'] ), number_format_i18n( $counts['unknown'] ), number_format_i18n( $counts['cancelled'] ) );
		return $counts;
	}
	/**
	 * Apply a permitted newsletter pause, resume, or cancellation.
	 *
	 * @param int    $id Newsletter or supporter row ID.
	 * @param string $action Requested control action.
	 * @return bool Result value.
	 */
	public function control( int $id, string $action ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'jtc_newsletters';
		if ( 'resume' === $action ) {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_newsletters SET status = 'queued' WHERE id = %d AND status = 'paused'", $id ) );
			$this->schedule( $id );
		} elseif ( 'pause' === $action || 'cancel' === $action ) {
			$status = 'pause' === $action ? 'paused' : 'cancelled';
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_newsletters SET status = %s WHERE id = %d AND status IN ('queued','sending','paused')", $status, $id ) );
			if ( 1 === $result && 'cancel' === $action ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jtc_deliveries SET status = 'cancelled' WHERE newsletter_id = %d AND status = 'pending'", $id ) );
			}
			wp_clear_scheduled_hook( 'jtc_newsletter_batch', array( $id ) );
		} else {
			return false;
		}
		return 1 === $result;
	}
	/**
	 * Verify administrator capability and newsletter nonce.
	 */
	private function authorize(): void {
		check_ajax_referer( 'jtc_newsletter', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'join-the-cause' ) ), 403 );
		}
	}
	/**
	 * Advance and report an authorized newsletter batch.
	 */
	public function ajax_progress(): void {
		$this->authorize();
		check_ajax_referer( 'jtc_newsletter', 'nonce' );
		$id = absint( jtc_post_input( 'newsletter_id' ) );
		// The open admin screen can drive a batch even when visitor-driven cron is disabled.
		$this->process( $id );
		wp_send_json_success( $this->progress( $id ) );
	}
	/**
	 * Apply an authorized newsletter control request.
	 */
	public function ajax_control(): void {
		$this->authorize();
		check_ajax_referer( 'jtc_newsletter', 'nonce' );
		$id     = absint( jtc_post_input( 'newsletter_id' ) );
		$action = sanitize_key( jtc_post_input( 'control' ) );
		$this->control( $id, $action );
		wp_send_json_success( $this->progress( $id ) );
	}
}
