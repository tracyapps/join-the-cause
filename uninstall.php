<?php
/**
 * Runs only when the plugin is deleted from WP Admin → Plugins → Delete.
 * Removes all plugin data: custom tables, options, post meta.
 *
 * Does NOT run on deactivation — data is preserved across deactivate/reactivate cycles.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jtc_supporters" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jtc_newsletters" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove all plugin options.
$option_keys = [
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
	'jtc_petition_defaults',
	'jtc_db_version',
];

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

// Remove all post meta for the CPT.
$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_jtc_form_fields' ] );       // phpcs:ignore
$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_jtc_petition_settings' ] ); // phpcs:ignore

// Remove all posts of the CPT (petitions) and their meta.
$petition_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'jtc_petition'"
);

foreach ( $petition_ids as $id ) {
	wp_delete_post( (int) $id, true );
}
