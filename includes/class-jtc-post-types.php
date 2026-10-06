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

/** Post Types WordPress component. */
class JTC_Post_Types {
	/**
	 * Register WordPress hooks for this component.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . JTC_CPT, array( $this, 'save_meta_boxes' ) );
		add_action( 'admin_notices', array( $this, 'shortio_admin_notices' ) );

		// Customise the CPT list-table columns.
		add_filter( 'manage_' . JTC_CPT . '_posts_columns', array( $this, 'cpt_columns' ) );
		add_action( 'manage_' . JTC_CPT . '_posts_custom_column', array( $this, 'cpt_column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . JTC_CPT . '_sortable_columns', array( $this, 'sortable_columns' ) );

		// Single-petition page template (theme can still override via its own
		// single-jtc_petition.php or single-petition.php).
		add_filter( 'template_include', array( $this, 'petition_template' ) );
	}

	// ─── Single-petition page template ────────────────────────────────────────
	/**
	 * Petition template.
	 *
	 * @param string $template Template.
	 * @return string Result value.
	 */
	public function petition_template( string $template ): string {
		if ( ! is_singular( JTC_CPT ) ) {
			return $template;
		}

		// Let the active theme override with its own template files first.
		$theme_override = locate_template(
			array(
				'single-' . JTC_CPT . '.php',
				'single-petition.php',
			)
		);

		if ( $theme_override ) {
			return $theme_override;
		}

		$plugin_template = JTC_PLUGIN_DIR . 'templates/single-jtc_petition.php';

		return file_exists( $plugin_template ) ? $plugin_template : $template;
	}

	// ─── CPT registration ─────────────────────────────────────────────────────
	/**
	 * Register cpt.
	 */
	public function register_cpt(): void {
		$labels = array(
			'name'               => __( 'Petitions', 'join-the-cause' ),
			'singular_name'      => __( 'Petition', 'join-the-cause' ),
			'add_new'            => __( 'Add Petition', 'join-the-cause' ),
			'add_new_item'       => __( 'Add New Petition', 'join-the-cause' ),
			'edit_item'          => __( 'Edit Petition', 'join-the-cause' ),
			'new_item'           => __( 'New Petition', 'join-the-cause' ),
			'view_item'          => __( 'View Petition', 'join-the-cause' ),
			'search_items'       => __( 'Search Petitions', 'join-the-cause' ),
			'not_found'          => __( 'No petitions found.', 'join-the-cause' ),
			'not_found_in_trash' => __( 'No petitions in trash.', 'join-the-cause' ),
			'menu_name'          => __( 'Petitions', 'join-the-cause' ),
		);

		register_post_type(
			JTC_CPT,
			array(
				'labels'                => $labels,
				'public'                => true,
				'publicly_queryable'    => true,
				'exclude_from_search'   => false,
				'has_archive'           => false, // Archive handled by JTC newsletter pages.
				'show_ui'               => true,
				'show_in_menu'          => false, // Shown under our custom menu instead.
				'show_in_nav_menus'     => true,
				'show_in_admin_bar'     => true,
				'show_in_rest'          => true,
				'rest_base'             => 'petitions',
				'rest_controller_class' => 'WP_REST_Posts_Controller',
				'capability_type'       => 'post',
				'supports'              => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'menu_position'         => 25,
				'query_var'             => true,
				'rewrite'               => array(
					'slug'       => 'petition',
					'with_front' => false,
				),
			)
		);
	}

