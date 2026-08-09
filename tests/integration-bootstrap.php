<?php
/**
 * PHPUnit bootstrap for the integration suite: same WordPress core test
 * bootstrap as tests/bootstrap.php, plus the real Groups.io credentials
 * (never hardcoded — read from environment, set as a GitHub Actions
 * environment secret in CI, or exported locally for a manual run).
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

if ( ! defined( 'GROUPS_IO_API_KEY' ) && getenv( 'GROUPS_IO_TEST_API_KEY' ) ) {
	define( 'GROUPS_IO_API_KEY', getenv( 'GROUPS_IO_TEST_API_KEY' ) );
}

if ( ! defined( 'GROUPS_IO_PARENT_GROUP' ) && getenv( 'GROUPS_IO_TEST_PARENT_GROUP' ) ) {
	define( 'GROUPS_IO_PARENT_GROUP', getenv( 'GROUPS_IO_TEST_PARENT_GROUP' ) );
}

/**
 * Loads the plugin under test.
 */
function bits_groupsio_sync_integration_load_plugin(): void {
	require dirname( __DIR__ ) . '/group-manager.php';

	// See the matching comment in tests/bootstrap.php - Settings' audit
	// hooks are registered once here for the whole suite, so the audit
	// table must always exist, same as it does in real usage from
	// plugin activation onward.
	\BITS\GroupsIOSync\AuditLog::create_table();
}
tests_add_filter( 'muplugins_loaded', 'bits_groupsio_sync_integration_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
