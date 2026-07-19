<?php
/**
 * Audit log table schema and creation.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the bits_groupsio_audit table: schema and creation only in this
 * phase. Recording/reading audit entries is added in later phases
 * alongside the sync engine that produces them.
 */
final class AuditLog {

	/**
	 * Returns the fully-prefixed audit table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'bits_groupsio_audit';
	}

	/**
	 * Runs on plugin activation via register_activation_hook.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_table();
	}

	/**
	 * Creates (or updates) the audit table via dbDelta.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires each column on its own line, two spaces before
		// PRIMARY KEY, and no backticks around field names.
		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			target_email VARCHAR(254) NOT NULL,
			subgroup_id VARCHAR(64) NOT NULL,
			action VARCHAR(20) NOT NULL,
			outcome VARCHAR(20) NOT NULL,
			api_response_detail TEXT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql );
	}
}
