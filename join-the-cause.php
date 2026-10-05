<?php
/**
 * Plugin Name:       Join the Cause
 * GitHub Plugin URI: https://github.com/tracyapps/join-the-cause
 * Description:       Petition and newsletter management for WordPress — create, manage, and share petitions with a change.org-style front end.
 * Version:           0.1.0
 * Author:            Tracy Apps
 * Author URI:        https://github.com/tracyapps
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
define( 'JTC_STYLE_VERSION',    '1' );
define( 'JTC_DB_VERSION',       '2' );
define( 'JTC_PLUGIN_FILE',      __FILE__ );
define( 'JTC_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'JTC_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'JTC_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );
define( 'JTC_CPT',              'jtc_petition' );

// ─── Autoload includes ────────────────────────────────────────────────────────

$includes = [
	'includes/class-jtc-activator.php',
	'includes/class-jtc-post-types.php',
	'includes/class-jtc-short-io.php',
	'includes/class-jtc-mailer.php',
	'includes/class-jtc-form-handler.php',
	'includes/class-jtc-shortcode.php',
	'includes/class-jtc-block.php',
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
	add_image_size( 'jtc_social_card', 1200, 630, true );

	// One-time migrations (preset rename, new DB indexes). Cheap: bail once flagged.
	JTC_Activator::maybe_migrate();
	JTC_Activator::maybe_upgrade_schema();

	// Translations shipped with the plugin (GitHub distribution).
	load_plugin_textdomain( 'join-the-cause', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Register CPT + meta boxes.
	( new JTC_Post_Types() )->register();

	// Register [jtc_petition] shortcode.
	( new JTC_Shortcode() )->register();

	// Register the jtc/petition Gutenberg block (delegates to the shortcode renderer).
	( new JTC_Block() )->register();

	// Register AJAX form handlers.
	( new JTC_Form_Handler() )->register();

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

	echo "<style id=\"jtc-theme-vars\">\n{$css}\n</style>\n";
	$GLOBALS['jtc_css_vars_emitted'] = true;
}

/**
 * Whether the current front-end request might render a petition.
 * Themes that render petitions from widgets/page builders can force output
 * through the jtc_should_output_css_vars filter.
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
 * Attaches the vars inline to the jtc-public handle when they could not be
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
 * Identifies the plugin's own admin screens (settings, supporters,
 * newsletter, petition CPT screens).
 */
function jtc_is_jtc_admin_screen( $screen ): bool {
	if ( ! $screen || ! is_object( $screen ) ) {
		return false;
	}

	$pages = [
		'toplevel_page_join-the-cause',
		'join-the-cause_page_jtc-supporters',
		'join-the-cause_page_jtc-newsletter',
	];

	if ( in_array( $screen->id, $pages, true ) ) {
		return true;
	}

	return isset( $screen->post_type ) && JTC_CPT === $screen->post_type;
}

/**
 * Custom mode: user-picked colors plus derived neutrals so switching modes
 * never leaves text/border/input values "inherit"-inconsistent.
 */