	// ─── Admin columns ────────────────────────────────────────────────────────
	/**
	 * Cpt columns.
	 *
	 * @param array $columns Columns.
	 * @return array Result value.
	 */
	public function cpt_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['jtc_thumb']     = __( 'Image', 'join-the-cause' );
				$new['title']         = $label;
				$new['jtc_count']     = __( 'Signatures', 'join-the-cause' );
				$new['jtc_shortcode'] = __( 'Shortcode', 'join-the-cause' );
			} elseif ( 'date' === $key ) {
				$new['date'] = $label;
			} else {
				$new[ $key ] = $label;
			}
		}
		return $new;
	}
	/**
	 * Cpt column content.
	 *
	 * @param string $column Column.
	 * @param int    $post_id WordPress post ID.
	 */
	public function cpt_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'jtc_thumb':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 50, 50 ) );
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
					'<code class="jtc-shortcode" title="%s" tabindex="0" role="button">%s</code>',
					esc_attr__( 'Click to copy', 'join-the-cause' ),
					esc_html( $code )
				);
				break;
		}
	}
	/**
	 * Sortable columns.
	 *
	 * @param array $columns Columns.
	 * @return array Result value.
	 */
	public function sortable_columns( array $columns ): array {
		return $columns;
	}

	// ─── Meta boxes ──────────────────────────────────────────────────────────
	/**
	 * Add meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'jtc_form_fields',
			__( 'Signature Form Fields', 'join-the-cause' ),
			array( $this, 'render_form_fields_meta_box' ),
			JTC_CPT,
			'normal',
			'high'
		);

		add_meta_box(
			'jtc_petition_settings',
			__( 'Petition Settings', 'join-the-cause' ),
			array( $this, 'render_settings_meta_box' ),
			JTC_CPT,
			'side',
			'default'
		);

		add_meta_box(
			'jtc_shortio_link',
			__( 'Short.io Link', 'join-the-cause' ),
			array( $this, 'render_shortio_meta_box' ),
			JTC_CPT,
			'side',
			'default'
		);

		add_meta_box(
			'jtc_petition_stats',
			__( 'Petition Stats', 'join-the-cause' ),
			array( $this, 'render_stats_meta_box' ),
			JTC_CPT,
			'side',
			'low'
		);
	}

	// ─── Form Fields meta box ─────────────────────────────────────────────────
	/**
	 * Render form fields meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_form_fields_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'jtc_save_form_fields_' . $post->ID, 'jtc_form_fields_nonce' );

		$raw    = get_post_meta( $post->ID, '_jtc_form_fields', true );
		$fields = $raw ? json_decode( $raw, true ) : array();

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
				<?php
				$built_in_fields = array(
					array( __( 'First Name', 'join-the-cause' ), 'text' ),
					array( __( 'Last Name', 'join-the-cause' ), 'text' ),
					array( __( 'Email Address', 'join-the-cause' ), 'email' ),
				);
				foreach ( $built_in_fields as $built_in ) :
					?>
				<tr style="opacity:.6;">
					<td><?php echo esc_html( $built_in[0] ); ?></td>
					<td><?php echo esc_html( $built_in[1] ); ?></td>
					<td><span aria-hidden="true">✓</span><span class="screen-reader-text"><?php esc_html_e( 'Yes', 'join-the-cause' ); ?></span></td>
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
						<th><?php esc_html_e( 'Options', 'join-the-cause' ); ?><br>
							<span class="description"><?php esc_html_e( 'select fields only', 'join-the-cause' ); ?></span></th>
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
		<input type="hidden" id="jtc_form_fields_data" name="jtc_form_fields_data" value="<?php echo esc_attr( jtc_fallback( $raw, '[]' ) ); ?>">
		<?php
	}
	/**
	 * Render shortio meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_shortio_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'jtc_save_shortio_' . $post->ID, 'jtc_shortio_nonce' );

		$client = new JTC_Short_IO();
		// No remote calls while rendering: refresh happens on save or via the
		// "Pull from Short.io" button (avoids HTTP + meta writes on page views).
		$data       = $client->get_petition_data( $post->ID );
		$short_url  = jtc_fallback( $data['secure_url'], $data['short_url'] );
		$qr_url     = jtc_get_petition_qr_url( $post->ID );
		$configured = $client->is_configured();
		$post_slug  = jtc_fallback( $post->post_name, sanitize_title( $post->post_title ) );
		?>
		<?php if ( ! $configured ) : ?>
			<p class="description">
				<?php esc_html_e( 'Configure Short.io in Join the Cause settings before creating petition short links.', 'join-the-cause' ); ?>
			</p>
		<?php endif; ?>

		<p>
			<label for="jtc_shortio_custom_path"><strong><?php esc_html_e( 'Custom short slug', 'join-the-cause' ); ?></strong></label>
			<input type="text" id="jtc_shortio_custom_path" name="jtc_shortio_custom_path"
				value="<?php echo esc_attr( $data['custom_path'] ); ?>" class="widefat"
				placeholder="<?php echo esc_attr( $post_slug ); ?>">
			<span class="description"><?php esc_html_e( 'This pushes a slug to Short.io when you create or update the link. Leave blank to let Short.io generate one.', 'join-the-cause' ); ?></span>
		</p>

		<p>
			<label>
				<input type="checkbox" name="jtc_shortio_sync_slug" value="1" <?php checked( $data['sync_slug'] ); ?>>
				<?php esc_html_e( 'Sync with the WordPress petition slug', 'join-the-cause' ); ?>
			</label>
			<span class="description"><?php esc_html_e( 'When checked, saving this petition pushes the WordPress slug to Short.io. If the slug changes in Short.io later, the site pulls the latest Short.io URL before sharing.', 'join-the-cause' ); ?></span>
		</p>

		<p>
			<button type="submit" class="button button-secondary" name="jtc_shortio_sync" value="1" <?php disabled( ! $configured ); ?>>
				<?php echo esc_html( $short_url ? __( 'Update short link', 'join-the-cause' ) : __( 'Create short link', 'join-the-cause' ) ); ?>
			</button>
			<?php if ( $short_url ) : ?>
				<button type="submit" class="button" name="jtc_shortio_pull_remote" value="1" <?php disabled( ! $configured ); ?>>
					<?php esc_html_e( 'Pull from Short.io', 'join-the-cause' ); ?>
				</button>
				<button type="submit" class="button" name="jtc_shortio_refresh_qr" value="1" <?php disabled( ! $configured ); ?>>
					<?php esc_html_e( 'Refresh QR', 'join-the-cause' ); ?>
				</button>
			<?php endif; ?>
		</p>

		<?php if ( $short_url ) : ?>
			<div class="jtc-shortio-current">
				<p><strong><?php esc_html_e( 'Current Short.io URL', 'join-the-cause' ); ?></strong></p>
				<p><a href="<?php echo esc_url( $short_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $short_url ); ?></a></p>
				<?php if ( $data['path'] ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s Short.io slug */
							esc_html__( 'Current Short.io slug: %s', 'join-the-cause' ),
							esc_html( $data['path'] )
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( $data['last_synced'] ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s sync date/time */
							esc_html__( 'Last synced: %s', 'join-the-cause' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $data['last_synced'] ) ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $qr_url ) : ?>
			<div class="jtc-shortio-qr">
				<img src="<?php echo esc_url( $qr_url ); ?>" alt="<?php esc_attr_e( 'Short link QR code', 'join-the-cause' ); ?>">
				<p class="jtc-shortio-qr__actions">
					<a class="button button-small" href="<?php echo esc_url( $qr_url ); ?>" download>
						<?php esc_html_e( 'Download QR', 'join-the-cause' ); ?>
					</a>
					<button type="button" class="button button-small jtc-print-qr" data-qr-url="<?php echo esc_url( $qr_url ); ?>">
						<?php esc_html_e( 'Print', 'join-the-cause' ); ?>
					</button>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $data['last_error'] ) : ?>
			<p class="jtc-shortio-error"><?php echo esc_html( $data['last_error'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Outputs a single editable field row (called both on page load and via JS template).
	 *
	 * @param int   $index Index.
	 * @param array $field Field.
	 */
	private function render_field_row( int $index, array $field ): void {
		$label       = esc_attr( $field['label'] ?? '' );
		$type        = esc_attr( $field['type'] ?? 'text' );
		$placeholder = esc_attr( $field['placeholder'] ?? '' );
		$required    = ! empty( $field['required'] );
		$uid         = esc_attr( $field['id'] ?? wp_generate_uuid4() );
		$options     = array_map( 'strval', (array) ( $field['options'] ?? array() ) );
		?>
		<tr class="jtc-field-row" data-id="<?php echo esc_attr( $uid ); ?>">
			<td class="jtc-drag-handle" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'join-the-cause' ); ?>">⠿</td>
			<td>
				<input
					type="text"
					class="jtc-field-label widefat"
					value="<?php echo esc_attr( $label ); ?>"
					aria-label="<?php esc_attr_e( 'Field label', 'join-the-cause' ); ?>"
				>
			</td>
			<td>
				<select class="jtc-field-type" aria-label="<?php esc_attr_e( 'Field type', 'join-the-cause' ); ?>">
					<?php foreach ( array( 'text', 'email', 'textarea', 'checkbox', 'select' ) as $t ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input
					type="text"
					class="jtc-field-placeholder widefat"
					value="<?php echo esc_attr( $placeholder ); ?>"
					aria-label="<?php esc_attr_e( 'Placeholder text', 'join-the-cause' ); ?>"
				>
			</td>
			<td class="jtc-field-options-cell"<?php echo 'select' !== ( $field['type'] ?? 'text' ) ? ' style="display:none;"' : ''; ?>>
				<textarea
					class="jtc-field-options"
					rows="2"
					placeholder="<?php esc_attr_e( 'One option per line', 'join-the-cause' ); ?>"
					aria-label="<?php esc_attr_e( 'Select field options', 'join-the-cause' ); ?>"
				><?php echo esc_textarea( implode( "\n", $options ) ); ?></textarea>
			</td>
			<td style="text-align:center;">
				<input
					type="checkbox"
					class="jtc-field-required"
					<?php checked( $required ); ?>
					aria-label="<?php esc_attr_e( 'Required field', 'join-the-cause' ); ?>"
				>
			</td>
			<td class="jtc-field-actions">
				<button type="button" class="button-link jtc-move-field jtc-move-field--up" aria-label="<?php esc_attr_e( 'Move field up', 'join-the-cause' ); ?>">↑</button>
				<button type="button" class="button-link jtc-move-field jtc-move-field--down" aria-label="<?php esc_attr_e( 'Move field down', 'join-the-cause' ); ?>">↓</button>
				<button type="button" class="button-link jtc-remove-field" aria-label="<?php esc_attr_e( 'Remove this field', 'join-the-cause' ); ?>">✕</button>
			</td>
		</tr>
		<?php
	}

	// ─── Petition Settings meta box ───────────────────────────────────────────
	/**
	 * Render settings meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_settings_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'jtc_save_settings_' . $post->ID, 'jtc_settings_nonce' );

		$defaults = get_option( 'jtc_petition_defaults', array() );
		$saved    = get_post_meta( $post->ID, '_jtc_petition_settings', true );
		$s        = is_array( $saved ) ? $saved : array();

		// Helper: get value, falling back to global default.
		$g = fn( string $key ) => $s[ $key ] ?? $defaults[ $key ] ?? null;

		$share_services = array( 'facebook', 'twitter', 'copy', 'embed' );
		$saved_shares   = (array) ( $s['share_buttons'] ?? $defaults['share_buttons'] ?? array() );
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
	/**
	 * Render stats meta box.
	 *
	 * @param WP_Post $post Post.
	 */
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
	/**
	 * Save meta boxes.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public function save_meta_boxes( int $post_id ): void {
		// Autosave / bulk-edit bail.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// ── Form fields ──
		if (
			isset( $_POST['jtc_form_fields_nonce'] ) &&
			wp_verify_nonce( jtc_post_input( 'jtc_form_fields_nonce' ), 'jtc_save_form_fields_' . $post_id )
		) {
			$raw = isset( $_POST['jtc_form_fields_data'] )
				? jtc_post_input( 'jtc_form_fields_data' )
				: '[]';

			$fields = json_decode( $raw, true );
			if ( ! is_array( $fields ) ) {
				$fields = array();
			}

			// Sanitise each field definition.
			$clean = array_map(
				function ( array $f ): array {
					return array(
						'id'          => sanitize_key( $f['id'] ?? wp_generate_uuid4() ),
						'label'       => sanitize_text_field( $f['label'] ?? '' ),
						'type'        => in_array( $f['type'] ?? '', array( 'text', 'email', 'textarea', 'checkbox', 'select' ), true )
									? $f['type'] : 'text',
						'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
						'required'    => ! empty( $f['required'] ),
						'options'     => isset( $f['options'] ) ? array_map( 'sanitize_text_field', (array) $f['options'] ) : array(),
					);
				},
				array_filter( $fields, 'is_array' )
			);

			update_post_meta( $post_id, '_jtc_form_fields', wp_json_encode( $clean ) );
		}

		// ── Petition settings ──
		if (
			isset( $_POST['jtc_settings_nonce'] ) &&
			wp_verify_nonce( jtc_post_input( 'jtc_settings_nonce' ), 'jtc_save_settings_' . $post_id )
		) {
			$raw = isset( $_POST['jtc_petition_settings'] )
				? (array) wp_unslash( $_POST['jtc_petition_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				: array();

			$allowed_shares = array( 'facebook', 'twitter', 'copy', 'embed' );

			$clean = array(
				'goal'                => absint( $raw['goal'] ?? 0 ),
				'show_count'          => ! empty( $raw['show_count'] ),
				'show_recent'         => ! empty( $raw['show_recent'] ),
				'allow_comments'      => ! empty( $raw['allow_comments'] ),
				'after_sign_action'   => in_array( $raw['after_sign_action'] ?? '', array( 'message', 'redirect' ), true )
											? $raw['after_sign_action'] : 'message',
				'after_sign_message'  => sanitize_textarea_field( jtc_scalar( $raw['after_sign_message'] ?? '' ) ),
				'after_sign_redirect' => esc_url_raw( jtc_scalar( $raw['after_sign_redirect'] ?? '' ) ),
				'share_buttons'       => array_intersect(
					array_map( 'sanitize_text_field', (array) ( $raw['share_buttons'] ?? array() ) ),
					$allowed_shares
				),
			);

			update_post_meta( $post_id, '_jtc_petition_settings', $clean );

			// Sync WP's native comment status with our setting.
			remove_action( 'save_post_' . JTC_CPT, array( $this, 'save_meta_boxes' ) );
			wp_update_post(
				array(
					'ID'             => $post_id,
					'comment_status' => $clean['allow_comments'] ? 'open' : 'closed',
				)
			);
			add_action( 'save_post_' . JTC_CPT, array( $this, 'save_meta_boxes' ) );
		}

		$this->save_shortio_meta( $post_id );
	}
	/**
	 * Save shortio meta.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	private function save_shortio_meta( int $post_id ): void {
		$has_nonce = isset( $_POST['jtc_shortio_nonce'] )
			&& wp_verify_nonce( jtc_post_input( 'jtc_shortio_nonce' ), 'jtc_save_shortio_' . $post_id );

		$old_custom_path = (string) get_post_meta( $post_id, '_jtc_shortio_custom_path', true );
		$old_sync_slug   = (bool) get_post_meta( $post_id, '_jtc_shortio_sync_slug', true );
		$custom_path     = $old_custom_path;
		$sync_slug       = $old_sync_slug;

		if ( $has_nonce ) {
			$custom_path = JTC_Short_IO::sanitize_path( jtc_post_input( 'jtc_shortio_custom_path' ) );
			$sync_slug   = '' !== jtc_post_input( 'jtc_shortio_sync_slug' );

			update_post_meta( $post_id, '_jtc_shortio_custom_path', $custom_path );
			update_post_meta( $post_id, '_jtc_shortio_sync_slug', $sync_slug ? 1 : 0 );
		}

		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$has_link      = (bool) get_post_meta( $post_id, '_jtc_shortio_link_id', true );
		$manual_sync   = $has_nonce && '' !== jtc_post_input( 'jtc_shortio_sync' );
		$refresh_qr    = $has_nonce && '' !== jtc_post_input( 'jtc_shortio_refresh_qr' );
		$pull_remote   = $has_nonce && '' !== jtc_post_input( 'jtc_shortio_pull_remote' );
		$auto_create   = (bool) get_option( 'jtc_shortio_auto_create', 0 );
		$path_changed  = $has_nonce && ( $old_custom_path !== $custom_path || $old_sync_slug !== $sync_slug );
		$current_slug  = (string) get_post_field( 'post_name', $post_id );
		$stored_path   = (string) get_post_meta( $post_id, '_jtc_shortio_path', true );
		$should_sync   = $manual_sync || ( $auto_create && ! $has_link ) || ( $has_link && ( $path_changed || ( $sync_slug && $current_slug !== $stored_path ) ) );
		$notice_status = '';
		$notice_text   = '';

		$client = new JTC_Short_IO();

		if ( $pull_remote && $has_link ) {
			$result = $client->refresh_petition_link_from_remote( $post_id, true );

			if ( is_wp_error( $result ) ) {
				$notice_status = 'error';
				$notice_text   = $result->get_error_message();
			} else {
				$notice_status = 'success';
				$notice_text   = __( 'Short.io link pulled from Short.io.', 'join-the-cause' );
			}
		} elseif ( $should_sync ) {
			$path   = $sync_slug ? (string) get_post_field( 'post_name', $post_id ) : $custom_path;
			$result = $client->sync_petition_link( $post_id, $path, ! $sync_slug && '' === $custom_path );

			if ( is_wp_error( $result ) ) {
				$notice_status = 'error';
				$notice_text   = $result->get_error_message();
			} else {
				$notice_status = 'success';
				$notice_text   = __( 'Short.io link synced.', 'join-the-cause' );
			}
		} elseif ( $refresh_qr && $has_link ) {
			$result = $client->refresh_petition_qr( $post_id );

			if ( is_wp_error( $result ) ) {
				$notice_status = 'error';
				$notice_text   = $result->get_error_message();
				update_post_meta( $post_id, '_jtc_shortio_last_error', $notice_text );
			} else {
				$notice_status = 'success';
				$notice_text   = __( 'Short.io QR code refreshed.', 'join-the-cause' );
				delete_post_meta( $post_id, '_jtc_shortio_last_error' );
			}
		}

		if ( $notice_text ) {
			set_transient(
				'jtc_shortio_notice_' . get_current_user_id(),
				array(
					'status' => $notice_status,
					'text'   => $notice_text,
				),
				MINUTE_IN_SECONDS
			);
		}
	}
	/**
	 * Shortio admin notices.
	 */
	public function shortio_admin_notices(): void {
		$notice = get_transient( 'jtc_shortio_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) || empty( $notice['text'] ) ) {
			return;
		}

		delete_transient( 'jtc_shortio_notice_' . get_current_user_id() );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === ( $notice['status'] ?? '' ) ? 'error' : 'success',
			esc_html( $notice['text'] )
		);
	}
}
