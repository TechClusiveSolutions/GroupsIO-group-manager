<?php

namespace BITS\GroupsIOSync\Tests\Unit\Admin;

use BITS\GroupsIOSync\Admin\UserAssignmentPage;
use BITS\GroupsIOSync\MemberIndex;
use BITS\GroupsIOSync\QueuedExecutionEngine;
use WP_UnitTestCase;

/**
 * Covers UserAssignmentPage's List view (#60) and Details view (#61),
 * per subgroup-crud-and-admin-pages-design.md section 12. Seeds
 * MemberIndex rows directly via apply_add()/apply_remove() (the same
 * seeding approach MemberIndexTest uses elsewhere) rather than mocking
 * Groups.io HTTP responses, since this page only ever reads the local
 * index and queues jobs via QueuedExecutionEngine - it never calls
 * GroupsIoApiClient itself.
 */
final class UserAssignmentPageTest extends WP_UnitTestCase {

	private const HOOK = 'bits_groupsio_execute_queued_action';

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'GROUPS_IO_API_KEY' ) ) {
			define( 'GROUPS_IO_API_KEY', 'fake-test-key-not-real' );
		}
		if ( ! defined( 'GROUPS_IO_PARENT_GROUP' ) ) {
			define( 'GROUPS_IO_PARENT_GROUP', 'perception-is-all' );
		}

		MemberIndex::create_table();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MemberIndex::table_name() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		set_current_screen( 'dashboard' );

		$_GET  = array();
		$_POST = array();
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	private function seed_member( string $email, string $display_name, array $subgroups ): void {
		foreach ( $subgroups as $subgroup_id => $subgroup_slug ) {
			MemberIndex::apply_add( 0, $email, $display_name, (int) $subgroup_id, (string) $subgroup_slug, '', 1 );
		}
	}

	public function test_renders_no_members_message_when_index_is_empty(): void {
		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No members found.', $output );
	}

	public function test_renders_table_row_per_member_with_name_email_and_group_count(): void {
		$this->seed_member(
			'alice@example.test',
			'Alice Example',
			array(
				900001 => 'perception-is-all',
				900002 => 'perception-is-all+announcements',
			)
		);

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Alice Example', $output );
		$this->assertStringContainsString( 'alice@example.test', $output );
		// One row, two (member, subgroup) pairs indexed above.
		$this->assertMatchesRegularExpression( '/<td>2<\/td>/', $output );
	}

	public function test_falls_back_to_email_as_link_text_when_no_display_name_is_on_file(): void {
		$this->seed_member( 'noname@example.test', '', array( 900001 => 'perception-is-all' ) );

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '>noname@example.test</a>', $output );
	}

	public function test_member_name_links_to_details_view_url_scheme(): void {
		$this->seed_member( 'bob@example.test', 'Bob Example', array( 900001 => 'perception-is-all' ) );

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'view=details', $output );
		$this->assertStringContainsString( 'member=bob%40example.test', $output );
	}

	public function test_search_by_member_name_filters_the_list(): void {
		$this->seed_member( 'alice@example.test', 'Alice Example', array( 900001 => 'perception-is-all' ) );
		$this->seed_member( 'carol@example.test', 'Carol Example', array( 900001 => 'perception-is-all' ) );

		$_GET['s'] = 'Alice';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'alice@example.test', $output );
		$this->assertStringNotContainsString( 'carol@example.test', $output );
	}

	public function test_search_by_member_email_filters_the_list(): void {
		$this->seed_member( 'alice@example.test', 'Alice Example', array( 900001 => 'perception-is-all' ) );
		$this->seed_member( 'carol@example.test', 'Carol Example', array( 900001 => 'perception-is-all' ) );

		$_GET['s'] = 'carol@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'carol@example.test', $output );
		$this->assertStringNotContainsString( 'alice@example.test', $output );
	}

	public function test_search_by_subgroup_name_filters_to_members_of_that_subgroup(): void {
		$this->seed_member(
			'alice@example.test',
			'Alice Example',
			array(
				900001 => 'perception-is-all',
				900002 => 'perception-is-all+announcements',
			)
		);
		$this->seed_member( 'carol@example.test', 'Carol Example', array( 900001 => 'perception-is-all' ) );

		$_GET['s'] = 'announcements';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'alice@example.test', $output );
		$this->assertStringNotContainsString( 'carol@example.test', $output );
	}

	public function test_a_subgroup_match_still_shows_the_members_full_group_count(): void {
		$this->seed_member(
			'alice@example.test',
			'Alice Example',
			array(
				900001 => 'perception-is-all',
				900002 => 'perception-is-all+announcements',
			)
		);

		$_GET['s'] = 'announcements';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		// Both of alice's groups, not just the one matching "announcements".
		$this->assertMatchesRegularExpression( '/<td>2<\/td>/', $output );
	}

	public function test_pagination_controls_appear_only_when_more_than_one_page_exists(): void {
		$this->seed_member( 'solo@example.test', 'Solo Example', array( 900001 => 'perception-is-all' ) );

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Members pagination', $output );
	}

	public function test_pagination_navigates_to_the_correct_subsequent_page(): void {
		// PER_PAGE is 20 - seed 21 distinct members so a second page exists.
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->seed_member(
				sprintf( 'member%02d@example.test', $i ),
				sprintf( 'Member %02d', $i ),
				array( 900001 => 'perception-is-all' )
			);
		}

		ob_start();
		UserAssignmentPage::render();
		$page_one = ob_get_clean();

		$this->assertStringContainsString( 'Members pagination', $page_one );
		$this->assertStringNotContainsString( 'member21@example.test', $page_one );

		$_GET['paged'] = '2';

		ob_start();
		UserAssignmentPage::render();
		$page_two = ob_get_clean();

		$this->assertStringContainsString( 'member21@example.test', $page_two );
		$this->assertStringNotContainsString( 'member01@example.test', $page_two );
	}

	public function test_details_view_renders_no_groups_message_when_member_has_no_groups(): void {
		$_GET['view']   = 'details';
		$_GET['member'] = 'nobody@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No currently subscribed groups found.', $output );
	}

	public function test_details_view_renders_group_row_with_title_and_namespace(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Announcements (perception-is-all+announcements)', $output );
	}

	public function test_details_view_shows_pmpro_expected_and_override_state_as_text(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Manually added', $output, 'apply_add() sets the "added" override flag, which must be shown as text.' );
		$this->assertStringContainsString( '<td>Yes</td>', $output, 'The parent group is always PMPro-expected.' );
	}

	public function test_details_view_search_filters_by_subgroup_name_or_title(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';
		$_GET['s']      = 'announcements';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Announcements', $output );
		$this->assertStringNotContainsString( 'perception-is-all (perception-is-all)', $output );
	}

	public function test_details_view_pagination_navigates_to_the_correct_subsequent_page(): void {
		// PER_PAGE is 20 - seed 21 groups (padded titles so alphabetic sort matches numeric order).
		for ( $i = 1; $i <= 21; $i++ ) {
			MemberIndex::apply_add( 0, 'target@example.test', 'Target', $i, "perception-is-all+list{$i}", sprintf( 'List %02d', $i ), 1 );
		}

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$page_one = ob_get_clean();

		$this->assertStringContainsString( 'Groups pagination', $page_one );
		$this->assertStringNotContainsString( 'List 21', $page_one );

		$_GET['paged'] = '2';

		ob_start();
		UserAssignmentPage::render();
		$page_two = ob_get_clean();

		$this->assertStringContainsString( 'List 21', $page_two );
		$this->assertStringNotContainsString( 'List 01', $page_two );
	}

	public function test_details_view_links_to_the_add_groups_view_url_scheme(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'view=add-groups', $output );
		$this->assertStringContainsString( 'member=target%40example.test', $output );
	}

	public function test_details_view_shows_clear_override_link_only_for_overridden_rows(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Clear override', $output );
		$this->assertStringContainsString( 'bits_groupsio_action=clear_override', $output );
	}

	public function test_details_view_shows_parent_removal_confirmation_when_query_arg_present(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_GET['view']                  = 'details';
		$_GET['member']                = 'target@example.test';
		$_GET['confirm_remove_parent'] = '1';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( "Removing the parent group removes this member from all of BITS", $output );
		$this->assertStringContainsString( 'confirm_parent_remove', $output );
	}

	public function test_process_remove_selected_queues_a_job_per_checked_non_parent_group(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 3, 'perception-is-all+sustaining', 'Sustaining', 1 );

		$_POST['member']       = 'target@example.test';
		$_POST['subgroup_ids'] = array( '2', '3' );
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_remove_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_remove_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertFalse( $result['invalid'] );
		$this->assertSame( 2, $result['queued_count'] );
		$this->assertSame( 0, $result['parent_id'] );
		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_process_remove_selected_defers_the_parent_row_pending_confirmation(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$_POST['member']       = 'target@example.test';
		$_POST['subgroup_ids'] = array( '1', '2' );
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_remove_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_remove_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertFalse( $result['invalid'] );
		$this->assertSame( 1, $result['queued_count'], 'The non-parent row still queues immediately.' );
		$this->assertSame( 1, $result['parent_id'], 'The parent row is deferred, not queued, pending confirmation.' );
	}

	public function test_process_remove_selected_is_invalid_when_nothing_checked(): void {
		$_POST['member']       = 'target@example.test';
		$_POST['subgroup_ids'] = array();
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_remove_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_remove_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $result['invalid'] );
	}

	public function test_process_confirm_parent_remove_queues_the_parent_removal(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_POST['member']      = 'target@example.test';
		$_POST['subgroup_id'] = '1';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_confirm_parent_remove' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_confirm_parent_remove();

		unset( $_POST['member'], $_POST['subgroup_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertFalse( $result['invalid'] );
		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_process_confirm_parent_remove_rejects_a_non_parent_subgroup_id(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$_POST['member']      = 'target@example.test';
		$_POST['subgroup_id'] = '2';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_confirm_parent_remove' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_confirm_parent_remove();

		unset( $_POST['member'], $_POST['subgroup_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $result['invalid'], 'A non-parent subgroup id must never be accepted by this action.' );
		$this->assertFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_process_clear_override_clears_the_flag(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$_GET['member']       = 'target@example.test';
		$_GET['subgroup_id']  = '2';
		$_GET['_wpnonce']     = wp_create_nonce( 'bits_groupsio_clear_override' );
		$_REQUEST['_wpnonce'] = $_GET['_wpnonce'];

		$result = UserAssignmentPage::process_clear_override();

		unset( $_GET['member'], $_GET['subgroup_id'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertFalse( $result['invalid'] );
		$rows = MemberIndex::get_member_groups( 'target@example.test', 1, 20 );
		$this->assertNull( $rows[0]['override_type'] );
	}

	public function test_process_clear_override_is_invalid_without_required_fields(): void {
		$_GET['_wpnonce']     = wp_create_nonce( 'bits_groupsio_clear_override' );
		$_REQUEST['_wpnonce'] = $_GET['_wpnonce'];

		$result = UserAssignmentPage::process_clear_override();

		unset( $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $result['invalid'] );
	}

	public function test_add_groups_view_renders_no_groups_message_when_nothing_addable(): void {
		$_GET['view']   = 'add-groups';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No groups available to add.', $output );
	}

	public function test_add_groups_view_renders_checkbox_row_with_title_and_namespace(): void {
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$_GET['view']   = 'add-groups';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Announcements (perception-is-all+announcements)', $output );
	}

	public function test_add_groups_view_excludes_groups_the_member_is_already_in(): void {
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_GET['view']   = 'add-groups';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Announcements (perception-is-all+announcements)', $output );
		$this->assertStringNotContainsString( 'perception-is-all (perception-is-all)', $output );
	}

	public function test_add_groups_view_search_filters_by_subgroup_name_or_title(): void {
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$_GET['view']   = 'add-groups';
		$_GET['member'] = 'target@example.test';
		$_GET['s']      = 'announcements';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Announcements', $output );
		$this->assertStringNotContainsString( 'perception-is-all (perception-is-all)', $output );
	}

	public function test_add_groups_view_pagination_navigates_to_the_correct_subsequent_page(): void {
		// PER_PAGE is 20 - seed 21 groups (padded titles so alphabetic sort matches numeric order).
		for ( $i = 1; $i <= 21; $i++ ) {
			MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', $i, "perception-is-all+list{$i}", sprintf( 'List %02d', $i ), 1 );
		}

		$_GET['view']   = 'add-groups';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$page_one = ob_get_clean();

		$this->assertStringContainsString( 'Add groups pagination', $page_one );
		$this->assertStringNotContainsString( 'List 21', $page_one );

		$_GET['paged'] = '2';

		ob_start();
		UserAssignmentPage::render();
		$page_two = ob_get_clean();

		$this->assertStringContainsString( 'List 21', $page_two );
		$this->assertStringNotContainsString( 'List 01', $page_two );
	}

	public function test_add_groups_view_links_back_to_the_details_view(): void {
		$_GET['view']   = 'add-groups';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'view=details', $output );
		$this->assertStringContainsString( 'Back to Details', $output );
	}

	public function test_process_add_selected_queues_a_job_per_checked_group(): void {
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 3, 'perception-is-all+sustaining', 'Sustaining', 1 );

		$_POST['member']       = 'target@example.test';
		$_POST['subgroup_ids'] = array( '2', '3' );
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_add_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_add_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertFalse( $result['invalid'] );
		$this->assertSame( 2, $result['queued_count'] );
		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_process_add_selected_ignores_ids_not_in_the_addable_set(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_POST['member']       = 'target@example.test';
		$_POST['subgroup_ids'] = array( '1' );
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_add_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_add_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $result['invalid'], 'A group the member is already in must not be queued via this action.' );
		$this->assertFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_process_add_selected_is_invalid_when_nothing_checked(): void {
		$_POST['member']       = 'target@example.test';
		$_POST['subgroup_ids'] = array();
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_add_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_add_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $result['invalid'] );
	}

	/**
	 * Regression test (#78): an explicit action="{admin_url}/admin.php"
	 * on these POST forms strips the page query arg WordPress needs to
	 * route the submission to this page's own load-{hook} handler -
	 * maybe_handle_post() never runs, and admin.php renders a blank
	 * response. None of the process_*() unit tests above catch this,
	 * since they call the process methods directly and never render or
	 * inspect the actual <form> tag - only a rendered-output assertion
	 * like this one does. Every state-changing POST form on this page
	 * must omit action entirely (submitting back to the current URL, the
	 * same convention SubgroupManagementPage's own forms use), never set
	 * an explicit admin.php action.
	 */
	public function test_remove_selected_form_omits_action_so_it_posts_back_to_the_current_url(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form method="post">', $output );
		$this->assertStringNotContainsString( 'method="post" action=', $output );
	}

	public function test_parent_removal_confirmation_form_omits_action_so_it_posts_back_to_the_current_url(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_GET['view']                  = 'details';
		$_GET['member']                = 'target@example.test';
		$_GET['confirm_remove_parent'] = '1';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form method="post">', $output );
		$this->assertStringNotContainsString( 'method="post" action=', $output );
	}

	public function test_add_selected_form_omits_action_so_it_posts_back_to_the_current_url(): void {
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$_GET['view']   = 'add-groups';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form method="post">', $output );
		$this->assertStringNotContainsString( 'method="post" action=', $output );
	}

	public function test_details_view_shows_the_add_groups_link_even_with_no_currently_subscribed_groups(): void {
		// A member manually removed from every group has no rows
		// get_member_groups() would return, but must still be able to
		// reach the Add Groups view - this is exactly the case where
		// that link is most needed.
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_remove( 'target@example.test', 1, 1 );

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No currently subscribed groups found.', $output );
		$this->assertStringContainsString( 'view=add-groups', $output );
		$this->assertStringContainsString( 'Add groups', $output );
	}

	public function test_list_view_shows_the_sync_button(): void {
		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'bits_groupsio_action" value="sync"', $output );
	}

	public function test_details_view_shows_the_sync_button_with_view_and_member_context(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$_GET['view']   = 'details';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'bits_groupsio_action" value="sync"', $output );
		$this->assertStringContainsString( 'name="sync_view" value="details"', $output );
		$this->assertStringContainsString( 'name="member" value="target@example.test"', $output );
	}

	public function test_add_groups_view_shows_the_sync_button_with_view_and_member_context(): void {
		$_GET['view']   = 'add-groups';
		$_GET['member'] = 'target@example.test';

		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'bits_groupsio_action" value="sync"', $output );
		$this->assertStringContainsString( 'name="sync_view" value="add-groups"', $output );
		$this->assertStringContainsString( 'name="member" value="target@example.test"', $output );
	}

	public function test_process_sync_processes_due_jobs_and_returns_the_submitted_view_context(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );
		QueuedExecutionEngine::queue_remove( 'target@example.test', 1, 1 );

		$_POST['sync_view']   = 'details';
		$_POST['member']      = 'target@example.test';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_sync' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_sync();

		unset( $_POST['sync_view'], $_POST['member'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertGreaterThanOrEqual( 1, $result['count'] );
		$this->assertSame( 'details', $result['view'] );
		$this->assertSame( 'target@example.test', $result['member'] );
	}

	public function test_process_remove_selected_blocks_removing_the_owner_from_the_parent_group(): void {
		global $wpdb;

		MemberIndex::apply_add( 0, 'owner@example.test', 'Owner', 1, 'perception-is-all', '', 1 );
		$wpdb->update(
			MemberIndex::table_name(),
			array( 'is_owner' => 1 ),
			array( 'email' => 'owner@example.test', 'subgroup_id' => 1 )
		);

		$_POST['member']       = 'owner@example.test';
		$_POST['subgroup_ids'] = array( '1' );
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_remove_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_remove_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $result['owner_blocked'] );
		$this->assertSame( 0, $result['parent_id'], 'The owner must never be shown the confirmation step at all.' );
		$this->assertFalse( $result['invalid'], 'owner_blocked is a distinct outcome from a plain invalid request.' );
	}

	public function test_process_remove_selected_still_queues_non_parent_groups_when_owner_blocked(): void {
		global $wpdb;

		MemberIndex::apply_add( 0, 'owner@example.test', 'Owner', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'owner@example.test', 'Owner', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		$wpdb->update(
			MemberIndex::table_name(),
			array( 'is_owner' => 1 ),
			array( 'email' => 'owner@example.test', 'subgroup_id' => 1 )
		);

		$_POST['member']       = 'owner@example.test';
		$_POST['subgroup_ids'] = array( '1', '2' );
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_remove_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_remove_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $result['owner_blocked'] );
		$this->assertSame( 1, $result['queued_count'], 'The non-parent row must still queue normally.' );
	}

	public function test_process_confirm_parent_remove_blocks_the_owner_as_defense_in_depth(): void {
		global $wpdb;

		MemberIndex::apply_add( 0, 'owner@example.test', 'Owner', 1, 'perception-is-all', '', 1 );
		$wpdb->update(
			MemberIndex::table_name(),
			array( 'is_owner' => 1 ),
			array( 'email' => 'owner@example.test', 'subgroup_id' => 1 )
		);

		$_POST['member']      = 'owner@example.test';
		$_POST['subgroup_id'] = '1';
		$_POST['_wpnonce']    = wp_create_nonce( 'bits_groupsio_confirm_parent_remove' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_confirm_parent_remove();

		unset( $_POST['member'], $_POST['subgroup_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $result['owner_blocked'] );
		$this->assertFalse( $result['invalid'] );
	}

	public function test_process_remove_selected_allows_removing_a_non_owner_from_the_parent_group(): void {
		MemberIndex::apply_add( 0, 'plain@example.test', 'Plain', 1, 'perception-is-all', '', 1 );

		$_POST['member']       = 'plain@example.test';
		$_POST['subgroup_ids'] = array( '1' );
		$_POST['_wpnonce']     = wp_create_nonce( 'bits_groupsio_remove_selected' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$result = UserAssignmentPage::process_remove_selected();

		unset( $_POST['member'], $_POST['subgroup_ids'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertFalse( $result['owner_blocked'] );
		$this->assertSame( 1, $result['parent_id'], 'A non-owner still goes through the normal confirmation flow.' );
	}
}
