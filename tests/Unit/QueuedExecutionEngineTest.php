<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\AuditLog;
use BITS\GroupsIOSync\MemberIndex;
use BITS\GroupsIOSync\QueuedExecutionEngine;
use WP_UnitTestCase;

final class QueuedExecutionEngineTest extends WP_UnitTestCase {

	private const HOOK = 'bits_groupsio_execute_queued_action';

	public function set_up(): void {
		parent::set_up();

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
			static function () use ( $response ) {
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
}
