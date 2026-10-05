<?php
/**
 * PHPUnit bootstrap for the Join the Cause test suite.
 *
 * Requires the WordPress test library (WP_TESTS_DIR or the default
 * /tmp/wordpress-tests-lib location) and a database. These tests are NOT
 * runnable in the static verification environment used for this pass
 * (no MySQL); they are provided as the standard scaffold for CI.
 *
 * @package JoinTheCause
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$_tests_dir}.\n";
	echo "See https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/ for setup instructions.\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/join-the-cause.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
