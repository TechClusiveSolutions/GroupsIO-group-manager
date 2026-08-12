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
 * call direct_add()/remove_member() or render any admin UI. QueuedExecutionEngine
 * performs the actual adds/removes and calls apply_add()/apply_remove()
 * below on success to update this index's rows and override columns.
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
			member_info_id BIGINT UNSIGNED NULL,
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
				isset( $member['id'] ) ? (int) $member['id'] : null,
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
	 * @param int      $user_id        Matched WP user id, or 0 if none.
	 * @param string   $email          Member's email address.
	 * @param string   $display_name   Member's display name, if known.
	 * @param int      $subgroup_id    Numeric Groups.io group/subgroup id.
	 * @param string   $subgroup_slug  Full slug.
	 * @param string   $subgroup_title Cosmetic title, if any.
	 * @param int|null $member_info_id Groups.io's per-membership-record id for this (email, subgroup) pairing, or null if unknown.
	 * @param bool     $pmpro_expected Whether this pairing is PMPro-expected.
	 * @return void
	 */
	private static function upsert_row(
		int $user_id,
		string $email,
		string $display_name,
		int $subgroup_id,
		string $subgroup_slug,
		string $subgroup_title,
		?int $member_info_id,
		bool $pmpro_expected
	): void {
		global $wpdb;

		$table = self::table_name();

		// wpdb::prepare()'s %d placeholder casts null to 0, not SQL NULL,
		// so a genuinely unknown member_info_id (older synced rows, or a
		// getmembers() response missing 'id') needs a literal NULL
		// placeholder instead - the value itself is always either null or
		// an int we produced ourselves (never user input), so this literal
		// substitution carries no injection risk.
		$member_info_id_sql = null === $member_info_id ? 'NULL' : (string) $member_info_id;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own fixed table name (self::table_name()); $member_info_id_sql is either the literal 'NULL' or a cast-to-string int, not user input. Both are interpolated across this multi-line SQL string, so the ignore is scoped to the whole statement below rather than a single line.
		$sql = $wpdb->prepare(
			"INSERT INTO $table
			(user_id, email, display_name, subgroup_id, subgroup_slug, subgroup_title, member_info_id, pmpro_expected, synced_at)
			VALUES (%d, %s, %s, %d, %s, %s, $member_info_id_sql, %d, %s)
			ON DUPLICATE KEY UPDATE
				user_id = VALUES(user_id),
				display_name = VALUES(display_name),
				subgroup_slug = VALUES(subgroup_slug),
				subgroup_title = VALUES(subgroup_title),
				member_info_id = VALUES(member_info_id),
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- an upsert (INSERT ... ON DUPLICATE KEY UPDATE) has no $wpdb->insert()/update() equivalent; $sql was already built via $wpdb->prepare() above; this table isn't object-cached, matching AuditLog's own uncached direct-write convention.
		$wpdb->query( $sql );
	}

	/**
	 * Called by QueuedExecutionEngine on a successful add. Upserts the
	 * (member, subgroup) row so it reflects the new membership even
	 * before the next hourly sync confirms it, then sets the sticky
	 * override flag. member_info_id is left null here — Groups.io's
	 * directadd response doesn't return it, so it's backfilled by the
	 * next sync() run.
	 *
	 * @param int    $user_id        Matched WP user id of the member added, or 0 if none.
	 * @param string $email          Member's email address.
	 * @param string $display_name   Member's display name, if known.
	 * @param int    $subgroup_id    Numeric Groups.io group/subgroup id added to.
	 * @param string $subgroup_slug  Full slug.
	 * @param string $subgroup_title Cosmetic title, if any.
	 * @param int    $admin_user_id  WP user id of the admin who queued the action.
	 * @return void
	 */
	public static function apply_add(
		int $user_id,
		string $email,
		string $display_name,
		int $subgroup_id,
		string $subgroup_slug,
		string $subgroup_title,
		int $admin_user_id
	): void {
		self::upsert_row(
			$user_id,
			$email,
			$display_name,
			$subgroup_id,
			$subgroup_slug,
			$subgroup_title,
			null,
			self::is_expected( $user_id, $subgroup_slug )
		);

		self::set_override( $email, $subgroup_id, 'added', $admin_user_id );
	}

	/**
	 * Called by QueuedExecutionEngine on a successful remove. Sets the
	 * sticky override flag on the existing (member, subgroup) row - the
	 * row itself is left in place (not deleted) since it still carries
	 * the override state the Details view needs to display, and the row
	 * will be corrected or cleaned up on the next sync() run.
	 *
	 * @param string $email         Member's email address.
	 * @param int    $subgroup_id   Numeric Groups.io group/subgroup id removed from.
	 * @param int    $admin_user_id WP user id of the admin who queued the action.
	 * @return void
	 */
	public static function apply_remove( string $email, int $subgroup_id, int $admin_user_id ): void {
		self::set_override( $email, $subgroup_id, 'removed', $admin_user_id );
	}

	/**
	 * Writes the sticky override columns for one (email, subgroup) row.
	 *
	 * @param string $email         Member's email address.
	 * @param int    $subgroup_id   Numeric Groups.io group/subgroup id.
	 * @param string $override_type 'added' or 'removed'.
	 * @param int    $admin_user_id WP user id of the admin who queued the action.
	 * @return void
	 */
	private static function set_override( string $email, int $subgroup_id, string $override_type, int $admin_user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this table isn't object-cached, matching AuditLog's own uncached direct-write convention.
		$wpdb->update(
			self::table_name(),
			array(
				'override_type' => $override_type,
				'override_by'   => $admin_user_id,
				'override_at'   => current_time( 'mysql', true ),
			),
			array(
				'email'       => $email,
				'subgroup_id' => $subgroup_id,
			)
		);
	}

	/**
	 * Clears the sticky manual-override flag for a single (email,
	 * subgroup) row - the single-row counterpart to
	 * clear_overrides_for_user() below, which clears every row for a
	 * user on a PMPro level change. Used by the User Assignment Details
	 * view's synchronous "Clear override" control: a pure local write
	 * with no Groups.io API call and nothing meaningfully to retry, per
	 * subgroup-crud-and-admin-pages-design.md section 12.
	 *
	 * @param string $email       Member's email address.
	 * @param int    $subgroup_id Numeric Groups.io group/subgroup id.
	 * @return void
	 */
	public static function clear_override( string $email, int $subgroup_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this table isn't object-cached, matching AuditLog's own uncached direct-write convention.
		$wpdb->update(
			self::table_name(),
			array(
				'override_type' => null,
				'override_by'   => null,
				'override_at'   => null,
			),
			array(
				'email'       => $email,
				'subgroup_id' => $subgroup_id,
			)
		);
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
	 * Reads the member_info_id stored for one (email, subgroup) row, for
	 * QueuedExecutionEngine's remove job to pass to
	 * GroupsIoApiClient::remove_member(). Returns null if no matching
	 * row exists, or the row's member_info_id itself is null (not yet
	 * backfilled by a sync() run).
	 *
	 * @param string $email       Member's email address.
	 * @param int    $subgroup_id Numeric Groups.io group/subgroup id.
	 * @return int|null
	 */
	public static function get_member_info_id( string $email, int $subgroup_id ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this table isn't object-cached, matching AuditLog's own uncached direct-write convention.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table_name() is our own fixed table name, not user input.
				'SELECT member_info_id FROM ' . self::table_name() . ' WHERE email = %s AND subgroup_id = %d',
				$email,
				$subgroup_id
			)
		);

		return null === $value ? null : (int) $value;
	}

	/**
	 * Reads the display name stored for one member (the MAX() across
	 * their rows, matching get_members_page()'s own aggregation, since
	 * every row for one email carries the same display_name from the
	 * last sync() run). Returns '' if no row exists yet or none carries
	 * a display name.
	 *
	 * @param string $email Member's email address.
	 * @return string
	 */
	public static function get_display_name( string $email ): string {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own fixed table name, not user input.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(display_name) FROM $table WHERE email = %s",
				$email
			)
		);
		// phpcs:enable

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Returns one page of a single member's currently subscribed groups
	 * (parent + subgroups), for the User Assignment Details view, per
	 * subgroup-crud-and-admin-pages-design.md section 12. Excludes any
	 * row carrying override_type = 'removed' - an explicitly-removed row
	 * no longer counts as "currently subscribed" even before the next
	 * hourly sync corrects it, since a member an admin just removed
	 * shouldn't still appear as subscribed on this same page. A search
	 * term matches subgroup_slug/subgroup_title, the same convention the
	 * List view's subgroup-name matching uses.
	 *
	 * @param string $email    Member's email address.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Optional search term.
	 * @return array<int, array{subgroup_id: int, subgroup_slug: string, subgroup_title: string, pmpro_expected: bool, override_type: string|null}>
	 */
	public static function get_member_groups( string $email, int $page, int $per_page, string $search = '' ): array {
		global $wpdb;

		$table  = self::table_name();
		$offset = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

		list( $where_sql, $params ) = self::member_groups_where( $email, $search );

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is our own fixed table name; $where_sql is a fixed fragment built by member_groups_where() containing only placeholders, filled via $params below.
		$sql = $wpdb->prepare(
			"SELECT subgroup_id, subgroup_slug, subgroup_title, pmpro_expected, override_type
			FROM $table
			WHERE $where_sql
			ORDER BY subgroup_title ASC, subgroup_slug ASC
			LIMIT %d OFFSET %d",
			$params
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql was already built via $wpdb->prepare() above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return array(
					'subgroup_id'    => (int) $row['subgroup_id'],
					'subgroup_slug'  => (string) $row['subgroup_slug'],
					'subgroup_title' => (string) $row['subgroup_title'],
					'pmpro_expected' => (bool) $row['pmpro_expected'],
					'override_type'  => null === $row['override_type'] ? null : (string) $row['override_type'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Total number of a single member's currently subscribed groups,
	 * optionally filtered by a search term - see get_member_groups()
	 * above for the shared exclusion/search semantics.
	 *
	 * @param string $email  Member's email address.
	 * @param string $search Optional search term.
	 * @return int
	 */
	public static function count_member_groups( string $email, string $search = '' ): int {
		global $wpdb;

		$table = self::table_name();

		list( $where_sql, $params ) = self::member_groups_where( $email, $search );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is our own fixed table name; $where_sql is a fixed fragment built by member_groups_where() containing only placeholders, filled via $params below.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE $where_sql",
			$params
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql was already built via $wpdb->prepare() above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Builds the shared WHERE fragment (with %s/%d placeholders) for
	 * get_member_groups()/count_member_groups() above: one member's rows,
	 * excluding any 'removed' override, optionally further filtered by a
	 * subgroup slug/title search term.
	 *
	 * @param string $email  Member's email address.
	 * @param string $search Optional search term.
	 * @return array{0: string, 1: array<int, string>} WHERE SQL fragment and its placeholder values, in order.
	 */
	private static function member_groups_where( string $email, string $search ): array {
		global $wpdb;

		$where  = "email = %s AND ( override_type IS NULL OR override_type != 'removed' )";
		$params = array( $email );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( subgroup_slug LIKE %s OR subgroup_title LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		return array( $where, $params );
	}

	/**
	 * Returns one page of groups (parent + subgroups) a member is *not*
	 * currently in, for the User Assignment Add Groups view, per
	 * subgroup-crud-and-admin-pages-design.md section 12. "Every group"
	 * here means every distinct subgroup_id known anywhere in the local
	 * index - a subgroup with no members at all yet (never synced with
	 * anyone in it) won't appear until it has at least one. A member's
	 * row with override_type = 'removed' counts as *not* currently in
	 * that group (the complement of get_member_groups()'s own
	 * exclusion), so a manually-removed group correctly reappears here
	 * as addable again.
	 *
	 * @param string $email    Member's email address.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Optional search term.
	 * @return array<int, array{subgroup_id: int, subgroup_slug: string, subgroup_title: string}>
	 */
	public static function get_addable_groups( string $email, int $page, int $per_page, string $search = '' ): array {
		global $wpdb;

		$table  = self::table_name();
		$offset = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

		list( $where_sql, $params ) = self::addable_groups_where( $email, $search );

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is our own fixed table name; $where_sql is a fixed fragment built by addable_groups_where() containing only placeholders, filled via $params below.
		$sql = $wpdb->prepare(
			"SELECT subgroup_id, MAX(subgroup_slug) AS subgroup_slug, MAX(subgroup_title) AS subgroup_title
			FROM $table
			WHERE $where_sql
			GROUP BY subgroup_id
			ORDER BY MAX(subgroup_title) ASC, MAX(subgroup_slug) ASC
			LIMIT %d OFFSET %d",
			$params
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql was already built via $wpdb->prepare() above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return array(
					'subgroup_id'    => (int) $row['subgroup_id'],
					'subgroup_slug'  => (string) $row['subgroup_slug'],
					'subgroup_title' => (string) $row['subgroup_title'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Total number of groups a member is not currently in, optionally
	 * filtered by a search term - see get_addable_groups() above for the
	 * shared exclusion/search semantics.
	 *
	 * @param string $email  Member's email address.
	 * @param string $search Optional search term.
	 * @return int
	 */
	public static function count_addable_groups( string $email, string $search = '' ): int {
		global $wpdb;

		$table = self::table_name();

		list( $where_sql, $params ) = self::addable_groups_where( $email, $search );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is our own fixed table name; $where_sql is a fixed fragment built by addable_groups_where() containing only placeholders, filled via $params below.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM ( SELECT subgroup_id FROM $table WHERE $where_sql GROUP BY subgroup_id ) addable",
			$params
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql was already built via $wpdb->prepare() above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Builds the shared WHERE fragment (with %s placeholders) for
	 * get_addable_groups()/count_addable_groups() above: every group in
	 * the index the member does not currently count as subscribed to
	 * (the complement of member_groups_where()'s own exclusion),
	 * optionally further filtered by a subgroup slug/title search term.
	 *
	 * @param string $email  Member's email address.
	 * @param string $search Optional search term.
	 * @return array{0: string, 1: array<int, string>} WHERE SQL fragment and its placeholder values, in order.
	 */
	private static function addable_groups_where( string $email, string $search ): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own fixed table name, not user input.
		$where  = "subgroup_id NOT IN ( SELECT subgroup_id FROM $table WHERE email = %s AND ( override_type IS NULL OR override_type != 'removed' ) )";
		$params = array( $email );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( subgroup_slug LIKE %s OR subgroup_title LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		return array( $where, $params );
	}

	/**
	 * Total number of distinct members in the index, optionally filtered
	 * by a search term matched against member name/email or subgroup
	 * name/slug/title. Backs the User Assignment List page's pagination,
	 * per subgroup-crud-and-admin-pages-design.md section 12.
	 *
	 * @param string $search Optional search term.
	 * @return int
	 */
	public static function count_members( string $search = '' ): int {
		global $wpdb;

		$table = self::table_name();

		if ( '' === $search ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own fixed table name (self::table_name()), not user input; there is no other value to prepare in this branch.
			return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT email) FROM $table" );
			// phpcs:enable
		}

		list( $where_sql, $params ) = self::search_where( $search );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is our own fixed table name; $where_sql is a fixed fragment built by search_where() containing only placeholders, filled by $params below.
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT email) FROM $table WHERE email IN ( SELECT DISTINCT email FROM $table WHERE $where_sql )",
			$params
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql was already built via $wpdb->prepare() above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Returns one page of distinct members for the User Assignment List
	 * page, each as {email, display_name, group_count} - group_count is
	 * the member's total *currently subscribed* subgroup-membership
	 * count (parent + subgroups) across the whole index, not just rows
	 * matching a search term. Excludes any row carrying
	 * override_type = 'removed', matching get_member_groups()'s own
	 * "currently subscribed" definition - a member who has been
	 * manually removed from a group must not still count it here (a
	 * member removed from everything correctly shows group_count 0, not
	 * a stale count of rows that no longer reflect real membership). A
	 * search term matching a subgroup name/slug/title still returns the
	 * member's full group count, per section 12's "matches against
	 * member name, email, or subgroup name/slug" acceptance criterion.
	 *
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Optional search term.
	 * @return array<int, array{email: string, display_name: string, group_count: int}>
	 */
	public static function get_members_page( int $page, int $per_page, string $search = '' ): array {
		global $wpdb;

		$table  = self::table_name();
		$offset = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

		if ( '' === $search ) {
			$where_sql = '1=1';
			$params    = array();
		} else {
			list( $sub_where_sql, $sub_params ) = self::search_where( $search );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own fixed table name; $sub_where_sql is a fixed fragment built by search_where() containing only placeholders, filled via $sub_params below.
			$where_sql = "email IN ( SELECT DISTINCT email FROM $table WHERE $sub_where_sql )";
			$params    = $sub_params;
		}

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is our own fixed table name; $where_sql is a fixed fragment (either the literal '1=1' or search_where()'s placeholder-only output); both are interpolated across this multi-line SQL string, so the disable is scoped to the whole statement, matching upsert_row()'s own convention above.
		$sql = $wpdb->prepare(
			"SELECT email, MAX(display_name) AS display_name,
				COUNT(DISTINCT CASE WHEN override_type IS NULL OR override_type != 'removed' THEN subgroup_id END) AS group_count
			FROM $table
			WHERE $where_sql
			GROUP BY email
			ORDER BY MAX(display_name) ASC, email ASC
			LIMIT %d OFFSET %d",
			$params
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql was already built via $wpdb->prepare() above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return array(
					'email'        => (string) $row['email'],
					'display_name' => (string) $row['display_name'],
					'group_count'  => (int) $row['group_count'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Builds a WHERE fragment (with %s placeholders) matching a search
	 * term against member email/display_name or subgroup
	 * slug/title, for count_members()/get_members_page() above.
	 *
	 * @param string $search Search term (already non-empty).
	 * @return array{0: string, 1: array<int, string>} WHERE SQL fragment and its placeholder values, in order.
	 */
	private static function search_where( string $search ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		return array(
			'( email LIKE %s OR display_name LIKE %s OR subgroup_slug LIKE %s OR subgroup_title LIKE %s )',
			array( $like, $like, $like, $like ),
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
