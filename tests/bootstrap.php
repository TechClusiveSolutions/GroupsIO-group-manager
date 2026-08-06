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
 * Loads Paid Memberships Pro (the composer/installers-placed dev
 * dependency, not a real plugins-directory install), so tests can
 * exercise this plugin's PMPro-dependent code against real PMPro
 * functions/objects rather than mocked hooks.
 *
 * @return void
 */
function bits_groupsio_sync_manually_load_pmpro(): void {
	global $wpdb;

	// PMPro's own top-level code queries its membership-levels table as
	// soon as its main file loads, before pmpro_db_delta() below has had
	// a chance to create that table - suppressed here since it's a
	// one-time, harmless bootstrap-ordering artifact, not a real error.
	$wpdb->suppress_errors( true );
	require dirname( __DIR__ ) . '/vendor/wordpress-plugins/paid-memberships-pro/paid-memberships-pro.php';
	$wpdb->suppress_errors( false );

	// Loading the plugin file alone doesn't create its DB tables - that
	// normally happens via its activation hook, which doesn't fire just
	// from requiring the file in a test bootstrap. pmpro_db_delta() is
	// the function it uses internally for exactly this (its own
	// activation handler and upgrade routine both call it).
	pmpro_db_delta();
}
tests_add_filter( 'muplugins_loaded', 'bits_groupsio_sync_manually_load_pmpro' );

/**
 * Loads the plugin under test.
 */
function bits_groupsio_sync_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/group-manager.php';
}
tests_add_filter( 'muplugins_loaded', 'bits_groupsio_sync_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
