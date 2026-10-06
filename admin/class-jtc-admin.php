<?php
/**
 * Admin class: registers menus, enqueues admin assets, handles settings
 * saves, and renders all admin page views.
 *
 * Menu structure:
 *   Join the Cause  →  Settings  (colour theme, general language, email)
 *                  →  Petitions  (redirects to CPT list)
 *                  →  Supporters (custom WP_List_Table)
 *                  →  Newsletter (compose + archive)
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Admin WordPress component. */
class JTC_Admin {
	/**
	 * Register WordPress hooks for this component.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_init', array( $this, 'handle_newsletter_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_supporter_actions' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_ajax_jtc_shortio_test', array( $this, 'ajax_shortio_test' ) );

		// Inline-copy shortcodes in the petition list.
		add_action( 'admin_footer-edit.php', array( $this, 'shortcode_copy_script' ) );
	}

	// ─── Menus ────────────────────────────────────────────────────────────────
	/**
	 * Register menus.
	 */
	public function register_menus(): void {
		$icon = 'dashicons-heart';

		add_menu_page(
			__( 'Join the Cause', 'join-the-cause' ),
			__( 'Join the Cause', 'join-the-cause' ),
			'manage_options',
			'join-the-cause',
			array( $this, 'page_settings' ),
			$icon,
			58
		);

		add_submenu_page(
			'join-the-cause',
			__( 'Settings — Join the Cause', 'join-the-cause' ),
			__( 'Settings', 'join-the-cause' ),
			'manage_options',
			'join-the-cause',
			array( $this, 'page_settings' )
		);

		add_submenu_page(
			'join-the-cause',
			__( 'Petitions — Join the Cause', 'join-the-cause' ),
			__( 'Petitions', 'join-the-cause' ),
			'manage_options',
			'edit.php?post_type=' . JTC_CPT // redirect to native CPT list.
		);

		add_submenu_page(
			'join-the-cause',
			__( 'Supporters — Join the Cause', 'join-the-cause' ),
			__( 'Supporters', 'join-the-cause' ),
			'manage_options',
			'jtc-supporters',
			array( $this, 'page_supporters' )
		);

		add_submenu_page(
			'join-the-cause',
			__( 'Newsletter — Join the Cause', 'join-the-cause' ),
			__( 'Newsletter', 'join-the-cause' ),
			'manage_options',
			'jtc-newsletter',
			array( $this, 'page_newsletter' )
		);
	}

	// ─── Assets ───────────────────────────────────────────────────────────────
	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue_assets( string $hook ): void {
		$jtc_pages = array(
			'toplevel_page_join-the-cause',
			'join-the-cause_page_jtc-supporters',
			'join-the-cause_page_jtc-newsletter',
		);

		$screen = get_current_screen();
		$is_jtc = in_array( $hook, $jtc_pages, true );
		$is_cpt = $screen && JTC_CPT === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true );

		if ( ! $is_jtc && ! $is_cpt ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'jtc-admin',
			JTC_PLUGIN_URL . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			JTC_VERSION
		);

		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script(
			'jtc-admin',
			JTC_PLUGIN_URL . 'admin/js/jtc-admin.js',
			array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable', 'wp-a11y' ),
			JTC_VERSION,
			true
		);

