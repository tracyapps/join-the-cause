<?php
/**
 * Plugin Name:       Join the Cause
 * GitHub Plugin URI: https://github.com/tracyapps/join-the-cause
 * Description:       Petition and newsletter management for WordPress — create, manage, and share petitions with a change.org-style front end.
 * Version:           0.2.0
 * Author:            Tracy Apps
 * Author URI:        https://github.com/tracyapps
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       join-the-cause
 * Domain Path:       /languages
 * Requires at least: 6.3
 * Requires PHP:      8.0
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────

define( 'JTC_VERSION', '0.2.0' );
define( 'JTC_STYLE_VERSION', '1' );
define( 'JTC_DB_VERSION', '6' );
define( 'JTC_PLUGIN_FILE', __FILE__ );
define( 'JTC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JTC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JTC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'JTC_CPT', 'jtc_petition' );

// ─── Autoload includes ────────────────────────────────────────────────────────

$includes = array(
	'includes/class-jtc-activator.php',
	'includes/class-jtc-post-types.php',
	'includes/class-jtc-short-io.php',
	'includes/petition-links.php',
	'includes/class-jtc-mailer.php',
	'includes/class-jtc-newsletter.php',
	'includes/class-jtc-privacy.php',
	'includes/class-jtc-form-handler.php',
	'includes/class-jtc-shortcode.php',
	'includes/class-jtc-block.php',
);

foreach ( $includes as $file ) {
	require_once JTC_PLUGIN_DIR . $file;
}

if ( is_admin() ) {
	require_once JTC_PLUGIN_DIR . 'admin/class-jtc-admin.php';
}

// ─── Activation / deactivation hooks ─────────────────────────────────────────

register_activation_hook( __FILE__, array( 'JTC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JTC_Activator', 'deactivate' ) );

// ─── Bootstrap on plugins_loaded ─────────────────────────────────────────────

add_action( 'plugins_loaded', 'jtc_init' );
/**
 * Scalar.
 *
 * @param mixed  $value Input value.
 * @param string $fallback Default.
 * @return string Result value.
 */
function jtc_scalar( $value, string $fallback = '' ): string {
	return is_scalar( $value ) ? (string) $value : $fallback;
}

/**
 * Read a scalar POST value, unslash once and sanitize for its storage context.
 *
 * @param string $key    POST field name.
 * @param string $format Text, textarea, html or secret.
 * @return string Sanitized scalar value.
 */
function jtc_post_input( string $key, string $format = 'text' ): string {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only reads input; each mutation entrypoint verifies its nonce and capability.
	$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the explicit context branches below.
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( 'html' === $format ) {
		return wp_kses_post( $value );
	}
	if ( 'textarea' === $format ) {
		return sanitize_textarea_field( $value );
	}
	if ( 'secret' === $format ) {
		// Do not strip meaningful password punctuation; secrets are never echoed.
		return preg_replace( '/[\x00-\x1f\x7f]/', '', $value );
	}
	return sanitize_text_field( $value );
}

/**
 * Preserve a nonempty value or return its explicit fallback.
 *
 * @param mixed $value    Preferred value.
 * @param mixed $fallback Empty-value fallback.
 * @return mixed Selected value.
 */
function jtc_fallback( $value, $fallback ) {
	return $value ? $value : $fallback;
}

/**
 * Get a secret from wp-config.php before consulting its non-autoloaded option.
 *
 * @param string $option Option.
 * @return string Result value.
 */
function jtc_get_secret( string $option ): string {
	$constant = strtoupper( $option );
	return defined( $constant ) ? jtc_scalar( constant( $constant ) ) : jtc_scalar( get_option( $option, '' ) );
}

/**
 * Unicode initial without requiring the optional mbstring extension.
 *
 * @param string $name Name.
 * @return string Result value.
 */
function jtc_name_initial( string $name ): string {
	return preg_match( '/^./us', $name, $matches ) ? $matches[0] : '';
}

/**
 * Neutralize spreadsheet formula prefixes, including hidden whitespace.
 *
 * @param mixed $value Input value.
 * @return string Result value.
 */
function jtc_csv_cell( $value ): string {
	$value = jtc_scalar( $value );
	return preg_match( '/^[\s\x00-\x1f]*[=+\-@]|^[\t\r\n]/u', $value ) ? "'" . $value : $value;
}
/**
 * Register plugin components and deferred initialization.
 */
