<?php
/**
 * Settings tab: Appearance.
 *
 * Colour mode (preset / custom / none), preset swatches, custom colour
 * pickers, Layout & Style options (radius, shadow, buttons, typography,
 * layout, hero, section toggles) and a live CSS-only preview panel.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$presets        = jtc_get_preset_themes();
$current_mode   = get_option( 'jtc_color_mode', 'preset' );
$current_preset = get_option( 'jtc_preset_theme', 'evergreen' );

if ( ! isset( $presets[ $current_preset ] ) ) {
	$current_preset = 'evergreen';
}

$style = jtc_get_style_options();
?>
<div class="jtc-tab-content">
	<h2><?php esc_html_e( 'Colour Theme', 'join-the-cause' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Choose how colours are applied to your petition pages. Selecting "None" lets your active theme handle all styling.', 'join-the-cause' ); ?></p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Colour mode', 'join-the-cause' ); ?></th>
			<td>
				<?php
				$modes = array(
					'preset' => __( 'Preset theme', 'join-the-cause' ),
					'custom' => __( 'Custom colours (colour picker)', 'join-the-cause' ),
					'none'   => __( 'None (use theme styles only)', 'join-the-cause' ),
				);
				foreach ( $modes as $val => $lbl ) :
					?>
				<label style="display:block;margin-bottom:6px;">
					<input type="radio" name="jtc_color_mode" value="<?php echo esc_attr( $val ); ?>"
						<?php checked( $current_mode, $val ); ?> class="jtc-mode-radio">
					<?php echo esc_html( $lbl ); ?>
				</label>
				<?php endforeach; ?>
			</td>
		</tr>

		<!-- Preset swatches -->
		<tr class="jtc-show-when-preset">
			<th scope="row"><label><?php esc_html_e( 'Choose preset', 'join-the-cause' ); ?></label></th>
			<td>
				<div class="jtc-preset-swatches" role="group" aria-label="<?php esc_attr_e( 'Colour preset options', 'join-the-cause' ); ?>">
					<?php
					foreach ( $presets as $slug => $colors ) :
						$label = $colors['label'] ?? ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
						?>
					<label class="jtc-swatch-label" title="<?php echo esc_attr( $label ); ?>">
						<input type="radio" name="jtc_preset_theme" value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( $current_preset, $slug ); ?>>
						<span class="jtc-swatch"
							style="background:linear-gradient(135deg, <?php echo esc_attr( $colors['hero_from'] ?? $colors['primary'] ); ?>, <?php echo esc_attr( $colors['hero_to'] ?? $colors['primary_dark'] ); ?>);"
							aria-hidden="true">
						</span>
						<span class="jtc-swatch-name"><?php echo esc_html( $label ); ?></span>
					</label>
					<?php endforeach; ?>
				</div>
			</td>
		</tr>

		<!-- Custom colour pickers -->
		<tr class="jtc-show-when-custom">
			<th scope="row"><?php esc_html_e( 'Custom colours', 'join-the-cause' ); ?></th>
			<td>
				<div class="jtc-color-pickers">
					<?php
					$pickers = array(
						'jtc_custom_primary'     => array( __( 'Primary colour', 'join-the-cause' ), '#2d6a2d' ),
						'jtc_custom_secondary'   => array( __( 'Dark variant', 'join-the-cause' ), '#1a3d1a' ),
						'jtc_custom_accent'      => array( __( 'Light accent / bg', 'join-the-cause' ), '#f0faf0' ),
						'jtc_custom_hero_from'   => array( __( 'Hero gradient start', 'join-the-cause' ), '#245e2b' ),
						'jtc_custom_hero_to'     => array( __( 'Hero gradient end', 'join-the-cause' ), '#4f8d33' ),
						'jtc_custom_page_bg'     => array( __( 'Page background', 'join-the-cause' ), '#f6f8f4' ),
						'jtc_custom_surface'     => array( __( 'Content background', 'join-the-cause' ), '#ffffff' ),
						'jtc_custom_surface_alt' => array( __( 'Accent background', 'join-the-cause' ), '#f3f7f0' ),
						'jtc_custom_border'      => array( __( 'Borders', 'join-the-cause' ), '#d8e2d2' ),
					);
					foreach ( $pickers as $key => [$label, $default] ) :
						$val = get_option( $key, $default );
						?>
					<div class="jtc-color-picker-row">
						<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
						<input
							type="text"
							id="<?php echo esc_attr( $key ); ?>"
							name="<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( $val ); ?>"
							class="jtc-color-picker"
							data-default-color="<?php echo esc_attr( $default ); ?>"
						>
					</div>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Text colours, input backgrounds, and the on-button text colour are derived automatically from your surface and primary colours.', 'join-the-cause' ); ?></p>
				</div>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Layout & Style', 'join-the-cause' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Applies to every petition. Changes preview live in the panel below.', 'join-the-cause' ); ?></p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="jtc_style_radius"><?php esc_html_e( 'Corner radius', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_style_radius" name="jtc_style[radius]">
					<?php
					foreach ( array(
						'compact' => __( 'Compact (4px)', 'join-the-cause' ),
						'default' => __( 'Default (8px)', 'join-the-cause' ),
						'round'   => __( 'Round (14px)', 'join-the-cause' ),
						'pill'    => __( 'Pill', 'join-the-cause' ),
					) as $v => $l ) :
						?>
					<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $style['radius'], $v ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_style_shadow"><?php esc_html_e( 'Shadow level', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_style_shadow" name="jtc_style[shadow]">
					<?php
					foreach ( array(
						'none'    => __( 'None', 'join-the-cause' ),
						'subtle'  => __( 'Subtle', 'join-the-cause' ),
						'default' => __( 'Default', 'join-the-cause' ),
						'strong'  => __( 'Strong', 'join-the-cause' ),
					) as $v => $l ) :
						?>
					<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $style['shadow'], $v ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_style_button"><?php esc_html_e( 'Button style', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_style_button" name="jtc_style[button_style]">
					<option value="solid" <?php selected( $style['button_style'], 'solid' ); ?>><?php esc_html_e( 'Solid', 'join-the-cause' ); ?></option>
					<option value="outline" <?php selected( $style['button_style'], 'outline' ); ?>><?php esc_html_e( 'Outline', 'join-the-cause' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_style_font_source"><?php esc_html_e( 'Font source', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_style_font_source" name="jtc_style[font_source]">
					<option value="inherit" <?php selected( $style['font_source'], 'inherit' ); ?>><?php esc_html_e( 'Inherit from theme (recommended)', 'join-the-cause' ); ?></option>
					<option value="system" <?php selected( $style['font_source'], 'system' ); ?>><?php esc_html_e( 'System UI stack', 'join-the-cause' ); ?></option>
					<option value="custom" <?php selected( $style['font_source'], 'custom' ); ?>><?php esc_html_e( 'Custom stack', 'join-the-cause' ); ?></option>
				</select>
				<p class="jtc-font-custom-row"<?php echo 'custom' !== $style['font_source'] ? ' style="display:none;"' : ''; ?>>
					<label for="jtc_style_font_custom" class="screen-reader-text"><?php esc_html_e( 'Custom font stack', 'join-the-cause' ); ?></label>
					<input type="text" id="jtc_style_font_custom" name="jtc_style[font_custom]"
						value="<?php echo esc_attr( $style['font_custom'] ); ?>" class="regular-text"
						placeholder="Georgia, 'Times New Roman', serif">
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_style_font_scale"><?php esc_html_e( 'Text size', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_style_font_scale" name="jtc_style[font_scale]">
					<?php foreach ( array( 90, 95, 100, 105, 110 ) as $pct ) : ?>
					<option value="<?php echo esc_attr( $pct ); ?>" <?php selected( (int) $style['font_scale'], $pct ); ?>><?php echo esc_html( $pct . '%' ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_style_panel_width"><?php esc_html_e( 'Sign panel width', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_style_panel_width" name="jtc_style[panel_width]">
					<?php foreach ( array( 340, 380, 420 ) as $w ) : ?>
					<option value="<?php echo esc_attr( $w ); ?>" <?php selected( (int) $style['panel_width'], $w ); ?>><?php echo esc_html( $w . 'px' ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_style_content_max"><?php esc_html_e( 'Content max width', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_style_content_max" name="jtc_style[content_max]">
					<option value="none" <?php selected( $style['content_max'], 'none' ); ?>><?php esc_html_e( 'Full width (theme container)', 'join-the-cause' ); ?></option>
					<?php foreach ( array( '720px', '960px', '1140px', '1320px' ) as $w ) : ?>
					<option value="<?php echo esc_attr( $w ); ?>" <?php selected( $style['content_max'], $w ); ?>><?php echo esc_html( $w ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jtc_style_hero_style"><?php esc_html_e( 'Hero style', 'join-the-cause' ); ?></label></th>
			<td>
				<select id="jtc_style_hero_style" name="jtc_style[hero_style]">
					<option value="gradient" <?php selected( $style['hero_style'], 'gradient' ); ?>><?php esc_html_e( 'Gradient (default)', 'join-the-cause' ); ?></option>
					<option value="solid" <?php selected( $style['hero_style'], 'solid' ); ?>><?php esc_html_e( 'Solid', 'join-the-cause' ); ?></option>
					<option value="minimal" <?php selected( $style['hero_style'], 'minimal' ); ?>><?php esc_html_e( 'Minimal (no fill)', 'join-the-cause' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Hero text colour', 'join-the-cause' ); ?></th>
			<td>
				<input
					type="text"
					id="jtc_style_hero_text"
					name="jtc_style[hero_text]"
					value="<?php echo esc_attr( $style['hero_text'] ); ?>"
					class="jtc-color-picker"
					data-default-color="#ffffff"
				>
				<p class="description"><?php esc_html_e( 'Leave empty for automatic white text. If you use light hero colours, set a dark text colour here.', 'join-the-cause' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Sections', 'join-the-cause' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Global section visibility', 'join-the-cause' ); ?></legend>
					<label style="display:block;margin-bottom:6px;">
						<input type="checkbox" name="jtc_style[show_share]" value="1" <?php checked( $style['show_share'] ); ?>>
						<?php esc_html_e( 'Show the share section', 'join-the-cause' ); ?>
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="checkbox" name="jtc_style[show_qr]" value="1" <?php checked( $style['show_qr'] ); ?>>
						<?php esc_html_e( 'Show the QR code block inside the share section', 'join-the-cause' ); ?>
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="checkbox" name="jtc_style[show_dek]" value="1" <?php checked( $style['show_dek'] ); ?>>
						<?php esc_html_e( 'Show the dek (excerpt) in the hero', 'join-the-cause' ); ?>
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="checkbox" name="jtc_style[show_featured_image]" value="1" <?php checked( $style['show_featured_image'] ); ?>>
						<?php esc_html_e( 'Show the featured image in the hero', 'join-the-cause' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Recent signers visibility is controlled under Petitions (defaults) and per petition.', 'join-the-cause' ); ?></p>
				</fieldset>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Live preview', 'join-the-cause' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Sample petition — updates instantly as you change the settings above. Nothing here is saved until you press Save Settings.', 'join-the-cause' ); ?></p>

	<div class="jtc-preview" id="jtc-preview">
		<div class="jtc-preview-sample" id="jtc-preview-sample" data-jtc-hero="<?php echo esc_attr( $style['hero_style'] ); ?>">
			<header class="jtc-preview-sample__hero">
				<h3 class="jtc-preview-sample__title"><?php esc_html_e( 'Clean water for every school', 'join-the-cause' ); ?></h3>
				<p class="jtc-preview-sample__dek"><?php esc_html_e( 'Sample dek text: one sentence that tells people why this matters right now.', 'join-the-cause' ); ?></p>
			</header>
			<div class="jtc-preview-sample__panel">
				<p class="jtc-preview-sample__count"><strong>1,248</strong> <?php esc_html_e( 'have signed', 'join-the-cause' ); ?></p>
				<label class="jtc-preview-sample__label" for="jtc-preview-input"><?php esc_html_e( 'First name', 'join-the-cause' ); ?></label>
				<input class="jtc-preview-sample__input" id="jtc-preview-input" type="text" value="" placeholder="<?php esc_attr_e( 'Jane', 'join-the-cause' ); ?>" readonly tabindex="-1">
				<button type="button" class="jtc-preview-sample__button" tabindex="-1"><?php esc_html_e( 'Sign the Petition', 'join-the-cause' ); ?></button>
				<div class="jtc-preview-sample__chips">
					<span class="jtc-preview-sample__chip"><?php esc_html_e( 'Share on Facebook', 'join-the-cause' ); ?></span>
					<span class="jtc-preview-sample__chip"><?php esc_html_e( 'Copy link', 'join-the-cause' ); ?></span>
				</div>
			</div>
		</div>
	</div>
</div>
