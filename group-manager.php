<?php
/**
 * Plugin bootstrap file.
 *
 * @package BITS\GroupsIOSync
 */

/**
 * Plugin Name:       BITS Groups.io Membership Sync
 * Plugin URI:        https://github.com/TechClusiveSolutions/GroupsIO-group-manager
 * Description:       Automates BITS Groups.io mailing list membership from Paid Memberships Pro status.
 * Version:           0.1.0
 * Requires PHP:      8.0
 * Author:            TechClusive Solutions
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       bits-groupsio-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

define( 'BITS_GROUPSIO_SYNC_VERSION', '0.1.0' );
define( 'BITS_GROUPSIO_SYNC_FILE', __FILE__ );
define( 'BITS_GROUPSIO_SYNC_DIR', plugin_dir_path( __FILE__ ) );

if ( file_exists( BITS_GROUPSIO_SYNC_DIR . 'vendor/autoload.php' ) ) {
	require_once BITS_GROUPSIO_SYNC_DIR . 'vendor/autoload.php';
}

if ( file_exists( BITS_GROUPSIO_SYNC_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once BITS_GROUPSIO_SYNC_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( 'BITS\GroupsIOSync\AuditLog', 'activate' ) );
register_activation_hook( __FILE__, array( 'BITS\GroupsIOSync\MemberIndex', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\BITS\GroupsIOSync\Plugin::instance();
	}
);