function jtc_init(): void {
	add_image_size( 'jtc_social_card', 1200, 630, true );

	// Translation-dependent migrations wait for init (required by WP 6.7+).
	add_action(
		'init',
		static function (): void {
			load_plugin_textdomain( 'join-the-cause', false, dirname( JTC_PLUGIN_BASENAME ) . '/languages' );
			JTC_Activator::maybe_migrate();
			JTC_Activator::maybe_upgrade_schema();
		},
		1
	);

	// Register CPT + meta boxes.
	( new JTC_Post_Types() )->register();

	// Register [jtc_petition] shortcode.
	( new JTC_Shortcode() )->register();

	// Register the jtc/petition Gutenberg block (delegates to the shortcode renderer).
	( new JTC_Block() )->register();

	// Register AJAX form handlers.
	( new JTC_Form_Handler() )->register();
	( new JTC_Newsletter() )->register();
	( new JTC_Privacy() )->register();

	// Admin menus, settings, supporter/newsletter pages.
	if ( is_admin() ) {
		( new JTC_Admin() )->register();
	}

	// Output plugin CSS variables into <head> for front-end theming.
	add_action( 'wp_head', 'jtc_output_css_vars' );
	add_action( 'wp_head', 'jtc_output_social_meta', 5 );
	add_action( 'admin_head', 'jtc_output_css_vars' );
}

/**
 * Emit CSS custom properties for the active color + style theme.
 * Runs on wp_head (front end) and admin_head (plugin screens only).
 */
function jtc_output_css_vars(): void {
	if ( is_admin() ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! jtc_is_jtc_admin_screen( $screen ) ) {
			return;
		}
	} elseif ( ! jtc_should_output_css_vars() ) {
		return; // Lean: only pages that can render a petition get the vars.
	}

	$css = jtc_build_css_vars();

	if ( '' === $css ) {
		return; // Color mode "none": let the active theme handle all styling.
	}

	echo "<style id=\"jtc-theme-vars\">\n{$css}\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values are validated and CSS-context sanitized by jtc_build_css_vars().
	$GLOBALS['jtc_css_vars_emitted'] = true;
}

/**
 * Whether the current front-end request might render a petition.
 * Themes that render petitions from widgets/page builders can force output
 * through the jtc_should_output_css_vars filter.
 *
 * @return bool Result value.
 */
function jtc_should_output_css_vars(): bool {
	if ( is_singular( JTC_CPT ) ) {
		return true;
	}

	if ( is_singular() ) {
		$post = get_post();

		if ( $post ) {
			if ( false !== strpos( (string) $post->post_content, '[jtc_' ) ) {
				return true;
			}
			if ( function_exists( 'has_block' ) && has_block( 'jtc/petition', $post ) ) {
				return true;
			}
		}
	}

	/**
	 * Filters whether the plugin CSS variables should be printed on this request.
	 *
	 * @param bool $output Whether to print the vars. Default false unless a petition render is detected.
	 */
	return (bool) apply_filters( 'jtc_should_output_css_vars', false );
}

/**
 * Builds the full CSS variable style (theme colors + style options).
 * Returns an empty string when styling is disabled ("none" mode).
 *
 * @return string Result value.
 */
function jtc_build_css_vars(): string {
	$mode = get_option( 'jtc_color_mode', 'preset' );

	if ( 'none' === $mode ) {
		return '';
	}

	$presets = jtc_get_preset_themes();
	$style   = jtc_get_style_options();

	if ( 'preset' === $mode ) {
		$theme  = get_option( 'jtc_preset_theme', 'evergreen' );
		$colors = $presets[ $theme ] ?? $presets['evergreen'];
	} else {
		$colors = jtc_custom_theme_colors();
	}

	$css  = jtc_theme_vars_block( ':root', $colors );
	$css .= "\n" . jtc_style_vars_block( $style );

	if ( ! empty( $colors['dark'] ) && is_array( $colors['dark'] ) ) {
		$dark_css  = jtc_theme_vars_block( ':root', $colors['dark'] );
		$class_css = jtc_theme_vars_block(
			':root.dark, :root[data-theme="dark"], body.dark, body[data-theme="dark"], body.is-dark-theme',
			$colors['dark']
		);
		$css      .= "\n@media (prefers-color-scheme: dark) {\n{$dark_css}\n}\n{$class_css}";
	}

	return $css;
}

/**
 * Attaches the vars inline to the jtc-public handle when they could not be.
 * printed in the head (petitions rendered late, e.g. by blocks or builders).
 * Prefer this over a duplicate wp_head tag: it only fires when needed.
 */
function jtc_maybe_add_inline_css_vars(): void {
	static $added = false;

	if ( $added || ! empty( $GLOBALS['jtc_css_vars_emitted'] ) ) {
		return;
	}

	// Only attach to a registered handle — in REST / block-render contexts
	// the public stylesheet may not be registered yet; the wp_head path
	// (or the calling renderer) covers those instead.
	if ( ! function_exists( 'wp_style_is' ) || ! wp_style_is( 'jtc-public', 'registered' ) ) {
		return;
	}

	$css = jtc_build_css_vars();

	if ( '' === $css ) {
		return;
	}

	wp_add_inline_style( 'jtc-public', $css );
	$added = true;
}

/**
 * Identifies the plugin's own admin screens (settings, supporters,.
 * newsletter, petition CPT screens).
 *
 * @param mixed $screen WordPress admin screen.
 * @return bool Result value.
 */
