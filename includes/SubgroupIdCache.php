<?php
/**
 * Slug-to-group_id cache for Groups.io subgroups.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caches the numeric group_id Groups.io requires for member-level
 * operations (PRD section 4.3), keyed by subgroup slug. This cache is
 * operational/derived data, not a source of truth — Groups.io itself
 * remains the source of truth, resolved via GroupsIoApiClient on a miss
 * or explicit invalidation. Not time-based: PRD section 4.3 describes
 * only explicit invalidation triggers, not TTL expiry.
 */
final class SubgroupIdCache {

	private const OPTION_NAME = 'bits_groupsio_subgroup_cache';

	/**
	 * Returns a subgroup's numeric group_id, resolving and caching it on
	 * a miss.
	 *
	 * @param string $slug Subgroup slug ("parent+subgroup" name-string form).
	 * @return int
	 *
	 * @throws GroupsIoApiException Propagated from the client on lookup failure.
	 * @throws GroupsIoTransportException Propagated from the client on a transport failure.
	 */
	public static function get_group_id( string $slug ): int {
		$cache = self::read_cache();

		if ( isset( $cache[ $slug ] ) ) {
			return (int) $cache[ $slug ];
		}

		$group_id = self::resolve( $slug );

		$cache[ $slug ] = $group_id;
		self::write_cache( $cache );

		return $group_id;
	}

	/**
	 * Removes a single slug from the cache. Called by a caller that
	 * caught a group_not_found error on a previously-cached ID (PRD
	 * section 4.3's first refresh trigger) — this class does not catch
	 * that exception itself; deciding when a failure means "this ID is
	 * stale" belongs to the caller that made the failing call.
	 *
	 * @param string $slug Subgroup slug to remove from the cache.
	 * @return void
	 */
	public static function invalidate( string $slug ): void {
		$cache = self::read_cache();

		unset( $cache[ $slug ] );

		self::write_cache( $cache );
	}

	/**
	 * Re-resolves every currently-cached slug, overwriting the stored
	 * mapping. Called by the Phase 5 nightly reconciliation job (PRD
	 * section 4.3's second refresh trigger); nothing in Phase 2 itself
	 * invokes this yet, since that job doesn't exist until Phase 5.
	 *
	 * @return void
	 */
	public static function refresh_all(): void {
		$cache     = self::read_cache();
		$refreshed = array();

		foreach ( array_keys( $cache ) as $slug ) {
			$refreshed[ $slug ] = self::resolve( $slug );
		}

		self::write_cache( $refreshed );
	}

	/**
	 * Resolves a slug to its numeric group_id via the API client, by
	 * matching against the configured parent group's subgroup listing.
	 * Assumes get_subgroups() returns the full listing in one
	 * unpaginated response, appropriate for the small subgroup counts
	 * expected at BITS's scale — revisit if pagination (has_more /
	 * next_page_token in the response) becomes necessary.
	 *
	 * @param string $slug Subgroup slug.
	 * @return int
	 *
	 * @throws GroupsIoApiException If no subgroup with this slug is found.
	 */
	private static function resolve( string $slug ): int {
		$parent_group = defined( 'GROUPS_IO_PARENT_GROUP' ) ? GROUPS_IO_PARENT_GROUP : '';

		$subgroups = GroupsIoApiClient::get_subgroups( $parent_group );

		foreach ( (array) ( $subgroups['data'] ?? array() ) as $subgroup ) {
			if ( isset( $subgroup['name'], $subgroup['id'] ) && $slug === $subgroup['name'] ) {
				return (int) $subgroup['id'];
			}
		}

		throw new GroupsIoApiException( 'group_not_found', $slug );
	}

	/**
	 * @return array<string, int>
	 */
	private static function read_cache(): array {
		$cache = get_option( self::OPTION_NAME, array() );

		return is_array( $cache ) ? $cache : array();
	}

	/**
	 * @param array<string, int> $cache Full slug => group_id mapping to persist.
	 * @return void
	 */
	private static function write_cache( array $cache ): void {
		update_option( self::OPTION_NAME, $cache );
	}
}