function jtc_custom_theme_colors(): array {
	$surface  = sanitize_hex_color( get_option( 'jtc_custom_surface', '#ffffff' ) ) ?: '#ffffff';
	$primary  = sanitize_hex_color( get_option( 'jtc_custom_primary', '#2d6a2d' ) ) ?: '#2d6a2d';
	$neutrals = jtc_derive_neutral_colors( $surface );

	return [
		'primary'        => $primary,
		'primary_dark'   => sanitize_hex_color( get_option( 'jtc_custom_secondary', '#1a3d1a' ) ) ?: '#1a3d1a',
		'primary_light'  => sanitize_hex_color( get_option( 'jtc_custom_accent', '#f0faf0' ) ) ?: '#f0faf0',
		'hero_from'      => sanitize_hex_color( get_option( 'jtc_custom_hero_from', '#245e2b' ) ) ?: '#245e2b',
		'hero_to'        => sanitize_hex_color( get_option( 'jtc_custom_hero_to', '#4f8d33' ) ) ?: '#4f8d33',
		'page_bg'        => sanitize_hex_color( get_option( 'jtc_custom_page_bg', '#f6f8f4' ) ) ?: '#f6f8f4',
		'surface'        => $surface,
		'surface_alt'    => sanitize_hex_color( get_option( 'jtc_custom_surface_alt', '#f3f7f0' ) ) ?: '#f3f7f0',
		'border'         => sanitize_hex_color( get_option( 'jtc_custom_border', '#d8e2d2' ) ) ?: '#d8e2d2',
		'text'           => $neutrals['text'],
		'text_strong'    => $neutrals['text_strong'],
		'text_muted'     => $neutrals['text_muted'],
		'input_bg'       => $neutrals['input_bg'],
		'button_text'    => jtc_contrast_text_color( $primary ),
	];
}

/**
 * Derives readable text + input colors for a given surface background.
 *
 * @return array{text:string,text_strong:string,text_muted:string,input_bg:string}
 */
function jtc_derive_neutral_colors( string $surface ): array {
	if ( jtc_hex_luminance( $surface ) > 0.4 ) {
		return [
			'text'        => '#1c261b',
			'text_strong' => '#111a10',
			'text_muted'  => '#5d6f58',
			'input_bg'    => '#ffffff',
		];
	}

	return [
		'text'        => '#edf5e9',
		'text_strong' => '#ffffff',
		'text_muted'  => '#b7c8ae',
		'input_bg'    => '#111a10',
	];
}

/** Picks a readable text color (near-white or near-black) for a background. */
function jtc_contrast_text_color( string $background ): string {
	return jtc_hex_luminance( $background ) > 0.35 ? '#111a10' : '#ffffff';
}

/** WCAG relative luminance of a hex color (0..1). */
function jtc_hex_luminance( string $hex ): float {
	$hex = sanitize_hex_color( $hex );

	if ( ! $hex ) {
		return 0.5;
	}

	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	$weights   = [ 0.2126, 0.7152, 0.0722 ];
	$luminance = 0.0;

	for ( $i = 0; $i < 3; $i++ ) {
		$channel    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
		$channel    = $channel <= 0.03928 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
		$luminance += $weights[ $i ] * $channel;
	}

	return $luminance;
}

/** Strips characters that could break out of a CSS declaration context. */
function jtc_safe_css_value( string $value ): string {
	return trim( str_replace( [ '<', '>', '{', '}', ';' ], '', $value ) );
}

/**
 * Defaults for the Layout & Style option (single serialized jtc_style array).
 *
 * @return array<string, mixed>
 */
function jtc_style_defaults(): array {
	return [
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
	];
}

/** Returns the merged style option (saved values + defaults). */
function jtc_get_style_options(): array {
	static $options = null;

	if ( null === $options ) {
		$saved   = get_option( 'jtc_style', [] );
		$options = wp_parse_args( is_array( $saved ) ? $saved : [], jtc_style_defaults() );
	}

	return $options;
}

/** Single style option accessor with fallback to defaults. */
function jtc_get_style_option( string $key ) {
	$options = jtc_get_style_options();

	return $options[ $key ] ?? ( jtc_style_defaults()[ $key ] ?? null );
}

/**
 * Allowed values/schemas for style options. Each entry resolves a stored
 * token into validated CSS values at output time.
 *
 * @return array<string, mixed>
 */
