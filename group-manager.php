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

// composer.json requires woocommerce/action-scheduler, but that package's
// own composer.json declares "type": "wordpress-plugin" - this project's
// own installer-paths config (see composer.json's "extra" section)
// redirects any "wordpress-plugin"-typed package to vendor/wordpress-plugins/,
// not composer's usual vendor/<vendor-name>/ layout. See #114.
if ( file_exists( BITS_GROUPSIO_SYNC_DIR . 'vendor/wordpress-plugins/action-scheduler/action-scheduler.php' ) ) {
	require_once BITS_GROUPSIO_SYNC_DIR . 'vendor/wordpress-plugins/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( 'BITS\GroupsIOSync\AuditLog', 'activate' ) );
register_activation_hook( __FILE__, array( 'BITS\GroupsIOSync\MemberIndex', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\BITS\GroupsIOSync\Plugin::instance();
	}
);
