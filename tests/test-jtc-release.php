<?php
/**
 * Release regressions at the public form, render, queue, and privacy boundaries.
 *
 * @package JoinTheCause
 */

class JTC_Release_Test extends WP_UnitTestCase {
	private array $mail = [];
	private int $petition;

	public function set_up(): void {
		parent::set_up();
		JTC_Activator::activate();
		global $wpdb;
		foreach ( [ 'jtc_supporters', 'jtc_newsletters', 'jtc_deliveries', 'jtc_rate_limits' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$this->petition = self::factory()->post->create( [ 'post_type' => JTC_CPT, 'post_status' => 'publish', 'post_title' => 'Public petition', 'post_content' => 'Petition body' ] );
		update_option( 'jtc_email_method', 'wp_mail' );
		$this->mail = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		$_POST = [];
		$_SERVER['REMOTE_ADDR'] = '192.0.2.9';
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		$_POST = [];
		parent::tear_down();
	}

	public function capture_mail( $result, array $attributes ): bool {
		$this->mail[] = $attributes;
		return true;
	}

	private function supporter( string $email = 'person@example.org', int $consent = 1, int $petition = 0, string $name = 'Alex' ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'jtc_supporters', [ 'petition_id' => $petition ?: $this->petition, 'first_name' => $name, 'last_name' => 'Érkövi', 'email' => $email, 'newsletter_consent' => $consent, 'signed_at' => current_time( 'mysql' ) ] );
		return (int) $wpdb->insert_id;
	}

	private function draft(): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'jtc_newsletters', [ 'subject' => 'Petition news', 'content' => '<p>Hello {first_name}.</p>' ] );
		return (int) $wpdb->insert_id;
	}

	private function submit( array $overrides = [] ): array {
		$_POST = array_merge( [ 'petition_id' => $this->petition, 'nonce' => wp_create_nonce( 'jtc_sign_petition_' . $this->petition ), 'jtc_first_name' => 'Alex', 'jtc_last_name' => 'Érkövi', 'jtc_email' => 'signature@example.org' ], $overrides );
		foreach ( $_POST as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$_POST[ $key ] = (string) $value;
			}
		}
		$die = static function () { throw new RuntimeException( 'jtc-json' ); };
		$handler = static function () use ( $die ) { return $die; };
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_handler', $handler );
		add_filter( 'wp_die_ajax_handler', $handler );
		ob_start();
		try {
			( new JTC_Form_Handler() )->handle();
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'jtc-json', $exception->getMessage() );
		} finally {
			$output = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_handler', $handler );
			remove_filter( 'wp_die_ajax_handler', $handler );
		}
		$data = json_decode( $output, true );
		$this->assertIsArray( $data, $output );
		return $data;
	}

	public function test_csv_cells_do_not_execute_formulas(): void {
		foreach ( [ '=HYPERLINK("https://example.org","x")', '+SUM(1,2)', '-1+2', '@SUM(A1)', "\t=1", "\r=1", " \t=1", "\n=1" ] as $cell ) {
			$this->assertSame( "'" . $cell, jtc_csv_cell( $cell ) );
		}
		$this->assertSame( 'Érkövi', jtc_csv_cell( 'Érkövi' ) );
		$this->assertSame( 'person@example.org', jtc_csv_cell( 'person@example.org' ) );
	}

	public function test_rate_bucket_counts_every_attempt_and_resets_after_expiry(): void {
		global $wpdb;
		$handler = new JTC_Form_Handler();
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$this->assertTrue( $handler->consume_attempt( '192.0.2.1', $this->petition ) );
		}
		$this->assertFalse( $handler->consume_attempt( '192.0.2.1', $this->petition ) );
		$this->assertFalse( $handler->consume_attempt( '192.0.2.1', $this->petition + 1 ) );
		$bucket = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}jtc_rate_limits", ARRAY_A );
		$this->assertSame( '7', $bucket['attempts'] );
		$this->assertStringNotContainsString( '192.0.2.1', $bucket['bucket'] );
		$wpdb->query( "UPDATE {$wpdb->prefix}jtc_rate_limits SET expires_at = 1" );
		$this->assertTrue( $handler->consume_attempt( '192.0.2.1', $this->petition ) );
	}

	public function test_duplicate_signature_is_indistinguishable_and_does_not_change_consent(): void {
		global $wpdb;
		$first = $this->submit();
		$second = $this->submit( [ 'jtc_first_name' => 'Different', 'jtc_newsletter_consent' => '1' ] );
		$this->assertTrue( $first['success'] );
		$this->assertSame( $first, $second );
		$this->assertSame( '0', $wpdb->get_var( "SELECT newsletter_consent FROM {$wpdb->prefix}jtc_supporters" ) );
		$this->assertSame( '', $wpdb->get_row( "SELECT ip_address FROM {$wpdb->prefix}jtc_supporters", ARRAY_A )['ip_address'] );
		$this->assertCount( 0, $this->mail );
		$this->assertSame( '2', $wpdb->get_var( "SELECT attempts FROM {$wpdb->prefix}jtc_rate_limits" ) );
	}

	public function test_invalid_fields_consume_quota_and_array_inputs_do_not_crash(): void {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$response = $this->submit( [ 'jtc_first_name' => [ 'unexpected' ] ] );
			$this->assertFalse( $response['success'] );
			$this->assertStringContainsString( 'First name', $response['data']['message'] );
		}
		$response = $this->submit();
		$this->assertStringContainsString( 'Too many', $response['data']['message'] );
	}

	public function test_nonce_cannot_be_reused_for_a_different_petition(): void {
		$other = self::factory()->post->create( [ 'post_type' => JTC_CPT, 'post_status' => 'publish' ] );
		$response = $this->submit( [ 'petition_id' => $other ] );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Security check', $response['data']['message'] );
	}

	public function test_password_protection_blocks_raw_render_and_signature(): void {
		wp_update_post( [ 'ID' => $this->petition, 'post_password' => 'private' ] );
		$html = ( new JTC_Shortcode() )->render( [ 'id' => $this->petition ] );
		$this->assertStringContainsString( 'post-password-form', $html );
		$this->assertStringNotContainsString( 'Petition body', $html );
		$this->assertStringNotContainsString( 'jtc-form', $html );
		$this->assertFalse( $this->submit()['success'] );
	}

	public function test_multiple_identical_petitions_have_unique_ids_and_no_main(): void {
		$renderer = new JTC_Shortcode();
		$html = $renderer->render( [ 'id' => $this->petition ] ) . $renderer->render( [ 'id' => $this->petition ] );
		preg_match_all( '/(?:^|\s)id="([^"]+)"/', $html, $ids );
		$this->assertCount( count( $ids[1] ), array_unique( $ids[1] ) );
		$this->assertStringNotContainsString( '<main', $html );
		$this->assertStringContainsString( 'role="region"', $html );
	}

	public function test_recursive_petition_embeds_are_bounded(): void {
		wp_update_post( [ 'ID' => $this->petition, 'post_content' => '[jtc_petition id="' . $this->petition . '"]' ] );
		$html = ( new JTC_Shortcode() )->render( [ 'id' => $this->petition ] );
		$this->assertStringContainsString( 'recursive petition embed skipped', $html );
		$this->assertLessThan( 30000, strlen( $html ) );
	}

	public function test_unicode_last_name_initial_is_valid_utf8(): void {
		$this->assertSame( 'É', jtc_name_initial( 'Érkövi' ) );
		$this->assertSame( '張', jtc_name_initial( '張三' ) );
		$this->assertSame( '', jtc_name_initial( '' ) );
	}

	public function test_nested_blocks_are_detected_for_social_metadata(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:jtc/petition {"petitionId":' . $this->petition . '} /--></div><!-- /wp:group -->' ] );
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( $this->petition, jtc_get_current_social_petition_id() );
	}

	public function test_render_does_not_call_shortio_even_on_cold_cache(): void {
		update_option( 'jtc_shortio_enabled', 1 );
		update_option( 'jtc_shortio_api_key', 'test-only-key' );
		update_option( 'jtc_shortio_domain', 'example.org' );
		update_option( 'jtc_shortio_use_for_sharing', 1 );
		$requests = [];
		$filter = static function ( $pre, $args, $url ) use ( &$requests ) { $requests[] = $url; return new WP_Error( 'no-network', 'Blocked in regression test' ); };
		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			( new JTC_Shortcode() )->render( [ 'id' => $this->petition ] );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}
		$this->assertSame( [], $requests );
	}

	public function test_contrast_foregrounds_pass_wcag_on_midtone_surfaces(): void {
		foreach ( [ '#777777', '#888888', '#a2a2a2', '#ffffff', '#171717' ] as $surface ) {
			$colors = jtc_get_preset_themes()['evergreen'];
			$colors['surface'] = $surface;
			$css = jtc_theme_vars_block( ':root', $colors );
			foreach ( [ 'error', 'success', 'link' ] as $name ) {
				preg_match( '/--jtc-' . $name . ': (#[0-9a-f]+);/', $css, $value );
				$this->assertGreaterThanOrEqual( 4.5, jtc_contrast_ratio( $value[1], $surface ), $name . ' on ' . $surface );
			}
			$this->assertGreaterThanOrEqual( 4.5, jtc_contrast_ratio( jtc_contrast_text_color( $surface ), $surface ) );
		}
	}

	public function test_newsletter_snapshot_dedupes_emails_and_requires_separate_consent(): void {
		$this->supporter();
		$this->supporter( 'person@example.org', 1, $this->petition + 1, 'Another name' );
		$this->supporter( 'not-consented@example.org', 0 );
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$this->assertTrue( $queue->start( $id ) );
		$this->assertWPError( $queue->start( $id ) );
		$this->assertSame( 1, $queue->progress( $id )['total'] );
		$queue->process( $id );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'jtc_unsubscribe', $this->mail[0]['message'] );
		$this->assertSame( 'sent', $queue->progress( $id )['status'] );
		$queue->process( $id );
		$this->assertCount( 1, $this->mail );
	}

	public function test_pause_resume_and_cancel_preserve_sent_progress(): void {
		$this->supporter();
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		$this->assertTrue( $queue->control( $id, 'pause' ) );
		$queue->process( $id );
		$this->assertCount( 0, $this->mail );
		$this->assertTrue( $queue->control( $id, 'resume' ) );
		$this->assertTrue( $queue->control( $id, 'cancel' ) );
		$queue->process( $id );
		$this->assertSame( 1, $queue->progress( $id )['cancelled'] );
		$this->assertFalse( $queue->control( $id, 'resume' ) );
		$this->assertCount( 0, $this->mail );
	}

	public function test_expired_lease_marks_ambiguous_deliveries_unknown_without_resending(): void {
		global $wpdb;
		$this->supporter();
		$this->supporter( 'another@example.org' );
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		$wpdb->query( "UPDATE {$wpdb->prefix}jtc_deliveries SET status = 'sending' ORDER BY id ASC LIMIT 1" );
		$wpdb->update( $wpdb->prefix . 'jtc_newsletters', [ 'status' => 'sending', 'locked_until' => 1, 'lock_token' => 'interrupted' ], [ 'id' => $id ] );
		$queue->process( $id );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 1, $queue->progress( $id )['unknown'] );
		$this->assertSame( 'completed', $queue->progress( $id )['status'] );
	}

	public function test_active_lease_schedules_recovery_instead_of_losing_queue(): void {
		global $wpdb;
		$this->supporter();
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		wp_clear_scheduled_hook( 'jtc_newsletter_batch', [ $id ] );
		$wpdb->update( $wpdb->prefix . 'jtc_newsletters', [ 'status' => 'sending', 'locked_until' => time() + 120 ], [ 'id' => $id ] );
		$queue->process( $id );
		$this->assertCount( 0, $this->mail );
		$this->assertGreaterThanOrEqual( time() + 120, wp_next_scheduled( 'jtc_newsletter_batch', [ $id ] ) );
	}

	public function test_unsubscribe_after_snapshot_prevents_newsletter_and_preserves_signature(): void {
		$this->supporter();
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		( new JTC_Privacy() )->withdraw_consent( 'person@example.org' );
		$queue->process( $id );
		$this->assertCount( 0, $this->mail );
		$this->assertSame( 1, $queue->progress( $id )['cancelled'] );
		$this->assertCount( 1, ( new JTC_Privacy() )->export( 'person@example.org' )['data'] );
	}

	public function test_privacy_eraser_paginates_without_skipping_and_removes_queue_personal_data(): void {
		global $wpdb;
		for ( $i = 0; $i < 101; $i++ ) {
			$this->supporter( 'person@example.org', 1, $this->petition + $i );
		}
		$id = $this->draft();
		( new JTC_Newsletter() )->start( $id );
		$privacy = new JTC_Privacy();
		$this->assertCount( 100, $privacy->export( 'person@example.org' )['data'] );
		$this->assertFalse( $privacy->erase( 'person@example.org' )['done'] );
		$this->assertTrue( $privacy->erase( 'person@example.org', 2 )['done'] );
		$this->assertSame( [], $privacy->export( 'person@example.org' )['data'] );
		$this->assertNull( $wpdb->get_row( "SELECT email FROM {$wpdb->prefix}jtc_deliveries", ARRAY_A )['email'] );
	}

	public function test_erasing_two_recipients_preserves_aggregate_outcomes_without_unique_index_collision(): void {
		global $wpdb;
		$this->supporter( 'first@example.org' );
		$this->supporter( 'second@example.org' );
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		$privacy = new JTC_Privacy();
		$this->assertTrue( $privacy->erase( 'first@example.org' )['done'] );
		$this->assertTrue( $privacy->erase( 'second@example.org' )['done'] );
		$rows = $wpdb->get_results( "SELECT email, first_name, supporter_id FROM {$wpdb->prefix}jtc_deliveries", ARRAY_A );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertNull( $row['email'] );
			$this->assertSame( '', $row['first_name'] );
			$this->assertSame( '0', $row['supporter_id'] );
		}
		$this->assertSame( 2, $queue->progress( $id )['cancelled'] );
	}

	public function test_failed_consent_update_is_not_reported_as_success(): void {
		global $wpdb;
		$this->supporter();
		$filter = static function ( $query ) {
			return false !== strpos( $query, "SET `newsletter_consent` = 0" ) ? 'INVALID CONSENT UPDATE' : $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( ( new JTC_Privacy() )->withdraw_consent( 'person@example.org' ) );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertSame( '1', $wpdb->get_var( "SELECT newsletter_consent FROM {$wpdb->prefix}jtc_supporters" ) );
	}

	public function test_legacy_duplicate_rows_are_preserved_and_new_signing_fails_closed(): void {
		global $wpdb;
		$this->supporter();
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}jtc_supporters DROP INDEX petition_email" );
		$this->supporter();
		update_option( 'jtc_db_version', '2' );
		$previous = $wpdb->suppress_errors( true );
		try {
			JTC_Activator::maybe_upgrade_schema();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		$this->assertSame( '0', get_option( 'jtc_signature_index_ready' ) );
		$this->assertSame( '2', get_option( 'jtc_db_version' ) );
		$response = $this->submit( [ 'jtc_email' => 'new@example.org' ] );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'temporarily unavailable', $response['data']['message'] );
		$this->assertSame( '2', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}jtc_supporters" ) );
		// This fixture owns all test rows; restore the index for following tests.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jtc_supporters" );
		JTC_Activator::activate();
		$this->assertSame( '1', get_option( 'jtc_signature_index_ready' ) );
	}

	public function test_stale_preparation_recovers_to_a_draft_without_sending(): void {
		global $wpdb;
		$this->supporter();
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		$wpdb->update( $wpdb->prefix . 'jtc_newsletters', [ 'status' => 'preparing', 'locked_until' => 1, 'lock_token' => 'interrupted' ], [ 'id' => $id ] );
		$queue->process( $id );
		$this->assertSame( 'draft', $queue->progress( $id )['status'] );
		$this->assertSame( 0, $queue->progress( $id )['total'] );
		$this->assertCount( 0, $this->mail );
		$this->assertTrue( $queue->start( $id ) );
		$this->assertSame( 1, $queue->progress( $id )['total'] );
	}

	public function test_stale_starter_cannot_snapshot_or_queue_a_new_preparation_generation(): void {
		global $wpdb;
		$this->supporter();
		$id = $this->draft();
		$filter = null;
		$filter = static function ( string $query ) use ( $wpdb, $id, &$filter ): string {
			if ( str_starts_with( $query, "INSERT INTO {$wpdb->prefix}jtc_deliveries" ) ) {
				remove_filter( 'query', $filter );
				// Simulate recovery plus a new preparation owning this newsletter
				// while the original starter was stalled before its snapshot query.
				$wpdb->update( $wpdb->prefix . 'jtc_newsletters', [ 'lock_token' => 'new-generation', 'locked_until' => time() + 120 ], [ 'id' => $id ] );
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertWPError( ( new JTC_Newsletter() )->start( $id ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 'new-generation', $wpdb->get_var( $wpdb->prepare( "SELECT lock_token FROM {$wpdb->prefix}jtc_newsletters WHERE id = %d", $id ) ) );
		$this->assertSame( 'preparing', ( new JTC_Newsletter() )->progress( $id )['status'] );
		$this->assertSame( 0, ( new JTC_Newsletter() )->progress( $id )['total'] );
	}

	public function test_stale_recovery_cannot_delete_new_generation_recipients(): void {
		global $wpdb;
		$this->supporter();
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		$wpdb->update( $wpdb->prefix . 'jtc_newsletters', [ 'status' => 'preparing', 'locked_until' => 1 ], [ 'id' => $id ] );
		$filter = null;
		$filter = static function ( string $query ) use ( $wpdb, $id, &$filter ): string {
			if ( str_starts_with( $query, "DELETE d FROM {$wpdb->prefix}jtc_deliveries" ) ) {
				remove_filter( 'query', $filter );
				$wpdb->update( $wpdb->prefix . 'jtc_newsletters', [ 'status' => 'queued', 'lock_token' => '', 'locked_until' => 0 ], [ 'id' => $id ] );
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$queue->process( $id );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 'queued', $queue->progress( $id )['status'] );
		$this->assertSame( 1, $queue->progress( $id )['total'] );
		$this->assertCount( 0, $this->mail );
	}

	private function fail_next_query( string $contains ): Closure {
		$filter = null;
		$filter = static function ( string $query ) use ( $contains, &$filter ): string {
			if ( str_contains( $query, $contains ) ) {
				remove_filter( 'query', $filter );
				return 'INVALID SQL FOR JTC FAULT TEST';
			}
			return $query;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	public function test_newsletter_read_failure_never_expands_a_specific_audience(): void {
		global $wpdb;
		$this->supporter();
		$this->supporter( 'other-petition@example.org', 1, $this->petition + 1 );
		$id = $this->draft();
		$wpdb->update( $wpdb->prefix . 'jtc_newsletters', [ 'petition_id' => $this->petition ], [ 'id' => $id ] );
		$previous = $wpdb->suppress_errors( true );
		$filter = $this->fail_next_query( "SELECT * FROM {$wpdb->prefix}jtc_newsletters" );
		try {
			$this->assertWPError( ( new JTC_Newsletter() )->start( $id ) );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertSame( 0, ( new JTC_Newsletter() )->progress( $id )['total'] );
		$this->assertSame( 'draft', ( new JTC_Newsletter() )->progress( $id )['status'] );
	}

	public function test_pending_delivery_read_failure_never_marks_newsletter_sent(): void {
		global $wpdb;
		$this->supporter();
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		$previous = $wpdb->suppress_errors( true );
		$filter = $this->fail_next_query( "SELECT * FROM {$wpdb->prefix}jtc_deliveries" );
		try {
			$queue->process( $id );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertSame( 'sending', $queue->progress( $id )['status'] );
		$this->assertSame( 1, $queue->progress( $id )['pending'] );
		$this->assertCount( 0, $this->mail );
		$queue->process( $id );
		$this->assertSame( 'sent', $queue->progress( $id )['status'] );
		$this->assertCount( 1, $this->mail );
	}

	public function test_progress_read_failure_stays_unavailable_and_retains_recovery(): void {
		global $wpdb;
		$this->supporter();
		$id = $this->draft();
		$queue = new JTC_Newsletter();
		$queue->start( $id );
		$previous = $wpdb->suppress_errors( true );
		$filter = $this->fail_next_query( 'SELECT status, COUNT(*) AS total' );
		try {
			$queue->process( $id );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertSame( 'sending', $queue->progress( $id )['status'] );
		$this->assertNotFalse( wp_next_scheduled( 'jtc_newsletter_batch', [ $id ] ) );
		$queue->process( $id );
		$this->assertSame( 'sent', $queue->progress( $id )['status'] );
		$this->assertCount( 1, $this->mail );
	}

	public function test_smtp_is_bounded_and_retains_external_phPMailer_property_contract(): void {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		$mailer = new PHPMailer\PHPMailer\PHPMailer();
		( new JTC_Mailer() )->configure_smtp( $mailer );
		$this->assertSame( 15, $mailer->Timeout );
		$this->assertSame( 15, $mailer->getSMTPInstance()->Timelimit );
		$this->assertSame( 'smtp', $mailer->Mailer );
	}
}
