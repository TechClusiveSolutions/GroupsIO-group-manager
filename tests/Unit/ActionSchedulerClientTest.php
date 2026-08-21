<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\ActionSchedulerClient;
use WP_UnitTestCase;

final class ActionSchedulerClientTest extends WP_UnitTestCase {

	private const HOOK = 'bits_groupsio_execute_queued_action';

	private function mark_complete( int $action_id ): void {
		\ActionScheduler_Store::instance()->mark_complete( $action_id );
	}

	private function mark_failed( int $action_id ): void {
		\ActionScheduler_Store::instance()->mark_failure( $action_id );
	}

	public function test_schedule_returns_a_positive_action_id(): void {
		$action_id = ActionSchedulerClient::schedule( array( 'job_action' => 'add' ), time() );

		$this->assertGreaterThan( 0, $action_id );
	}

	public function test_get_status_returns_pending_for_a_freshly_scheduled_action(): void {
		$action_id = ActionSchedulerClient::schedule( array( 'job_action' => 'add' ), time() );

		$this->assertSame( 'pending', ActionSchedulerClient::get_status( $action_id ) );
	}

	public function test_get_status_returns_null_for_an_unknown_action_id(): void {
		$this->assertNull( ActionSchedulerClient::get_status( 999999999 ) );
	}

	public function test_get_status_returns_null_for_a_zero_action_id(): void {
		$this->assertNull( ActionSchedulerClient::get_status( 0 ) );
	}

	public function test_is_finished_returns_false_for_a_pending_action(): void {
		$action_id = ActionSchedulerClient::schedule( array( 'job_action' => 'add' ), time() );

		$this->assertFalse( ActionSchedulerClient::is_finished( $action_id ) );
	}

	public function test_is_finished_returns_true_once_the_action_is_marked_complete(): void {
		$action_id = ActionSchedulerClient::schedule( array( 'job_action' => 'add' ), time() );
		$this->mark_complete( $action_id );

		$this->assertTrue( ActionSchedulerClient::is_finished( $action_id ) );
	}

	public function test_is_finished_returns_true_once_the_action_is_marked_failed(): void {
		$action_id = ActionSchedulerClient::schedule( array( 'job_action' => 'add' ), time() );
		$this->mark_failed( $action_id );

		$this->assertTrue( ActionSchedulerClient::is_finished( $action_id ) );
	}

	public function test_is_finished_returns_false_for_an_unknown_action_id(): void {
		$this->assertFalse( ActionSchedulerClient::is_finished( 999999999 ) );
	}
}