function jtc_style_maps(): array {
	return [
		'radius'         => [
			'compact' => [ '4px', '6px' ],
			'default' => [ '8px', '12px' ],
			'round'   => [ '14px', '18px' ],
			'pill'    => [ '999px', '999px' ],
		],
		'shadow'         => [
			'none'    => [ 'none', 'none', 'none' ],
			'subtle'  => [
				'0 1px 2px rgba(15, 23, 42, 0.06)',
				'0 6px 20px rgba(15, 23, 42, 0.09)',
				'0 14px 36px rgba(15, 23, 42, 0.13)',
			],
			'default' => [
				'0 1px 3px rgba(15, 23, 42, 0.08)',
				'0 10px 30px rgba(15, 23, 42, 0.12)',
				'0 22px 55px rgba(15, 23, 42, 0.18)',
			],
			'strong'  => [
				'0 2px 6px rgba(15, 23, 42, 0.16)',
				'0 16px 42px rgba(15, 23, 42, 0.22)',
				'0 32px 72px rgba(15, 23, 42, 0.30)',
			],
		],
		'button_style'   => [ 'solid', 'outline' ],
		'font_source'    => [
			'inherit' => 'inherit',
			'system'  => '-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif',
		],
		'panel_width'    => [ 340, 380, 420 ],
		'content_max'    => [ 'none', '720px', '960px', '1140px', '1320px' ],
		'hero_style'     => [ 'gradient', 'solid', 'minimal' ],
	];
}

/**
 * Sanitizes raw POST data for the jtc_style option (typed, whitelisted).
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function jtc_sanitize_style_options( array $raw ): array {
	$maps     = jtc_style_maps();
	$defaults = jtc_style_defaults();
	$clean    = [];

	$enum = static function ( string $key, array $allowed, string $default ) use ( $raw ): string {
		$value = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '';

		return in_array( $value, $allowed, true ) ? $value : $default;
	};

	$clean['radius']       = $enum( 'radius', array_keys( $maps['radius'] ), $defaults['radius'] );
	$clean['shadow']       = $enum( 'shadow', array_keys( $maps['shadow'] ), $defaults['shadow'] );
	$clean['button_style'] = $enum( 'button_style', $maps['button_style'], $defaults['button_style'] );

	$clean['font_source'] = $enum( 'font_source', [ 'inherit', 'system', 'custom' ], $defaults['font_source'] );
	$font_custom          = trim( sanitize_text_field( wp_unslash( (string) ( $raw['font_custom'] ?? '' ) ) ) );
	$clean['font_custom'] = trim( jtc_safe_css_value( $font_custom ), ", \t\n\r\0\x0B'\"" );

	$clean['font_scale'] = max( 90, min( 110, absint( $raw['font_scale'] ?? $defaults['font_scale'] ) ) );

	$panel                = absint( $raw['panel_width'] ?? $defaults['panel_width'] );
	$clean['panel_width'] = in_array( $panel, $maps['panel_width'], true ) ? $panel : $defaults['panel_width'];

	$clean['content_max'] = $enum( 'content_max', $maps['content_max'], $defaults['content_max'] );
	$clean['hero_style']  = $enum( 'hero_style', $maps['hero_style'], $defaults['hero_style'] );
	$clean['hero_text']   = sanitize_hex_color( wp_unslash( (string) ( $raw['hero_text'] ?? '' ) ) ) ?: '';

	$clean['show_share']          = ! empty( $raw['show_share'] ) ? 1 : 0;
	$clean['show_qr']             = ! empty( $raw['show_qr'] ) ? 1 : 0;
	$clean['show_dek']            = ! empty( $raw['show_dek'] ) ? 1 : 0;
	$clean['show_featured_image'] = ! empty( $raw['show_featured_image'] ) ? 1 : 0;

	$clean['version'] = JTC_STYLE_VERSION;

	return $clean;
}

/**
 * Emits the Layout & Style CSS variables. Values resolve through the
 * jtc_style_maps() schema, so only whitelisted tokens reach the stylesheet.
 */
