<?php
/**
 * Runs on plugin activation and handles DB table creation + default options.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Activator WordPress component. */
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

	// ─── One-time migrations ────────────────────────────────────────────────

	/**
	 * Maps a stored preset slug to a current, valid slug.
	 * Legacy or renamed slugs that no longer exist fall back to the default
	 * preset, so renamed keys migrate cleanly without keeping the old string
	 * anywhere in the codebase.
	 *
	 * @param string   $stored      Value currently stored in the option.
	 * @param string[] $valid_slugs Current preset keys.
	 * @return string Result value.
	 */
	public static function sanitize_stored_preset_slug( string $stored, array $valid_slugs ): string {
		if ( in_array( $stored, $valid_slugs, true ) ) {
			return $stored;
		}

		return 'evergreen';
	}

	/**
	 * One-time, idempotent migration for installs that stored a preset slug.
	 * which has since been renamed. Runs on every request until flagged once.
	 */
	public static function maybe_migrate(): void {
		if ( get_option( 'jtc_trace_migration' ) ) {
			return;
		}

		$valid  = array_keys( jtc_get_preset_themes() );
		$stored = (string) get_option( 'jtc_preset_theme', '' );
		$fixed  = self::sanitize_stored_preset_slug( $stored, $valid );

		if ( $fixed !== $stored ) {
			update_option( 'jtc_preset_theme', $fixed );
		}

		update_option( 'jtc_trace_migration', 1 );
	}

	/**
	 * Upgrade existing schemas while preserving historical signature data.
	 * Signing remains unavailable if a required unique index cannot be created.
	 */
	public static function maybe_upgrade_schema(): void {
		if ( JTC_DB_VERSION === (string) get_option( 'jtc_db_version', '' ) && null !== get_option( 'jtc_signature_index_ready', null ) ) {
			return;
		}

		self::create_tables();
		self::set_default_options();
	}

	// ─── DB tables ───────────────────────────────────────────────────────────
	/**
	 * Create tables.
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset     = $wpdb->get_charset_collate();
		$supporters  = $wpdb->prefix . 'jtc_supporters';
		$newsletters = $wpdb->prefix . 'jtc_newsletters';

		/**
		 * Signature storage.
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
			newsletter_consent tinyint(1) NOT NULL DEFAULT 0,
			extra_fields  longtext     NULL,
			ip_address    varchar(45)  NOT NULL DEFAULT '',
			signed_at     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY petition_id (petition_id),
			UNIQUE KEY petition_email (petition_id, email(191))
		) {$charset};";

		/**
		 * Newsletter storage.
		 * One row per drafted/sent newsletter blast.
		 * petition_id = 0 means "sent to all signers across all petitions".
		 */
		$sql_newsletters = "CREATE TABLE {$newsletters} (
			id               bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			petition_id      bigint(20)   UNSIGNED NOT NULL DEFAULT 0,
			subject          varchar(500) NOT NULL DEFAULT '',
			content          longtext     NULL,
			status           varchar(20)  NOT NULL DEFAULT 'draft',
			sent_at          datetime     DEFAULT NULL,
			recipients_count int(11)      NOT NULL DEFAULT 0,
			lock_token       varchar(64) NOT NULL DEFAULT '',
			locked_until     bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY petition_id (petition_id),
			KEY status (status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_supporters );
		dbDelta( $sql_newsletters );

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}jtc_rate_limits (
			bucket varchar(64) NOT NULL,
			attempts bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			expires_at bigint(20) UNSIGNED NOT NULL,
			PRIMARY KEY  (bucket),
			KEY expires_at (expires_at)
		) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}jtc_deliveries (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			newsletter_id bigint(20) UNSIGNED NOT NULL,
			supporter_id bigint(20) UNSIGNED NOT NULL,
			email varchar(200) NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			KEY newsletter_status (newsletter_id,status),
			UNIQUE KEY newsletter_email (newsletter_id,email(191)),
			KEY email (email(191))
		) {$charset};"
		);
		// dbDelta compares column types but does not migrate NOT NULL to NULL.
		// Make the privacy-erasure change explicit for previously queued recipients.
		$email_column = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}jtc_deliveries LIKE 'email'", ARRAY_A );
		if ( is_array( $email_column ) && 'NO' === $email_column['Null'] ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}jtc_deliveries MODIFY email varchar(200) NULL" );
		}

		// Never autoload credentials, including options created by older versions.
		foreach ( array( 'jtc_smtp_password', 'jtc_api_key', 'jtc_shortio_api_key' ) as $secret ) {
			$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $secret ), array( '%s' ), array( '%s' ) );
			wp_cache_delete( $secret, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		if ( ! wp_next_scheduled( 'jtc_cleanup_rates' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'jtc_cleanup_rates' );
		}
		$previous_version = (string) get_option( 'jtc_db_version', '' );
		if ( ! in_array( $previous_version, array( '4', '5', '6' ), true ) ) {
			$wpdb->query( "UPDATE {$wpdb->prefix}jtc_newsletters SET status = 'interrupted' WHERE status = 'sending' AND lock_token = '' AND locked_until = 0" );
		}
		// Legacy duplicate rows can prevent dbDelta from creating the unique key.
		// Preserve those signatures, but refuse new writes until the index exists.
		$signature_index = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}jtc_supporters WHERE Key_name = 'petition_email'", ARRAY_A );
		$index_ready     = '' === $wpdb->last_error && 2 === count( $signature_index )
			&& '0' === (string) $signature_index[0]['Non_unique']
			&& 'petition_id' === $signature_index[0]['Column_name']
			&& 'email' === $signature_index[1]['Column_name'];
		update_option( 'jtc_signature_index_ready', $index_ready ? '1' : '0', false );
		$email_column = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}jtc_deliveries LIKE 'email'", ARRAY_A );
		if ( ! $index_ready || ! is_array( $email_column ) || 'YES' !== $email_column['Null'] ) {
			return; // Retry incomplete migrations instead of flagging them successful.
		}

		update_option( 'jtc_db_version', JTC_DB_VERSION );
	}

	// ─── Default options ─────────────────────────────────────────────────────

	/**
	 * Seeds plugin options only on first activation (add_option is a no-op if.
	 * the option already exists, so updating the plugin won't reset user settings).
	 */
	private static function set_default_options(): void {
		$scalar_defaults = array(
			// Appearance.
			'jtc_color_mode'              => 'preset',
			'jtc_preset_theme'            => 'evergreen',
			'jtc_custom_primary'          => '#2d6a2d',
			'jtc_custom_secondary'        => '#1a3d1a',
			'jtc_custom_accent'           => '#f0faf0',
			'jtc_custom_hero_from'        => '#245e2b',
			'jtc_custom_hero_to'          => '#4f8d33',
			'jtc_custom_page_bg'          => '#f6f8f4',
			'jtc_custom_surface'          => '#ffffff',
			'jtc_custom_surface_alt'      => '#f3f7f0',
			'jtc_custom_border'           => '#d8e2d2',
			// General language.
			'jtc_privacy_notice'          => 'By signing, you agree to let us contact you about this petition. Our configured email provider may process your contact details to deliver these messages. Newsletter emails are optional and require your separate consent.',
			'jtc_terms_of_service'        => '',
			// Email sending.
			'jtc_email_method'            => 'wp_mail',
			'jtc_smtp_host'               => '',
			'jtc_smtp_port'               => 587,
			'jtc_smtp_username'           => '',
			'jtc_smtp_password'           => '',
			'jtc_smtp_encryption'         => 'tls',
			'jtc_api_provider'            => 'mailgun',
			'jtc_api_key'                 => '',
			'jtc_mailgun_domain'          => '',
			'jtc_mailgun_region'          => 'us',
			'jtc_from_name'               => get_bloginfo( 'name' ),
			'jtc_from_email'              => get_option( 'admin_email' ),
			// Transactional emails.
			'jtc_welcome_email_enabled'   => 1,
			'jtc_welcome_email_subject'   => 'Thank you for signing — {petition_title}',
			'jtc_welcome_email_body'      => "Dear {first_name},\n\nThank you for signing \"{petition_title}\". Your support makes a real difference.\n\nBest,\n{site_name}",
			'jtc_admin_notify_enabled'    => 1,
			'jtc_admin_notify_email'      => get_option( 'admin_email' ),
			// Short.io.
			'jtc_shortio_enabled'         => 0,
			'jtc_shortio_api_key'         => '',
			'jtc_shortio_domain'          => '',
			'jtc_shortio_domain_id'       => 0,
			'jtc_shortio_auto_create'     => 0,
			'jtc_shortio_use_for_sharing' => 0,
		);

		foreach ( $scalar_defaults as $key => $value ) {
			add_option( $key, $value, '', ! in_array( $key, array( 'jtc_smtp_password', 'jtc_api_key', 'jtc_shortio_api_key' ), true ) );
		}

		// Petition-level defaults (stored as a single serialised array).
		add_option(
			'jtc_petition_defaults',
			array(
				'show_count'          => 1,
				'show_recent'         => 1,
				'allow_comments'      => 0,
				'after_sign_action'   => 'message',
				'after_sign_message'  => 'Thank you for signing! Your name has been added to the petition.',
				'after_sign_redirect' => '',
				'share_buttons'       => array( 'facebook', 'twitter', 'copy', 'embed' ),
				'goal'                => 0,
			)
		);

		// Layout & Style (single serialized, versioned option).
		add_option( 'jtc_style', jtc_style_defaults() );
	}
}
