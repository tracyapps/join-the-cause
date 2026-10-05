<?php
/**
 * Tests the stored-preset migration helper.
 *
 * A legacy client-named preset slug was renamed in this release. The
 * migration helper maps any stored slug that is no longer a valid preset
 * back to the evergreen default, so existing installs keep working without
 * the legacy string remaining anywhere in the codebase.
 *
 * NOTE: requires the WordPress test suite + database (see tests/bootstrap.php);
 * not runnable in the static verification environment (MySQL unavailable).
 *
 * @package JoinTheCause
 */

class JTC_Preset_Migration_Test extends WP_UnitTestCase {

	public function test_valid_slugs_are_kept_unchanged(): void {
		$valid = array_keys( jtc_get_preset_themes() );

		foreach ( $valid as $slug ) {
			$this->assertSame(
				$slug,
				JTC_Activator::sanitize_stored_preset_slug( $slug, $valid ),
				"Valid slug '{$slug}' must not be rewritten."
			);
		}
	}

	public function test_unknown_legacy_slug_migrates_to_evergreen(): void {
		$valid = array_keys( jtc_get_preset_themes() );

		// Any stored slug that no longer exists (e.g. a renamed preset)
		// falls back to the evergreen default.
		$this->assertSame(
			'evergreen',
			JTC_Activator::sanitize_stored_preset_slug( 'legacy-renamed-preset', $valid )
		);
	}

	public function test_empty_or_invalid_values_resolve_to_evergreen(): void {
		$valid = array_keys( jtc_get_preset_themes() );

		$this->assertSame( 'evergreen', JTC_Activator::sanitize_stored_preset_slug( '', $valid ) );
		$this->assertSame( 'evergreen', JTC_Activator::sanitize_stored_preset_slug( 'not-a-preset', $valid ) );
	}

	public function test_migration_is_idempotent_for_evergreen(): void {
		$valid = array_keys( jtc_get_preset_themes() );

		$once  = JTC_Activator::sanitize_stored_preset_slug( 'legacy-renamed-preset', $valid );
		$twice = JTC_Activator::sanitize_stored_preset_slug( $once, $valid );

		$this->assertSame( $once, $twice );
	}
}