function jtc_style_vars_block( array $style ): string {
	$maps  = jtc_style_maps();
	$lines = [];

	$radius  = $maps['radius'][ $style['radius'] ?? '' ] ?? $maps['radius']['default'];
	$lines[] = '  --jtc-radius: ' . esc_attr( $radius[0] ) . ';';
	$lines[] = '  --jtc-radius-lg: ' . esc_attr( $radius[1] ) . ';';

	$shadow  = $maps['shadow'][ $style['shadow'] ?? '' ] ?? $maps['shadow']['default'];
	$lines[] = '  --jtc-shadow-sm: ' . esc_attr( $shadow[0] ) . ';';
	$lines[] = '  --jtc-shadow-md: ' . esc_attr( $shadow[1] ) . ';';
	$lines[] = '  --jtc-shadow-lg: ' . esc_attr( $shadow[2] ) . ';';

	if ( 'outline' === ( $style['button_style'] ?? 'solid' ) ) {
		$lines[] = '  --jtc-button-bg: transparent;';
		$lines[] = '  --jtc-button-fg: var(--jtc-primary);';
		$lines[] = '  --jtc-button-border: var(--jtc-primary);';
		$lines[] = '  --jtc-button-hover-bg: var(--jtc-primary);';
		$lines[] = '  --jtc-button-hover-fg: var(--jtc-button-text, #ffffff);';
	} else {
		$lines[] = '  --jtc-button-bg: var(--jtc-primary);';
		$lines[] = '  --jtc-button-fg: var(--jtc-button-text, #ffffff);';
		$lines[] = '  --jtc-button-border: transparent;';
		$lines[] = '  --jtc-button-hover-bg: var(--jtc-primary-dark);';
		$lines[] = '  --jtc-button-hover-fg: var(--jtc-button-text, #ffffff);';
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

	if ( ! empty( $style['hero_text'] ) ) {
		$hero_text = sanitize_hex_color( (string) $style['hero_text'] );
		if ( $hero_text ) {
			$lines[] = '  --jtc-hero-text: ' . esc_attr( $hero_text ) . ';';
		}
	}

	return ":root {\n" . implode( "\n", $lines ) . "\n}";
}

function jtc_output_social_meta(): void {
	$petition_id = jtc_get_current_social_petition_id();
	if ( ! $petition_id ) {
		return;
	}

	$petition = get_post( $petition_id );
	if ( ! $petition || JTC_CPT !== $petition->post_type || 'publish' !== $petition->post_status ) {
		return;
	}

	$title       = get_the_title( $petition_id );
	$description = has_excerpt( $petition )
		? get_the_excerpt( $petition )
		: wp_trim_words( wp_strip_all_tags( $petition->post_content ), 32, '' );
	// No remote refresh in wp_head: social meta must never trigger a
	// blocking Short.io request. Stored values are used as-is.
	$url         = jtc_get_petition_share_url( $petition_id, false );
	$image       = jtc_get_petition_social_image( $petition_id );
	$site_name   = get_bloginfo( 'name' );

	$meta = [
		[ 'property', 'og:type', 'article' ],
		[ 'property', 'og:site_name', $site_name ],
		[ 'property', 'og:title', $title ],
		[ 'property', 'og:description', $description ],
		[ 'property', 'og:url', $url ],
		[ 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' ],
		[ 'name', 'twitter:title', $title ],
		[ 'name', 'twitter:description', $description ],
	];

	if ( $image ) {
		$meta[] = [ 'property', 'og:image', $image['url'] ];
		$meta[] = [ 'property', 'og:image:secure_url', $image['url'] ];
		$meta[] = [ 'property', 'og:image:alt', $title ];
		$meta[] = [ 'name', 'twitter:image', $image['url'] ];

		if ( ! empty( $image['width'] ) && ! empty( $image['height'] ) ) {
			$meta[] = [ 'property', 'og:image:width', (string) $image['width'] ];
			$meta[] = [ 'property', 'og:image:height', (string) $image['height'] ];
		}
	}

	echo "\n<!-- Join the Cause social sharing -->\n";
	foreach ( $meta as $item ) {
		$attr = 'property' === $item[0] ? 'property' : 'name';
		printf(
			'<meta %1$s="%2$s" content="%3$s">' . "\n",
			$attr,
			esc_attr( $item[1] ),
			esc_attr( wp_strip_all_tags( (string) $item[2] ) )
		);
	}
}

function jtc_get_current_social_petition_id(): int {
	if ( is_singular( JTC_CPT ) ) {
		return (int) get_queried_object_id();
	}

	if ( ! is_singular() ) {
		return 0;
	}

	$post = get_post();
	if ( ! $post || false === strpos( $post->post_content, '[jtc_petition' ) ) {
		return 0;
	}

	if ( preg_match( '/\[jtc_petition[^\]]*id=[\'"]?(\d+)/', $post->post_content, $matches ) ) {
		return absint( $matches[1] );
	}

	return 0;
}

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
			return [
				'url'    => $image[0],
				'width'  => (int) $image[1],
				'height' => (int) $image[2],
			];
		}
	}

	$site_icon = get_site_icon_url( 512 );
	if ( $site_icon ) {
		return [
			'url'    => $site_icon,
			'width'  => 512,
			'height' => 512,
		];
	}

	return [];
}

