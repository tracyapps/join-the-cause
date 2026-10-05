<?php
/**
 * Tests that every built-in preset theme ships a complete palette after the
 * preset rename, including dark variants for all presets.
 *
 * NOTE: requires the WordPress test suite + database (see tests/bootstrap.php);
 * not runnable in the static verification environment (MySQL unavailable).
 *
 * @package JoinTheCause
 */

class JTC_Preset_Themes_Test extends WP_UnitTestCase {

	/**
	 * Color keys every preset (and every dark variant) must define.
	 *
	 * @return string[]
	 */
	private function required_keys(): array {
		return [
			'primary',
			'primary_dark',
			'primary_light',
			'hero_from',
			'hero_to',
			'page_bg',
			'surface',
			'surface_alt',
			'text',
			'text_strong',
			'text_muted',
			'border',
			'button_text',
		];
	}

	public function test_all_presets_have_required_keys(): void {
		$presets = jtc_get_preset_themes();

		$this->assertNotEmpty( $presets, 'No preset themes found.' );

		foreach ( $presets as $slug => $colors ) {
			$this->assertArrayHasKey( 'label', $colors, "Preset '{$slug}' is missing 'label'." );

			foreach ( $this->required_keys() as $key ) {
				$this->assertArrayHasKey( $key, $colors, "Preset '{$slug}' is missing '{$key}'." );
			}
		}
	}

	public function test_every_preset_has_a_complete_dark_variant(): void {
		$presets = jtc_get_preset_themes();

		foreach ( $presets as $slug => $colors ) {
			$this->assertArrayHasKey( 'dark', $colors, "Preset '{$slug}' has no dark variant." );
			$this->assertIsArray( $colors['dark'], "Preset '{$slug}' dark variant is not an array." );

			foreach ( $this->required_keys() as $key ) {
				$this->assertArrayHasKey( $key, $colors['dark'], "Dark variant of '{$slug}' is missing '{$key}'." );
			}
		}
	}

	public function test_default_evergreen_preset_exists(): void {
		$presets = jtc_get_preset_themes();

		$this->assertArrayHasKey( 'evergreen', $presets, 'The default evergreen preset is missing.' );
	}

	public function test_legacy_renamed_slug_is_gone(): void {
		$presets = jtc_get_preset_themes();

		// The old client-named slug was renamed away; no trace may remain.
		$this->assertSame( [ 'evergreen', 'change', 'blue', 'teal', 'purple' ], array_keys( $presets ) );
	}
}
