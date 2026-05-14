<?php
/**
 * Runs on plugin activation and handles DB table creation + default options.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JTC_Activator {

	/**
	 * Plugin activation callback.
	 * Creates DB tables, seeds options, flushes rewrite rules.
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation callback.
	 * Only flushes rewrite rules — data is intentionally preserved.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	// ─── DB tables ───────────────────────────────────────────────────────────

	private static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$supporters  = $wpdb->prefix . 'jtc_supporters';
		$newsletters = $wpdb->prefix . 'jtc_newsletters';

		/**
		 * jtc_supporters
		 * One row per signature. extra_fields holds any custom petition form
		 * field values as JSON so we don't need schema changes per petition.
		 */
		$sql_supporters = "CREATE TABLE {$supporters} (
			id            bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			petition_id   bigint(20)   UNSIGNED NOT NULL,
			first_name    varchar(100) NOT NULL DEFAULT '',
			last_name     varchar(100) NOT NULL DEFAULT '',
			email         varchar(200) NOT NULL DEFAULT '',
			display_consent tinyint(1) NOT NULL DEFAULT 0,
			extra_fields  longtext     DEFAULT NULL,
			ip_address    varchar(45)  NOT NULL DEFAULT '',
			signed_at     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY petition_id (petition_id),
			KEY email (email(100))
		) {$charset};";

		/**
		 * jtc_newsletters
		 * One row per drafted/sent newsletter blast.
		 * petition_id = 0 means "sent to all signers across all petitions".
		 */
		$sql_newsletters = "CREATE TABLE {$newsletters} (
			id               bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			petition_id      bigint(20)   UNSIGNED NOT NULL DEFAULT 0,
			subject          varchar(500) NOT NULL DEFAULT '',
			content          longtext     DEFAULT NULL,
			status           varchar(20)  NOT NULL DEFAULT 'draft',
			sent_at          datetime     DEFAULT NULL,
			recipients_count int(11)      NOT NULL DEFAULT 0,
			created_at       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY petition_id (petition_id),
			KEY status (status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_supporters );
		dbDelta( $sql_newsletters );

		update_option( 'jtc_db_version', JTC_VERSION );
	}

	// ─── Default options ─────────────────────────────────────────────────────

	/**
	 * Seeds plugin options only on first activation (add_option is a no-op if
	 * the option already exists, so updating the plugin won't reset user settings).
	 */
	private static function set_default_options(): void {
		$scalar_defaults = [
			// Appearance.
			'jtc_color_mode'             => 'preset',
			'jtc_preset_theme'           => 'wcfjip',
			'jtc_custom_primary'         => '#2d6a2d',
			'jtc_custom_secondary'       => '#1a3d1a',
			'jtc_custom_accent'          => '#f0faf0',
			'jtc_custom_hero_from'       => '#245e2b',
			'jtc_custom_hero_to'         => '#4f8d33',
			'jtc_custom_page_bg'         => '#f6f8f4',
			'jtc_custom_surface'         => '#ffffff',
			'jtc_custom_surface_alt'     => '#f3f7f0',
			// General language.
			'jtc_privacy_notice'         => 'By signing, you agree to let us contact you about this petition. We will never share your information with third parties.',
			'jtc_terms_of_service'       => '',
			// Email sending.
			'jtc_email_method'           => 'wp_mail',
			'jtc_smtp_host'              => '',
			'jtc_smtp_port'              => 587,
			'jtc_smtp_username'          => '',
			'jtc_smtp_password'          => '',
			'jtc_smtp_encryption'        => 'tls',
			'jtc_api_provider'           => 'mailgun',
			'jtc_api_key'                => '',
			'jtc_mailgun_domain'         => '',
			'jtc_mailgun_region'         => 'us',
			'jtc_from_name'              => get_bloginfo( 'name' ),
			'jtc_from_email'             => get_option( 'admin_email' ),
			// Transactional emails.
			'jtc_welcome_email_enabled'  => 1,
			'jtc_welcome_email_subject'  => 'Thank you for signing — {petition_title}',
			'jtc_welcome_email_body'     => "Dear {first_name},\n\nThank you for signing \"{petition_title}\". Your support makes a real difference.\n\nBest,\n{site_name}",
			'jtc_admin_notify_enabled'   => 1,
			'jtc_admin_notify_email'     => get_option( 'admin_email' ),
		];

		foreach ( $scalar_defaults as $key => $value ) {
			add_option( $key, $value );
		}

		// Petition-level defaults (stored as a single serialised array).
		add_option( 'jtc_petition_defaults', [
			'show_count'          => 1,
			'show_recent'         => 1,
			'allow_comments'      => 0,
			'after_sign_action'   => 'message',
			'after_sign_message'  => 'Thank you for signing! Your name has been added to the petition.',
			'after_sign_redirect' => '',
			'share_buttons'       => [ 'facebook', 'twitter', 'copy', 'embed' ],
			'goal'                => 0,
		] );
	}
}
