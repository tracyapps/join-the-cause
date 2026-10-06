<?php
/**
 * Registers the jtc/petition Gutenberg block.
 *
 * The block is a thin wrapper around the existing shortcode renderer: the
 * PHP render callback delegates to `JTC_Shortcode::render()`, so block
 * output is identical to `[jtc_petition]` for the same inputs and every
 * per-instance behavior (per-petition nonce, share URL, count refresh,
 * multi-instance support) is inherited unchanged.
 *
 * No build step: the editor UI is a hand-written script against wp.* globals
 * (`blocks/petition/index.js` + hand-written `index.asset.php`), plus a
 * minimal `editor.css`. Block metadata lives in `blocks/petition/block.json`
 * (apiVersion 3).
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Block WordPress component. */
class JTC_Block {

	const BLOCK_NAME     = 'jtc/petition';
	const BLOCK_CATEGORY = 'join-the-cause';

	/**
	 * Wire the block into WordPress. Blocks must be registered on `init`,.
	 * so the actual registration is deferred to that hook; the category
	 * filter and editor-canvas styles are registered immediately.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block_type' ) );
		add_filter( 'block_categories_all', array( $this, 'register_category' ), 10, 2 );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_editor_canvas_assets' ) );
	}

	// ─── Registration ─────────────────────────────────────────────────────────

	/**
	 * Register the block from its block.json and wire the PHP render callback.
	 *
	 * Runs on `init` (default priority 10) so core's block-support attribute
	 * auto-registration (`WP_Block_Supports::init` on init priority 22) still
	 * applies the `supports` flags — e.g. the `anchor`/`className` attributes
	 * used by the block-renderer REST route's attribute schema.
	 */
	public function register_block_type(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			return;
		}

		$block_type = register_block_type_from_metadata(
			JTC_PLUGIN_DIR . 'blocks/petition',
			array( 'render_callback' => array( $this, 'render' ) )
		);

		// Pass admin URLs to the editor script (empty-state "create" link).
		// The handle is read from the registered block type instead of being
		// assumed, so an asset-file handle override cannot break this.
		if ( $block_type && ! empty( $block_type->editor_script_handles[0] ) ) {
			wp_localize_script(
				$block_type->editor_script_handles[0],
				'jtcBlockEditor',
				array(
					'newPetitionUrl' => admin_url( 'post-new.php?post_type=' . JTC_CPT ),
				)
			);
		}
	}

	/**
	 * Add the "Join the Cause" category to the block inserter.
	 *
	 * @param array[]                 $categories           Block categories.
	 * @param WP_Block_Editor_Context $block_editor_context Editor context (unused).
	 * @return array[] Categories, with ours first.
	 */
	public function register_category( $categories, $block_editor_context ) {
		foreach ( (array) $categories as $category ) {
			if ( isset( $category['slug'] ) && self::BLOCK_CATEGORY === $category['slug'] ) {
				return $categories; // Already registered — stay idempotent.
			}
		}

		return array_merge(
			array(
				array(
					'slug'  => self::BLOCK_CATEGORY,
					'title' => __( 'Join the Cause', 'join-the-cause' ),
					'icon'  => null,
				),
			),
			(array) $categories
		);
	}

	// ─── Rendering ────────────────────────────────────────────────────────────

	/**
	 * Server render for jtc/petition — delegates to the shortcode renderer so.
	 * the block and [jtc_petition] produce identical inner output (same
	 * markup, data attributes, escaping, and asset enqueues).
	 *
	 * The result is wrapped in `get_block_wrapper_attributes()` — the only
	 * core path that applies block supports such as the HTML anchor id and
	 * the additional CSS class — so editor-side `supports` (anchor,
	 * customClassName) actually render on the front end.
	 *
	 * @param array    $attributes Block attributes (schema-validated on render, not sanitized).
	 * @param string   $content    Inner content (unused — the block has none).
	 * @param WP_Block $block      Block instance (unused).
	 * @return string
	 */
	public function render( $attributes, $content = '', $block = null ): string {
		$inner = ( new JTC_Shortcode() )->render(
			array(
				'id'         => isset( $attributes['petitionId'] ) ? absint( $attributes['petitionId'] ) : 0,
				'show_title' => ! empty( $attributes['showTitle'] ) ? 1 : 0,
			)
		);

		return '<div ' . get_block_wrapper_attributes() . '>' . $inner . '</div>';
	}

	// ─── Editor canvas styles ─────────────────────────────────────────────────

	/**
	 * Make the public stylesheet + CSS variables available inside the block.
	 * editor canvas (iframe) so the ServerSideRender preview matches the
	 * front end.
	 *
	 * Why here: the front-end path (shortcode renderer enqueues `jtc-public`,
	 * wp_head prints the vars) never runs for the editor canvas — the iframe
	 * sees neither wp_head nor wp_enqueue_scripts. WP 6.3+ collects the
	 * iframe's styles from the `enqueue_block_assets` hook
	 * (`_wp_get_iframed_editor_assets()`), so this handler registers/enqueues
	 * the same `jtc-public` handle there and attaches the CSS vars inline.
	 * It is guarded to admin requests that are editing content containing the
	 * block, so the front end is untouched (no double enqueue) and unrelated
	 * admin screens stay clean.
	 */
	public function enqueue_editor_canvas_assets(): void {
		if ( ! is_admin() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! has_block( self::BLOCK_NAME, $post ) ) {
			return;
		}

		if ( ! wp_style_is( 'jtc-public', 'registered' ) ) {
			wp_register_style( 'jtc-public', JTC_PLUGIN_URL . 'assets/css/public.css', array(), JTC_VERSION );
		}

		wp_enqueue_style( 'jtc-public' );

		// Attach the theme/style CSS vars to the handle, once per styles
		// instance (the editor canvas is collected into a fresh WP_Styles
		// instance; the dedupe keeps a second pass from double-printing).
		$vars = jtc_build_css_vars();
		if ( '' !== $vars ) {
			$inline = wp_styles()->get_data( 'jtc-public', 'after' );
			if ( ! is_array( $inline ) || ! in_array( $vars, $inline, true ) ) {
				wp_add_inline_style( 'jtc-public', $vars );
			}
		}
	}
}
