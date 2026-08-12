<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\AuditLog;
use BITS\GroupsIOSync\MemberIndex;
use BITS\GroupsIOSync\QueuedExecutionEngine;
use WP_UnitTestCase;

final class QueuedExecutionEngineTest extends WP_UnitTestCase {

	private const HOOK = 'bits_groupsio_execute_queued_action';

	private static ?array $last_request = null;

	public function set_up(): void {
		parent::set_up();

		self::$last_request = null;

		if ( ! defined( 'GROUPS_IO_API_KEY' ) ) {
			define( 'GROUPS_IO_API_KEY', 'fake-test-key-not-real' );
		}
		if ( ! defined( 'GROUPS_IO_PARENT_GROUP' ) ) {
			define( 'GROUPS_IO_PARENT_GROUP', 'perception-is-all' );
		}

		AuditLog::create_table();
		MemberIndex::create_table();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AuditLog::table_name() );
		$wpdb->query( 'DELETE FROM ' . MemberIndex::table_name() );
		delete_option( 'bits_groupsio_admin_notifications' );
	}

	private function fetch_row( string $email, int $subgroup_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MemberIndex::table_name() . ' WHERE email = %s AND subgroup_id = %d',
				$email,
				$subgroup_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	private function mock_response( array $response ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( $response ) {
				QueuedExecutionEngineTest::$last_request = array(
					'url'  => $url,
					'args' => $parsed_args,
				);

				return $response;
			},
			10,
			3
		);
	}

	private function json_response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	private function add_job( array $overrides = array() ): array {
		return array_merge(
			array(
				'job_action'     => 'add',
				'user_id'        => 3,
				'email'          => 'add-target@example.test',
				'display_name'   => 'Add Target',
				'subgroup_id'    => 900002,
				'subgroup_slug'  => 'perception-is-all+list',
				'subgroup_title' => 'List',
				'admin_user_id'  => 9,
				'attempt'        => 1,
			),
			$overrides
		);
	}

	private function remove_job( array $overrides = array() ): array {
		return array_merge(
			array(
				'job_action'    => 'remove',
				'email'         => 'remove-target@example.test',
				'subgroup_id'   => 900003,
				'admin_user_id' => 9,
				'attempt'       => 1,
			),
			$overrides
		);
	}

	public function test_queue_add_schedules_an_action_scheduler_job(): void {
		QueuedExecutionEngine::queue_add( 1, 'queued-add@example.test', 'Name', 900002, 'perception-is-all+list', 'List', 9 );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_queue_remove_schedules_an_action_scheduler_job(): void {
		QueuedExecutionEngine::queue_remove( 'queued-remove@example.test', 900003, 9 );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_execute_add_success_updates_index_records_audit_and_notification(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'ok' ) ) );

		QueuedExecutionEngine::execute( $this->add_job() );

		$row = $this->fetch_row( 'add-target@example.test', 900002 );
		$this->assertNotNull( $row );
		$this->assertSame( 'added', $row['override_type'] );
		$this->assertSame( '9', $row['override_by'] );

		global $wpdb;
		$audit_row = $wpdb->get_row( 'SELECT * FROM ' . AuditLog::table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( 'add', $audit_row['action'] );
		$this->assertSame( 'success', $audit_row['outcome'] );

		$notifications = get_option( 'bits_groupsio_admin_notifications' );
		$this->assertCount( 1, $notifications );
		$this->assertSame( 'success', $notifications[0]['type'] );
	}

	public function test_execute_remove_success_updates_index_records_audit_and_notification(): void {
		global $wpdb;
		$wpdb->insert( MemberIndex::table_name(), array(
			'email' => 'remove-target@example.test', 'subgroup_id' => 900003,
			'subgroup_slug' => 'perception-is-all+list', 'member_info_id' => 555,
			'synced_at' => current_time( 'mysql', true ),
		) );

		$this->mock_response( $this->json_response( 200, array( 'object' => 'ok' ) ) );

		QueuedExecutionEngine::execute( $this->remove_job() );

		$row = $this->fetch_row( 'remove-target@example.test', 900003 );
		$this->assertSame( 'removed', $row['override_type'] );

		$audit_row = $wpdb->get_row( 'SELECT * FROM ' . AuditLog::table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( 'remove', $audit_row['action'] );
		$this->assertSame( 'success', $audit_row['outcome'] );
	}

	public function test_execute_remove_without_known_member_info_id_retries_instead_of_failing_immediately(): void {
		// No row in the index at all - get_member_info_id() returns null.
		QueuedExecutionEngine::execute( $this->remove_job() );

		global $wpdb;
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AuditLog::table_name() );
		$this->assertSame( '0', $count, 'Attempt 1 of 3 must reschedule, not record a failure outcome.' );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ), 'A retry job must be scheduled.' );
	}

	public function test_execute_add_failure_reschedules_when_attempts_remain(): void {
		$this->mock_response( $this->json_response( 400, array( 'object' => 'error', 'type' => 'invalid_email', 'extra' => '' ) ) );

		QueuedExecutionEngine::execute( $this->add_job( array( 'attempt' => 1 ) ) );

		global $wpdb;
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AuditLog::table_name() );
		$this->assertSame( '0', $count );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_execute_add_failure_records_outcome_once_attempts_are_exhausted(): void {
		$this->mock_response( $this->json_response( 400, array( 'object' => 'error', 'type' => 'invalid_email', 'extra' => '' ) ) );

		QueuedExecutionEngine::execute( $this->add_job( array( 'attempt' => 3 ) ) );

		global $wpdb;
		$audit_row = $wpdb->get_row( 'SELECT * FROM ' . AuditLog::table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( 'failure', $audit_row['outcome'] );

		$this->assertNull( $this->fetch_row( 'add-target@example.test', 900002 ), 'The index row must be left unchanged (unset) on exhaustion.' );

		$notifications = get_option( 'bits_groupsio_admin_notifications' );
		$this->assertCount( 1, $notifications );
		$this->assertSame( 'failure', $notifications[0]['type'] );
	}

	/**
	 * The tests above only assert on this job's *side effects* (index
	 * row, audit row, notification) - they never confirm execute() ever
	 * actually made an HTTP call, let alone the right one. This asserts
	 * directly against the request pre_http_request captured: that a
	 * queued add job results in exactly the same POST .../directadd
	 * request shape GroupsIoApiClientTest already confirms direct_add()
	 * itself produces, proving the engine really does invoke the real
	 * API client rather than, say, silently short-circuiting.
	 */
	public function test_execute_add_sends_the_expected_directadd_request(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'ok' ) ) );

		QueuedExecutionEngine::execute( $this->add_job() );

		$this->assertNotNull( self::$last_request, 'execute() never made an HTTP request.' );
		$this->assertStringContainsString( 'directadd', self::$last_request['url'] );
		$this->assertSame( 'POST', self::$last_request['args']['method'] );
		$this->assertSame(
			array(
				'group_name'  => GROUPS_IO_PARENT_GROUP,
				'emails'      => 'add-target@example.test',
				'subgroupids' => '900002',
			),
			self::$last_request['args']['body']
		);
	}

	/**
	 * Same rationale as test_execute_add_sends_the_expected_directadd_request()
	 * - confirms a remove job results in the exact POST .../removemember
	 * request Groups.io's contract requires, keyed on the member_info_id
	 * resolved from the local index (not email/subgroup_id, which
	 * removemember does not accept).
	 */
	public function test_execute_remove_sends_the_expected_removemember_request(): void {
		global $wpdb;
		$wpdb->insert( MemberIndex::table_name(), array(
			'email' => 'remove-target@example.test', 'subgroup_id' => 900003,
			'subgroup_slug' => 'perception-is-all+list', 'member_info_id' => 555,
			'synced_at' => current_time( 'mysql', true ),
		) );

		$this->mock_response( $this->json_response( 200, array( 'object' => 'ok' ) ) );

		QueuedExecutionEngine::execute( $this->remove_job() );

		$this->assertNotNull( self::$last_request, 'execute() never made an HTTP request.' );
		$this->assertStringContainsString( 'removemember', self::$last_request['url'] );
		$this->assertSame( 'POST', self::$last_request['args']['method'] );
		$this->assertSame( array( 'member_info_id' => 555 ), self::$last_request['args']['body'] );
	}

	/**
	 * Counts scheduled actions for our hook mentioning $email in their
	 * args - filters by a unique-per-test email rather than counting
	 * all scheduled actions for the hook, since Action Scheduler's own
	 * tables aren't covered by WP_UnitTestCase's per-test transaction
	 * rollback and residual actions from earlier tests in the same
	 * suite run would otherwise pollute a global count.
	 */
	private function count_scheduled_for_email( string $email ): int {
		global $wpdb;

		// Action Scheduler stores args as a literal JSON string in the
		// args column only up to a length limit - our job payload
		// exceeds it, so the real payload lives in extended_args
		// instead and args holds a hash reference. Check both.
		$like = '%' . $wpdb->esc_like( $email ) . '%';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND ( args LIKE %s OR extended_args LIKE %s )",
				self::HOOK,
				$like,
				$like
			)
		);
	}

	public function test_queue_add_also_queues_a_parent_group_add_when_member_is_not_a_parent_member(): void {
		global $wpdb;

		$email = 'new-member-parent-autoadd@example.test';

		// The parent's own numeric id/title is resolved from any
		// existing member's row for that slug - seed one for a
		// different member, matching how a real deployment always has
		// parent rows synced from other members already.
		$wpdb->insert(
			MemberIndex::table_name(),
			array(
				'email'         => 'someone-else@example.test',
				'subgroup_id'   => 900001,
				'subgroup_slug' => 'perception-is-all',
				'synced_at'     => current_time( 'mysql', true ),
			)
		);

		QueuedExecutionEngine::queue_add( 1, $email, 'New Member', 900002, 'perception-is-all+list', 'List', 9 );

		$this->assertSame( 2, $this->count_scheduled_for_email( $email ), 'Both the subgroup add and an auto-queued parent add should be scheduled.' );
	}

	public function test_queue_add_does_not_queue_a_duplicate_parent_add_when_member_already_in_parent(): void {
		global $wpdb;

		$email = 'existing-parent-member@example.test';

		$wpdb->insert(
			MemberIndex::table_name(),
			array(
				'email'         => $email,
				'subgroup_id'   => 900001,
				'subgroup_slug' => 'perception-is-all',
				'synced_at'     => current_time( 'mysql', true ),
			)
		);

		QueuedExecutionEngine::queue_add( 1, $email, 'Existing Member', 900002, 'perception-is-all+list', 'List', 9 );

		$this->assertSame( 1, $this->count_scheduled_for_email( $email ), 'Only the requested subgroup add should be queued - the member is already a current parent member.' );
	}

	public function test_queue_add_for_the_parent_group_itself_does_not_recurse(): void {
		$email = 'parent-only-add@example.test';

		QueuedExecutionEngine::queue_add( 1, $email, 'Parent Only', 900001, 'perception-is-all', '', 9 );

		$this->assertSame( 1, $this->count_scheduled_for_email( $email ), 'Adding the parent group itself must not trigger a second, recursive parent-add.' );
	}
}
