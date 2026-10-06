<?php
/**
 * Settings page — tabbed shell + renderer.
 *
 * Tabs (in order): Help & Quick Start | Appearance | Petitions | Email |
 * Integrations | General. Each tab body lives in admin/views/settings/.
 * Saves are per-tab via the hidden jtc_tab field; the Help tab is read-only
 * (no form). The old "shortio" slug redirects to "integrations" for
 * back-compat with bookmarks.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Not allowed.', 'join-the-cause' ) );
}

$jtc_tabs = array(
	'help'         => __( 'Help & Quick Start', 'join-the-cause' ),
	'appearance'   => __( 'Appearance', 'join-the-cause' ),
	'defaults'     => __( 'Petitions', 'join-the-cause' ),
	'email'        => __( 'Email', 'join-the-cause' ),
	'integrations' => __( 'Integrations', 'join-the-cause' ),
	'general'      => __( 'General', 'join-the-cause' ),
);

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'help';

if ( 'shortio' === $active_tab ) {
	$active_tab = 'integrations'; // Legacy slug.
}

if ( ! isset( $jtc_tabs[ $active_tab ] ) ) {
	$active_tab = 'help';
}
?>
<div class="wrap jtc-settings-wrap">
	<h1><?php esc_html_e( 'Join the Cause — Settings', 'join-the-cause' ); ?></h1>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings tabs', 'join-the-cause' ); ?>">
		<?php foreach ( $jtc_tabs as $jtc_slug => $jtc_label ) : ?>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => 'join-the-cause',
						'tab'  => $jtc_slug,
					),
					admin_url( 'admin.php' )
				)
			);
			?>
						"
				class="nav-tab<?php echo $active_tab === $jtc_slug ? ' nav-tab-active' : ''; ?>"
				<?php echo $active_tab === $jtc_slug ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $jtc_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'help' === $active_tab ) : ?>

		<?php require JTC_PLUGIN_DIR . 'admin/views/settings/help.php'; ?>

	<?php else : ?>

		<form method="post" action="" class="jtc-settings-form">
			<?php wp_nonce_field( 'jtc_save_settings', 'jtc_settings_nonce' ); ?>
			<input type="hidden" name="jtc_settings_submit" value="1">
			<input type="hidden" name="jtc_tab" value="<?php echo esc_attr( $active_tab ); ?>">

			<?php require JTC_PLUGIN_DIR . 'admin/views/settings/' . $active_tab . '.php'; ?>

			<?php submit_button( __( 'Save Settings', 'join-the-cause' ) ); ?>
		</form>

	<?php endif; ?>
</div>
