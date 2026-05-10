<?php
/**
 * Single petition page template.
 *
 * Used when someone visits /petition/petition-slug/ directly.
 * The active theme can override this by providing its own:
 *   - single-jtc_petition.php
 *   - single-petition.php
 * in its root directory.
 *
 * show_title is forced to 1 here because unlike the [jtc_petition] shortcode
 * (which is embedded inside a page that already has its own title), this IS
 * the page, so the petition title needs to be rendered as the visible <h1>.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div id="jtc-page-wrap" class="jtc-page-wrap">
	<?php
	if ( have_posts() ) :
		the_post();

		// Render the full petition layout.
		// show_title=1 so the petition's <h1> appears — this IS the page.
		echo do_shortcode( '[jtc_petition id="' . get_the_ID() . '" show_title="1"]' );

		// Native WordPress comments (if enabled on this petition).
		if ( comments_open() || get_comments_number() ) :
			comments_template();
		endif;

	else :
		?>
		<p class="jtc-not-found">
			<?php esc_html_e( 'Petition not found.', 'join-the-cause' ); ?>
		</p>
		<?php
	endif;
	?>
</div>

<?php
get_footer();
