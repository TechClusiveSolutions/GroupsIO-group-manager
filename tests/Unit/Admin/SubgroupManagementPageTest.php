<?php

namespace BITS\GroupsIOSync\Tests\Unit\Admin;

use BITS\GroupsIOSync\Admin\SubgroupManagementPage;
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
	 * Queues a sequence of pre_http_request responses, one per call, in
	 * the order they'll be requested. Several tests need this since
	 * process_*() now makes multiple HTTP calls (a pre-validation
	 * listing, the mutating call, and a read-back listing).
	 *
	 * @param array<int, array<string, mixed>> $responses Ordered list of json_response()-shaped arrays.
	 */
	private function queue_responses( array $responses ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$responses ) {
				if ( empty( $responses ) ) {
					return $preempt;
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

	public function test_render_outputs_nothing_for_a_user_without_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_render_lists_subgroups_with_member_counts(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+sociology', 'subs_count' => 3 ),
			) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'perception-is-all+sociology', $output );
		$this->assertStringContainsString( '3 members', $output );
	}

	public function test_render_shows_no_subgroups_message_when_list_is_empty(): void {
		$this->queue_responses( array( $this->subgroups_list_response( array() ) ) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No subgroups exist yet.', $output );
	}

	public function test_render_surfaces_a_load_failure_in_plain_language(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'inadequate_permissions', 'extra' => '' ) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Could not load subgroups', $output );
		$this->assertStringNotContainsString( '{"object"', $output );
		// The create form must still be usable even when the list fails to
		// load - a transient outage shouldn't also block subgroup creation.
		$this->assertStringContainsString( '<h2>Create Subgroup</h2>', $output );
	}

	public function test_render_surfaces_unexpected_error_type_as_plain_language_not_raw_token(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'some_unmapped_type', 'extra' => '' ) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'some_unmapped_type', $output );
		$this->assertStringContainsString( 'unexpected error', $output );
	}

	public function test_view_members_query_arg_shows_live_member_list_with_per_row_accessible_names(): void {
		$_GET['view_members'] = '152360';

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+sociology', 'subs_count' => 1 ),
			) ),
		) );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) {
				if ( false !== strpos( $url, 'getmembers' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array(
							'object' => 'list',
							'data'   => array(
								array( 'id' => 999, 'email' => 'member@example.test' ),
							),
						) ),
						'headers'  => array(),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['view_members'] );

		$this->assertStringContainsString( 'member@example.test', $output );
		$this->assertStringContainsString( 'Hide members for perception-is-all+sociology', $output );
		// The "Hide members" link must actually drop view_members from its
		// URL so activating it collapses the list instead of reloading it
		// expanded again.
		$this->assertStringNotContainsString( 'view_members=152360', $output );
	}

	public function test_render_includes_slug_in_per_row_accessible_names(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+sociology', 'subs_count' => 1 ),
			) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'View members for perception-is-all+sociology', $output );
		$this->assertStringContainsString( 'Rename perception-is-all+sociology', $output );
		$this->assertStringContainsString( 'Delete subgroup perception-is-all+sociology', $output );
	}

	public function test_process_create_with_blank_name_returns_invalid_request_without_calling_api(): void {
		$_POST['sub_group_name'] = '';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'invalid_request', $code );
		$this->assertSame( '', $detail );
	}

	public function test_process_create_success_confirms_via_read_back(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'name' => 'perception-is-all+new-subgroup' ) ),
			$this->subgroups_list_response( array(
				array( 'id' => 152999, 'name' => 'perception-is-all+new-subgroup', 'subs_count' => 1 ),
			) ),
		) );

		$_POST['sub_group_name'] = 'new-subgroup';
		$_POST['description']    = '';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['description'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'created', $code );
		$this->assertSame( '', $detail );
	}

	public function test_process_create_fails_when_read_back_does_not_find_new_subgroup(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'name' => 'perception-is-all+new-subgroup' ) ),
			$this->subgroups_list_response( array() ),
		) );

		$_POST['sub_group_name'] = 'new-subgroup';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'create_failed', $code );
		$this->assertStringContainsString( 'could not be confirmed', $detail );
	}

	public function test_process_create_api_failure_returns_friendly_detail_not_raw_json(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'subgroup_exists', 'extra' => 'sub_group_name already exists' ) ),
		) );

		$_POST['sub_group_name'] = 'sociology';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'create_failed', $code );
		$this->assertSame( 'sub_group_name already exists', $detail );
	}

	public function test_process_rename_rejects_id_that_does_not_belong_to_configured_parent(): void {
		// The listing under the configured parent does not contain the
		// submitted subgroup_id at all - simulates a crafted request
		// targeting a group the API credential can reach but that isn't
		// one of this parent's subgroups.
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+sociology', 'subs_count' => 1 ),
			) ),
		) );

		$_POST['subgroup_id']       = '999999';
		$_POST['old_slug']          = 'some-other-group+not-ours';
		$_POST['new_subgroup_name'] = 'renamed';
		$_POST['_wpnonce']          = wp_create_nonce( 'bits_groupsio_rename_subgroup' );
		$_REQUEST['_wpnonce']       = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_rename();

		unset( $_POST['subgroup_id'], $_POST['old_slug'], $_POST['new_subgroup_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'not_found', $code );
	}

	public function test_process_rename_rejects_id_whose_current_slug_does_not_match_submitted_old_slug(): void {
		// The id exists under the parent, but its *current* slug doesn't
		// match what the form claims as old_slug - e.g. it was already
		// renamed by someone else since the page loaded.
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+already-renamed', 'subs_count' => 1 ),
			) ),
		) );

		$_POST['subgroup_id']       = '152360';
		$_POST['old_slug']          = 'perception-is-all+sociology';
		$_POST['new_subgroup_name'] = 'renamed';
		$_POST['_wpnonce']          = wp_create_nonce( 'bits_groupsio_rename_subgroup' );
		$_REQUEST['_wpnonce']       = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_rename();

		unset( $_POST['subgroup_id'], $_POST['old_slug'], $_POST['new_subgroup_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'not_found', $code );
	}

	public function test_process_rename_success_confirms_via_read_back_and_invalidates_old_slug_cache_entry(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+old-name' => 152360 ) );

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+old-name', 'subs_count' => 1 ),
			) ),
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152360, 'name' => 'perception-is-all+new-name' ) ),
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+new-name', 'subs_count' => 1 ),
			) ),
		) );

		$_POST['subgroup_id']       = '152360';
		$_POST['old_slug']          = 'perception-is-all+old-name';
		$_POST['new_subgroup_name'] = 'new-name';
		$_POST['_wpnonce']          = wp_create_nonce( 'bits_groupsio_rename_subgroup' );
		$_REQUEST['_wpnonce']       = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_rename();

		unset( $_POST['subgroup_id'], $_POST['old_slug'], $_POST['new_subgroup_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'renamed', $code );
		$this->assertSame( '', $detail );

		$cache = get_option( 'bits_groupsio_subgroup_cache' );
		$this->assertArrayNotHasKey( 'perception-is-all+old-name', $cache );
	}

	public function test_process_rename_fails_when_read_back_shows_unchanged_name(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+old-name', 'subs_count' => 1 ),
			) ),
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152360, 'name' => 'perception-is-all+old-name' ) ),
			// Read-back shows the name never actually changed.
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+old-name', 'subs_count' => 1 ),
			) ),
		) );

		$_POST['subgroup_id']       = '152360';
		$_POST['old_slug']          = 'perception-is-all+old-name';
		$_POST['new_subgroup_name'] = 'new-name';
		$_POST['_wpnonce']          = wp_create_nonce( 'bits_groupsio_rename_subgroup' );
		$_REQUEST['_wpnonce']       = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_rename();

		unset( $_POST['subgroup_id'], $_POST['old_slug'], $_POST['new_subgroup_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'rename_failed', $code );
		$this->assertStringContainsString( 'could not be confirmed', $detail );
	}

	public function test_process_delete_rejects_id_slug_mismatch_against_fresh_listing(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152361, 'name' => 'perception-is-all+real-slug', 'subs_count' => 1 ),
			) ),
		) );

		$_POST['subgroup_id'] = '152361';
		$_POST['slug']        = 'perception-is-all+forged-slug';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'not_found', $code );
	}

	public function test_process_delete_success_confirms_via_read_back_and_invalidates_slug_cache_entry(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+doomed' => 152361 ) );

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152361, 'name' => 'perception-is-all+doomed', 'subs_count' => 1 ),
			) ),
			$this->json_response( 200, array( 'object' => 'ok' ) ),
			// Read-back: subgroup is gone.
			$this->subgroups_list_response( array() ),
		) );

		$_POST['subgroup_id'] = '152361';
		$_POST['slug']        = 'perception-is-all+doomed';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'deleted', $code );
		$this->assertSame( '', $detail );

		$cache = get_option( 'bits_groupsio_subgroup_cache' );
		$this->assertArrayNotHasKey( 'perception-is-all+doomed', $cache );
	}

	public function test_process_delete_fails_when_read_back_still_shows_subgroup(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152361, 'name' => 'perception-is-all+stubborn', 'subs_count' => 1 ),
			) ),
			$this->json_response( 200, array( 'object' => 'ok' ) ),
			// Read-back: still present.
			$this->subgroups_list_response( array(
				array( 'id' => 152361, 'name' => 'perception-is-all+stubborn', 'subs_count' => 1 ),
			) ),
		) );

		$_POST['subgroup_id'] = '152361';
		$_POST['slug']        = 'perception-is-all+stubborn';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'delete_failed', $code );
		$this->assertStringContainsString( 'could not be confirmed', $detail );
	}

	public function test_process_delete_api_failure_returns_friendly_detail(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 999999, 'name' => 'perception-is-all+ghost', 'subs_count' => 0 ),
			) ),
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'group_not_found', 'extra' => 'that group no longer exists' ) ),
		) );

		$_POST['subgroup_id'] = '999999';
		$_POST['slug']        = 'perception-is-all+ghost';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'delete_failed', $code );
		$this->assertSame( 'that group no longer exists', $detail );
	}

	public function test_confirm_delete_query_arg_shows_confirmation_step_instead_of_create_form(): void {
		$_GET['confirm_delete'] = '152360';

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+sociology', 'subs_count' => 1 ),
			) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['confirm_delete'] );

		$this->assertStringContainsString( 'Are you sure you want to delete the subgroup', $output );
		$this->assertStringContainsString( 'perception-is-all+sociology', $output );
		$this->assertStringContainsString( 'Yes, delete this subgroup', $output );
		// The create form still renders below the confirmation banner, just
		// without autofocus (the confirmation is this view's primary action).
		$this->assertStringContainsString( '<h2>Create Subgroup</h2>', $output );
		$this->assertStringNotContainsString( 'autofocus /><p class="description" id="bits_groupsio_sub_group_name_description">', $output );
	}

	public function test_confirm_delete_for_unmatched_id_shows_error_not_a_confirmation_form(): void {
		$_GET['confirm_delete'] = '404404';

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				array( 'id' => 152360, 'name' => 'perception-is-all+sociology', 'subs_count' => 1 ),
			) ),
		) );

		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		unset( $_GET['confirm_delete'] );

		$this->assertStringNotContainsString( 'Yes, delete this subgroup', $output );
		$this->assertStringContainsString( 'could not be found', $output );
	}
}
