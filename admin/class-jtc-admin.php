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

class JTC_Admin {

	public function register(): void {
		add_action( 'admin_menu',             [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init',             [ $this, 'handle_settings_save' ] );
		add_action( 'admin_init',             [ $this, 'handle_newsletter_actions' ] );
		add_action( 'admin_init',             [ $this, 'handle_supporter_actions' ] );
		add_action( 'admin_notices',          [ $this, 'admin_notices' ] );

		// Inline-copy shortcodes in the petition list.
		add_action( 'admin_footer-edit.php',  [ $this, 'shortcode_copy_script' ] );
	}

	// ─── Menus ────────────────────────────────────────────────────────────────

	public function register_menus(): void {
		// Top-level icon: a simple leaf/cause SVG as base64.
		$icon = 'dashicons-heart';

		add_menu_page(
			__( 'Join the Cause', 'join-the-cause' ),
			__( 'Join the Cause', 'join-the-cause' ),
			'manage_options',
			'join-the-cause',
			[ $this, 'page_settings' ],
			$icon,
			58
		);

		add_submenu_page(
			'join-the-cause',
			__( 'Settings — Join the Cause', 'join-the-cause' ),
			__( 'Settings',   'join-the-cause' ),
			'manage_options',
			'join-the-cause',
			[ $this, 'page_settings' ]
		);

		add_submenu_page(
			'join-the-cause',
			__( 'Petitions — Join the Cause', 'join-the-cause' ),
			__( 'Petitions',  'join-the-cause' ),
			'manage_options',
			'edit.php?post_type=' . JTC_CPT // redirect to native CPT list.
		);

		add_submenu_page(
			'join-the-cause',
			__( 'Supporters — Join the Cause', 'join-the-cause' ),
			__( 'Supporters', 'join-the-cause' ),
			'manage_options',
			'jtc-supporters',
			[ $this, 'page_supporters' ]
		);

		add_submenu_page(
			'join-the-cause',
			__( 'Newsletter — Join the Cause', 'join-the-cause' ),
			__( 'Newsletter', 'join-the-cause' ),
			'manage_options',
			'jtc-newsletter',
			[ $this, 'page_newsletter' ]
		);
	}

	// ─── Assets ───────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		$jtc_pages = [
			'toplevel_page_join-the-cause',
			'join-the-cause_page_jtc-supporters',
			'join-the-cause_page_jtc-newsletter',
		];

		$is_jtc     = in_array( $hook, $jtc_pages, true );
		$is_cpt     = in_array( $hook, [ 'post.php', 'post-new.php' ], true ) &&
		              isset( $_GET['post_type'] ) ? get_post_type( (int) ( $_GET['post'] ?? 0 ) ) === JTC_CPT : false;

		if ( ! $is_jtc && ! $is_cpt ) return;

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'jtc-admin',
			JTC_PLUGIN_URL . 'assets/css/admin.css',
			[ 'wp-color-picker' ],
			JTC_VERSION
		);

		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script(
			'jtc-admin',
			JTC_PLUGIN_URL . 'admin/js/jtc-admin.js',
			[ 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ],
			JTC_VERSION,
			true
		);

