<?php
/**
 * Supporters list page — all petition signers with filter, sort, export, delete.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Not allowed.', 'join-the-cause' ) );
}

global $wpdb;

// ── Filters ────────────────────────────────────────────────────────────────
$filter_petition = isset( $_GET['petition_id'] ) ? absint( $_GET['petition_id'] ) : 0;
$jtc_search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$order_by        = in_array( $_GET['orderby'] ?? '', array( 'signed_at', 'first_name', 'email' ), true )
					? sanitize_key( $_GET['orderby'] ) : 'signed_at';
$jtc_order       = 'ASC' === sanitize_text_field( wp_unslash( $_GET['order'] ?? '' ) ) ? 'ASC' : 'DESC';
$jtc_per_page    = 25;
$current_page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
$offset          = ( $current_page - 1 ) * $jtc_per_page;

// The optional filter values are always prepared; only a whitelisted column
// identifier and a literal ASC/DESC branch control ordering.
$jtc_like = '%' . $wpdb->esc_like( $jtc_search ) . '%';
$total    = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}jtc_supporters WHERE (%d = 0 OR petition_id = %d) AND (%s = '' OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)",
		$filter_petition,
		$filter_petition,
		$jtc_search,
		$jtc_like,
		$jtc_like,
		$jtc_like
	)
);
if ( 'ASC' === $jtc_order ) {
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}jtc_supporters WHERE (%d = 0 OR petition_id = %d) AND (%s = '' OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s) ORDER BY %i ASC LIMIT %d OFFSET %d",
			$filter_petition,
			$filter_petition,
			$jtc_search,
			$jtc_like,
			$jtc_like,
			$jtc_like,
			$order_by,
			$jtc_per_page,
			$offset
		),
		ARRAY_A
	);
} else {
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}jtc_supporters WHERE (%d = 0 OR petition_id = %d) AND (%s = '' OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s) ORDER BY %i DESC LIMIT %d OFFSET %d",
			$filter_petition,
			$filter_petition,
			$jtc_search,
			$jtc_like,
			$jtc_like,
			$jtc_like,
			$order_by,
			$jtc_per_page,
			$offset
		),
		ARRAY_A
	);
}

// ── All petitions for the filter dropdown ─────────────────────────────────
$all_petitions = get_posts(
	array(
		'post_type'      => JTC_CPT,
		'posts_per_page' => -1,
		'post_status'    => array( 'publish', 'draft' ),
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);

// ── Helpers ────────────────────────────────────────────────────────────────
$sort_url = function ( string $col ) use ( $order_by, $jtc_order, $jtc_search, $filter_petition ): string {
	$new_order = ( $col === $order_by && 'ASC' === $jtc_order ) ? 'DESC' : 'ASC';
	$args      = array(
		'page'    => 'jtc-supporters',
		'orderby' => $col,
		'order'   => $new_order,
	);

	if ( $jtc_search ) {
		$args['s'] = $jtc_search;
	}
	if ( $filter_petition ) {
		$args['petition_id'] = $filter_petition;
	}

	return add_query_arg( $args, admin_url( 'admin.php' ) );
};

$sort_class  = fn( string $col ): string => $col === $order_by ? 'sorted ' . strtolower( $jtc_order ) : 'sortable';
$total_pages = (int) ceil( $total / $jtc_per_page );
?>
<div class="wrap jtc-supporters-wrap">
	<h1><?php esc_html_e( 'Supporters', 'join-the-cause' ); ?></h1>

	<!-- Toolbar: filter + search + export -->
	<div class="tablenav top jtc-tablenav">
		<form method="get" action="" class="jtc-supporters-filter">
			<input type="hidden" name="page" value="jtc-supporters">

			<!-- Petition filter -->
			<select name="petition_id" id="jtc-petition-filter" aria-label="<?php esc_attr_e( 'Filter by petition', 'join-the-cause' ); ?>">
				<option value="0"><?php esc_html_e( '— All petitions —', 'join-the-cause' ); ?></option>
				<?php foreach ( $all_petitions as $p ) : ?>
				<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $filter_petition, $p->ID ); ?>>
					<?php echo esc_html( $p->post_title ); ?>
				</option>
				<?php endforeach; ?>
			</select>

			<!-- Search -->
			<label for="jtc-supporter-search" class="screen-reader-text"><?php esc_html_e( 'Search supporters', 'join-the-cause' ); ?></label>
			<input type="search" id="jtc-supporter-search" name="s"
				value="<?php echo esc_attr( $jtc_search ); ?>"
				placeholder="<?php esc_attr_e( 'Search name or email…', 'join-the-cause' ); ?>">

			<?php submit_button( __( 'Filter', 'join-the-cause' ), 'secondary', 'filter_action', false ); ?>

			<!-- Export -->
			<?php
			$export_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'                 => 'jtc-supporters',
						'jtc_supporter_action' => 'export',
						'petition_id'          => $filter_petition,
					),
					admin_url( 'admin.php' )
				),
				'jtc_export_supporters'
			);
			?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary" style="margin-left:8px;">
				<?php esc_html_e( 'Export CSV', 'join-the-cause' ); ?>
			</a>
		</form>
	</div>

	<!-- Pagination info -->
	<div class="tablenav-pages" style="margin-bottom:8px;">
		<?php
		printf(
			'<span class="displaying-num">%s</span>',
			sprintf(
				/* translators: %d number of records */
				esc_html( _n( '%d supporter', '%d supporters', $total, 'join-the-cause' ) ),
				esc_html( number_format_i18n( $total ) )
			)
		);
		?>
	</div>

	<!-- Table -->
	<table class="wp-list-table widefat fixed striped" aria-label="<?php esc_attr_e( 'Supporters', 'join-the-cause' ); ?>">
		<thead>
			<tr>
				<th scope="col" class="manage-column column-name <?php echo esc_attr( $sort_class( 'first_name' ) ); ?>">
					<a href="<?php echo esc_url( $sort_url( 'first_name' ) ); ?>">
						<?php esc_html_e( 'Name', 'join-the-cause' ); ?>
					</a>
				</th>
				<th scope="col" class="manage-column column-email <?php echo esc_attr( $sort_class( 'email' ) ); ?>">
					<a href="<?php echo esc_url( $sort_url( 'email' ) ); ?>">
						<?php esc_html_e( 'Email', 'join-the-cause' ); ?>
					</a>
				</th>
				<th scope="col" class="manage-column column-petition"><?php esc_html_e( 'Petition', 'join-the-cause' ); ?></th>
				<th scope="col" class="manage-column column-date <?php echo esc_attr( $sort_class( 'signed_at' ) ); ?>">
					<a href="<?php echo esc_url( $sort_url( 'signed_at' ) ); ?>">
						<?php esc_html_e( 'Signed', 'join-the-cause' ); ?>
					</a>
				</th>
				<th scope="col" class="manage-column column-display"><?php esc_html_e( 'Display consent', 'join-the-cause' ); ?></th>
				<th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'join-the-cause' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
			<tr>
				<td colspan="6"><?php esc_html_e( 'No supporters found.', 'join-the-cause' ); ?></td>
			</tr>
			<?php else : ?>
				<?php
				foreach ( $rows as $row ) :
					$petition_title = jtc_fallback( get_the_title( (int) $row['petition_id'] ), '—' );
					?>
			<tr>
				<td><?php echo esc_html( $row['first_name'] . ' ' . $row['last_name'] ); ?></td>
				<td><?php echo esc_html( $row['email'] ); ?></td>
				<td>
					<a href="<?php echo esc_url( get_edit_post_link( (int) $row['petition_id'] ) ); ?>">
						<?php echo esc_html( $petition_title ); ?>
					</a>
				</td>
				<td>
					<time datetime="<?php echo esc_attr( $row['signed_at'] ); ?>">
						<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['signed_at'] ) ) ); ?>
					</time>
				</td>
				<td>
					<?php if ( $row['display_consent'] ) : ?>
						<span aria-hidden="true">✓</span><span class="screen-reader-text"><?php esc_html_e( 'Yes', 'join-the-cause' ); ?></span>
					<?php else : ?>
						<span aria-hidden="true">—</span><span class="screen-reader-text"><?php esc_html_e( 'No', 'join-the-cause' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<form method="post" action="" style="display:inline;">
						<?php wp_nonce_field( 'jtc_delete_supporter_' . $row['id'] ); ?>
						<input type="hidden" name="jtc_supporter_action" value="delete">
						<input type="hidden" name="supporter_id" value="<?php echo esc_attr( $row['id'] ); ?>">
						<button type="submit" class="button-link jtc-delete-link"
							aria-label="
							<?php
								printf(
									/* translators: %s: supporter name */
									esc_attr__( 'Delete %s', 'join-the-cause' ),
									esc_attr( $row['first_name'] . ' ' . $row['last_name'] )
								);
							?>
							"
							onclick="return confirm('<?php echo esc_js( __( 'Delete this supporter? This cannot be undone.', 'join-the-cause' ) ); ?>')">
								<?php esc_html_e( 'Delete', 'join-the-cause' ); ?>
						</button>
					</form>
				</td>
			</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Bottom pagination -->
	<?php if ( $total_pages > 1 ) : ?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<?php
			$page_links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $current_page,
				)
			);
			echo wp_kses_post( $page_links );
			?>
		</div>
	</div>
	<?php endif; ?>
</div>