function jtc_get_generated_logo_social_card( int $attachment_id ): array {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return [];
	}

	$source_file = get_attached_file( $attachment_id );
	if ( ! $source_file || ! file_exists( $source_file ) ) {
		return [];
	}

	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		return [];
	}

	$dir = trailingslashit( $upload_dir['basedir'] ) . 'join-the-cause';
	if ( ! wp_mkdir_p( $dir ) ) {
		return [];
	}

	$target_file = trailingslashit( $dir ) . 'social-card-logo-' . $attachment_id . '.png';
	$target_url  = trailingslashit( $upload_dir['baseurl'] ) . 'join-the-cause/social-card-logo-' . $attachment_id . '.png';

	if ( file_exists( $target_file ) && filemtime( $target_file ) >= filemtime( $source_file ) ) {
		return [
			'url'    => $target_url,
			'width'  => 1200,
			'height' => 630,
		];
	}

	$source_contents = file_get_contents( $source_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! $source_contents ) {
		return [];
	}

	$logo = imagecreatefromstring( $source_contents );
	if ( ! $logo ) {
		return [];
	}

	$canvas = imagecreatetruecolor( 1200, 630 );
	$bg     = imagecolorallocate( $canvas, 247, 249, 246 );
	imagefill( $canvas, 0, 0, $bg );

	$logo_w = imagesx( $logo );
	$logo_h = imagesy( $logo );
	if ( ! $logo_w || ! $logo_h ) {
		imagedestroy( $logo );
		imagedestroy( $canvas );
		return [];
	}

	$max_w = 560;
	$max_h = 280;
	$scale = min( $max_w / $logo_w, $max_h / $logo_h, 1 );
	$dest_w = max( 1, (int) round( $logo_w * $scale ) );
	$dest_h = max( 1, (int) round( $logo_h * $scale ) );
	$dest_x = (int) round( ( 1200 - $dest_w ) / 2 );
	$dest_y = (int) round( ( 630 - $dest_h ) / 2 );

	imagealphablending( $canvas, true );
	imagesavealpha( $logo, true );
	imagecopyresampled( $canvas, $logo, $dest_x, $dest_y, 0, 0, $dest_w, $dest_h, $logo_w, $logo_h );

	$ok = imagepng( $canvas, $target_file );

	imagedestroy( $logo );
	imagedestroy( $canvas );

	if ( ! $ok ) {
		return [];
	}

	return [
		'url'    => $target_url,
		'width'  => 1200,
		'height' => 630,
	];
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
	return [
		'evergreen' => [
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
				'button_text'   => '#0f160f',
			],
		],
		'change' => [
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
			'dark'          => [
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
			],
		],
		'blue' => [
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
			'dark'          => [
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
			],
		],
		'teal' => [
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
			'dark'          => [
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
			],
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
			'button_text'   => '#ffffff',
			'dark'          => [
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
			],
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
