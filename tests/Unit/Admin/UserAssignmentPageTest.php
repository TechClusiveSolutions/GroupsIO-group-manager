<?php

namespace BITS\GroupsIOSync\Tests\Unit\Admin;

use BITS\GroupsIOSync\Admin\UserAssignmentPage;
use BITS\GroupsIOSync\MemberIndex;
use WP_UnitTestCase;

/**
 * Covers UserAssignmentPage's List view (#60) - the only view built in
 * this pass, per subgroup-crud-and-admin-pages-design.md section 12.
 * Seeds MemberIndex rows directly via apply_add() (the same seeding
 * approach MemberIndexTest uses elsewhere) rather than mocking
 * Groups.io HTTP responses, since this page only ever reads the local
 * index - it never calls GroupsIoApiClient itself.
 */
final class UserAssignmentPageTest extends WP_UnitTestCase {

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

		$_GET = array();
	}

	public function tear_down(): void {
		$_GET = array();

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
}
