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
	 * the order they'll be requested.
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

	public function test_process_create_name_only_confirms_via_read_back(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'name' => 'perception-is-all+new-subgroup' ) ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152999, 'perception-is-all+new-subgroup' ),
			) ),
		) );

		$_POST['sub_group_name'] = 'new-subgroup';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'created', $code );
		$this->assertSame( '', $detail );
	}

	public function test_process_create_with_title_sends_follow_up_update_and_confirms_it(): void {
		$this->queue_responses( array(
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'name' => 'perception-is-all+new-subgroup' ) ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152999, 'perception-is-all+new-subgroup' ),
			) ),
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152999, 'title' => 'New Title' ) ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152999, 'perception-is-all+new-subgroup', 'New Title' ),
			) ),
		) );

		$_POST['sub_group_name'] = 'new-subgroup';
		$_POST['title']          = 'New Title';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['title'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'created', $code );
	}

	public function test_process_create_api_failure_returns_friendly_detail(): void {
		$this->queue_responses( array(
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'bad_request', 'extra' => 'name already taken' ) ),
		) );

		$_POST['sub_group_name'] = 'sociology';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_create_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_create();

		unset( $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'create_failed', $code );
		$this->assertSame( 'name already taken', $detail );
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

	public function test_process_update_sends_only_changed_fields_and_confirms_via_read_back(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+sociology' => 152360 ) );

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', 'Old Title', 'Old desc' ),
			) ),
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152360, 'title' => 'New Title' ) ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', 'New Title', 'Old desc' ),
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

		$this->assertSame( 'updated', $code );
	}

	public function test_process_update_name_change_invalidates_old_slug_cache_entry(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+old-name' => 152360 ) );

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+old-name' ),
			) ),
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152360, 'name' => 'perception-is-all+new-name' ) ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+new-name' ),
			) ),
		) );

		$_POST['subgroup_id']    = '152360';
		$_POST['current_slug']   = 'perception-is-all+old-name';
		$_POST['sub_group_name'] = 'new-name';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_update_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_update();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['sub_group_name'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'updated', $code );

		$cache = get_option( 'bits_groupsio_subgroup_cache' );
		$this->assertArrayNotHasKey( 'perception-is-all+old-name', $cache );
	}

	public function test_process_update_fails_when_read_back_does_not_match(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', 'Old Title' ),
			) ),
			$this->json_response( 200, array( 'object' => 'group', 'id' => 152360, 'title' => 'New Title' ) ),
			// Read-back shows the title never actually changed.
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152360, 'perception-is-all+sociology', 'Old Title' ),
			) ),
		) );

		$_POST['subgroup_id']    = '152360';
		$_POST['current_slug']   = 'perception-is-all+sociology';
		$_POST['sub_group_name'] = 'sociology';
		$_POST['title']          = 'New Title';
		$_POST['_wpnonce']       = wp_create_nonce( 'bits_groupsio_update_subgroup' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_update();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['sub_group_name'], $_POST['title'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'update_failed', $code );
		$this->assertStringContainsString( 'could not be confirmed', $detail );
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

	public function test_process_delete_success_confirms_via_read_back_and_invalidates_cache(): void {
		update_option( 'bits_groupsio_subgroup_cache', array( 'perception-is-all+doomed' => 152361 ) );

		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152361, 'perception-is-all+doomed' ),
			) ),
			$this->json_response( 200, array( 'object' => 'ok' ) ),
			$this->subgroups_list_response( array() ),
		) );

		$_POST['subgroup_id']  = '152361';
		$_POST['current_slug'] = 'perception-is-all+doomed';
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'deleted', $code );

		$cache = get_option( 'bits_groupsio_subgroup_cache' );
		$this->assertArrayNotHasKey( 'perception-is-all+doomed', $cache );
	}

	public function test_process_delete_fails_when_read_back_still_shows_subgroup(): void {
		$this->queue_responses( array(
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152361, 'perception-is-all+stubborn' ),
			) ),
			$this->json_response( 200, array( 'object' => 'ok' ) ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 152361, 'perception-is-all+stubborn' ),
			) ),
		) );

		$_POST['subgroup_id']  = '152361';
		$_POST['current_slug'] = 'perception-is-all+stubborn';
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_delete_subgroup' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		list( $code, $detail ) = SubgroupManagementPage::process_delete();

		unset( $_POST['subgroup_id'], $_POST['current_slug'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertSame( 'delete_failed', $code );
		$this->assertStringContainsString( 'could not be confirmed', $detail );
	}
}
