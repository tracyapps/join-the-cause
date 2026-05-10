<?php
/**
 * Plugin Name:       Join the Cause
 * Plugin URI:        https://github.com/
 * Description:       Petition and newsletter management for WordPress — create, manage, and share petitions with a change.org-style front end.
 * Version:           0.1.0
 * Author:
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       join-the-cause
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────

define( 'JTC_VERSION',          '0.1.0' );
define( 'JTC_PLUGIN_FILE',      __FILE__ );
define( 'JTC_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'JTC_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'JTC_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );
define( 'JTC_CPT',              'jtc_petition' );

// ─── Autoload includes ────────────────────────────────────────────────────────

$includes = [
	'includes/class-jtc-activator.php',
	'includes/class-jtc-post-types.php',
	'includes/class-jtc-mailer.php',
	'includes/class-jtc-form-handler.php',
	'includes/class-jtc-shortcode.php',
];

foreach ( $includes as $file ) {
	require_once JTC_PLUGIN_DIR . $file;
}

if ( is_admin() ) {
	require_once JTC_PLUGIN_DIR . 'admin/class-jtc-admin.php';
}

// ─── Activation / deactivation hooks ─────────────────────────────────────────

register_activation_hook( __FILE__, [ 'JTC_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'JTC_Activator', 'deactivate' ] );

// ─── Bootstrap on plugins_loaded ─────────────────────────────────────────────

add_action( 'plugins_loaded', 'jtc_init' );

function jtc_init(): void {
	// Register CPT + meta boxes.
	( new JTC_Post_Types() )->register();

	// Register [jtc_petition] shortcode.
	( new JTC_Shortcode() )->register();

	// Register AJAX form handlers.
	( new JTC_Form_Handler() )->register();

	// Admin menus, settings, supporter/newsletter pages.
	if ( is_admin() ) {
		( new JTC_Admin() )->register();
	}

	// Output plugin CSS variables into <head> for front-end theming.
	add_action( 'wp_head', 'jtc_output_css_vars' );
	add_action( 'admin_head', 'jtc_output_css_vars' );
}

/**
 * Emit CSS custom properties for the active color theme.
 * These cascade into both the public stylesheet and admin preview.
 */
function jtc_output_css_vars(): void {
	$mode = get_option( 'jtc_color_mode', 'preset' );

	if ( 'none' === $mode ) {
		return; // Let the active theme handle all styling.
	}

	$presets = jtc_get_preset_themes();

	if ( 'preset' === $mode ) {
		$theme   = get_option( 'jtc_preset_theme', 'wcfjip' );
		$colors  = $presets[ $theme ] ?? $presets['wcfjip'];
	} else {
		// Custom: user-selected via WP color picker.
		$colors = [
			'primary'        => sanitize_hex_color( get_option( 'jtc_custom_primary',   '#2d6a2d' ) ) ?: '#2d6a2d',
			'primary_dark'   => sanitize_hex_color( get_option( 'jtc_custom_secondary', '#1a3d1a' ) ) ?: '#1a3d1a',
			'primary_light'  => sanitize_hex_color( get_option( 'jtc_custom_accent',    '#f0faf0' ) ) ?: '#f0faf0',
			'hero_from'      => sanitize_hex_color( get_option( 'jtc_custom_hero_from', '#245e2b' ) ) ?: '#245e2b',
			'hero_to'        => sanitize_hex_color( get_option( 'jtc_custom_hero_to',   '#4f8d33' ) ) ?: '#4f8d33',
			'page_bg'        => sanitize_hex_color( get_option( 'jtc_custom_page_bg',   '#f6f8f4' ) ) ?: '#f6f8f4',
			'surface'        => sanitize_hex_color( get_option( 'jtc_custom_surface',   '#ffffff' ) ) ?: '#ffffff',
			'surface_alt'    => sanitize_hex_color( get_option( 'jtc_custom_surface_alt', '#f3f7f0' ) ) ?: '#f3f7f0',
			'border'         => '#d8e2d2',
		];
	}

	$css = jtc_theme_vars_block( ':root', $colors );

	if ( ! empty( $colors['dark'] ) && is_array( $colors['dark'] ) ) {
		$dark_css  = jtc_theme_vars_block( ':root', $colors['dark'] );
		$class_css = jtc_theme_vars_block(
			':root.dark, :root[data-theme="dark"], body.dark, body[data-theme="dark"], body.is-dark-theme',
			$colors['dark']
		);
		$css      .= "\n@media (prefers-color-scheme: dark) {\n{$dark_css}\n}\n{$class_css}";
	}

	echo "<style id=\"jtc-theme-vars\">\n{$css}\n</style>\n";
}

/**
 * Returns all built-in preset colour themes.
 *
 * @return array<string, array<string, mixed>>
 */
