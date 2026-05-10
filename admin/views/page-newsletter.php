<?php
/**
 * Newsletter page — compose new / edit draft, view archive.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Not allowed.', 'join-the-cause' ) );

global $wpdb;
$table = $wpdb->prefix . 'jtc_newsletters';

// Are we editing a specific draft?
$edit_id     = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$editing     = null;

if ( $edit_id ) {
	$editing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'draft'", $edit_id ), ARRAY_A );
}

// All petitions for dropdown.
$all_petitions = get_posts( [
	'post_type'      => JTC_CPT,
	'posts_per_page' => -1,
	'post_status'    => 'publish',
	'orderby'        => 'title',
	'order'          => 'ASC',
] );

// Archive list.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no user input; table names are trusted $wpdb->prefix values.
$archive = $wpdb->get_results(
	"SELECT nl.*, p.post_title AS petition_title
	 FROM {$table} nl
	 LEFT JOIN {$wpdb->posts} p ON p.ID = nl.petition_id
	 ORDER BY nl.created_at DESC LIMIT 50",
	ARRAY_A
);
?>
<div class="wrap jtc-newsletter-wrap">
	<h1><?php esc_html_e( 'Newsletter', 'join-the-cause' ); ?></h1>

	<!-- ── Compose form ──────────────────────────────────────────────────── -->
	<div class="jtc-nl-compose">
		<h2><?php $editing ? esc_html_e( 'Edit Draft', 'join-the-cause' ) : esc_html_e( 'Compose Newsletter', 'join-the-cause' ); ?></h2>

		<form method="post" action="" id="jtc-newsletter-form">
			<?php wp_nonce_field( 'jtc_newsletter_action', 'jtc_newsletter_nonce' ); ?>
			<?php if ( $editing ) : ?>
			<input type="hidden" name="jtc_nl_id" value="<?php echo esc_attr( $editing['id'] ); ?>">
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jtc-nl-petition"><?php esc_html_e( 'Send to signers of', 'join-the-cause' ); ?></label></th>
					<td>
						<select id="jtc-nl-petition" name="jtc_nl_petition_id" aria-required="true">
							<option value="0"><?php esc_html_e( '— All petitions —', 'join-the-cause' ); ?></option>
							<?php foreach ( $all_petitions as $p ) : ?>
							<option value="<?php echo esc_attr( $p->ID ); ?>"
								<?php selected( $editing['petition_id'] ?? 0, $p->ID ); ?>>
								<?php echo esc_html( $p->post_title ); ?>
							</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Selects the recipients. "All petitions" sends to everyone in your database.', 'join-the-cause' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jtc-nl-subject"><?php esc_html_e( 'Subject line', 'join-the-cause' ); ?></label></th>
					<td>
						<input type="text" id="jtc-nl-subject" name="jtc_nl_subject"
							value="<?php echo esc_attr( $editing['subject'] ?? '' ); ?>"
							class="large-text" required aria-required="true">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jtc-nl-content"><?php esc_html_e( 'Message', 'join-the-cause' ); ?></label></th>
					<td>
						<?php
						wp_editor(
							wp_kses_post( $editing['content'] ?? '' ),
							'jtc-nl-content',
							[
								'textarea_name' => 'jtc_nl_content',
								'textarea_rows' => 14,
								'media_buttons' => true,
								'tinymce'       => [
									'toolbar1' => 'formatselect bold italic underline | bullist numlist | link image | alignleft aligncenter alignright | undo redo',
								],
							]
						);
						?>
						<p class="description"><?php esc_html_e( 'Use {first_name} to personalise. Emails are sent as HTML.', 'join-the-cause' ); ?></p>
					</td>
				</tr>
			</table>

			<div class="jtc-nl-actions">
				<?php submit_button( __( 'Save as Draft', 'join-the-cause' ), 'secondary', 'jtc_newsletter_action', false, [ 'value' => 'save_draft' ] ); ?>

				<button type="submit" name="jtc_newsletter_action" value="send"
					class="button button-primary jtc-send-btn"
					onclick="return confirm('<?php echo esc_js( __( 'Send this newsletter now? This cannot be undone.', 'join-the-cause' ) ); ?>')">
					<?php esc_html_e( 'Send Now', 'join-the-cause' ); ?>
				</button>
			</div>
		</form>
	</div>

	<!-- ── Newsletter archive ───────────────────────────────────────────── -->
	<div class="jtc-nl-archive">
		<h2><?php esc_html_e( 'Newsletter Archive', 'join-the-cause' ); ?></h2>

		<?php if ( empty( $archive ) ) : ?>
		<p><?php esc_html_e( 'No newsletters yet.', 'join-the-cause' ); ?></p>
		<?php else : ?>
		<table class="wp-list-table widefat fixed striped" aria-label="<?php esc_attr_e( 'Newsletter archive', 'join-the-cause' ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Subject',     'join-the-cause' ); ?></th>
					<th><?php esc_html_e( 'Petition',    'join-the-cause' ); ?></th>
					<th><?php esc_html_e( 'Status',      'join-the-cause' ); ?></th>
					<th><?php esc_html_e( 'Recipients',  'join-the-cause' ); ?></th>
					<th><?php esc_html_e( 'Date',        'join-the-cause' ); ?></th>
					<th><?php esc_html_e( 'Actions',     'join-the-cause' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $archive as $nl ) :
				$is_sent   = 'sent' === $nl['status'];
				$date_col  = $is_sent ? $nl['sent_at'] : $nl['created_at'];
				$edit_link = add_query_arg( [ 'page' => 'jtc-newsletter', 'edit' => $nl['id'] ], admin_url( 'admin.php' ) );
				$del_link  = '';
				if ( ! $is_sent ) {
					$del_link = add_query_arg( [
						'page'                  => 'jtc-newsletter',
						'jtc_newsletter_action' => 'delete',
						'jtc_nl_id'             => $nl['id'],
					], admin_url( 'admin.php' ) );
				}
			?>
			<tr>
				<td><strong><?php echo esc_html( $nl['subject'] ); ?></strong></td>
				<td><?php echo esc_html( $nl['petition_title'] ?: __( 'All petitions', 'join-the-cause' ) ); ?></td>
				<td>
					<span class="jtc-status jtc-status--<?php echo esc_attr( $nl['status'] ); ?>">
						<?php echo esc_html( ucfirst( $nl['status'] ) ); ?>
					</span>
				</td>
				<td><?php echo $is_sent ? esc_html( number_format_i18n( (int) $nl['recipients_count'] ) ) : '—'; ?></td>
				<td>
					<?php if ( $date_col ) : ?>
					<time datetime="<?php echo esc_attr( $date_col ); ?>">
						<?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $date_col ) ) ); ?>
					</time>
					<?php else : ?>—<?php endif; ?>
				</td>
				<td>
					<?php if ( ! $is_sent ) : ?>
					<a href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Edit', 'join-the-cause' ); ?></a>
					<?php if ( $del_link ) : ?>
					 |
					<form method="post" action="" style="display:inline;">
						<?php wp_nonce_field( 'jtc_newsletter_action', 'jtc_newsletter_nonce' ); ?>
						<input type="hidden" name="jtc_nl_id" value="<?php echo esc_attr( $nl['id'] ); ?>">
						<button type="submit" name="jtc_newsletter_action" value="delete"
							class="button-link jtc-delete-link"
							onclick="return confirm('<?php echo esc_js( __( 'Delete this draft?', 'join-the-cause' ) ); ?>')">
							<?php esc_html_e( 'Delete', 'join-the-cause' ); ?>
						</button>
					</form>
					<?php endif; ?>
					<?php else : ?>
					<span class="description"><?php esc_html_e( 'Sent', 'join-the-cause' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
</div>
