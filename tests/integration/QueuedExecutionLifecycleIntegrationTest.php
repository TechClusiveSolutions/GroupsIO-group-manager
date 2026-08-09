<?php

namespace BITS\GroupsIOSync\Tests\Integration;

use BITS\GroupsIOSync\GroupsIoApiClient;
use BITS\GroupsIOSync\GroupsIoApiException;
use BITS\GroupsIOSync\MemberIndex;
use BITS\GroupsIOSync\QueuedExecutionEngine;
use WP_UnitTestCase;

/**
 * Permanent integration test proving a queued job (QueuedExecutionEngine's
 * execute(), the same code path Action Scheduler invokes) produces a real,
 * tangible result against the live test Groups.io group - not just the
 * mocked-response unit coverage in QueuedExecutionEngineTest.
 *
 * Runs execute() with a real add job against one ephemeral test subgroup,
 * confirms the test email is actually a member via a live get_members()
 * read-back, then runs execute() with a real remove job (member_info_id
 * seeded into the local index from that same live read, matching how a
 * real sync() run would populate it - remove_member() is keyed on
 * member_info_id, not email/subgroup, so this mirrors the real
 * prerequisite rather than bypassing it) and confirms live removal.
 *
 * Requires GROUPS_IO_API_KEY and GROUPS_IO_PARENT_GROUP to be set (see
 * SubgroupLifecycleIntegrationTest for the same skip-if-absent behavior).
 * Read-backs poll for the same eventually-consistent-membership reason
 * documented there.
 */
final class QueuedExecutionLifecycleIntegrationTest extends WP_UnitTestCase {

	private const TEST_EMAIL = 'dev+integration-queued@techclusivesolutions.com';

	private const POLL_ATTEMPTS = 8;
	private const POLL_DELAY_SECONDS = 3;

	private ?int $created_subgroup_id = null;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'GROUPS_IO_API_KEY' ) || ! defined( 'GROUPS_IO_PARENT_GROUP' ) ) {
			$this->markTestSkipped( 'GROUPS_IO_TEST_API_KEY / GROUPS_IO_TEST_PARENT_GROUP not set — skipping live integration test.' );
		}

		MemberIndex::create_table();

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . MemberIndex::table_name() . ' WHERE email = %s', self::TEST_EMAIL )
		);
	}

	public function tear_down(): void {
		if ( null !== $this->created_subgroup_id ) {
			try {
				GroupsIoApiClient::remove_subgroup( $this->created_subgroup_id );
			} catch ( \Throwable $exception ) {
				// Already gone, or genuinely failed to clean up — nothing
				// further we can safely do from a tear_down().
			}
		}

		parent::tear_down();
	}

	/**
	 * Same eventually-consistent-membership polling helper as
	 * SubgroupLifecycleIntegrationTest — see that class for rationale.
	 *
	 * @param callable $poll  Fetches the current state (e.g. a get_members() call).
	 * @param callable $check Runs the real PHPUnit assertion(s) against that state.
	 * @return void
	 */
	private function assert_eventually( callable $poll, callable $check ): void {
		for ( $attempt = 1; $attempt < self::POLL_ATTEMPTS; $attempt++ ) {
			try {
				$check( $poll() );
				return;
			} catch ( \PHPUnit\Framework\AssertionFailedError $exception ) {
				sleep( self::POLL_DELAY_SECONDS );
			}
		}

		$check( $poll() );
	}

	public function test_queued_add_and_remove_produce_a_real_tangible_result(): void {
		$run_id = (string) time();

		try {
			$created = GroupsIoApiClient::create_subgroup(
				GROUPS_IO_PARENT_GROUP,
				'integration-test-queued-' . $run_id,
				'Created by the permanent queued execution lifecycle integration test.'
			);
		} catch ( GroupsIoApiException $exception ) {
			$this->fail( sprintf( 'create_subgroup() failed: %s (extra: %s)', $exception->get_error_type(), $exception->get_extra() ) );
		}

		$this->assertArrayHasKey( 'id', $created, "create_subgroup() response missing 'id'." );
		$subgroup_id               = (int) $created['id'];
		$this->created_subgroup_id = $subgroup_id;

		// 1. A real queued add job — the exact call Action Scheduler would
		// make when running a job queue_add() scheduled.
		QueuedExecutionEngine::execute(
			array(
				'job_action'     => 'add',
				'user_id'        => 0,
				'email'          => self::TEST_EMAIL,
				'display_name'   => 'Queued Execution Integration Test',
				'subgroup_id'    => $subgroup_id,
				'subgroup_slug'  => $created['group_name'] ?? '',
				'subgroup_title' => $created['title'] ?? '',
				'admin_user_id'  => 0,
				'attempt'        => 1,
			)
		);

		// Tangible result #1: the local index reflects the add.
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MemberIndex::table_name() . ' WHERE email = %s AND subgroup_id = %d',
				self::TEST_EMAIL,
				$subgroup_id
			),
			ARRAY_A
		);
		$this->assertNotNull( $row, 'MemberIndex row not created by the queued add job.' );
		$this->assertSame( 'added', $row['override_type'] );

		// Tangible result #2: the email is a real member of the real
		// subgroup on Groups.io itself, confirmed via a live read-back —
		// not just trusting the API's initial response.
		$member_info_id = null;
		$this->assert_eventually(
			static fn () => GroupsIoApiClient::get_members( $subgroup_id ),
			function ( array $members ) use ( &$member_info_id ) {
				$matched = null;
				foreach ( $members['data'] ?? array() as $record ) {
					if ( self::TEST_EMAIL === ( $record['email'] ?? '' ) ) {
						$matched = $record;
						break;
					}
				}

				$this->assertNotNull( $matched, self::TEST_EMAIL . ' not found in live get_members() read-back after the queued add job.' );
				$member_info_id = (int) $matched['id'];
			}
		);
		$this->assertNotNull( $member_info_id, 'Could not resolve a live member_info_id to seed the index with for the remove step.' );

		// A real sync() run is what would normally backfill member_info_id
		// into the index — seed it directly here to isolate this test to
		// QueuedExecutionEngine's own remove path, per the class docblock.
		$wpdb->update(
			MemberIndex::table_name(),
			array( 'member_info_id' => $member_info_id ),
			array( 'email' => self::TEST_EMAIL, 'subgroup_id' => $subgroup_id )
		);

		// 2. A real queued remove job.
		QueuedExecutionEngine::execute(
			array(
				'job_action'    => 'remove',
				'email'         => self::TEST_EMAIL,
				'subgroup_id'   => $subgroup_id,
				'admin_user_id' => 0,
				'attempt'       => 1,
			)
		);

		// Tangible result #3: the local index reflects the remove.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MemberIndex::table_name() . ' WHERE email = %s AND subgroup_id = %d',
				self::TEST_EMAIL,
				$subgroup_id
			),
			ARRAY_A
		);
		$this->assertSame( 'removed', $row['override_type'] );

		// Tangible result #4: the email is genuinely no longer a member
		// of the real subgroup on Groups.io.
		$this->assert_eventually(
			static fn () => GroupsIoApiClient::get_members( $subgroup_id ),
			function ( array $members ) {
				$emails_present = array_column( $members['data'] ?? array(), 'email' );
				$this->assertNotContains( self::TEST_EMAIL, $emails_present, self::TEST_EMAIL . ' still present in the live subgroup after the queued remove job.' );
			}
		);

		// Successfully cleaned up the test email — the subgroup itself is
		// still removed by tear_down().
	}
}