function jtc_is_jtc_admin_screen( $screen ): bool {
	if ( ! $screen || ! is_object( $screen ) ) {
		return false;
	}

	$pages = array(
		'toplevel_page_join-the-cause',
		'join-the-cause_page_jtc-supporters',
		'join-the-cause_page_jtc-newsletter',
	);

	if ( in_array( $screen->id, $pages, true ) ) {
		return true;
	}

	return isset( $screen->post_type ) && JTC_CPT === $screen->post_type;
}

/**
 * Custom mode: user-picked colors plus derived neutrals so switching modes.
 * never leaves text/border/input values "inherit"-inconsistent.
 *
 * @return array Result value.
 */
function jtc_custom_theme_colors(): array {
	$surface  = jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_surface', '#ffffff' ) ), '#ffffff' );
	$primary  = jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_primary', '#2d6a2d' ) ), '#2d6a2d' );
	$neutrals = jtc_derive_neutral_colors( $surface );

	return array(
		'primary'           => $primary,
		'primary_dark'      => jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_secondary', '#1a3d1a' ) ), '#1a3d1a' ),
		'primary_light'     => jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_accent', '#f0faf0' ) ), '#f0faf0' ),
		'hero_from'         => jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_hero_from', '#245e2b' ) ), '#245e2b' ),
		'hero_to'           => jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_hero_to', '#4f8d33' ) ), '#4f8d33' ),
		'page_bg'           => jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_page_bg', '#f6f8f4' ) ), '#f6f8f4' ),
		'surface'           => $surface,
		'surface_alt'       => jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_surface_alt', '#f3f7f0' ) ), '#f3f7f0' ),
		'border'            => jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_border', '#d8e2d2' ) ), '#d8e2d2' ),
		'text'              => $neutrals['text'],
		'text_strong'       => $neutrals['text_strong'],
		'text_muted'        => $neutrals['text_muted'],
		'input_bg'          => $neutrals['input_bg'],
		'button_text'       => jtc_contrast_text_color( $primary ),
		'button_hover_text' => jtc_contrast_text_color( jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_secondary', '#1a3d1a' ) ), '#1a3d1a' ) ),
		'hero_text'         => jtc_contrast_text_color( jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_hero_from', '#245e2b' ) ), '#245e2b' ) ),
		'hero_copy_bg'      => jtc_fallback( sanitize_hex_color( get_option( 'jtc_custom_hero_from', '#245e2b' ) ), '#245e2b' ),
	);
}

/**
 * Derives readable text + input colors for a given surface background.
 *
 * @return array{text:string,text_strong:string,text_muted:string,input_bg:string}
 * @param string $surface Surface hex color.
 */
function jtc_derive_neutral_colors( string $surface ): array {
	$text = jtc_contrast_text_color( $surface );
	return array(
		'text'        => $text,
		'text_strong' => $text,
		'text_muted'  => $text,
		'input_bg'    => $surface,
	);
}

/**
 * Select the higher-contrast foreground by the WCAG luminance formula.
 *
 * @param string $background Background hex color.
 * @return string Result value.
 */
function jtc_contrast_text_color( string $background ): string {
	$luminance = jtc_hex_luminance( $background );
	return ( $luminance + 0.05 ) / 0.05 >= 1.05 / ( $luminance + 0.05 ) ? '#000000' : '#ffffff';
}

/**
 * WCAG relative luminance of a hex color (0..1).
 *
 * @param string $foreground Foreground hex color.
 * @param string $background Background hex color.
 * @return float Result value.
 */
function jtc_contrast_ratio( string $foreground, string $background ): float {
	$first  = jtc_hex_luminance( $foreground );
	$second = jtc_hex_luminance( $background );
	return ( max( $first, $second ) + 0.05 ) / ( min( $first, $second ) + 0.05 );
}
/**
 * Hex luminance.
 *
 * @param string $hex Hex.
 * @return float Result value.
 */
function jtc_hex_luminance( string $hex ): float {
	$hex = sanitize_hex_color( $hex );

	if ( ! $hex ) {
		return 0.5;
	}

	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	$weights   = array( 0.2126, 0.7152, 0.0722 );
	$luminance = 0.0;

	for ( $i = 0; $i < 3; $i++ ) {
		$channel    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
		$channel    = $channel <= 0.03928 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
		$luminance += $weights[ $i ] * $channel;
	}

	return $luminance;
}

/**
 * Strips characters that could break out of a CSS declaration context.
 *
 * @param string $value Input value.
 * @return string Result value.
 */
function jtc_safe_css_value( string $value ): string {
	return trim( str_replace( array( '<', '>', '{', '}', ';' ), '', $value ) );
}

/**
 * Defaults for the Layout & Style option (single serialized jtc_style array).
 *
 * @return array<string, mixed>
 */
function jtc_style_defaults(): array {
	return array(
		'version'             => JTC_STYLE_VERSION,
		'radius'              => 'default',
		'shadow'              => 'default',
		'button_style'        => 'solid',
		'font_source'         => 'inherit',
		'font_custom'         => '',
		'font_scale'          => 100,
		'panel_width'         => 380,
		'content_max'         => 'none',
		'hero_style'          => 'gradient',
		'hero_text'           => '',
		'show_share'          => 1,
		'show_qr'             => 1,
		'show_dek'            => 1,
		'show_featured_image' => 1,
	);
}

/**
 * Returns the merged style option (saved values + defaults).
 *
 * @return array Result value.
 */
function jtc_get_style_options(): array {
	static $options = null;

	if ( null === $options ) {
		$saved   = get_option( 'jtc_style', array() );
		$options = wp_parse_args( is_array( $saved ) ? $saved : array(), jtc_style_defaults() );
	}

	return $options;
}

/**
 * Single style option accessor with fallback to defaults.
 *
 * @param string $key Setting or field name.
 */
function jtc_get_style_option( string $key ) {
	$options = jtc_get_style_options();

	return $options[ $key ] ?? ( jtc_style_defaults()[ $key ] ?? null );
}

/**
 * Allowed values/schemas for style options. Each entry resolves a stored.
 * token into validated CSS values at output time.
 *
 * @return array<string, mixed>
 */
function jtc_style_maps(): array {
	return array(
		'radius'       => array(
			'compact' => array( '4px', '6px' ),
			'default' => array( '8px', '12px' ),
			'round'   => array( '14px', '18px' ),
			'pill'    => array( '999px', '999px' ),
		),
		'shadow'       => array(
			'none'    => array( 'none', 'none', 'none' ),
			'subtle'  => array(
				'0 1px 2px rgba(15, 23, 42, 0.06)',
				'0 6px 20px rgba(15, 23, 42, 0.09)',
				'0 14px 36px rgba(15, 23, 42, 0.13)',
			),
			'default' => array(
				'0 1px 3px rgba(15, 23, 42, 0.08)',
				'0 10px 30px rgba(15, 23, 42, 0.12)',
				'0 22px 55px rgba(15, 23, 42, 0.18)',
			),
			'strong'  => array(
				'0 2px 6px rgba(15, 23, 42, 0.16)',
				'0 16px 42px rgba(15, 23, 42, 0.22)',
				'0 32px 72px rgba(15, 23, 42, 0.30)',
			),
		),
		'button_style' => array( 'solid', 'outline' ),
		'font_source'  => array(
			'inherit' => 'inherit',
			'system'  => '-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif',
		),
		'panel_width'  => array( 340, 380, 420 ),
		'content_max'  => array( 'none', '720px', '960px', '1140px', '1320px' ),
		'hero_style'   => array( 'gradient', 'solid', 'minimal' ),
	);
}

/**
 * Sanitizes raw POST data for the jtc_style option (typed, whitelisted).
 *
 * @param array<string, mixed> $raw Raw values to validate.
 * @return array<string, mixed>
 */
function jtc_sanitize_style_options( array $raw ): array {
	$maps     = jtc_style_maps();
	$defaults = jtc_style_defaults();
	$clean    = array();

	$enum = static function ( string $key, array $allowed, string $fallback ) use ( $raw ): string {
		$value = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	};

	$clean['radius']       = $enum( 'radius', array_keys( $maps['radius'] ), $defaults['radius'] );
	$clean['shadow']       = $enum( 'shadow', array_keys( $maps['shadow'] ), $defaults['shadow'] );
	$clean['button_style'] = $enum( 'button_style', $maps['button_style'], $defaults['button_style'] );

	$clean['font_source'] = $enum( 'font_source', array( 'inherit', 'system', 'custom' ), $defaults['font_source'] );
	$font_custom          = trim( sanitize_text_field( wp_unslash( jtc_scalar( $raw['font_custom'] ?? '' ) ) ) );
	$clean['font_custom'] = trim( jtc_safe_css_value( $font_custom ), ", \t\n\r\0\x0B'\"" );

	$clean['font_scale'] = max( 90, min( 110, absint( $raw['font_scale'] ?? $defaults['font_scale'] ) ) );

	$panel                = absint( $raw['panel_width'] ?? $defaults['panel_width'] );
	$clean['panel_width'] = in_array( $panel, $maps['panel_width'], true ) ? $panel : $defaults['panel_width'];

	$clean['content_max'] = $enum( 'content_max', $maps['content_max'], $defaults['content_max'] );
	$clean['hero_style']  = $enum( 'hero_style', $maps['hero_style'], $defaults['hero_style'] );
	$clean['hero_text']   = jtc_fallback( sanitize_hex_color( wp_unslash( jtc_scalar( $raw['hero_text'] ?? '' ) ) ), '' );

	$clean['show_share']          = ! empty( $raw['show_share'] ) ? 1 : 0;
	$clean['show_qr']             = ! empty( $raw['show_qr'] ) ? 1 : 0;
	$clean['show_dek']            = ! empty( $raw['show_dek'] ) ? 1 : 0;
	$clean['show_featured_image'] = ! empty( $raw['show_featured_image'] ) ? 1 : 0;

	$clean['version'] = JTC_STYLE_VERSION;

	return $clean;
}

/**
 * Emits the Layout & Style CSS variables. Values resolve through the.
 * jtc_style_maps() schema, so only whitelisted tokens reach the stylesheet.
 *
 * @param array $style Validated layout and style settings.
 * @return string Result value.
 */
function jtc_style_vars_block( array $style ): string {
	$maps  = jtc_style_maps();
	$lines = array();

	$radius  = $maps['radius'][ $style['radius'] ?? '' ] ?? $maps['radius']['default'];
	$lines[] = '  --jtc-radius: ' . esc_attr( $radius[0] ) . ';';
	$lines[] = '  --jtc-radius-lg: ' . esc_attr( $radius[1] ) . ';';

	$shadow  = $maps['shadow'][ $style['shadow'] ?? '' ] ?? $maps['shadow']['default'];
	$lines[] = '  --jtc-shadow-sm: ' . esc_attr( $shadow[0] ) . ';';
	$lines[] = '  --jtc-shadow-md: ' . esc_attr( $shadow[1] ) . ';';
	$lines[] = '  --jtc-shadow-lg: ' . esc_attr( $shadow[2] ) . ';';

	if ( 'outline' === ( $style['button_style'] ?? 'solid' ) ) {
		$lines[] = '  --jtc-button-bg: transparent;';
		$lines[] = '  --jtc-button-fg: var(--jtc-link);';
		$lines[] = '  --jtc-button-border: var(--jtc-primary);';
		$lines[] = '  --jtc-button-hover-bg: var(--jtc-primary);';
		$lines[] = '  --jtc-button-hover-fg: var(--jtc-button-text, #ffffff);';
	} else {
		$lines[] = '  --jtc-button-bg: var(--jtc-primary);';
		$lines[] = '  --jtc-button-fg: var(--jtc-button-text, #ffffff);';
		$lines[] = '  --jtc-button-border: transparent;';
		$lines[] = '  --jtc-button-hover-bg: var(--jtc-primary-dark);';
		$lines[] = '  --jtc-button-hover-fg: var(--jtc-button-hover-text, #ffffff);';
	}

	$font_source = $style['font_source'] ?? 'inherit';
	$font        = $maps['font_source'][ $font_source ] ?? 'inherit';
	if ( 'custom' === $font_source && ! empty( $style['font_custom'] ) ) {
		$font = jtc_safe_css_value( (string) $style['font_custom'] );
	}
	// Deliberately unescaped: quotes are valid in CSS font stacks and HTML
	// entities would not decode inside <style>. jtc_safe_css_value() strips
	// the characters that matter (< > { } ;).
	$lines[] = '  --jtc-font-base: ' . $font . ';';

	$scale   = round( max( 90, min( 110, (int) ( $style['font_scale'] ?? 100 ) ) ) / 100, 2 );
	$lines[] = '  --jtc-font-scale: ' . esc_attr( ( $scale < 1 ? rtrim( sprintf( '%.2f', $scale ), '0' ) : sprintf( '%.2f', $scale ) ) ) . ';';

	$panel_width = in_array( (int) ( $style['panel_width'] ?? 380 ), $maps['panel_width'], true ) ? (int) $style['panel_width'] : 380;
	$lines[]     = '  --jtc-panel-width: ' . esc_attr( $panel_width . 'px' ) . ';';

	$content_max = in_array( $style['content_max'] ?? 'none', $maps['content_max'], true ) ? $style['content_max'] : 'none';
	$lines[]     = '  --jtc-content-max: ' . esc_attr( $content_max ) . ';';

	return ":root {\n" . implode( "\n", $lines ) . "\n}";
}
/**
 * Output social meta.
 */
function jtc_output_social_meta(): void {
	$petition_id = jtc_get_current_social_petition_id();
	if ( ! $petition_id ) {
		return;
	}

	$petition = get_post( $petition_id );
	if ( ! $petition || JTC_CPT !== $petition->post_type || 'publish' !== $petition->post_status || post_password_required( $petition ) ) {
		return;
	}

	$title       = get_the_title( $petition_id );
	$description = has_excerpt( $petition )
		? get_the_excerpt( $petition )
		: wp_trim_words( wp_strip_all_tags( $petition->post_content ), 32, '' );
	// No remote refresh in wp_head: social meta must never trigger a
	// blocking Short.io request. Stored values are used as-is.
	$url       = jtc_get_petition_share_url( $petition_id, false );
	$image     = jtc_get_petition_social_image( $petition_id );
	$site_name = get_bloginfo( 'name' );

	$meta = array(
		array( 'property', 'og:type', 'article' ),
		array( 'property', 'og:site_name', $site_name ),
		array( 'property', 'og:title', $title ),
		array( 'property', 'og:description', $description ),
		array( 'property', 'og:url', $url ),
		array( 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' ),
		array( 'name', 'twitter:title', $title ),
		array( 'name', 'twitter:description', $description ),
	);

	if ( $image ) {
		$meta[] = array( 'property', 'og:image', $image['url'] );
		$meta[] = array( 'property', 'og:image:secure_url', $image['url'] );
		$meta[] = array( 'property', 'og:image:alt', $title );
		$meta[] = array( 'name', 'twitter:image', $image['url'] );

		if ( ! empty( $image['width'] ) && ! empty( $image['height'] ) ) {
			$meta[] = array( 'property', 'og:image:width', (string) $image['width'] );
			$meta[] = array( 'property', 'og:image:height', (string) $image['height'] );
		}
	}

	echo "\n<!-- Join the Cause social sharing -->\n";
	foreach ( $meta as $item ) {
		$attr = 'property' === $item[0] ? 'property' : 'name';
		printf(
			'<meta %1$s="%2$s" content="%3$s">' . "\n",
			esc_attr( $attr ),
			esc_attr( $item[1] ),
			esc_attr( wp_strip_all_tags( (string) $item[2] ) )
		);
	}
}
/**
 * Get current social petition id.
 *
 * @return int Result value.
 */
function jtc_get_current_social_petition_id(): int {
	if ( is_singular( JTC_CPT ) ) {
		return (int) get_queried_object_id();
	}

	if ( ! is_singular() ) {
		return 0;
	}

	$post = get_post();
	if ( ! $post || post_password_required( $post ) ) {
		return 0;
	}

	$find_block = static function ( array $blocks ) use ( &$find_block ): int {
		foreach ( $blocks as $block ) {
			if ( 'jtc/petition' === ( $block['blockName'] ?? '' ) ) {
				return absint( $block['attrs']['petitionId'] ?? 0 );
			}
			$id = $find_block( $block['innerBlocks'] ?? array() );
			if ( $id ) {
				return $id;
			}
		}
		return 0;
	};
	$block_id   = $find_block( parse_blocks( $post->post_content ) );
	if ( $block_id ) {
		return $block_id;
	}

	if ( preg_match( '/\[jtc_petition[^\]]*id=[\'"]?(\d+)/', $post->post_content, $matches ) ) {
		return absint( $matches[1] );
	}

	return 0;
}
/**
 * Get petition social image.
 *
 * @param int $petition_id Petition post ID.
 * @return array Result value.
 */
function jtc_get_petition_social_image( int $petition_id ): array {
	$attachment_id = get_post_thumbnail_id( $petition_id );

	if ( ! $attachment_id ) {
		$attachment_id = absint( get_theme_mod( 'custom_logo' ) );
	}

	if ( $attachment_id ) {
		if ( ! get_post_thumbnail_id( $petition_id ) ) {
			$generated = jtc_get_generated_logo_social_card( $attachment_id );
			if ( $generated ) {
				return $generated;
			}
		}

		$image = wp_get_attachment_image_src( $attachment_id, 'jtc_social_card' );
		if ( $image ) {
			return array(
				'url'    => $image[0],
				'width'  => (int) $image[1],
				'height' => (int) $image[2],
			);
		}
	}

	$site_icon = get_site_icon_url( 512 );
	if ( $site_icon ) {
		return array(
			'url'    => $site_icon,
			'width'  => 512,
			'height' => 512,
		);
	}

	return array();
}
/**
 * Get generated logo social card.
 *
 * @param int $attachment_id Media attachment ID.
 * @return array Result value.
 */
function jtc_get_generated_logo_social_card( int $attachment_id ): array {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return array();
	}

	$source_file = get_attached_file( $attachment_id );
	if ( ! $source_file || ! file_exists( $source_file ) ) {
		return array();
	}

	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		return array();
	}

	$dir = trailingslashit( $upload_dir['basedir'] ) . 'join-the-cause';
	if ( ! wp_mkdir_p( $dir ) ) {
		return array();
	}

	$target_file = trailingslashit( $dir ) . 'social-card-logo-' . $attachment_id . '.png';
	$target_url  = trailingslashit( $upload_dir['baseurl'] ) . 'join-the-cause/social-card-logo-' . $attachment_id . '.png';

	if ( file_exists( $target_file ) && filemtime( $target_file ) >= filemtime( $source_file ) ) {
		return array(
			'url'    => $target_url,
			'width'  => 1200,
			'height' => 630,
		);
	}

	$source_contents = file_get_contents( $source_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! $source_contents ) {
		return array();
	}

	$logo = imagecreatefromstring( $source_contents );
	if ( ! $logo ) {
		return array();
	}

	$canvas = imagecreatetruecolor( 1200, 630 );
	$bg     = imagecolorallocate( $canvas, 247, 249, 246 );
	imagefill( $canvas, 0, 0, $bg );

	$logo_w = imagesx( $logo );
	$logo_h = imagesy( $logo );
	if ( ! $logo_w || ! $logo_h ) {
		unset( $logo );
		unset( $canvas );
		return array();
	}

	$max_w  = 560;
	$max_h  = 280;
	$scale  = min( $max_w / $logo_w, $max_h / $logo_h, 1 );
	$dest_w = max( 1, (int) round( $logo_w * $scale ) );
	$dest_h = max( 1, (int) round( $logo_h * $scale ) );
	$dest_x = (int) round( ( 1200 - $dest_w ) / 2 );
	$dest_y = (int) round( ( 630 - $dest_h ) / 2 );

	imagealphablending( $canvas, true );
	imagesavealpha( $logo, true );
	imagecopyresampled( $canvas, $logo, $dest_x, $dest_y, 0, 0, $dest_w, $dest_h, $logo_w, $logo_h );

	$ok = imagepng( $canvas, $target_file );

	unset( $logo );
	unset( $canvas );

	if ( ! $ok ) {
		return array();
	}

	return array(
		'url'    => $target_url,
		'width'  => 1200,
		'height' => 630,
	);
}

/**
 * Returns all built-in preset colour themes.
 *
 * Each preset provides: label, core colors, text/border neutrals, an
 * on-primary text color (button_text) and an optional `dark` variant used
 * for prefers-color-scheme + [data-theme="dark"] output.
 *
 * @return array<string, array<string, mixed>>
 */
function jtc_get_preset_themes(): array {
	return array(
		'evergreen' => array(
			'label'         => __( 'Evergreen', 'join-the-cause' ),
			'primary'       => '#2d6a2d',
			'primary_dark'  => '#173f1d',
			'primary_light' => '#e9f6e5',
			'hero_from'     => '#1f5d2a',
			'hero_to'       => '#4e7a1d',
			'page_bg'       => '#f5f8f2',
			'surface'       => '#ffffff',
			'surface_alt'   => '#eef6e9',
			'text'          => '#1c261b',
			'text_strong'   => '#111a10',
			'text_muted'    => '#5d6f58',
			'border'        => '#dbe7d2',
			'button_text'   => '#ffffff',
			'dark'          => array(
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
				'button_text'   => '#0f160f',
			),
		),
		'change'    => array(
			'label'         => __( 'Civic Red', 'join-the-cause' ),
			'primary'       => '#e12729',
			'primary_dark'  => '#a41416',
			'primary_light' => '#fff0ef',
			'hero_from'     => '#b21620',
			'hero_to'       => '#c24a1e',
			'page_bg'       => '#fff7f4',
			'surface'       => '#ffffff',
			'surface_alt'   => '#fff0ef',
			'text'          => '#241818',
			'text_strong'   => '#160d0d',
			'text_muted'    => '#725a58',
			'border'        => '#f1d4d0',
			'button_text'   => '#ffffff',
			'dark'          => array(
				'primary'       => '#ff8a7a',
				'primary_dark'  => '#ffb3a6',
				'primary_light' => '#3a1512',
				'hero_from'     => '#3b1310',
				'hero_to'       => '#8a3a22',
				'page_bg'       => '#170f0e',
				'surface'       => '#211615',
				'surface_alt'   => '#2a1b19',
				'text'          => '#f7ecea',
				'text_strong'   => '#ffffff',
				'text_muted'    => '#c9a9a4',
				'border'        => '#4a2b28',
				'input_bg'      => '#180f0e',
				'button_text'   => '#33100c',
			),
		),
		'blue'      => array(
			'label'         => __( 'Trust Blue', 'join-the-cause' ),
			'primary'       => '#1a5276',
			'primary_dark'  => '#0e2f44',
			'primary_light' => '#eaf2fb',
			'hero_from'     => '#103d63',
			'hero_to'       => '#1d7a9f',
			'page_bg'       => '#f3f8fc',
			'surface'       => '#ffffff',
			'surface_alt'   => '#edf5fb',
			'text'          => '#172331',
			'text_strong'   => '#0f1720',
			'text_muted'    => '#536577',
			'border'        => '#d6e2ec',
			'button_text'   => '#ffffff',
			'dark'          => array(
				'primary'       => '#8fc7ea',
				'primary_dark'  => '#b5dbf4',
				'primary_light' => '#12293a',
				'hero_from'     => '#0f2434',
				'hero_to'       => '#2b5f7e',
				'page_bg'       => '#0d141b',
				'surface'       => '#141e27',
				'surface_alt'   => '#1b2935',
				'text'          => '#e8f1f8',
				'text_strong'   => '#ffffff',
				'text_muted'    => '#a9bccb',
				'border'        => '#2a3d4c',
				'input_bg'      => '#0d141b',
				'button_text'   => '#0e2636',
			),
		),
		'teal'      => array(
			'label'         => __( 'Organizing Teal', 'join-the-cause' ),
			'primary'       => '#0e6b6b',
			'primary_dark'  => '#074040',
			'primary_light' => '#e8f8f8',
			'hero_from'     => '#075254',
			'hero_to'       => '#207a6b',
			'page_bg'       => '#f1fbf9',
			'surface'       => '#ffffff',
			'surface_alt'   => '#e8f8f8',
			'text'          => '#132928',
			'text_strong'   => '#091b1a',
			'text_muted'    => '#55716e',
			'border'        => '#cfe5e2',
			'button_text'   => '#ffffff',
			'dark'          => array(
				'primary'       => '#6fd7c3',
				'primary_dark'  => '#9de6d8',
				'primary_light' => '#0f2c28',
				'hero_from'     => '#0c2624',
				'hero_to'       => '#1f6b5c',
				'page_bg'       => '#0b1514',
				'surface'       => '#12201e',
				'surface_alt'   => '#182b28',
				'text'          => '#e6f6f3',
				'text_strong'   => '#ffffff',
				'text_muted'    => '#a3c4bd',
				'border'        => '#27403b',
				'input_bg'      => '#0b1514',
				'button_text'   => '#0c2a24',
			),
		),
		'purple'    => array(
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
			'button_text'   => '#ffffff',
			'dark'          => array(
				'primary'       => '#c9a0e0',
				'primary_dark'  => '#ddc2ec',
				'primary_light' => '#2b1938',
				'hero_from'     => '#291340',
				'hero_to'       => '#5e3a75',
				'page_bg'       => '#120d17',
				'surface'       => '#1c1424',
				'surface_alt'   => '#251b30',
				'text'          => '#f1e9f7',
				'text_strong'   => '#ffffff',
				'text_muted'    => '#b9a8c6',
				'border'        => '#3a2a48',
				'input_bg'      => '#120d17',
				'button_text'   => '#2c1440',
			),
		),
	);
}

/**
 * Convert a theme array into a CSS variable block.
 *
 * @param string               $selector CSS selector.
 * @param array<string, mixed> $colors Theme colors.
 * @return string Result value.
 */
function jtc_theme_vars_block( string $selector, array $colors ): string {
	$dark    = jtc_hex_luminance( $colors['surface'] ?? '#ffffff' ) < 0.18;
	$colors += array(
		'error'             => $dark ? '#ff9b94' : '#a51f16',
		'error_bg'          => $dark ? '#351b1a' : '#fdedec',
		'success'           => $dark ? '#a6e6b2' : '#155b28',
		'success_bg'        => $dark ? '#142d1c' : '#eafaf1',
		'button_hover_text' => jtc_contrast_text_color( $colors['primary_dark'] ?? '#1a3d1a' ),
	);
	$surface = $colors['surface'] ?? '#ffffff';
	foreach ( array( 'error', 'success' ) as $key ) {
		if ( jtc_contrast_ratio( $colors[ $key ], $surface ) < 4.5 ) {
			$colors[ $key ]         = jtc_contrast_text_color( $surface );
			$colors[ $key . '_bg' ] = $surface;
		}
	}
	$colors['link']         = jtc_contrast_ratio( $colors['primary'], $surface ) >= 4.5 ? $colors['primary'] : jtc_contrast_text_color( $surface );
	$colors['link_strong']  = jtc_contrast_ratio( $colors['primary_dark'], $colors['primary_light'] ) >= 4.5 ? $colors['primary_dark'] : jtc_contrast_text_color( $colors['primary_light'] );
	$colors['hero_copy_bg'] = $colors['hero_from'];
	$style                  = jtc_get_style_options();
	$requested              = sanitize_hex_color( jtc_scalar( $style['hero_text'] ?? '' ) );
	$colors['hero_text']    = $requested && jtc_contrast_ratio( $requested, $colors['hero_copy_bg'] ) >= 4.5 ? $requested : jtc_contrast_text_color( $colors['hero_copy_bg'] );
	$map                    = array(
		'link'              => '--jtc-link',
		'link_strong'       => '--jtc-link-strong',
		'error'             => '--jtc-error',
		'error_bg'          => '--jtc-error-bg',
		'success'           => '--jtc-success',
		'success_bg'        => '--jtc-success-bg',
		'hero_text'         => '--jtc-hero-text',
		'hero_copy_bg'      => '--jtc-hero-copy-bg',
		'button_hover_text' => '--jtc-button-hover-text',
		'primary'           => '--jtc-primary',
		'primary_dark'      => '--jtc-primary-dark',
		'primary_light'     => '--jtc-primary-light',
		'hero_from'         => '--jtc-hero-from',
		'hero_to'           => '--jtc-hero-to',
		'page_bg'           => '--jtc-page-bg',
		'surface'           => '--jtc-surface',
		'surface_alt'       => '--jtc-surface-alt',
		'text'              => '--jtc-text',
		'text_strong'       => '--jtc-text-strong',
		'text_muted'        => '--jtc-text-muted',
		'border'            => '--jtc-border',
		'input_bg'          => '--jtc-input-bg',
		'button_text'       => '--jtc-button-text',
	);

	$lines = array( $selector . ' {' );
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
/**
 * Hex to rgb string.
 *
 * @param string $hex Hex.
 * @return string Result value.
 */
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
