<?php
/**
 * Supporters list page — all petition signers with filter, sort, export, delete.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Not allowed.', 'join-the-cause' ) );

global $wpdb;

// ── Filters ────────────────────────────────────────────────────────────────
$filter_petition = isset( $_GET['petition_id'] ) ? absint( $_GET['petition_id'] ) : 0;
$search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$order_by        = in_array( $_GET['orderby'] ?? '', [ 'signed_at', 'first_name', 'email' ], true )
                   ? sanitize_key( $_GET['orderby'] ) : 'signed_at';
$order           = 'ASC' === ( $_GET['order'] ?? '' ) ? 'ASC' : 'DESC';
$per_page        = 25;
$current_page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
$offset          = ( $current_page - 1 ) * $per_page;

// ── Query ─────────────────────────────────────────────────────────────────
$where_parts = [ '1=1' ];
$params      = [];

if ( $filter_petition ) {
	$where_parts[] = 'petition_id = %d';
	$params[]      = $filter_petition;
}

if ( $search ) {
	$where_parts[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)';
	$like          = '%' . $wpdb->esc_like( $search ) . '%';
	$params        = array_merge( $params, [ $like, $like, $like ] );
}

$where = implode( ' AND ', $where_parts );
$table = $wpdb->prefix . 'jtc_supporters';

$total_params = $params;
$total = (int) $wpdb->get_var(
	$params
		? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$total_params )
		: "SELECT COUNT(*) FROM {$table} WHERE {$where}"
);

$query_params = array_merge( $params, [ $per_page, $offset ] );
$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d";
$rows = $params
	? $wpdb->get_results( $wpdb->prepare( $sql, ...$query_params ), ARRAY_A )
	: $wpdb->get_results( $wpdb->prepare( $sql, $per_page, $offset ), ARRAY_A );

// ── All petitions for the filter dropdown ─────────────────────────────────
$all_petitions = get_posts( [
	'post_type'      => JTC_CPT,
	'posts_per_page' => -1,
	'post_status'    => [ 'publish', 'draft' ],
	'orderby'        => 'title',
	'order'          => 'ASC',
] );

// ── Helpers ────────────────────────────────────────────────────────────────
$sort_url = function( string $col ) use ( $order_by, $order ): string {
	$new_order = ( $col === $order_by && 'ASC' === $order ) ? 'DESC' : 'ASC';
	return add_query_arg( [ 'page' => 'jtc-supporters', 'orderby' => $col, 'order' => $new_order ], admin_url( 'admin.php' ) );
};

$sort_class = fn( string $col ): string => $col === $order_by ? 'sorted ' . strtolower( $order ) : 'sortable';
$total_pages = (int) ceil( $total / $per_page );
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
				value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search name or email…', 'join-the-cause' ); ?>">

			<?php submit_button( __( 'Filter', 'join-the-cause' ), 'secondary', 'filter_action', false ); ?>

			<!-- Export -->
			<?php
			$export_url = wp_nonce_url(
				add_query_arg( [
					'page'                  => 'jtc-supporters',
					'jtc_supporter_action'  => 'export',
					'petition_id'           => $filter_petition,
				], admin_url( 'admin.php' ) ),
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
			<?php foreach ( $rows as $row ) :
				$petition_title = get_the_title( (int) $row['petition_id'] ) ?: '—';
				$delete_url     = wp_nonce_url(
					add_query_arg( [
						'page'                 => 'jtc-supporters',
						'jtc_supporter_action' => 'delete',
						'supporter_id'         => $row['id'],
					], admin_url( 'admin.php' ) ),
					'jtc_delete_supporter_' . $row['id']
				);
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
				<td><?php echo $row['display_consent'] ? '✓' : '—'; ?></td>
				<td>
					<a href="<?php echo esc_url( $delete_url ); ?>"
					   class="jtc-delete-link"
					   aria-label="<?php printf( esc_attr__( 'Delete %s', 'join-the-cause' ), esc_attr( $row['first_name'] . ' ' . $row['last_name'] ) ); ?>"
					   onclick="return confirm('<?php echo esc_js( __( 'Delete this supporter? This cannot be undone.', 'join-the-cause' ) ); ?>')">
						<?php esc_html_e( 'Delete', 'join-the-cause' ); ?>
					</a>
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
			$page_links = paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $total_pages,
				'current'   => $current_page,
			] );
			echo wp_kses_post( $page_links );
			?>
		</div>
	</div>
	<?php endif; ?>
</div>
