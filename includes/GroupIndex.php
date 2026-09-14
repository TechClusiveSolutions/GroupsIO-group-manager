<?php
/**
 * Local index of every group (parent + subgroups), one row per group.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the bits_groupsio_group_index table - schema only. Per
 * docs/group-membership-index-design.md section 3.1, this is the first
 * of three tables (GroupIndex, GroupMemberships, repurposed MemberIndex)
 * splitting the current single denormalized MemberIndex table apart so
 * each entity (group, membership pairing, member) tracks its own sync
 * status against Groups.io/PMPro independently. Read/write/sync logic is
 * intentionally deferred to later child issues under #116 - this class
 * only creates and self-heals the table, mirroring MemberIndex's own
 * create_table()/ensure_table()/SCHEMA_VERSION pattern.
 *
 * Single responsibility: schema only.
 */
final class GroupIndex {

	// Bumped whenever create_table()'s schema changes, so ensure_table()
	// (self-heal check, see register()) knows to re-run dbDelta() even
	// when the table already exists (e.g. after adding a column) - not
	// just when it's missing entirely.
	private const SCHEMA_VERSION = '1';

	private const SCHEMA_VERSION_OPTION = 'bits_groupsio_group_index_schema_version';

	/**
	 * Returns the fully-prefixed group-index table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'bits_groupsio_group_index';
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
	 * Creates (or updates) the group-index table via dbDelta.
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
			subgroup_id BIGINT UNSIGNED NOT NULL,
			slug VARCHAR(255) NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT NULL,
			is_parent TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			sync_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			modified_at DATETIME NOT NULL,
			synced_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subgroup_id (subgroup_id)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Self-heals the group-index table on every request, since
	 * register_activation_hook is not a fully reliable guarantee that
	 * create_table() actually ran - see MemberIndex::ensure_table()'s
	 * docblock (issue #108) for the confirmed real-world failure mode
	 * this guards against. The stored option is checked first so the
	 * common case (table already present, current schema) costs one
	 * autoloaded option read, not a query - only a version mismatch or
	 * missing option falls through to create_table(); dbDelta() is
	 * idempotent, so re-running it is always safe.
	 *
	 * @return void
	 */
	public static function ensure_table(): void {
		if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION ) ) {
			return;
		}

		// Version mismatch (including "never set", e.g. an install
		// predating this table, or the version option itself somehow
		// not surviving a partial activation) - re-running is always
		// safe, dbDelta() only adds/alters what's actually different
		// and never drops data.
		self::create_table();
	}

	/**
	 * Registers the self-heal check. Called once from Plugin's
	 * constructor (on plugins_loaded). No sync job or read/write logic
	 * yet - deferred to later child issues under #116.
	 *
	 * @return void
	 */
	public static function register(): void {
		self::ensure_table();
	}
}
