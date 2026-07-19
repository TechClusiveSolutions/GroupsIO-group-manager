<?php
/**
 * Main plugin bootstrap class.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin bootstrap. Single responsibility: wire up the plugin's
 * subsystems on `plugins_loaded`; it does not itself contain sync,
 * audit, or admin-UI logic.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the singleton instance, creating it on first call.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor; use instance() instead.
	 */
	private function __construct() {
		// Subsystems (Groups.io client, sync engine, admin settings, magic
		// link, audit log reader) are registered here as later phases add them.
	}
}
