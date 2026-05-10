<?php
/**
 * Registers the jtc_petition Custom Post Type, its admin columns,
 * and all meta boxes (form fields builder, petition settings, stats).
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JTC_Post_Types {

	public function register(): void {
		add_action( 'init',                  [ $this, 'register_cpt' ] );
		add_action( 'add_meta_boxes',        [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_' . JTC_CPT,  [ $this, 'save_meta_boxes' ] );

		// Customise the CPT list-table columns.
		add_filter( 'manage_' . JTC_CPT . '_posts_columns',         [ $this, 'cpt_columns' ] );
		add_action( 'manage_' . JTC_CPT . '_posts_custom_column',   [ $this, 'cpt_column_content' ], 10, 2 );
		add_filter( 'manage_edit-' . JTC_CPT . '_sortable_columns', [ $this, 'sortable_columns' ] );

		// Single-petition page template (theme can still override via its own
		// single-jtc_petition.php or single-petition.php).
		add_filter( 'template_include', [ $this, 'petition_template' ] );
	}

	// ─── Single-petition page template ────────────────────────────────────────

	public function petition_template( string $template ): string {
		if ( ! is_singular( JTC_CPT ) ) {
			return $template;
		}

		// Let the active theme override with its own template files first.
		$theme_override = locate_template( [
			'single-' . JTC_CPT . '.php',
			'single-petition.php',
		] );

		if ( $theme_override ) {
			return $theme_override;
		}

		$plugin_template = JTC_PLUGIN_DIR . 'templates/single-jtc_petition.php';

		return file_exists( $plugin_template ) ? $plugin_template : $template;
	}

	// ─── CPT registration ─────────────────────────────────────────────────────

	public function register_cpt(): void {
		$labels = [
			'name'               => __( 'Petitions',              'join-the-cause' ),
			'singular_name'      => __( 'Petition',               'join-the-cause' ),
			'add_new'            => __( 'Add Petition',           'join-the-cause' ),
			'add_new_item'       => __( 'Add New Petition',       'join-the-cause' ),
			'edit_item'          => __( 'Edit Petition',          'join-the-cause' ),
			'new_item'           => __( 'New Petition',           'join-the-cause' ),
			'view_item'          => __( 'View Petition',          'join-the-cause' ),
			'search_items'       => __( 'Search Petitions',       'join-the-cause' ),
			'not_found'          => __( 'No petitions found.',    'join-the-cause' ),
			'not_found_in_trash' => __( 'No petitions in trash.', 'join-the-cause' ),
			'menu_name'          => __( 'Petitions',              'join-the-cause' ),
		];

		register_post_type( JTC_CPT, [
			'labels'             => $labels,
			'public'             => true,
			'has_archive'        => false, // Archive handled by JTC newsletter pages.
			'show_ui'            => true,
			'show_in_menu'       => false, // Shown under our custom menu instead.
			'show_in_rest'       => false, // Classic editor only.
			'capability_type'    => 'post',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
			'menu_position'      => 25,
			'rewrite'            => [ 'slug' => 'petition', 'with_front' => false ],
		] );
	}

	// ─── Admin columns ────────────────────────────────────────────────────────

	public function cpt_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['jtc_thumb']     = __( 'Image',       'join-the-cause' );
				$new['title']         = $label;
				$new['jtc_count']     = __( 'Signatures',  'join-the-cause' );
				$new['jtc_shortcode'] = __( 'Shortcode',   'join-the-cause' );
			} elseif ( 'date' === $key ) {
				$new['date'] = $label;
			} else {
				$new[ $key ] = $label;
			}
		}
		return $new;
	}

	public function cpt_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'jtc_thumb':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, [ 50, 50 ] );
				} else {
					echo '<span aria-label="' . esc_attr__( 'No image', 'join-the-cause' ) . '">—</span>';
				}
				break;

			case 'jtc_count':
				global $wpdb;
				$count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}jtc_supporters WHERE petition_id = %d",
						$post_id
					)
				);
				echo esc_html( number_format_i18n( $count ) );
				break;

			case 'jtc_shortcode':
				$code = '[jtc_petition id="' . $post_id . '"]';
				printf(
					'<code class="jtc-shortcode" title="%s" tabindex="0">%s</code>',
					esc_attr__( 'Click to copy', 'join-the-cause' ),
					esc_html( $code )
				);
				break;
		}
	}

	public function sortable_columns( array $columns ): array {
		$columns['jtc_count'] = 'jtc_count';
		return $columns;
	}

	// ─── Meta boxes ──────────────────────────────────────────────────────────

	public function add_meta_boxes(): void {
		add_meta_box(
			'jtc_form_fields',
			__( 'Signature Form Fields', 'join-the-cause' ),
			[ $this, 'render_form_fields_meta_box' ],
			JTC_CPT,
			'normal',
			'high'
		);

		add_meta_box(
			'jtc_petition_settings',
			__( 'Petition Settings', 'join-the-cause' ),
			[ $this, 'render_settings_meta_box' ],
			JTC_CPT,
			'side',
			'default'
		);

		add_meta_box(
			'jtc_petition_stats',
			__( 'Petition Stats', 'join-the-cause' ),
			[ $this, 'render_stats_meta_box' ],
			JTC_CPT,
			'side',
			'low'
		);
	}

	// ─── Form Fields meta box ─────────────────────────────────────────────────

	public function render_form_fields_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'jtc_save_form_fields_' . $post->ID, 'jtc_form_fields_nonce' );

		$raw    = get_post_meta( $post->ID, '_jtc_form_fields', true );
		$fields = $raw ? json_decode( $raw, true ) : [];

		// Always include the built-in (non-removable) fields for reference.
		?>
		<p class="description">
			<?php esc_html_e( 'First Name, Last Name, and Email are always collected and cannot be removed. Add any extra fields below.', 'join-the-cause' ); ?>
		</p>

		<table class="widefat jtc-built-in-fields" style="margin-bottom:12px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'join-the-cause' ); ?></th>
					<th><?php esc_html_e( 'Type', 'join-the-cause' ); ?></th>
					<th><?php esc_html_e( 'Required', 'join-the-cause' ); ?></th>
					<th><?php esc_html_e( 'Built-in', 'join-the-cause' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( [ 'First Name', 'Last Name', 'Email Address' ] as $built_in ) : ?>
				<tr style="opacity:.6;">
					<td><?php echo esc_html( $built_in ); ?></td>
					<td><?php echo 'Email Address' === $built_in ? 'email' : 'text'; ?></td>
					<td>✓</td>
					<td><em><?php esc_html_e( 'locked', 'join-the-cause' ); ?></em></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div id="jtc-field-builder">
			<table class="widefat" id="jtc-fields-table">
				<thead>
					<tr>
						<th style="width:30px;" aria-label="<?php esc_attr_e( 'Drag to reorder', 'join-the-cause' ); ?>"></th>
						<th><?php esc_html_e( 'Label', 'join-the-cause' ); ?></th>
						<th><?php esc_html_e( 'Type', 'join-the-cause' ); ?></th>
						<th><?php esc_html_e( 'Placeholder', 'join-the-cause' ); ?></th>
						<th><?php esc_html_e( 'Required', 'join-the-cause' ); ?></th>
						<th><?php esc_html_e( 'Remove', 'join-the-cause' ); ?></th>
					</tr>
				</thead>
				<tbody id="jtc-fields-body">
					<?php
					foreach ( $fields as $i => $field ) {
						$this->render_field_row( $i, $field );
					}
					?>
				</tbody>
			</table>

			<button type="button" id="jtc-add-field" class="button button-secondary" style="margin-top:8px;">
				<?php esc_html_e( '+ Add Field', 'join-the-cause' ); ?>
			</button>
		</div>

		<!-- Hidden input that JS keeps in sync with the table state -->
		<input type="hidden" id="jtc_form_fields_data" name="jtc_form_fields_data" value="<?php echo esc_attr( $raw ?: '[]' ); ?>">
		<?php
	}

	/** Outputs a single editable field row (called both on page load and via JS template). */
	private function render_field_row( int $index, array $field ): void {
		$label       = esc_attr( $field['label']       ?? '' );
		$type        = esc_attr( $field['type']        ?? 'text' );
		$placeholder = esc_attr( $field['placeholder'] ?? '' );
		$required    = ! empty( $field['required'] );
		$uid         = esc_attr( $field['id'] ?? wp_generate_uuid4() );
		?>
		<tr class="jtc-field-row" data-id="<?php echo $uid; ?>">
			<td class="jtc-drag-handle" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'join-the-cause' ); ?>">⠿</td>
			<td>
				<input
					type="text"
					class="jtc-field-label widefat"
					value="<?php echo $label; ?>"
					aria-label="<?php esc_attr_e( 'Field label', 'join-the-cause' ); ?>"
				>
			</td>
			<td>
				<select class="jtc-field-type" aria-label="<?php esc_attr_e( 'Field type', 'join-the-cause' ); ?>">
					<?php foreach ( [ 'text', 'email', 'textarea', 'checkbox', 'select' ] as $t ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input
					type="text"
					class="jtc-field-placeholder widefat"
					value="<?php echo $placeholder; ?>"
					aria-label="<?php esc_attr_e( 'Placeholder text', 'join-the-cause' ); ?>"
				>
			</td>
			<td style="text-align:center;">
				<input
					type="checkbox"
					class="jtc-field-required"
					<?php checked( $required ); ?>
					aria-label="<?php esc_attr_e( 'Required field', 'join-the-cause' ); ?>"
				>
			</td>
			<td>
				<button type="button" class="button-link jtc-remove-field" aria-label="<?php esc_attr_e( 'Remove this field', 'join-the-cause' ); ?>">✕</button>
			</td>
		</tr>
		<?php
	}

	// ─── Petition Settings meta box ───────────────────────────────────────────

	public function render_settings_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'jtc_save_settings_' . $post->ID, 'jtc_settings_nonce' );

		$defaults = get_option( 'jtc_petition_defaults', [] );
		$saved    = get_post_meta( $post->ID, '_jtc_petition_settings', true );
		$s        = is_array( $saved ) ? $saved : [];

		// Helper: get value, falling back to global default.
		$g = fn( string $key ) => $s[ $key ] ?? $defaults[ $key ] ?? null;

		$share_services = [ 'facebook', 'twitter', 'copy', 'embed' ];
		$saved_shares   = (array) ( $s['share_buttons'] ?? $defaults['share_buttons'] ?? [] );
		?>
		<p class="description" style="margin-bottom:12px;">
			<?php esc_html_e( 'Override the global defaults for this petition only.', 'join-the-cause' ); ?>
		</p>

		<table class="form-table jtc-settings-table" role="presentation">
			<tr>
				<th scope="row"><label for="jtc_goal"><?php esc_html_e( 'Signature goal', 'join-the-cause' ); ?></label></th>
				<td>
					<input type="number" id="jtc_goal" name="jtc_petition_settings[goal]"
						value="<?php echo esc_attr( $g( 'goal' ) ); ?>" min="0" class="small-text">
					<p class="description"><?php esc_html_e( '0 = no goal shown.', 'join-the-cause' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show count', 'join-the-cause' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="jtc_petition_settings[show_count]" value="1" <?php checked( $g( 'show_count' ) ); ?>>
						<?php esc_html_e( 'Display total signatures', 'join-the-cause' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Recent signers', 'join-the-cause' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="jtc_petition_settings[show_recent]" value="1" <?php checked( $g( 'show_recent' ) ); ?>>
						<?php esc_html_e( 'Show recent signer names (adds name-display consent to form)', 'join-the-cause' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Allow comments', 'join-the-cause' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="jtc_petition_settings[allow_comments]" value="1" <?php checked( $g( 'allow_comments' ) ); ?>>
						<?php esc_html_e( 'Enable WordPress comments on this petition', 'join-the-cause' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'After signing', 'join-the-cause' ); ?></th>
				<td>
					<label style="display:block;margin-bottom:4px;">
						<input type="radio" name="jtc_petition_settings[after_sign_action]" value="message"
							<?php checked( $g( 'after_sign_action' ), 'message' ); ?>>
						<?php esc_html_e( 'Show message', 'join-the-cause' ); ?>
					</label>
					<label style="display:block;">
						<input type="radio" name="jtc_petition_settings[after_sign_action]" value="redirect"
							<?php checked( $g( 'after_sign_action' ), 'redirect' ); ?>>
						<?php esc_html_e( 'Redirect to URL', 'join-the-cause' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="jtc_after_sign_message"><?php esc_html_e( 'Success message', 'join-the-cause' ); ?></label></th>
				<td>
					<textarea id="jtc_after_sign_message" name="jtc_petition_settings[after_sign_message]"
						class="widefat" rows="2"><?php echo esc_textarea( $g( 'after_sign_message' ) ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="jtc_after_sign_redirect"><?php esc_html_e( 'Redirect URL', 'join-the-cause' ); ?></label></th>
				<td>
					<input type="url" id="jtc_after_sign_redirect" name="jtc_petition_settings[after_sign_redirect]"
						value="<?php echo esc_attr( $g( 'after_sign_redirect' ) ); ?>" class="widefat"
						placeholder="https://...">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Share buttons', 'join-the-cause' ); ?></th>
				<td>
					<?php foreach ( $share_services as $svc ) : ?>
					<label style="display:inline-block;margin-right:12px;">
						<input type="checkbox" name="jtc_petition_settings[share_buttons][]"
							value="<?php echo esc_attr( $svc ); ?>"
							<?php checked( in_array( $svc, $saved_shares, true ) ); ?>>
						<?php echo esc_html( ucfirst( $svc ) ); ?>
					</label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	// ─── Stats meta box ───────────────────────────────────────────────────────

	public function render_stats_meta_box( WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Stats available after first save.', 'join-the-cause' ) . '</p>';
			return;
		}

		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jtc_supporters WHERE petition_id = %d",
				$post->ID
			)
		);

		$latest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT first_name, last_name, signed_at FROM {$wpdb->prefix}jtc_supporters
				 WHERE petition_id = %d ORDER BY signed_at DESC LIMIT 1",
				$post->ID
			)
		);

		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html( number_format_i18n( $count ) ),
			esc_html( _n( 'signature', 'signatures', $count, 'join-the-cause' ) )
		);

		if ( $latest ) {
			printf(
				'<p class="description">%s<br><time datetime="%s">%s</time></p>',
				sprintf(
					/* translators: 1 first name, 2 last name */
					esc_html__( 'Latest: %1$s %2$s', 'join-the-cause' ),
					esc_html( $latest->first_name ),
					esc_html( $latest->last_name )
				),
				esc_attr( $latest->signed_at ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $latest->signed_at ) ) )
			);
		}

		printf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=jtc-supporters&petition_id=' . $post->ID ) ),
			esc_html__( 'View all supporters →', 'join-the-cause' )
		);
	}

	// ─── Save callbacks ───────────────────────────────────────────────────────

	public function save_meta_boxes( int $post_id ): void {
		// Autosave / bulk-edit bail.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) )     return;

		// ── Form fields ──
		if (
			isset( $_POST['jtc_form_fields_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jtc_form_fields_nonce'] ) ), 'jtc_save_form_fields_' . $post_id )
		) {
			$raw = isset( $_POST['jtc_form_fields_data'] )
				? sanitize_text_field( wp_unslash( $_POST['jtc_form_fields_data'] ) )
				: '[]';

			$fields = json_decode( $raw, true );
			if ( ! is_array( $fields ) ) {
				$fields = [];
			}

			// Sanitise each field definition.
			$clean = array_map( function( array $f ): array {
				return [
					'id'          => sanitize_key( $f['id']          ?? wp_generate_uuid4() ),
					'label'       => sanitize_text_field( $f['label']       ?? '' ),
					'type'        => in_array( $f['type'] ?? '', [ 'text', 'email', 'textarea', 'checkbox', 'select' ], true )
					                 ? $f['type'] : 'text',
					'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
					'required'    => ! empty( $f['required'] ),
					'options'     => isset( $f['options'] ) ? array_map( 'sanitize_text_field', (array) $f['options'] ) : [],
				];
			}, $fields );

			update_post_meta( $post_id, '_jtc_form_fields', wp_json_encode( $clean ) );
		}

		// ── Petition settings ──
		if (
			isset( $_POST['jtc_settings_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jtc_settings_nonce'] ) ), 'jtc_save_settings_' . $post_id )
		) {
			$raw = isset( $_POST['jtc_petition_settings'] )
				? (array) $_POST['jtc_petition_settings'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				: [];

			$allowed_shares = [ 'facebook', 'twitter', 'copy', 'embed' ];

			$clean = [
				'goal'                => absint( $raw['goal'] ?? 0 ),
				'show_count'          => ! empty( $raw['show_count'] ),
				'show_recent'         => ! empty( $raw['show_recent'] ),
				'allow_comments'      => ! empty( $raw['allow_comments'] ),
				'after_sign_action'   => in_array( $raw['after_sign_action'] ?? '', [ 'message', 'redirect' ], true )
				                          ? $raw['after_sign_action'] : 'message',
				'after_sign_message'  => sanitize_textarea_field( $raw['after_sign_message'] ?? '' ),
				'after_sign_redirect' => esc_url_raw( $raw['after_sign_redirect'] ?? '' ),
				'share_buttons'       => array_intersect(
					array_map( 'sanitize_text_field', (array) ( $raw['share_buttons'] ?? [] ) ),
					$allowed_shares
				),
			];

			update_post_meta( $post_id, '_jtc_petition_settings', $clean );

			// Sync WP's native comment status with our setting.
			remove_action( 'save_post_' . JTC_CPT, [ $this, 'save_meta_boxes' ] );
			wp_update_post( [
				'ID'             => $post_id,
				'comment_status' => $clean['allow_comments'] ? 'open' : 'closed',
			] );
			add_action( 'save_post_' . JTC_CPT, [ $this, 'save_meta_boxes' ] );
		}
	}
}
