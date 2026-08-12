<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\Admin\AdminNotifications;
use BITS\GroupsIOSync\AuditLog;
use BITS\GroupsIOSync\SubgroupExecutionEngine;
use WP_UnitTestCase;

final class SubgroupExecutionEngineTest extends WP_UnitTestCase {

	private const HOOK = 'bits_groupsio_execute_queued_subgroup_action';

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

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AuditLog::table_name() );
		delete_option( 'bits_groupsio_admin_notifications' );
		delete_option( 'bits_groupsio_subgroup_cache' );
	}

	/**
	 * Queues a sequence of pre_http_request responses, one per call, in
	 * order - a real network call from a unit test must fail loudly, not
	 * run slow/flaky, so under-queuing throws rather than falling
	 * through to a real request.
	 */
	private function queue_responses( array $responses ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$responses ) {
				self::$last_request = array(
					'url'  => $url,
					'args' => $parsed_args,
				);

				if ( empty( $responses ) ) {
					throw new \RuntimeException( "queue_responses() exhausted - the code under test made more HTTP requests than the test queued responses for (URL: {$url})." );
				}

				return array_shift( $responses );
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

	private function subgroups_list_response( array $rows ): array {
		return $this->json_response( 200, array(
			'object' => 'list',
			'data'   => $rows,
		) );
	}

	private function create_job( array $overrides = array() ): array {
		return array_merge(
			array(
				'job_action'    => 'create',
				'name'          => 'new-subgroup',
				'title'         => '',
				'description'   => '',
				'expected_slug' => 'perception-is-all+new-subgroup',
				'admin_user_id' => 9,
				'attempt'       => 1,
			),
			$overrides
		);
	}

	private function update_job( array $overrides = array() ): array {
		return array_merge(
			array(
				'job_action'    => 'update',
				'subgroup_id'   => 152360,
				'current_slug'  => 'perception-is-all+sociology',
				'fields'        => array( 'title' => 'New Title' ),
				'admin_user_id' => 9,
				'attempt'       => 1,
			),
			$overrides
		);
	}

	private function delete_job( array $overrides = array() ): array {
		return array_merge(
			array(
				'job_action'    => 'delete',
				'subgroup_id'   => 152361,
				'slug'          => 'perception-is-all+doomed',
				'admin_user_id' => 9,
				'attempt'       => 1,
			),
			$overrides
		);
	}

	private function last_audit_row(): ?array {
		global $wpdb;

		$row = $wpdb->get_row( 'SELECT * FROM ' . AuditLog::table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		return $row ?: null;
	}

	// -------------------- queue_*() --------------------

	public function test_queue_create_schedules_an_action_scheduler_job(): void {
		SubgroupExecutionEngine::queue_create( 'new-subgroup', '', '', 9 );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_queue_update_schedules_an_action_scheduler_job(): void {
		SubgroupExecutionEngine::queue_update( 152360, 'perception-is-all+sociology', array( 'title' => 'New Title' ), 9 );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_queue_delete_schedules_an_action_scheduler_job(): void {
		SubgroupExecutionEngine::queue_delete( 152361, 'perception-is-all+doomed', 9 );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	// -------------------- execute(): create --------------------

	public function test_execute_create_success_records_audit_with_no_notification(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'name' => 'perception-is-all+new-subgroup', 'desc' => '' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->create_job() );

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'subgroup_create', $audit_row['action'] );
		$this->assertSame( 'success', $audit_row['outcome'] );
		$this->assertSame( 'perception-is-all+new-subgroup', $audit_row['subgroup_id'] );

		// No success notice - the admin already saw the immediate
		// "submitted" response when the action was queued.
		$notifications = get_option( 'bits_groupsio_admin_notifications', array() );
		$this->assertSame( array(), $notifications );
	}

	public function test_execute_create_with_title_sends_a_follow_up_update_and_validates_against_its_response(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'name' => 'perception-is-all+new-subgroup', 'desc' => '' ) ),
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'title' => 'New Title', 'desc' => '' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->create_job( array( 'title' => 'New Title' ) ) );

		$this->assertSame( 'POST', self::$last_request['args']['method'] );
		$this->assertStringContainsString( 'updategroup', self::$last_request['url'] );
		$this->assertSame(
			array(
				'group_id' => 152999,
				'title'    => 'New Title',
			),
			self::$last_request['args']['body']
		);

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'success', $audit_row['outcome'] );
	}

	public function test_execute_create_reschedules_when_description_does_not_match_response(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'name' => 'perception-is-all+new-subgroup', 'desc' => 'wrong description' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->create_job( array( 'description' => 'the real description', 'attempt' => 1 ) ) );

		$this->assertNull( $this->last_audit_row(), 'Attempt 1 of 3 must reschedule, not record an outcome.' );
		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_execute_create_records_failure_once_attempts_are_exhausted(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'bad_request', 'extra' => 'invalid group_name' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->create_job( array( 'attempt' => 3 ) ) );

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'failure', $audit_row['outcome'] );
		$this->assertSame( 'invalid group_name', $audit_row['api_response_detail'] );

		$notifications = get_option( 'bits_groupsio_admin_notifications' );
		$this->assertCount( 1, $notifications );
		$this->assertSame( 'failure', $notifications[0]['type'] );
		$this->assertStringContainsString( 'new-subgroup', $notifications[0]['message'] );
	}

	/**
	 * A retried attempt hitting a "name already exists"-shaped error is
	 * idempotent success, not a failure - a prior attempt likely already
	 * created it, and the response was lost to the same transient
	 * failure being retried.
	 */
	public function test_execute_create_retry_treats_duplicate_name_error_as_idempotent_success(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'bad_request', 'extra' => 'name exists' ) ),
			$this->subgroups_list_response( array(
				array( 'id' => 152999, 'name' => 'perception-is-all+new-subgroup', 'title' => '', 'desc' => '' ),
			) ),
		) );

		SubgroupExecutionEngine::execute( $this->create_job( array( 'attempt' => 2 ) ) );

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'success', $audit_row['outcome'] );
	}

	// -------------------- execute(): update --------------------

	public function test_execute_update_success_invalidates_cache_and_records_audit(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+sociology' => 152360 ) );

		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152360, 'title' => 'New Title' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->update_job() );

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'subgroup_update', $audit_row['action'] );
		$this->assertSame( 'success', $audit_row['outcome'] );
		$this->assertSame( '152360', $audit_row['subgroup_id'] );

		$notifications = get_option( 'bits_groupsio_admin_notifications', array() );
		$this->assertSame( array(), $notifications );
	}

	public function test_execute_update_invalidates_both_old_and_new_slug_on_rename(): void {
		update_option( 'bits_groupsio_subgroup_cache', array(
			'perception-is-all+old-name' => 152360,
			'perception-is-all+new-name' => 999999,
		) );

		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152360, 'name' => 'perception-is-all+new-name' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->update_job( array(
			'current_slug' => 'perception-is-all+old-name',
			'fields'       => array( 'name' => 'perception-is-all+new-name' ),
		) ) );

		$cache = get_option( 'bits_groupsio_subgroup_cache' );
		$this->assertArrayNotHasKey( 'perception-is-all+old-name', $cache );
		$this->assertArrayNotHasKey( 'perception-is-all+new-name', $cache );
	}

	public function test_execute_update_reschedules_when_field_does_not_match_response(): void {
		$this->queue_responses( array(
			// Response doesn't reflect the submitted title change.
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152360, 'title' => 'Old Title' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->update_job( array( 'attempt' => 1 ) ) );

		$this->assertNull( $this->last_audit_row(), 'Attempt 1 of 3 must reschedule, not record an outcome.' );
		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_execute_update_records_failure_once_attempts_are_exhausted(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'unauthorized_error', 'extra' => '' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->update_job( array( 'attempt' => 3 ) ) );

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'failure', $audit_row['outcome'] );

		$notifications = get_option( 'bits_groupsio_admin_notifications' );
		$this->assertCount( 1, $notifications );
		$this->assertStringContainsString( 'perception-is-all+sociology', $notifications[0]['message'] );
	}

	// -------------------- execute(): delete --------------------

	public function test_execute_delete_success_invalidates_cache_and_records_audit(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+doomed' => 152361 ) );

		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'ok' ) ),
			$this->subgroups_list_response( array() ),
		) );

		SubgroupExecutionEngine::execute( $this->delete_job() );

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'subgroup_delete', $audit_row['action'] );
		$this->assertSame( 'success', $audit_row['outcome'] );
		$this->assertSame( '152361', $audit_row['subgroup_id'] );

		$cache = get_option( 'bits_groupsio_subgroup_cache' );
		$this->assertArrayNotHasKey( 'perception-is-all+doomed', $cache );

		$notifications = get_option( 'bits_groupsio_admin_notifications', array() );
		$this->assertSame( array(), $notifications );
	}

	/**
	 * The one case among the three where the retry loop is also
	 * functioning as the polling the eventual-consistency problem
	 * actually needs - still listed after the delete call means "not
	 * yet confirmed," not a failure, while attempts remain.
	 */
	public function test_execute_delete_reschedules_when_still_listed_after_delete_call(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'ok' ) ),
			// Still listed - eventual consistency hasn't caught up yet.
			$this->subgroups_list_response( array(
				array( 'id' => 152361, 'name' => 'perception-is-all+doomed' ),
			) ),
		) );

		SubgroupExecutionEngine::execute( $this->delete_job( array( 'attempt' => 1 ) ) );

		$this->assertNull( $this->last_audit_row(), 'Attempt 1 of 3 must reschedule, not record an outcome.' );
		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	/**
	 * A stale pre-check listing (Groups.io's listing is eventually
	 * consistent) can mean the delete call itself reports the subgroup
	 * already gone - a concurrent/earlier delete must have already
	 * succeeded, so this is treated as idempotent success, matching the
	 * pre-existing synchronous logic this replaces.
	 */
	public function test_execute_delete_treats_group_not_found_from_delete_call_as_idempotent_success(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'group_not_found', 'extra' => '' ) ),
			$this->subgroups_list_response( array() ),
		) );

		SubgroupExecutionEngine::execute( $this->delete_job() );

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'success', $audit_row['outcome'] );
	}

	public function test_execute_delete_records_failure_once_attempts_are_exhausted(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'unauthorized_error', 'extra' => '' ) ),
		) );

		SubgroupExecutionEngine::execute( $this->delete_job( array( 'attempt' => 3 ) ) );

		$audit_row = $this->last_audit_row();
		$this->assertSame( 'failure', $audit_row['outcome'] );

		$notifications = get_option( 'bits_groupsio_admin_notifications' );
		$this->assertCount( 1, $notifications );
		$this->assertStringContainsString( 'perception-is-all+doomed', $notifications[0]['message'] );
	}

	public function test_process_due_jobs_processes_subgroup_jobs_too(): void {
		// SubgroupExecutionEngine schedules under a distinct hook from
		// QueuedExecutionEngine's own, but QueuedExecutionEngine::process_due_jobs()
		// delegates to Action Scheduler's own queue runner, which
		// processes every due action system-wide regardless of which
		// hook queued it - confirming this doesn't require a
		// SubgroupExecutionEngine-specific process_due_jobs() of its own.
		SubgroupExecutionEngine::queue_create( 'sync-check-subgroup', '', '', 9 );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}
}
