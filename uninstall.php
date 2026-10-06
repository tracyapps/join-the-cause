<?php
/**
 * Runs only when the plugin is deleted from WP Admin → Plugins → Delete.
 * Removes all plugin data: custom tables, options, post meta, transients,
 * generated uploads, QR attachments, and petition posts — for every site
 * on multisite networks.
 *
 * @package JoinTheCause
 *
 * Does NOT run on deactivation — data is preserved across deactivate/reactivate cycles.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes all Join the Cause data for the current site.
 */
function jtc_uninstall_site(): void {
	global $wpdb;

	// ── Generated uploads (social cards + QR files in the plugin folder) ───
	$uploads = wp_upload_dir();

	if ( empty( $uploads['error'] ) ) {
		$jtc_dir  = trailingslashit( $uploads['basedir'] ) . 'join-the-cause';
		$jtc_pngs = glob( $jtc_dir . '/*.png' );

		if ( is_array( $jtc_pngs ) ) {
			foreach ( $jtc_pngs as $jtc_file ) {
				if ( is_file( $jtc_file ) ) {
					wp_delete_file( $jtc_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		if ( is_dir( $jtc_dir ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			global $wp_filesystem;
			if ( WP_Filesystem() && $wp_filesystem ) {
				$wp_filesystem->rmdir( $jtc_dir );
			}
		}
	}

	// ── QR attachments (uploaded via Short.io refresh) ───────────────────────
	$qr_attachment_ids = $wpdb->get_col(
		"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_jtc_shortio_qr_attachment_id'" // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	);

	foreach ( $qr_attachment_ids as $qr_attachment_id ) {
		$qr_attachment_id = (int) $qr_attachment_id;
		if ( $qr_attachment_id ) {
			wp_delete_attachment( $qr_attachment_id, true );
		}
	}

	wp_clear_scheduled_hook( 'jtc_newsletter_batch' );
	wp_clear_scheduled_hook( 'jtc_signature_mail' );

	// ── Custom tables ─────────────────────────────────────────────────────
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jtc_supporters" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jtc_newsletters" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jtc_rate_limits" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jtc_deliveries" );

	// ── Options (explicit list + prefix sweep for future keys) ─────────────
	$option_keys = array(
		'jtc_color_mode',
		'jtc_preset_theme',
		'jtc_custom_primary',
		'jtc_custom_secondary',
		'jtc_custom_accent',
		'jtc_custom_hero_from',
		'jtc_custom_hero_to',
		'jtc_custom_page_bg',
		'jtc_custom_surface',
		'jtc_custom_surface_alt',
		'jtc_custom_border',
		'jtc_style',
		'jtc_privacy_notice',
		'jtc_terms_of_service',
		'jtc_email_method',
		'jtc_smtp_host',
		'jtc_smtp_port',
		'jtc_smtp_username',
		'jtc_smtp_password',
		'jtc_smtp_encryption',
		'jtc_api_provider',
		'jtc_api_key',
		'jtc_mailgun_domain',
		'jtc_mailgun_region',
		'jtc_from_name',
		'jtc_from_email',
		'jtc_welcome_email_enabled',
		'jtc_welcome_email_subject',
		'jtc_welcome_email_body',
		'jtc_admin_notify_enabled',
		'jtc_admin_notify_email',
		'jtc_last_mailer_error',
		'jtc_last_mailer_error_time',
		'jtc_shortio_enabled',
		'jtc_shortio_api_key',
		'jtc_shortio_domain',
		'jtc_shortio_domain_id',
		'jtc_shortio_auto_create',
		'jtc_shortio_use_for_sharing',
		'jtc_petition_defaults',
		'jtc_trace_migration',
		'jtc_db_version',
	);

	foreach ( $option_keys as $key ) {
		delete_option( $key );
	}

	// Catch any current or future jtc_* options not in the explicit list.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'jtc\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// ── Transients (rate limits, test results, notices, refresh cache) ─────
	$transient_like = $wpdb->esc_like( '_transient_jtc_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_jtc_' ) . '%';

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like ) );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// ── Post meta (form fields, settings, Short.io, tracker fields) ────────
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_jtc_form_fields' ) );       // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_jtc_petition_settings' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_jtc\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// ── Petition posts + their meta ────────────────────────────────────────
	$petition_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'jtc_petition'" // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	);

	foreach ( $petition_ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		jtc_uninstall_site();
		restore_current_blog();
	}
} else {
	jtc_uninstall_site();
}
