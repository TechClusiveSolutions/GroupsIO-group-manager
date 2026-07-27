<?php

namespace BITS\GroupsIOSync\Tests\Integration;

use BITS\GroupsIOSync\GroupsIoApiClient;
use BITS\GroupsIOSync\GroupsIoApiException;
use WP_UnitTestCase;

/**
 * Permanent integration test exercising the full subgroup lifecycle
 * against the real test Groups.io group: create two subgroups, add three
 * emails to both, remove all three, delete both subgroups — with a
 * get_members()/get_subgroups() read-back after every step, per this
 * project's "verify live, don't just trust the response" practice.
 *
 * Requires GROUPS_IO_API_KEY and GROUPS_IO_PARENT_GROUP to be set (via
 * tests/integration-bootstrap.php, from the GROUPS_IO_TEST_API_KEY /
 * GROUPS_IO_TEST_PARENT_GROUP environment variables). Skips itself if
 * they are not present, so a manual local run without the real
 * credential does not fail — in CI, the groupsio-test-group environment
 * secret guarantees they are always set.
 */
final class SubgroupLifecycleIntegrationTest extends WP_UnitTestCase {

	private const TEST_EMAILS = array(
		'dev+integration-a@techclusivesolutions.com',
		'dev+integration-b@techclusivesolutions.com',
		'dev+integration-c@techclusivesolutions.com',
	);

	/** @var array<int, int> Subgroup IDs created during the test, for cleanup. */
	private array $created_subgroup_ids = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'GROUPS_IO_API_KEY' ) || ! defined( 'GROUPS_IO_PARENT_GROUP' ) ) {
			$this->markTestSkipped( 'GROUPS_IO_TEST_API_KEY / GROUPS_IO_TEST_PARENT_GROUP not set — skipping live integration test.' );
		}
	}

	public function tear_down(): void {
		// Best-effort cleanup even if an assertion above failed mid-run,
		// so a partial failure never leaves orphaned test subgroups.
		foreach ( $this->created_subgroup_ids as $subgroup_id ) {
			try {
				GroupsIoApiClient::remove_subgroup( $subgroup_id );
			} catch ( \Throwable $exception ) {
				// Already gone, or genuinely failed to clean up — nothing
				// further we can safely do from a tear_down().
			}
		}

		parent::tear_down();
	}

	public function test_full_subgroup_lifecycle_against_real_test_group(): void {
		$run_id = (string) time();
		$subgroup_names = array(
			'integration-test-a-' . $run_id,
			'integration-test-b-' . $run_id,
		);

		// 1. Create two test subgroups.
		$subgroup_ids = array();

		foreach ( $subgroup_names as $name ) {
			try {
				$created = GroupsIoApiClient::create_subgroup( GROUPS_IO_PARENT_GROUP, $name, 'Created by the permanent subgroup lifecycle integration test.' );
			} catch ( GroupsIoApiException $exception ) {
				$this->fail( sprintf( 'create_subgroup(%s) failed: %s (extra: %s)', $name, $exception->get_error_type(), $exception->get_extra() ) );
			}

			$this->assertArrayHasKey( 'id', $created, "create_subgroup() response missing 'id' for {$name}." );

			$subgroup_id = (int) $created['id'];
			$subgroup_ids[]                = $subgroup_id;
			$this->created_subgroup_ids[]   = $subgroup_id;
		}

		// Read-back: both subgroups actually exist on Groups.io.
		$listed = GroupsIoApiClient::get_subgroups( GROUPS_IO_PARENT_GROUP );
		$listed_ids = array_column( $listed['data'] ?? array(), 'id' );

		foreach ( $subgroup_ids as $subgroup_id ) {
			$this->assertContains( $subgroup_id, $listed_ids, "Created subgroup {$subgroup_id} not found in get_subgroups() read-back." );
		}

		// 2. Add three test emails to both subgroups.
		GroupsIoApiClient::direct_add( GROUPS_IO_PARENT_GROUP, self::TEST_EMAILS, $subgroup_ids );

		// Read-back: every email is now a member of every subgroup.
		$member_info_ids = array();

		foreach ( $subgroup_ids as $subgroup_id ) {
			$members = GroupsIoApiClient::get_members( $subgroup_id );
			$member_records = $members['data'] ?? array();
			$emails_present = array_column( $member_records, 'email' );

			foreach ( self::TEST_EMAILS as $email ) {
				$this->assertContains( $email, $emails_present, "{$email} not found in subgroup {$subgroup_id} after direct_add()." );
			}

			foreach ( $member_records as $record ) {
				if ( in_array( $record['email'] ?? '', self::TEST_EMAILS, true ) ) {
					$member_info_ids[] = (int) $record['id'];
				}
			}
		}

		// 3. Remove all three emails from both subgroups (one call per
		// membership record — remove_member() does not batch).
		foreach ( $member_info_ids as $member_info_id ) {
			GroupsIoApiClient::remove_member( $member_info_id );
		}

		// Read-back: no test emails remain in either subgroup.
		foreach ( $subgroup_ids as $subgroup_id ) {
			$members = GroupsIoApiClient::get_members( $subgroup_id );
			$emails_present = array_column( $members['data'] ?? array(), 'email' );

			foreach ( self::TEST_EMAILS as $email ) {
				$this->assertNotContains( $email, $emails_present, "{$email} still present in subgroup {$subgroup_id} after remove_member()." );
			}
		}

		// 4. Delete both subgroups.
		foreach ( $subgroup_ids as $subgroup_id ) {
			GroupsIoApiClient::remove_subgroup( $subgroup_id );
		}

		// Read-back: neither subgroup exists anymore.
		$listed_after_delete = GroupsIoApiClient::get_subgroups( GROUPS_IO_PARENT_GROUP );
		$listed_ids_after_delete = array_column( $listed_after_delete['data'] ?? array(), 'id' );

		foreach ( $subgroup_ids as $subgroup_id ) {
			$this->assertNotContains( $subgroup_id, $listed_ids_after_delete, "Subgroup {$subgroup_id} still listed after remove_subgroup()." );
		}

		// Successfully deleted — nothing left for tear_down() to clean up.
		$this->created_subgroup_ids = array();
	}
}
