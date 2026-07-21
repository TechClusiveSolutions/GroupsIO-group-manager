<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\GroupsIoApiException;
use BITS\GroupsIOSync\SubgroupIdCache;
use WP_UnitTestCase;

final class SubgroupIdCacheTest extends WP_UnitTestCase {

	private const OPTION_NAME = 'bits_groupsio_subgroup_cache';

	private static int $call_count = 0;

	public function set_up(): void {
		parent::set_up();

		self::$call_count = 0;
		delete_option( self::OPTION_NAME );

		if ( ! defined( 'GROUPS_IO_API_KEY' ) ) {
			define( 'GROUPS_IO_API_KEY', 'fake-test-key-not-real' );
		}

		if ( ! defined( 'GROUPS_IO_PARENT_GROUP' ) ) {
			define( 'GROUPS_IO_PARENT_GROUP', 'bits' );
		}
	}

	/**
	 * Mocks getsubgroups to return the given slug => numeric id pairs.
	 *
	 * @param array<string, int> $subgroups Slug => id pairs to return.
	 */
	private function mock_subgroups_response( array $subgroups ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $subgroups ) {
				SubgroupIdCacheTest::$call_count++;

				$data = array();
				foreach ( $subgroups as $slug => $id ) {
					$data[] = array(
						'name' => $slug,
						'id'   => $id,
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'object' => 'list',
						'data'   => $data,
					) ),
					'headers'  => array(),
				);
			}
		);
	}

	public function test_cache_hit_returns_cached_id_without_calling_the_client(): void {
		update_option( self::OPTION_NAME, array( 'bits+psychology' => 152357 ) );

		$id = SubgroupIdCache::get_group_id( 'bits+psychology' );

		$this->assertSame( 152357, $id );
		$this->assertSame( 0, self::$call_count );
	}

	public function test_cache_miss_resolves_via_client_and_caches_the_result(): void {
		$this->mock_subgroups_response( array( 'bits+psychology' => 152357 ) );

		$first  = SubgroupIdCache::get_group_id( 'bits+psychology' );
		$second = SubgroupIdCache::get_group_id( 'bits+psychology' );

		$this->assertSame( 152357, $first );
		$this->assertSame( 152357, $second );
		$this->assertSame( 1, self::$call_count );
	}

	public function test_lookup_failure_propagates_and_does_not_cache(): void {
		$this->mock_subgroups_response( array( 'bits+other' => 1 ) );

		try {
			SubgroupIdCache::get_group_id( 'bits+psychology' );
			$this->fail( 'Expected GroupsIoApiException.' );
		} catch ( GroupsIoApiException $exception ) {
			$this->assertSame( 'group_not_found', $exception->get_error_type() );
		}

		$this->assertSame( array(), get_option( self::OPTION_NAME, array() ) );
	}

	public function test_invalidate_removes_only_the_targeted_slug(): void {
		$this->mock_subgroups_response( array(
			'bits+psychology' => 152357,
			'bits+sociology'  => 152360,
		) );

		SubgroupIdCache::get_group_id( 'bits+psychology' );
		SubgroupIdCache::get_group_id( 'bits+sociology' );

		SubgroupIdCache::invalidate( 'bits+psychology' );

		$cache = get_option( self::OPTION_NAME );
		$this->assertArrayNotHasKey( 'bits+psychology', $cache );
		$this->assertSame( 152360, $cache['bits+sociology'] );
	}

	public function test_refresh_all_re_resolves_every_cached_slug(): void {
		update_option(
			self::OPTION_NAME,
			array(
				'bits+psychology' => 111,
				'bits+sociology'  => 222,
			)
		);

		$this->mock_subgroups_response( array(
			'bits+psychology' => 999,
			'bits+sociology'  => 888,
		) );

		SubgroupIdCache::refresh_all();

		$cache = get_option( self::OPTION_NAME );
		$this->assertSame( 999, $cache['bits+psychology'] );
		$this->assertSame( 888, $cache['bits+sociology'] );
		$this->assertSame( 2, self::$call_count );
	}
}