		wp_localize_script( 'jtc-admin', 'jtcAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'jtc_admin' ),
			'i18n'    => [
				'confirmDelete'  => __( 'Are you sure you want to delete this record? This cannot be undone.', 'join-the-cause' ),
				'confirmSend'    => __( 'Send this newsletter now? This cannot be undone.', 'join-the-cause' ),
				'copied'         => __( 'Copied!', 'join-the-cause' ),
				'newField'       => __( 'New Field', 'join-the-cause' ),
			],
		] );

		// TinyMCE for newsletter compose.
		if ( 'join-the-cause_page_jtc-newsletter' === $hook ) {
			wp_enqueue_editor();
		}
	}

	// ─── Settings save ────────────────────────────────────────────────────────

	public function handle_settings_save(): void {
		if ( ! isset( $_POST['jtc_settings_submit'] ) ) return;

		check_admin_referer( 'jtc_save_settings', 'jtc_settings_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Not allowed.', 'join-the-cause' ) );

		$tab = sanitize_key( $_POST['jtc_tab'] ?? 'appearance' );

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
		}

		wp_redirect( add_query_arg( [ 'page' => 'join-the-cause', 'tab' => $tab, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function save_appearance(): void {
		$mode = in_array( $_POST['jtc_color_mode'] ?? '', [ 'preset', 'custom', 'none' ], true )
			? sanitize_key( $_POST['jtc_color_mode'] )
			: 'preset';
		update_option( 'jtc_color_mode', $mode );

		$presets = array_keys( jtc_get_preset_themes() );
		$preset  = in_array( $_POST['jtc_preset_theme'] ?? '', $presets, true )
			? sanitize_key( $_POST['jtc_preset_theme'] )
			: 'wcfjip';
		update_option( 'jtc_preset_theme', $preset );

		update_option( 'jtc_custom_primary',   sanitize_hex_color( $_POST['jtc_custom_primary']   ?? '' ) ?: '#2d6a2d' );
		update_option( 'jtc_custom_secondary', sanitize_hex_color( $_POST['jtc_custom_secondary'] ?? '' ) ?: '#1a3d1a' );
		update_option( 'jtc_custom_accent',    sanitize_hex_color( $_POST['jtc_custom_accent']    ?? '' ) ?: '#f0faf0' );
		update_option( 'jtc_custom_hero_from', sanitize_hex_color( $_POST['jtc_custom_hero_from'] ?? '' ) ?: '#245e2b' );
		update_option( 'jtc_custom_hero_to',   sanitize_hex_color( $_POST['jtc_custom_hero_to']   ?? '' ) ?: '#4f8d33' );
		update_option( 'jtc_custom_page_bg',   sanitize_hex_color( $_POST['jtc_custom_page_bg']   ?? '' ) ?: '#f6f8f4' );
		update_option( 'jtc_custom_surface',   sanitize_hex_color( $_POST['jtc_custom_surface']   ?? '' ) ?: '#ffffff' );
		update_option( 'jtc_custom_surface_alt', sanitize_hex_color( $_POST['jtc_custom_surface_alt'] ?? '' ) ?: '#f3f7f0' );
	}

	private function save_general(): void {
		update_option( 'jtc_privacy_notice',   wp_kses_post( wp_unslash( $_POST['jtc_privacy_notice']   ?? '' ) ) );
		update_option( 'jtc_terms_of_service', wp_kses_post( wp_unslash( $_POST['jtc_terms_of_service'] ?? '' ) ) );
	}

	private function save_defaults(): void {
		$allowed_shares = [ 'facebook', 'twitter', 'copy', 'embed' ];

		$defaults = [
			'show_count'          => ! empty( $_POST['jtc_show_count'] ),
			'show_recent'         => ! empty( $_POST['jtc_show_recent'] ),
			'allow_comments'      => ! empty( $_POST['jtc_allow_comments'] ),
			'after_sign_action'   => in_array( $_POST['jtc_after_sign_action'] ?? '', [ 'message', 'redirect' ], true )
			                          ? sanitize_key( $_POST['jtc_after_sign_action'] ) : 'message',
			'after_sign_message'  => sanitize_textarea_field( wp_unslash( $_POST['jtc_after_sign_message'] ?? '' ) ),
			'after_sign_redirect' => esc_url_raw( wp_unslash( $_POST['jtc_after_sign_redirect'] ?? '' ) ),
			'share_buttons'       => array_intersect(
				array_map( 'sanitize_text_field', (array) ( $_POST['jtc_share_buttons'] ?? [] ) ),
				$allowed_shares
			),
			'goal'                => absint( $_POST['jtc_goal'] ?? 0 ),
		];

		update_option( 'jtc_petition_defaults', $defaults );
	}

	private function save_email(): void {
		$method = in_array( $_POST['jtc_email_method'] ?? '', [ 'wp_mail', 'smtp', 'api' ], true )
			? sanitize_key( $_POST['jtc_email_method'] )
			: 'wp_mail';
		update_option( 'jtc_email_method', $method );

		update_option( 'jtc_from_name',  sanitize_text_field( wp_unslash( $_POST['jtc_from_name']  ?? '' ) ) );
		update_option( 'jtc_from_email', sanitize_email( wp_unslash( $_POST['jtc_from_email'] ?? '' ) ) );

		// SMTP.
		update_option( 'jtc_smtp_host',       sanitize_text_field( wp_unslash( $_POST['jtc_smtp_host'] ?? '' ) ) );
		update_option( 'jtc_smtp_port',       absint( $_POST['jtc_smtp_port'] ?? 587 ) );
		update_option( 'jtc_smtp_username',   sanitize_text_field( wp_unslash( $_POST['jtc_smtp_username'] ?? '' ) ) );
		update_option( 'jtc_smtp_encryption', in_array( $_POST['jtc_smtp_encryption'] ?? '', [ 'tls', 'ssl', 'none' ], true )
			? sanitize_key( $_POST['jtc_smtp_encryption'] ) : 'tls' );
		// Only update password if a new one was provided.
		if ( ! empty( $_POST['jtc_smtp_password'] ) ) {
			update_option( 'jtc_smtp_password', sanitize_text_field( wp_unslash( $_POST['jtc_smtp_password'] ) ) );
		}

		// API.
		update_option( 'jtc_api_provider', sanitize_key( $_POST['jtc_api_provider'] ?? 'sendgrid' ) );
		if ( ! empty( $_POST['jtc_api_key'] ) ) {
			update_option( 'jtc_api_key', sanitize_text_field( wp_unslash( $_POST['jtc_api_key'] ) ) );
		}

		// Transactional emails.
		update_option( 'jtc_welcome_email_enabled',  ! empty( $_POST['jtc_welcome_email_enabled'] ) ? 1 : 0 );
		update_option( 'jtc_welcome_email_subject',  sanitize_text_field( wp_unslash( $_POST['jtc_welcome_email_subject'] ?? '' ) ) );
		update_option( 'jtc_welcome_email_body',     sanitize_textarea_field( wp_unslash( $_POST['jtc_welcome_email_body'] ?? '' ) ) );
		update_option( 'jtc_admin_notify_enabled',   ! empty( $_POST['jtc_admin_notify_enabled'] ) ? 1 : 0 );
		update_option( 'jtc_admin_notify_email',     sanitize_email( wp_unslash( $_POST['jtc_admin_notify_email'] ?? '' ) ) );
	}

	// ─── Newsletter actions ────────────────────────────────────────────────────

	public function handle_newsletter_actions(): void {
		if ( ! isset( $_POST['jtc_newsletter_action'] ) ) return;

		check_admin_referer( 'jtc_newsletter_action', 'jtc_newsletter_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die();

		$action = sanitize_key( $_POST['jtc_newsletter_action'] );
		global $wpdb;

		if ( 'save_draft' === $action || 'send' === $action ) {
			$petition_id = absint( $_POST['jtc_nl_petition_id'] ?? 0 );
			$subject     = sanitize_text_field( wp_unslash( $_POST['jtc_nl_subject'] ?? '' ) );
			$content     = wp_kses_post( wp_unslash( $_POST['jtc_nl_content'] ?? '' ) );
			$nl_id       = absint( $_POST['jtc_nl_id'] ?? 0 );

			if ( $nl_id ) {
				$wpdb->update(
					$wpdb->prefix . 'jtc_newsletters',
					[ 'petition_id' => $petition_id, 'subject' => $subject, 'content' => $content ],
					[ 'id' => $nl_id ],
					[ '%d', '%s', '%s' ],
					[ '%d' ]
				);
			} else {
				$wpdb->insert(
					$wpdb->prefix . 'jtc_newsletters',
					[ 'petition_id' => $petition_id, 'subject' => $subject, 'content' => $content, 'status' => 'draft' ],
					[ '%d', '%s', '%s', '%s' ]
				);
				$nl_id = (int) $wpdb->insert_id;
			}

			if ( 'send' === $action && $nl_id ) {
				$mailer = new JTC_Mailer();
				$count  = $mailer->send_newsletter( $nl_id, $petition_id, $subject, $content );
				wp_redirect( add_query_arg( [ 'page' => 'jtc-newsletter', 'sent' => $count ], admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		if ( 'delete' === $action ) {
			$nl_id = absint( $_POST['jtc_nl_id'] ?? 0 );
			if ( $nl_id ) {
				$wpdb->delete( $wpdb->prefix . 'jtc_newsletters', [ 'id' => $nl_id ], [ '%d' ] );
			}
		}

		wp_redirect( add_query_arg( [ 'page' => 'jtc-newsletter', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ─── Supporter actions ────────────────────────────────────────────────────

	public function handle_supporter_actions(): void {
		if ( ! isset( $_REQUEST['jtc_supporter_action'] ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) wp_die();

		$action = sanitize_key( $_REQUEST['jtc_supporter_action'] );
		global $wpdb;

		if ( 'delete' === $action ) {
			check_admin_referer( 'jtc_delete_supporter_' . absint( $_REQUEST['supporter_id'] ) );
			$wpdb->delete(
				$wpdb->prefix . 'jtc_supporters',
				[ 'id' => absint( $_REQUEST['supporter_id'] ) ],
				[ '%d' ]
			);
		}

		if ( 'export' === $action ) {
			check_admin_referer( 'jtc_export_supporters' );
			$this->export_supporters_csv();
		}

		wp_redirect( add_query_arg( [ 'page' => 'jtc-supporters', 'done' => $action ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function export_supporters_csv(): void {
		global $wpdb;

		$petition_id = absint( $_REQUEST['petition_id'] ?? 0 );

		$rows = $petition_id
			? $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}jtc_supporters WHERE petition_id = %d ORDER BY signed_at DESC",
				$petition_id
			), ARRAY_A )
			: $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}jtc_supporters ORDER BY signed_at DESC", ARRAY_A );

		$filename = 'jtc-supporters-' . ( $petition_id ?: 'all' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'ID', 'Petition ID', 'First Name', 'Last Name', 'Email', 'Display Consent', 'Signed At', 'IP' ] );

		foreach ( $rows as $row ) {
			fputcsv( $out, [
				$row['id'],
				$row['petition_id'],
				$row['first_name'],
				$row['last_name'],
				$row['email'],
				$row['display_consent'] ? 'yes' : 'no',
				$row['signed_at'],
				$row['ip_address'],
			] );
		}

		fclose( $out );
		exit;
	}

	// ─── Admin notices ─────────────────────────────────────────────────────────

	public function admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen ) return;

		if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'join-the-cause' ) . '</p></div>';
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
	}

	// ─── Page renderers ───────────────────────────────────────────────────────

	public function page_settings(): void {
		require_once JTC_PLUGIN_DIR . 'admin/views/page-settings.php';
	}

	public function page_supporters(): void {
		require_once JTC_PLUGIN_DIR . 'admin/views/page-supporters.php';
	}

	public function page_newsletter(): void {
		require_once JTC_PLUGIN_DIR . 'admin/views/page-newsletter.php';
	}

	// ─── Shortcode copy helper ────────────────────────────────────────────────

	public function shortcode_copy_script(): void {
		global $post_type;
		if ( JTC_CPT !== $post_type ) return;
		?>
		<script>
		jQuery( function( $ ) {
			$( document ).on( 'click', '.jtc-shortcode', function() {
				navigator.clipboard.writeText( $( this ).text() ).then( () => {
					var $el = $( this );
					$el.addClass( 'jtc-shortcode--copied' ).attr( 'title', '<?php echo esc_js( __( 'Copied!', 'join-the-cause' ) ); ?>' );
					setTimeout( () => $el.removeClass( 'jtc-shortcode--copied' ).attr( 'title', '<?php echo esc_js( __( 'Click to copy', 'join-the-cause' ) ); ?>' ), 1500 );
				} );
			} );
		} );
		</script>
		<?php
	}
}
