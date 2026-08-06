<?php
/**
 * Local index of actual Groups.io membership, synced periodically, with
 * the PMPro-expected-set comparison and the sticky manual-override flag.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the bits_groupsio_member_index table and the sync job that
 * populates it. Groups.io's API is subgroup-scoped (get_subgroups(),
 * get_members() for one subgroup at a time) - it has no member-centric
 * endpoint that can serve a single paginated, searchable "every member
 * across the parent group and all subgroups" view directly, which the
 * User Assignment admin pages need. This table is that local index; the
 * admin pages read from it, never live-aggregating across every subgroup
 * on a page load.
 *
 * Single responsibility: this class only maintains the index (schema,
 * sync, and the override flag's storage/auto-clear) - it does not itself
 * call direct_add()/remove_member() or render any admin UI. The queued
 * execution engine that performs adds/removes and updates this index's
 * rows on success lives in a later phase.
 */
final class MemberIndex {

	private const SYNC_HOOK = 'bits_groupsio_sync_member_index';

	/**
	 * Returns the fully-prefixed member-index table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'bits_groupsio_member_index';
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
	 * Creates (or updates) the member-index table via dbDelta.
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
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(254) NOT NULL,
			display_name VARCHAR(255) NOT NULL DEFAULT '',
			subgroup_id BIGINT UNSIGNED NOT NULL,
			subgroup_slug VARCHAR(255) NOT NULL,
			subgroup_title VARCHAR(255) NOT NULL DEFAULT '',
			pmpro_expected TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			override_type VARCHAR(20) NULL,
			override_by BIGINT UNSIGNED NULL,
			override_at DATETIME NULL,
			synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email_subgroup (email, subgroup_id),
			KEY user_id (user_id),
			KEY subgroup_id (subgroup_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Registers the recurring sync job and the PMPro level-change
	 * override-clearing hook. Called once from Plugin's constructor
	 * (on plugins_loaded).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::SYNC_HOOK, array( self::class, 'sync' ) );
		add_action( 'pmpro_after_all_membership_level_changes', array( self::class, 'clear_overrides_for_user' ) );

		// Action Scheduler's data store isn't initialized yet on
		// plugins_loaded (it finishes its own bootstrap by init) -
		// calling as_schedule_recurring_action() this early logs an
		// "incorrectly called" doing_it_wrong notice. Defer to init,
		// per Action Scheduler's own documented usage.
		add_action( 'init', array( self::class, 'maybe_schedule_sync' ) );
	}

	/**
	 * Schedules the recurring sync action if it isn't already scheduled.
	 * Bound to init, not called directly - see register().
	 *
	 * @return void
	 */
	public static function maybe_schedule_sync(): void {
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' )
			&& false === as_next_scheduled_action( self::SYNC_HOOK )
		) {
			as_schedule_recurring_action( time(), HOUR_IN_SECONDS, self::SYNC_HOOK, array(), '', false );
		}
	}

	/**
	 * The sync job itself: walks the parent group and every subgroup,
	 * upserting one row per (member, subgroup) pair to reflect actual
	 * current Groups.io membership, and recomputes each row's
	 * pmpro_expected flag. Every member is also indexed against the
	 * parent group itself (always expected), which is what gives the
	 * User Assignment List page's "including parent" group count its
	 * +1. Existing override_type/override_by/override_at values are
	 * left untouched - only clear_overrides_for_user() and the (later)
	 * queued execution engine write those columns.
	 *
	 * @return void
	 */
	public static function sync(): void {
		$parent_slug = self::parent_group();
		if ( '' === $parent_slug ) {
			return;
		}

		try {
			$parent = GroupsIoApiClient::get_group( $parent_slug );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return;
		}

		$parent_id = (int) ( $parent['id'] ?? 0 );
		if ( 0 === $parent_id ) {
			return;
		}

		self::sync_subgroup( $parent_id, $parent_slug, (string) ( $parent['title'] ?? $parent_slug ) );

		try {
			$subgroups = GroupsIoApiClient::get_subgroups( $parent_slug );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return;
		}

		foreach ( (array) ( $subgroups['data'] ?? array() ) as $subgroup ) {
			$subgroup_id = (int) ( $subgroup['id'] ?? 0 );
			if ( 0 === $subgroup_id ) {
				continue;
			}

			self::sync_subgroup(
				$subgroup_id,
				(string) ( $subgroup['name'] ?? '' ),
				(string) ( $subgroup['title'] ?? '' )
			);
		}
	}

	/**
	 * Syncs one subgroup's (or the parent group's) member list into the
	 * index. A single subgroup's lookup failure doesn't abort the whole
	 * sync - the next scheduled run retries it.
	 *
	 * @param int    $subgroup_id    Numeric Groups.io group/subgroup id.
	 * @param string $subgroup_slug  Full slug (parent, or parent+sub).
	 * @param string $subgroup_title Cosmetic title, if any.
	 * @return void
	 */
	private static function sync_subgroup( int $subgroup_id, string $subgroup_slug, string $subgroup_title ): void {
		try {
			$members = GroupsIoApiClient::get_members( $subgroup_id );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return;
		}

		foreach ( (array) ( $members['data'] ?? array() ) as $member ) {
			$email = (string) ( $member['email'] ?? '' );
			if ( '' === $email ) {
				continue;
			}

			$user    = get_user_by( 'email', $email );
			$user_id = $user ? (int) $user->ID : 0;

			self::upsert_row(
				$user_id,
				$email,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not exception output; member display-name field, escaped on render by whichever admin page reads it.
				(string) ( $member['full_name'] ?? '' ),
				$subgroup_id,
				$subgroup_slug,
				$subgroup_title,
				self::is_expected( $user_id, $subgroup_slug )
			);
		}
	}

	/**
	 * Whether a (user, subgroup) pairing is expected per the member's
	 * current PMPro level - the parent group is always expected; a
	 * global-mandatory or level-mandatory subgroup is expected for a
	 * user with a matched WP account and an active PMPro level; anything
	 * else (including any member with no matched WP account, since
	 * expected-set membership is inherently PMPro-derived) is not.
	 *
	 * @param int    $user_id       Matched WP user id, or 0 if none.
	 * @param string $subgroup_slug Full subgroup slug being checked.
	 * @return bool
	 */
	private static function is_expected( int $user_id, string $subgroup_slug ): bool {
		if ( self::parent_group() === $subgroup_slug ) {
			return true;
		}

		if ( in_array( $subgroup_slug, Settings::get( 'global_mandatory_groups' ), true ) ) {
			return true;
		}

		if ( 0 === $user_id || ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
			return false;
		}

		$level = pmpro_getMembershipLevelForUser( $user_id );
		if ( ! $level || empty( $level->id ) ) {
			return false;
		}

		return in_array( $subgroup_slug, LevelMandatoryGroups::get_for_level( (int) $level->id ), true );
	}

	/**
	 * Upserts one (member, subgroup) row, matching on the table's
	 * (email, subgroup_id) unique key. Deliberately does not touch the
	 * override_type/override_by/override_at columns - those are owned
	 * by clear_overrides_for_user() and the queued execution engine.
	 *
	 * @param int    $user_id        Matched WP user id, or 0 if none.
	 * @param string $email          Member's email address.
	 * @param string $display_name   Member's display name, if known.
	 * @param int    $subgroup_id    Numeric Groups.io group/subgroup id.
	 * @param string $subgroup_slug  Full slug.
	 * @param string $subgroup_title Cosmetic title, if any.
	 * @param bool   $pmpro_expected Whether this pairing is PMPro-expected.
	 * @return void
	 */
	private static function upsert_row(
		int $user_id,
		string $email,
		string $display_name,
		int $subgroup_id,
		string $subgroup_slug,
		string $subgroup_title,
		bool $pmpro_expected
	): void {
		global $wpdb;

		$table = self::table_name();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own fixed table name (self::table_name()), not user input.
			"INSERT INTO $table
			(user_id, email, display_name, subgroup_id, subgroup_slug, subgroup_title, pmpro_expected, synced_at)
			VALUES (%d, %s, %s, %d, %s, %s, %d, %s)
			ON DUPLICATE KEY UPDATE
				user_id = VALUES(user_id),
				display_name = VALUES(display_name),
				subgroup_slug = VALUES(subgroup_slug),
				subgroup_title = VALUES(subgroup_title),
				pmpro_expected = VALUES(pmpro_expected),
				synced_at = VALUES(synced_at)",
			$user_id,
			$email,
			$display_name,
			$subgroup_id,
			$subgroup_slug,
			$subgroup_title,
			$pmpro_expected ? 1 : 0,
			current_time( 'mysql', true )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- an upsert (INSERT ... ON DUPLICATE KEY UPDATE) has no $wpdb->insert()/update() equivalent; $sql was already built via $wpdb->prepare() above; this table isn't object-cached, matching AuditLog's own uncached direct-write convention.
		$wpdb->query( $sql );
	}

	/**
	 * Clears the sticky manual-override flag for every row belonging to
	 * a member whose PMPro level just changed - a fresh join/upgrade/
	 * downgrade supersedes a stale manual override. Bound to
	 * pmpro_after_all_membership_level_changes.
	 *
	 * @param int $user_id The member whose level changed.
	 * @return void
	 */
	public static function clear_overrides_for_user( int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this table isn't object-cached, matching AuditLog's own uncached direct-write convention.
		$wpdb->update(
			self::table_name(),
			array(
				'override_type' => null,
				'override_by'   => null,
				'override_at'   => null,
			),
			array( 'user_id' => $user_id )
		);
	}

	/**
	 * Reads the configured parent group slug.
	 *
	 * @return string
	 */
	private static function parent_group(): string {
		return defined( 'GROUPS_IO_PARENT_GROUP' ) ? GROUPS_IO_PARENT_GROUP : '';
	}
}