		wp_localize_script(
			'jtc-admin',
			'jtcAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'jtc_admin' ),
				'newsletterNonce' => wp_create_nonce( 'jtc_newsletter' ),
				'presets'         => jtc_get_preset_themes(),
				'styleMaps'       => jtc_style_maps(),
				'i18n'            => array(
					'confirmDelete'        => __( 'Are you sure you want to delete this record? This cannot be undone.', 'join-the-cause' ),
					/* translators: %d: number of recipients */
					'confirmSendCount'     => __( 'Send this newsletter now to %d recipients? This cannot be undone.', 'join-the-cause' ),
					'confirmSend'          => __( 'Send this newsletter now? This cannot be undone.', 'join-the-cause' ),
					'copied'               => __( 'Copied!', 'join-the-cause' ),
					'newField'             => __( 'New Field', 'join-the-cause' ),
					'fieldLabel'           => __( 'Field label', 'join-the-cause' ),
					'fieldType'            => __( 'Field type', 'join-the-cause' ),
					'placeholderText'      => __( 'Placeholder text', 'join-the-cause' ),
					'requiredField'        => __( 'Required field', 'join-the-cause' ),
					'removeField'          => __( 'Remove this field', 'join-the-cause' ),
					'dragToReorder'        => __( 'Drag to reorder', 'join-the-cause' ),
					'moveFieldUp'          => __( 'Move field up', 'join-the-cause' ),
					'moveFieldDown'        => __( 'Move field down', 'join-the-cause' ),
					'optionsLabel'         => __( 'Select field options', 'join-the-cause' ),
					'optionsPlaceholder'   => __( 'One option per line', 'join-the-cause' ),
					'printQrTitle'         => __( 'Print QR', 'join-the-cause' ),
					'qrAlt'                => __( 'QR code', 'join-the-cause' ),
					'testingConnection'    => __( 'Testing connection…', 'join-the-cause' ),
					'errorGeneric'         => __( 'Something went wrong. Please try again.', 'join-the-cause' ),
					'debugCopied'          => __( 'Debug information copied to clipboard.', 'join-the-cause' ),
					/* translators: %d: number of recipients */
					'recipientCount'       => __( '%d recipients', 'join-the-cause' ),
					'newsletterDraft'      => __( 'Draft', 'join-the-cause' ),
					'newsletterPreparing'  => __( 'Preparing', 'join-the-cause' ),
					'newsletterRecovering' => __( 'Recovering preparation', 'join-the-cause' ),
					'newsletterError'      => __( 'Progress could not be refreshed. Sending continues in the background; reload this page to check it.', 'join-the-cause' ),
					'newsletterPaused'     => __( 'Paused', 'join-the-cause' ),
					'newsletterQueued'     => __( 'Queued', 'join-the-cause' ),
					'newsletterSending'    => __( 'Sending', 'join-the-cause' ),
					'newsletterSent'       => __( 'Sent', 'join-the-cause' ),
					'newsletterCompleted'  => __( 'Completed with delivery issues', 'join-the-cause' ),
					'newsletterCancelled'  => __( 'Cancelled', 'join-the-cause' ),
					'recipientCountOne'    => __( '1 recipient', 'join-the-cause' ),
				),
			)
		);

		// TinyMCE for newsletter compose.
		if ( 'join-the-cause_page_jtc-newsletter' === $hook ) {
			wp_enqueue_editor();
		}
	}

	// ─── Settings save ────────────────────────────────────────────────────────
	/**
	 * Handle settings save.
	 */
	public function handle_settings_save(): void {
		$is_test_email = isset( $_POST['jtc_email_test_submit'] );
		if ( ! isset( $_POST['jtc_settings_submit'] ) && ! $is_test_email ) {
			return;
		}

		check_admin_referer( 'jtc_save_settings', 'jtc_settings_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'join-the-cause' ) );
		}

		$tab = sanitize_key( jtc_post_input( 'jtc_tab' ) );

		switch ( $tab ) {
			case 'appearance':
				$this->save_appearance();
				break;
			case 'general':
				$this->save_general();
				break;
			case 'defaults':
				$this->save_defaults();
				break;
			case 'email':
				$this->save_email();
				break;
			case 'integrations':
			case 'shortio':
				$this->save_shortio();
				break;
		}

		$args = array(
			'page'  => 'join-the-cause',
			'tab'   => $tab,
			'saved' => '1',
		);

		if ( $is_test_email && 'email' === $tab ) {
			$args['email_test'] = $this->send_test_email() ? 'sent' : 'failed';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
	/**
	 * Save appearance.
	 */
	private function save_appearance(): void {
		check_admin_referer( 'jtc_save_settings', 'jtc_settings_nonce' );
		$mode = in_array( jtc_post_input( 'jtc_color_mode' ), array( 'preset', 'custom', 'none' ), true )
			? sanitize_key( jtc_post_input( 'jtc_color_mode' ) )
			: 'preset';
		update_option( 'jtc_color_mode', $mode );

		$presets = array_keys( jtc_get_preset_themes() );
		$preset  = in_array( jtc_post_input( 'jtc_preset_theme' ), $presets, true )
			? sanitize_key( jtc_post_input( 'jtc_preset_theme' ) )
			: 'evergreen';
		update_option( 'jtc_preset_theme', $preset );

		$hex_fields = array(
			'jtc_custom_primary'     => '#2d6a2d',
			'jtc_custom_secondary'   => '#1a3d1a',
			'jtc_custom_accent'      => '#f0faf0',
			'jtc_custom_hero_from'   => '#245e2b',
			'jtc_custom_hero_to'     => '#4f8d33',
			'jtc_custom_page_bg'     => '#f6f8f4',
			'jtc_custom_surface'     => '#ffffff',
			'jtc_custom_surface_alt' => '#f3f7f0',
			'jtc_custom_border'      => '#d8e2d2',
		);

		foreach ( $hex_fields as $key => $default ) {
			$value = sanitize_hex_color( jtc_post_input( $key ) );
			update_option( $key, jtc_fallback( $value, $default ) );
		}

		// Layout & Style options (single serialized, versioned option).
		$style = jtc_sanitize_style_options( (array) wp_unslash( $_POST['jtc_style'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- jtc_sanitize_style_options validates every array member against its explicit schema.
		update_option( 'jtc_style', $style );
	}
	/**
	 * Save general.
	 */
	private function save_general(): void {
		check_admin_referer( 'jtc_save_settings', 'jtc_settings_nonce' );
		update_option( 'jtc_privacy_notice', wp_kses_post( jtc_post_input( 'jtc_privacy_notice', 'html' ) ) );
		update_option( 'jtc_terms_of_service', wp_kses_post( jtc_post_input( 'jtc_terms_of_service', 'html' ) ) );
	}
	/**
	 * Save defaults.
	 */
	private function save_defaults(): void {
		check_admin_referer( 'jtc_save_settings', 'jtc_settings_nonce' );
		$allowed_shares = array( 'facebook', 'twitter', 'copy', 'embed' );

		$defaults = array(
			'show_count'          => '' !== jtc_post_input( 'jtc_show_count' ),
			'show_recent'         => '' !== jtc_post_input( 'jtc_show_recent' ),
			'allow_comments'      => '' !== jtc_post_input( 'jtc_allow_comments' ),
			'after_sign_action'   => in_array( jtc_post_input( 'jtc_after_sign_action' ), array( 'message', 'redirect' ), true )
										? sanitize_key( jtc_post_input( 'jtc_after_sign_action' ) ) : 'message',
			'after_sign_message'  => sanitize_textarea_field( jtc_post_input( 'jtc_after_sign_message', 'textarea' ) ),
			'after_sign_redirect' => esc_url_raw( jtc_post_input( 'jtc_after_sign_redirect' ) ),
			'share_buttons'       => array_intersect(
				array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['jtc_share_buttons'] ?? array() ) ),
				$allowed_shares
			),
			'goal'                => absint( jtc_post_input( 'jtc_goal' ) ),
		);

		update_option( 'jtc_petition_defaults', $defaults );
	}
	/**
	 * Save email.
	 */
	private function save_email(): void {
		check_admin_referer( 'jtc_save_settings', 'jtc_settings_nonce' );
		$method = in_array( jtc_post_input( 'jtc_email_method' ), array( 'wp_mail', 'smtp', 'api' ), true )
			? sanitize_key( jtc_post_input( 'jtc_email_method' ) )
			: 'wp_mail';
		update_option( 'jtc_email_method', $method );

		update_option( 'jtc_from_name', sanitize_text_field( jtc_post_input( 'jtc_from_name' ) ) );
		update_option( 'jtc_from_email', sanitize_email( jtc_post_input( 'jtc_from_email' ) ) );

		// SMTP.
		update_option( 'jtc_smtp_host', sanitize_text_field( jtc_post_input( 'jtc_smtp_host' ) ) );
		$smtp_port = absint( jtc_post_input( 'jtc_smtp_port' ) );
		if ( $smtp_port < 1 || $smtp_port > 65535 ) {
			$smtp_port = 587; // Real port validation: 1–65535, else back to default.
		}
		update_option( 'jtc_smtp_port', $smtp_port );
		update_option( 'jtc_smtp_username', sanitize_text_field( jtc_post_input( 'jtc_smtp_username' ) ) );
		update_option(
			'jtc_smtp_encryption',
			in_array( jtc_post_input( 'jtc_smtp_encryption' ), array( 'tls', 'ssl', 'none' ), true )
			? sanitize_key( jtc_post_input( 'jtc_smtp_encryption' ) ) : 'tls'
		);
		// Only update password if a new one was provided.
		if ( '' !== jtc_post_input( 'jtc_smtp_password', 'secret' ) ) {
			update_option( 'jtc_smtp_password', jtc_post_input( 'jtc_smtp_password', 'secret' ), false );
		}

		// API.
		$api_provider = in_array( jtc_post_input( 'jtc_api_provider' ), array( 'sendgrid', 'mailgun' ), true )
			? sanitize_key( jtc_post_input( 'jtc_api_provider' ) )
			: 'mailgun';
		update_option( 'jtc_api_provider', $api_provider );
		if ( '' !== jtc_post_input( 'jtc_api_key' ) ) {
			update_option( 'jtc_api_key', sanitize_text_field( jtc_post_input( 'jtc_api_key' ) ), false );
		}
		update_option( 'jtc_mailgun_domain', $this->sanitize_mailgun_domain( jtc_post_input( 'jtc_mailgun_domain' ) ) );
		update_option(
			'jtc_mailgun_region',
			in_array( jtc_post_input( 'jtc_mailgun_region' ), array( 'us', 'eu' ), true )
				? sanitize_key( jtc_post_input( 'jtc_mailgun_region' ) )
				: 'us'
		);

		// Transactional emails.
		update_option( 'jtc_welcome_email_enabled', '' !== jtc_post_input( 'jtc_welcome_email_enabled' ) ? 1 : 0 );
		update_option( 'jtc_welcome_email_subject', sanitize_text_field( jtc_post_input( 'jtc_welcome_email_subject' ) ) );
		update_option( 'jtc_welcome_email_body', sanitize_textarea_field( jtc_post_input( 'jtc_welcome_email_body', 'textarea' ) ) );
		update_option( 'jtc_admin_notify_enabled', '' !== jtc_post_input( 'jtc_admin_notify_enabled' ) ? 1 : 0 );
		update_option( 'jtc_admin_notify_email', sanitize_email( jtc_post_input( 'jtc_admin_notify_email' ) ) );
	}
	/**
	 * Save shortio.
	 */
	private function save_shortio(): void {
		check_admin_referer( 'jtc_save_settings', 'jtc_settings_nonce' );
		$old_domain = JTC_Short_IO::normalize_domain( (string) get_option( 'jtc_shortio_domain', '' ) );
		$new_domain = JTC_Short_IO::normalize_domain( jtc_post_input( 'jtc_shortio_domain' ) );

		update_option( 'jtc_shortio_enabled', '' !== jtc_post_input( 'jtc_shortio_enabled' ) ? 1 : 0 );
		update_option( 'jtc_shortio_domain', $new_domain );
		update_option( 'jtc_shortio_auto_create', '' !== jtc_post_input( 'jtc_shortio_auto_create' ) ? 1 : 0 );
		update_option( 'jtc_shortio_use_for_sharing', '' !== jtc_post_input( 'jtc_shortio_use_for_sharing' ) ? 1 : 0 );

		if ( $old_domain !== $new_domain ) {
			delete_option( 'jtc_shortio_domain_id' );
		}

		if ( '' !== jtc_post_input( 'jtc_shortio_api_key' ) ) {
			update_option( 'jtc_shortio_api_key', sanitize_text_field( jtc_post_input( 'jtc_shortio_api_key' ) ), false );
			delete_option( 'jtc_shortio_domain_id' );
		}
	}

	// ─── Short.io AJAX: test connection ────────────────────────────────────────

	/**
	 * AJAX: tests the Short.io connection with a harmless read-only request.
	 * (list domains) and reports whether the configured domain is available.
	 */
	public function ajax_shortio_test(): void {
		check_ajax_referer( 'jtc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'join-the-cause' ) ), 403 );
		}

		$client = new JTC_Short_IO();

		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Short.io is not configured yet. Save your API key and short domain first.', 'join-the-cause' ) ), 400 );
		}

		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		wp_send_json_success( $result );
	}
	/**
	 * Sanitize mailgun domain.
	 *
	 * @param string $domain Domain.
	 * @return string Result value.
	 */
	private function sanitize_mailgun_domain( string $domain ): string {
		$domain = trim( strtolower( $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = strtok( (string) $domain, '/:' );

		return sanitize_text_field( (string) $domain );
	}
	/**
	 * Send test email.
	 *
	 * @return bool Result value.
	 */
	private function send_test_email(): bool {
		$user   = wp_get_current_user();
		$to     = is_email( $user->user_email ) ? $user->user_email : get_option( 'admin_email' );
		$mailer = new JTC_Mailer();
		$sent   = $mailer->send(
			$to,
			__( '[Join the Cause] Email test', 'join-the-cause' ),
			sprintf(
				/* translators: %s site name */
				__( "This is a test email from Join the Cause on %s.\n\nIf you received it, your email settings are working.", 'join-the-cause' ),
				get_bloginfo( 'name' )
			)
		);
		$message = $sent
			? sprintf(
				/* translators: %s recipient email */
				__( 'Test email sent to %s.', 'join-the-cause' ),
				$to
			)
			: sprintf(
				/* translators: %s mailer error */
				__( 'Test email failed. %s', 'join-the-cause' ),
				jtc_fallback( $mailer->get_last_error(), __( 'No additional error was returned.', 'join-the-cause' ) )
			);
		$transient = 'jtc_email_test_result_' . get_current_user_id();

		set_transient(
			$transient,
			array(
				'success' => $sent,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);

		return $sent;
	}

	// ─── Newsletter actions ────────────────────────────────────────────────────
	/**
	 * Handle newsletter actions.
	 */
	public function handle_newsletter_actions(): void {
		if ( ! isset( $_POST['jtc_newsletter_action'] ) ) {
			return;
		}

		check_admin_referer( 'jtc_newsletter_action', 'jtc_newsletter_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		$action = sanitize_key( jtc_post_input( 'jtc_newsletter_action' ) );
		global $wpdb;

		if ( 'save_draft' === $action || 'send' === $action ) {
			$petition_id = absint( jtc_post_input( 'jtc_nl_petition_id' ) );
			$subject     = sanitize_text_field( jtc_post_input( 'jtc_nl_subject' ) );
			$content     = wp_kses_post( jtc_post_input( 'jtc_nl_content', 'html' ) );
			$nl_id       = absint( jtc_post_input( 'jtc_nl_id' ) );

			if ( $nl_id ) {
				$is_draft = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jtc_newsletters WHERE id = %d AND status = 'draft'", $nl_id ) );
				if ( ! $is_draft ) {
					wp_die( esc_html__( 'Only drafts can be edited or queued.', 'join-the-cause' ) );
				}
				$wpdb->update(
					$wpdb->prefix . 'jtc_newsletters',
					array(
						'petition_id' => $petition_id,
						'subject'     => $subject,
						'content'     => $content,
					),
					array(
						'id'     => $nl_id,
						'status' => 'draft',
					),
					array( '%d', '%s', '%s' ),
					array( '%d', '%s' )
				);
			} else {
				$wpdb->insert(
					$wpdb->prefix . 'jtc_newsletters',
					array(
						'petition_id' => $petition_id,
						'subject'     => $subject,
						'content'     => $content,
						'status'      => 'draft',
					),
					array( '%d', '%s', '%s', '%s' )
				);
				$nl_id = (int) $wpdb->insert_id;
			}

			if ( 'send' === $action && $nl_id ) {
				if ( '' === $subject || '' === trim( wp_strip_all_tags( $content ) ) ) {
					wp_die( esc_html__( 'Add a subject and message before sending.', 'join-the-cause' ) );
				}
				$result = ( new JTC_Newsletter() )->start( $nl_id );
				if ( is_wp_error( $result ) ) {
					wp_die( esc_html( $result->get_error_message() ) );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'   => 'jtc-newsletter',
							'queued' => $nl_id,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		if ( 'delete' === $action ) {
			$nl_id = absint( jtc_post_input( 'jtc_nl_id' ) );
			if ( $nl_id ) {
				$wpdb->delete(
					$wpdb->prefix . 'jtc_newsletters',
					array(
						'id'     => $nl_id,
						'status' => 'draft',
					),
					array( '%d', '%s' )
				);
			}
		}

		if ( 'send_test' === $action ) {
			$subject = sanitize_text_field( jtc_post_input( 'jtc_nl_subject' ) );
			$content = wp_kses_post( jtc_post_input( 'jtc_nl_content', 'html' ) );

			if ( '' === $subject || '' === $content ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'      => 'jtc-newsletter',
							'test_sent' => 'missing',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$user = wp_get_current_user();
			$to   = is_email( $user->user_email ) ? $user->user_email : get_option( 'admin_email' );

			$mailer = new JTC_Mailer();
			$ok     = $mailer->send( $to, '[TEST] ' . $subject, $content, true );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'      => 'jtc-newsletter',
						'test_sent' => $ok ? 'sent' : 'failed',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'jtc-newsletter',
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ─── Supporter actions ────────────────────────────────────────────────────
	/**
	 * Handle supporter actions.
	 */
	public function handle_supporter_actions(): void {
		if ( ! isset( $_REQUEST['jtc_supporter_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		$action = sanitize_key( $_REQUEST['jtc_supporter_action'] );
		global $wpdb;

		if ( 'delete' === $action ) {
			check_admin_referer( 'jtc_delete_supporter_' . absint( ( $_REQUEST['supporter_id'] ?? 0 ) ) );
			$wpdb->delete(
				$wpdb->prefix . 'jtc_supporters',
				array( 'id' => absint( ( $_REQUEST['supporter_id'] ?? 0 ) ) ),
				array( '%d' )
			);
		}

		if ( 'export' === $action ) {
			check_admin_referer( 'jtc_export_supporters' );
			$this->export_supporters_csv();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'jtc-supporters',
					'done' => $action,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
	/**
	 * Export supporters csv.
	 */
	private function export_supporters_csv(): void {
		global $wpdb;
		$petition_id = isset( $_REQUEST['petition_id'] ) && is_scalar( $_REQUEST['petition_id'] ) ? absint( wp_unslash( $_REQUEST['petition_id'] ) ) : 0;
		$filename    = 'jtc-supporters-' . ( jtc_fallback( $petition_id, 'all' ) ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open the export stream.', 'join-the-cause' ) );
		}
		fputcsv( $out, array( 'ID', 'Petition ID', 'First Name', 'Last Name', 'Email', 'Display Consent', 'Newsletter Consent', 'Signed At' ), ',', '"', '' );
		$last_id = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jtc_supporters WHERE id > %d AND (petition_id = %d OR %d = 0) ORDER BY id ASC LIMIT 500", $last_id, $petition_id, $petition_id ), ARRAY_A );
			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				$cells   = array( $row['id'], $row['petition_id'], $row['first_name'], $row['last_name'], $row['email'], $row['display_consent'] ? 'yes' : 'no', $row['newsletter_consent'] ? 'yes' : 'no', $row['signed_at'] );
				fputcsv( $out, array_map( 'jtc_csv_cell', $cells ), ',', '"', '' );
			}
			$row_count = count( $rows );
		} while ( 500 === $row_count );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the native php://output CSV response stream, not a filesystem file.
		exit;
	}

	// ─── Admin notices ─────────────────────────────────────────────────────────
	/**
	 * Admin notices.
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! jtc_is_jtc_admin_screen( $screen ) ) {
			return;
		}

		if ( '1' !== (string) get_option( 'jtc_signature_index_ready', '0' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The signature database upgrade is incomplete. Existing signatures are preserved, and new signing is temporarily disabled. Check database permissions and historical duplicate email addresses before retrying the upgrade.', 'join-the-cause' ) . '</p></div>';
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		// Settings screens: every tab save lands here with saved=1 + tab.
		if ( 'join-the-cause' === $page && isset( $_GET['saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['saved'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'join-the-cause' ) . '</p></div>';
		}

		// Email test result (transient written by send_test_email()).
		if ( 'join-the-cause' === $page && isset( $_GET['email_test'] ) ) {
			$transient = 'jtc_email_test_result_' . get_current_user_id();
			$result    = get_transient( $transient );
			delete_transient( $transient );

			if ( is_array( $result ) && ! empty( $result['message'] ) ) {
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					! empty( $result['success'] ) ? 'success' : 'error',
					esc_html( $result['message'] )
				);
			}
		}

		// Supporters screen.
		if ( 'jtc-supporters' === $page && isset( $_GET['done'] ) && 'delete' === sanitize_key( wp_unslash( $_GET['done'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Supporter deleted.', 'join-the-cause' ) . '</p></div>';
		}

		// Newsletter screen.
		if ( 'jtc-newsletter' === $page ) {
			if ( isset( $_GET['queued'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Newsletter queued. Progress and controls are available in the archive.', 'join-the-cause' ) . '</p></div>';
			}
			if ( isset( $_GET['sent'] ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					sprintf(
						/* translators: %d number of recipients */
						esc_html__( 'Newsletter sent to %d recipients.', 'join-the-cause' ),
						(int) $_GET['sent']
					)
				);
			}

			if ( isset( $_GET['saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['saved'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Newsletter draft saved.', 'join-the-cause' ) . '</p></div>';
			}

			if ( isset( $_GET['test_sent'] ) ) {
				$test = sanitize_key( wp_unslash( $_GET['test_sent'] ) );
				$map  = array(
					'sent'    => array( 'success', __( 'Test email sent to you.', 'join-the-cause' ) ),
					'failed'  => array( 'error', __( 'Test email failed. Check the Email tab settings.', 'join-the-cause' ) ),
					'missing' => array( 'warning', __( 'Add a subject and message before sending a test.', 'join-the-cause' ) ),
				);

				if ( isset( $map[ $test ] ) ) {
					printf(
						'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
						esc_attr( $map[ $test ][0] ),
						esc_html( $map[ $test ][1] )
					);
				}
			}
		}
	}

	// ─── Page renderers ───────────────────────────────────────────────────────
	/**
	 * Page settings.
	 */
	public function page_settings(): void {
		require_once JTC_PLUGIN_DIR . 'admin/views/page-settings.php';
	}
	/**
	 * Page supporters.
	 */
	public function page_supporters(): void {
		require_once JTC_PLUGIN_DIR . 'admin/views/page-supporters.php';
	}
	/**
	 * Page newsletter.
	 */
	public function page_newsletter(): void {
		require_once JTC_PLUGIN_DIR . 'admin/views/page-newsletter.php';
	}

	// ─── Shortcode copy helper ────────────────────────────────────────────────
	/**
	 * Shortcode copy script.
	 */
	public function shortcode_copy_script(): void {
		global $post_type;
		if ( JTC_CPT !== $post_type ) {
			return;
		}
		?>
		<script>
		jQuery( function( $ ) {
			/**
			 * Speak.
			 */
			function speak( message ) {
				if ( window.wp && wp.a11y && wp.a11y.speak ) {
					wp.a11y.speak( message, 'assertive' );
				}
			}
			/**
			 * Copyshortcode.
			 *
			 * @param mixed $el El.
			 */

			function copyShortcode( $el ) {
				if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) return;

				navigator.clipboard.writeText( $el.text() ).then( function() {
					$el.addClass( 'jtc-shortcode--copied' ).attr( 'title', '<?php echo esc_js( __( 'Copied!', 'join-the-cause' ) ); ?>' );
					speak( '<?php echo esc_js( __( 'Shortcode copied to clipboard.', 'join-the-cause' ) ); ?>' );
					setTimeout( function() {
						$el.removeClass( 'jtc-shortcode--copied' ).attr( 'title', '<?php echo esc_js( __( 'Click to copy', 'join-the-cause' ) ); ?>' );
					}, 1500 );
				} );
			}

			$( document ).on( 'click', '.jtc-shortcode', function() {
				copyShortcode( $( this ) );
			} );

			$( document ).on( 'keydown', '.jtc-shortcode', function( event ) {
				if ( 'Enter' === event.key || ' ' === event.key || 'Spacebar' === event.key ) {
					event.preventDefault();
					copyShortcode( $( this ) );
				}
			} );
		} );
		</script>
		<?php
	}
}