function jtc_get_preset_themes(): array {
	return [
		'wcfjip' => [
			'label'         => __( 'WCFJIP Gradient', 'join-the-cause' ),
			'primary'       => '#2d6a2d',
			'primary_dark'  => '#173f1d',
			'primary_light' => '#e9f6e5',
			'hero_from'     => '#1f5d2a',
			'hero_to'       => '#77a834',
			'page_bg'       => '#f5f8f2',
			'surface'       => '#ffffff',
			'surface_alt'   => '#eef6e9',
			'text'          => '#1c261b',
			'text_strong'   => '#111a10',
			'text_muted'    => '#5d6f58',
			'border'        => '#dbe7d2',
			'dark'          => [
				'primary'       => '#8bcf65',
				'primary_dark'  => '#b4e38d',
				'primary_light' => '#18341a',
				'hero_from'     => '#123418',
				'hero_to'       => '#3f681e',
				'page_bg'       => '#0f160f',
				'surface'       => '#172016',
				'surface_alt'   => '#1f2c1d',
				'text'          => '#edf5e9',
				'text_strong'   => '#ffffff',
				'text_muted'    => '#b7c8ae',
				'border'        => '#33452c',
				'input_bg'      => '#111a10',
			],
		],
		'change' => [
			'label'         => __( 'Civic Red', 'join-the-cause' ),
			'primary'       => '#e12729',
			'primary_dark'  => '#a41416',
			'primary_light' => '#fff0ef',
			'hero_from'     => '#b21620',
			'hero_to'       => '#f36a32',
			'page_bg'       => '#fff7f4',
			'surface'       => '#ffffff',
			'surface_alt'   => '#fff0ef',
			'text'          => '#241818',
			'text_strong'   => '#160d0d',
			'text_muted'    => '#725a58',
			'border'        => '#f1d4d0',
		],
		'blue' => [
			'label'         => __( 'Trust Blue', 'join-the-cause' ),
			'primary'       => '#1a5276',
			'primary_dark'  => '#0e2f44',
			'primary_light' => '#eaf2fb',
			'hero_from'     => '#103d63',
			'hero_to'       => '#2596be',
			'page_bg'       => '#f3f8fc',
			'surface'       => '#ffffff',
			'surface_alt'   => '#edf5fb',
			'text'          => '#172331',
			'text_strong'   => '#0f1720',
			'text_muted'    => '#536577',
			'border'        => '#d6e2ec',
		],
		'teal' => [
			'label'         => __( 'Organizing Teal', 'join-the-cause' ),
			'primary'       => '#0e6b6b',
			'primary_dark'  => '#074040',
			'primary_light' => '#e8f8f8',
			'hero_from'     => '#075254',
			'hero_to'       => '#2aa891',
			'page_bg'       => '#f1fbf9',
			'surface'       => '#ffffff',
			'surface_alt'   => '#e8f8f8',
			'text'          => '#132928',
			'text_strong'   => '#091b1a',
			'text_muted'    => '#55716e',
			'border'        => '#cfe5e2',
		],
		'purple' => [
			'label'         => __( 'Community Purple', 'join-the-cause' ),
			'primary'       => '#6b2d8b',
			'primary_dark'  => '#3d1454',
			'primary_light' => '#f5eefb',
			'hero_from'     => '#4c1d6e',
			'hero_to'       => '#a14cb4',
			'page_bg'       => '#faf6fc',
			'surface'       => '#ffffff',
			'surface_alt'   => '#f5eefb',
			'text'          => '#241a2c',
			'text_strong'   => '#160e1d',
			'text_muted'    => '#6b5b73',
			'border'        => '#e4d6eb',
		],
	];
}

/**
 * Convert a theme array into a CSS variable block.
 *
 * @param string $selector CSS selector.
 * @param array<string, mixed> $colors Theme colors.
 */
function jtc_theme_vars_block( string $selector, array $colors ): string {
	$map = [
		'primary'       => '--jtc-primary',
		'primary_dark'  => '--jtc-primary-dark',
		'primary_light' => '--jtc-primary-light',
		'hero_from'     => '--jtc-hero-from',
		'hero_to'       => '--jtc-hero-to',
		'page_bg'       => '--jtc-page-bg',
		'surface'       => '--jtc-surface',
		'surface_alt'   => '--jtc-surface-alt',
		'text'          => '--jtc-text',
		'text_strong'   => '--jtc-text-strong',
		'text_muted'    => '--jtc-text-muted',
		'border'        => '--jtc-border',
		'input_bg'      => '--jtc-input-bg',
		'button_text'   => '--jtc-button-text',
	];

	$lines = [ $selector . ' {' ];
	foreach ( $map as $key => $var ) {
		if ( empty( $colors[ $key ] ) || ! is_string( $colors[ $key ] ) ) {
			continue;
		}
		$value = sanitize_hex_color( $colors[ $key ] );
		if ( $value ) {
			$lines[] = '  ' . $var . ': ' . esc_attr( $value ) . ';';
		}
	}

	$rgb = jtc_hex_to_rgb_string( $colors['primary'] ?? '' );
	if ( $rgb ) {
		$lines[] = '  --jtc-primary-rgb: ' . esc_attr( $rgb ) . ';';
	}

	$lines[] = '}';
	return implode( "\n", $lines );
}

function jtc_hex_to_rgb_string( string $hex ): string {
	$hex = sanitize_hex_color( $hex );
	if ( ! $hex ) {
		return '';
	}

	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	return hexdec( substr( $hex, 0, 2 ) ) . ', ' .
		hexdec( substr( $hex, 2, 2 ) ) . ', ' .
		hexdec( substr( $hex, 4, 2 ) );
}
