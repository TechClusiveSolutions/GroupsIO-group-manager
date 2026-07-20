<?php
/**
 * PHPUnit bootstrap: loads the WordPress core test suite, then this plugin.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin under test.
 */
function bits_groupsio_sync_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/group-manager.php';
}
tests_add_filter( 'muplugins_loaded', 'bits_groupsio_sync_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
