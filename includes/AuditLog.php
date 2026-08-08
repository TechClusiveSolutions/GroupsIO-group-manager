<?php
/**
 * Audit log table schema, creation, and recording.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the bits_groupsio_audit table: schema, creation, and recording.
 * Reading/displaying entries is a later phase's per-member audit view.
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

	/**
	 * Records one audit entry for an add/remove attempt, on either a
	 * success or failure outcome.
	 *
	 * @param string $action              e.g. 'add', 'remove'.
	 * @param string $outcome             e.g. 'success', 'failure'.
	 * @param string $target_email        Email of the member acted on.
	 * @param string $subgroup_id         Groups.io subgroup identifier acted on.
	 * @param int    $user_id             WP user id of the admin/process that initiated the action (0 if none).
	 * @param string $api_response_detail Raw Groups.io API response detail, if any.
	 * @return void
	 */
	public static function record(
		string $action,
		string $outcome,
		string $target_email,
		string $subgroup_id,
		int $user_id,
		string $api_response_detail = ''
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this table isn't object-cached, matching this file's own uncached direct-write convention.
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'          => current_time( 'mysql', true ),
				'user_id'             => $user_id,
				'target_email'        => $target_email,
				'subgroup_id'         => $subgroup_id,
				'action'              => $action,
				'outcome'             => $outcome,
				'api_response_detail' => $api_response_detail,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
