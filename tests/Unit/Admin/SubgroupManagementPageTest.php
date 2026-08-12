<?php

namespace BITS\GroupsIOSync\Tests\Unit\Admin;

use BITS\GroupsIOSync\Admin\SubgroupManagementPage;
use BITS\GroupsIOSync\QueuedExecutionEngine;
use WP_UnitTestCase;

final class SubgroupManagementPageTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'GROUPS_IO_API_KEY' ) ) {
			define( 'GROUPS_IO_API_KEY', 'fake-test-key-not-real' );
		}
		if ( ! defined( 'GROUPS_IO_PARENT_GROUP' ) ) {
			define( 'GROUPS_IO_PARENT_GROUP', 'perception-is-all' );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Remaining responses queue_responses() hasn't handed out yet -
	 * exposed so a test can call assert_queue_exhausted() to catch a
	 * short-circuited implementation that returns success without
	 * actually making every expected call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $pending_responses = array();

	/**
	 * Queues a sequence of pre_http_request responses, one per call, in
	 * the order they'll be requested. If a test under-queues responses
	 * (fewer entries than the code under test actually requests), this
	 * throws rather than falling through to $preempt (which would let a
	 * real outbound HTTP request through) - a real network call from a
	 * unit test must fail loudly and immediately, not run slow/flaky.
	 */
	private function queue_responses( array $responses ): void {
		$this->pending_responses = $responses;

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				if ( empty( $this->pending_responses ) ) {
					throw new \RuntimeException( "queue_responses() exhausted - the code under test made more HTTP requests than the test queued responses for (URL: {$url})." );
				}

				return array_shift( $this->pending_responses );
			},
			10,
			3
		);
	}

	/**
	 * Asserts every response passed to queue_responses() was actually
	 * consumed - catches an implementation that short-circuits and
	 * returns success without making every call a correct
	 * implementation would.
	 */
	private function assert_queue_exhausted(): void {
		$this->assertSame( array(), $this->pending_responses, 'Not every queued HTTP response was consumed by the code under test.' );
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

	private function subgroup_row( int $id, string $name, string $title = '', string $desc = '', int $count = 0 ): array {
		$pos     = strpos( $name, '+' );
		$segment = false === $pos ? $name : substr( $name, $pos + 1 );
		$parent  = false === $pos ? $name : substr( $name, 0, $pos );

		return array(
			'id'            => $id,
			'name'          => $name,
			'title'         => $title,
			'desc'          => $desc,
			'subs_count'    => $count,
			'email_address' => $segment . '@' . $parent . '.groups.io',
		);
	}

	// -------------------- render() --------------------

	public function test_render_outputs_nothing_for_a_user_without_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_list_view_shows_count_parent_address_and_subgroup_links(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'id' => 999, 'email_address' => 'main@perception-is-all.groups.io' ) ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', '', '', 3 ),
			) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'main@perception-is-all.groups.io', $output );
		$this->assertStringContainsString( '1 subgroup provisioned.', $output );
		$this->assertStringContainsString( 'Create new subgroup', $output );
		$this->assertStringContainsString( '3 members', $output );
	}

	public function test_list_view_shows_no_subgroups_message_when_empty(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'id' => 999, 'email_address' => 'main@perception-is-all.groups.io' ) ),
			$this->subgroups_list_response( array() ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '0 subgroups provisioned.', $output );
	}

	public function test_create_view_shows_name_title_description_fields(): void {
		$_GET['view'] = 'create';

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['view'] );

		$this->assertStringContainsString( 'Create Subgroup', $output );
		$this->assertStringContainsString( 'name="sub_group_name"', $output );
		$this->assertStringContainsString( 'name="title"', $output );
		$this->assertStringContainsString( 'name="description"', $output );
	}

	public function test_details_view_shows_current_values_and_members(): void {
		$_GET['view']        = 'details';
		$_GET['subgroup_id'] = '152360';

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', 'Sociology Club', 'A description.', 1 ),
			) ),
			$this->json_response( 200, array(
				'object' => 'list',
				'data'   => array( array( 'id' => 1, 'email' => 'someone@example.test' ) ),
			) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['view'], $_GET['subgroup_id'] );

		$this->assertStringContainsString( 'value="sociology"', $output );
		$this->assertStringContainsString( 'Sociology Club', $output );
		$this->assertStringContainsString( 'A description.', $output );
		$this->assertStringContainsString( 'someone@example.test', $output );
		$this->assertStringContainsString( 'Delete this subgroup', $output );
	}

	public function test_details_view_shows_inline_confirmation_when_confirm_delete_set(): void {
		$_GET['view']           = 'details';
		$_GET['subgroup_id']    = '152360';
		$_GET['confirm_delete'] = '1';

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', '', '', 1 ),
			) ),
			$this->json_response( 200, array( 'object' => 'list', 'data' => array() ) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['view'], $_GET['subgroup_id'], $_GET['confirm_delete'] );

		$this->assertStringContainsString( 'Are you sure you want to delete this subgroup', $output );
		$this->assertStringContainsString( 'Yes, delete this subgroup', $output );
	}

	public function test_details_view_for_unknown_id_shows_error(): void {
		$_GET['view']        = 'details';
		$_GET['subgroup_id'] = '999999';

		$this->queue_responses( array(
			$this->subgroups_list_response( array() ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['view'], $_GET['subgroup_id'] );

		$this->assertStringContainsString( 'could not be found', $output );
	}

	// -------------------- process_create() --------------------

	public function test_process_create_with_blank_name_returns_invalid_request(): void {
		$_POST['sub_group_name'] = '';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'invalid_request', $code );
		$this->assertSame( '', $detail );
	}

	/**
	 * Per #50: process_create() no longer makes any Groups.io API call
	 * itself - it only validates the submitted Name and queues the
	 * actual create (SubgroupExecutionEngine::queue_create()). No
	 * queue_responses() at all here - if the implementation regressed
	 * to making a synchronous HTTP call, queue_responses() would throw
	 * on an unqueued request, per its own "must fail loudly" design.
	 */
	public function test_process_create_queues_a_job_and_returns_create_submitted(): void {
		$_POST['sub_group_name'] = 'new-subgroup';
		$_POST['title']          = 'New Title';
		$_POST['description']    = 'A description.';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['title'], $_POST['description'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'create_submitted', $code );
		$this->assertSame( '', $detail );
		$this->assertNotFalse( as_next_scheduled_action( 'bits_groupsio_execute_queued_subgroup_action' ) );
	}

	// -------------------- process_update() --------------------

	public function test_process_update_rejects_id_slug_mismatch(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+real-slug' ),
			) ),
		) );

		$_POST['subgroup_id']   = '152360';
		$_POST['current_slug']  = 'perception-is-all+forged-slug';
		$_POST['sub_group_name'] = 'renamed';
		$_POST['_wpnonce']      = wp_create_nonce( 'bits_groupsio_update_subgroup' );
		$_REQUEST['_wpnonce']   = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_update();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'not_found', $code );
	}

	public function test_process_update_with_no_changes_is_a_no_op_success(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', 'Existing Title', 'Existing desc' ),
			) ),
		) );

		$_POST['subgroup_id']    = '152360';
		$_POST['current_slug']   = 'perception-is-all+sociology';
		$_POST['sub_group_name'] = 'sociology';
		$_POST['title']          = 'Existing Title';
		$_POST['description']    = 'Existing desc';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_update_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_update();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['sub_group_name'], $_POST['title'], $_POST['description'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'updated', $code );
	}

	/**
	 * Per #50: once process_update() has computed a real field diff, it
	 * queues the actual update_subgroup() call
	 * (SubgroupExecutionEngine::queue_update()) instead of performing it
	 * synchronously - only the pre-check listing (to validate the
	 * id/slug pairing and compute the diff) is a real HTTP call here.
	 */
	public function test_process_update_with_changes_queues_a_job_and_returns_update_submitted(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', 'Old Title', 'Old desc' ),
			) ),
		) );

		$_POST['subgroup_id']    = '152360';
		$_POST['current_slug']   = 'perception-is-all+sociology';
		$_POST['sub_group_name'] = 'sociology';
		$_POST['title']          = 'New Title';
		$_POST['description']    = 'Old desc';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_update_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_update();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['sub_group_name'], $_POST['title'], $_POST['description'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'update_submitted', $code );
		$this->assert_queue_exhausted();
		$this->assertNotFalse( as_next_scheduled_action( 'bits_groupsio_execute_queued_subgroup_action' ) );
	}

	// -------------------- process_delete() --------------------

	public function test_process_delete_rejects_id_slug_mismatch(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152361, 'perception-is-all+real-slug' ),
			) ),
		) );

		$_POST['subgroup_id']   = '152361';
		$_POST['current_slug']  = 'perception-is-all+forged-slug';
		$_POST['_wpnonce']      = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce']   = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'not_found', $code );
	}

	/**
	 * Per #50: once process_delete()'s pre-check confirms the target
	 * genuinely exists under the submitted slug, it queues the actual
	 * remove_subgroup() call and absence confirmation
	 * (SubgroupExecutionEngine::queue_delete()) instead of performing
	 * them synchronously - only the pre-check listing is a real HTTP
	 * call here.
	 */
	public function test_process_delete_of_an_existing_target_queues_a_job_and_returns_delete_submitted(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152361, 'perception-is-all+doomed' ),
			) ),
		) );

		$_POST['subgroup_id']  = '152361';
		$_POST['current_slug'] = 'perception-is-all+doomed';
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'delete_submitted', $code );
		$this->assert_queue_exhausted();
		$this->assertNotFalse( as_next_scheduled_action( 'bits_groupsio_execute_queued_subgroup_action' ) );
	}

	public function test_process_delete_when_target_already_absent_is_idempotent_success(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+already-gone' => 152362 ) );

		// Pre-check listing succeeds but simply doesn't contain this id -
		// simulates a retried/duplicate delete request after an earlier
		// attempt already succeeded.
		$this->queue_responses( array(
			$this->subgroups_list_response( array() ),
		) );

		$_POST['subgroup_id']  = '152362';
		$_POST['current_slug'] = 'perception-is-all+already-gone';
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'deleted', $code );

		$cache = get_option( 'bits_groupsio_subgroup_cache' );
		$this->assertArrayNotHasKey( 'perception-is-all+already-gone', $cache );
	}

	public function test_process_delete_does_not_report_success_when_target_id_absent_but_slug_reused(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+reused-slug' => 152365 ) );

		// The submitted id (152365) is gone, but the slug is now in use by a
		// different, current subgroup (a new id) - a missing id alone must
		// not be treated as "this delete already happened", since that
		// would falsely report success and invalidate a cache entry that
		// now correctly points at the live subgroup.
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 999999, 'perception-is-all+reused-slug' ),
			) ),
		) );

		$_POST['subgroup_id']  = '152365';
		$_POST['current_slug'] = 'perception-is-all+reused-slug';
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'not_found', $code );
		$this->assert_queue_exhausted();
	}

	public function test_process_delete_pre_check_lookup_failure_returns_delete_failed_not_deleted(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'unauthorized_error', 'extra' => '' ) ),
		) );

		$_POST['subgroup_id']  = '152363';
		$_POST['current_slug'] = 'perception-is-all+whatever';
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// A failed check must never be reported as a confirmed deletion.
		$this->assertSame( 'delete_failed', $code );
	}

	public function test_process_update_pre_check_lookup_failure_returns_update_failed_not_not_found(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'unauthorized_error', 'extra' => '' ) ),
		) );

		$_POST['subgroup_id']    = '152360';
		$_POST['current_slug']   = 'perception-is-all+sociology';
		$_POST['sub_group_name'] = 'renamed';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_update_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_update();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// A failed check must never be reported as "not found".
		$this->assertSame( 'update_failed', $code );
	}

	public function test_details_view_suppresses_name_autofocus_while_confirming_delete(): void {
		$_GET['view']           = 'details';
		$_GET['subgroup_id']    = '152360';
		$_GET['confirm_delete'] = '1';

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', '', '', 1 ),
			) ),
			$this->json_response( 200, array( 'object' => 'list', 'data' => array() ) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['view'], $_GET['subgroup_id'], $_GET['confirm_delete'] );

		// Only the delete confirmation button's autofocus should be
		// present - if the Name field also carries autofocus, the
		// browser gives focus to whichever comes first in the DOM (the
		// Name field), silently defeating the confirmation button's.
		$this->assertStringNotContainsString( 'id="bits_groupsio_sub_group_name" name="sub_group_name" value="sociology" required aria-describedby="bits_groupsio_sub_group_name_description" autofocus', $output );
		$this->assertStringContainsString( 'Yes, delete this subgroup', $output );
	}

	public function test_list_view_does_not_claim_zero_subgroups_when_load_fails(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'id' => 999, 'email_address' => 'main@perception-is-all.groups.io' ) ),
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'unauthorized_error', 'extra' => '' ) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '0 subgroups provisioned.', $output );
		$this->assertStringContainsString( 'Could not load subgroups', $output );
		// Create must remain available during a list-load outage.
		$this->assertStringContainsString( 'Create new subgroup', $output );
	}

	public function test_list_view_stops_after_a_hard_stop_error_instead_of_making_further_calls(): void {
		// Only one response queued: get_group()'s. If the code
		// incorrectly treats unauthorized_error as an ordinary,
		// non-fatal error and proceeds to call get_subgroups() anyway,
		// the queue will be exhausted and queue_responses() will throw -
		// failing this test, since unauthorized_error/inadequate_permissions
		// is a hard-stop signal per security-sensitive.instructions.md,
		// not a per-call problem to shrug off and continue past.
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'unauthorized_error', 'extra' => '' ) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Could not access Groups.io', $output );
		$this->assertStringNotContainsString( 'Create new subgroup', $output );
		$this->assert_queue_exhausted();
	}

	// -------------------- Sync control --------------------

	public function test_list_view_shows_the_sync_button(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'id' => 999, 'email_address' => 'main@perception-is-all.groups.io' ) ),
			$this->subgroups_list_response( array() ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'bits_groupsio_action" value="sync"', $output );
	}

	public function test_create_view_shows_the_sync_button_with_view_context(): void {
		$_GET['view'] = 'create';

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['view'] );

		$this->assertStringContainsString( 'bits_groupsio_action" value="sync"', $output );
		$this->assertStringContainsString( 'name="sync_view" value="create"', $output );
	}

	public function test_details_view_shows_the_sync_button_with_view_and_subgroup_id_context(): void {
		$_GET['view']        = 'details';
		$_GET['subgroup_id'] = '152360';

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', 'Sociology Club', 'A description.', 1 ),
			) ),
			$this->json_response( 200, array( 'object' => 'list', 'data' => array() ) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['view'], $_GET['subgroup_id'] );

		$this->assertStringContainsString( 'bits_groupsio_action" value="sync"', $output );
		$this->assertStringContainsString( 'name="sync_view" value="details"', $output );
		$this->assertStringContainsString( 'name="subgroup_id" value="152360"', $output );
	}

	public function test_process_sync_processes_due_jobs(): void {
		QueuedExecutionEngine::queue_remove( 'sync-target@example.test', 900010, 1 );

		$_POST['sync_view']   = 'list';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_sync' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$processed = SubgroupManagementPage::process_sync();

		unset( $_POST['sync_view'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertGreaterThanOrEqual( 1, $processed );
	}
}
