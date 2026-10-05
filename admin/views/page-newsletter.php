<?php
/**
 * Newsletter page — compose new / edit draft, send test, view archive.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Not allowed.', 'join-the-cause' ) );

global $wpdb;
$table = $wpdb->prefix . 'jtc_newsletters';

// Are we editing a specific draft?
$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$editing = null;

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

// Recipient counts (for the "will send to" hint + JS confirm dialog).
$recipient_counts = [
	0 => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}jtc_supporters" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
];

foreach ( $all_petitions as $p ) {
	$recipient_counts[ $p->ID ] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}jtc_supporters WHERE petition_id = %d",
			$p->ID
		)
	);
}

$editing_petition = (int) ( $editing['petition_id'] ?? 0 );
$selected_count   = $recipient_counts[ $editing_petition ] ?? $recipient_counts[0];

// Archive list.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no user input; table names are trusted $wpdb->prefix values.
$archive = $wpdb->get_results(
	"SELECT nl.*, p.post_title AS petition_title
	 FROM {$table} nl
	 LEFT JOIN {$wpdb->posts} p ON p.ID = nl.petition_id
	 ORDER BY nl.created_at DESC LIMIT 50",
	ARRAY_A
);

$interrupted = false;
foreach ( $archive as $nl ) {
	if ( 'sending' === $nl['status'] ) {
		$interrupted = true;
		break;
	}
}
?>
<div class="wrap jtc-newsletter-wrap">
	<h1><?php esc_html_e( 'Newsletter', 'join-the-cause' ); ?></h1>

	<?php if ( $interrupted ) : ?>
	<div class="notice notice-warning inline">
		<p><?php esc_html_e( 'A previous newsletter send looks interrupted. Its progress is saved in the archive below; sending again starts a fresh pass.', 'join-the-cause' ); ?></p>
	</div>
	<?php endif; ?>

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
							<option value="0" data-recipients="<?php echo esc_attr( $recipient_counts[0] ); ?>"><?php esc_html_e( '— All petitions —', 'join-the-cause' ); ?></option>
							<?php foreach ( $all_petitions as $p ) : ?>
							<option value="<?php echo esc_attr( $p->ID ); ?>"
								data-recipients="<?php echo esc_attr( $recipient_counts[ $p->ID ] ); ?>"
								<?php selected( $editing_petition, $p->ID ); ?>>
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
				<button type="submit" name="jtc_newsletter_action" value="save_draft" class="button button-secondary">
					<?php esc_html_e( 'Save as Draft', 'join-the-cause' ); ?>
				</button>

				<button type="submit" name="jtc_newsletter_action" value="send_test" class="button button-secondary">
					<?php esc_html_e( 'Send Test to Me', 'join-the-cause' ); ?>
				</button>

				<button type="submit" name="jtc_newsletter_action" value="send"
					class="button button-primary jtc-send-btn">
					<?php esc_html_e( 'Send Now', 'join-the-cause' ); ?>
				</button>

				<span id="jtc-nl-recipients" class="description" role="status" data-count="<?php echo esc_attr( $selected_count ); ?>">
					<?php
					printf(
						/* translators: %s: number of recipients */
						esc_html( _n( 'Will send to %s recipient.', 'Will send to %s recipients.', $selected_count, 'join-the-cause' ) ),
						esc_html( number_format_i18n( $selected_count ) )
					);
					?>
				</span>
			</div>

			<p class="description">
				<?php esc_html_e( 'Sending runs synchronously and saves progress per batch, so an interruption never loses the sent count. For very large lists, prefer a dedicated mailing service.', 'join-the-cause' ); ?>
			</p>
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
			?>
			<tr>
				<td><strong><?php echo esc_html( $nl['subject'] ); ?></strong></td>
				<td><?php echo esc_html( $nl['petition_title'] ?: __( 'All petitions', 'join-the-cause' ) ); ?></td>
				<td>
					<span class="jtc-status jtc-status--<?php echo esc_attr( $nl['status'] ); ?>">
						<?php echo esc_html( ucfirst( $nl['status'] ) ); ?>
					</span>
				</td>
				<td><?php echo esc_html( number_format_i18n( (int) $nl['recipients_count'] ) ); ?></td>
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
