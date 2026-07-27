<?php

namespace BITS\GroupsIOSync\Tests\Unit\Admin;

use BITS\GroupsIOSync\Admin\FeatureControlsPage;
use BITS\GroupsIOSync\Admin\GroupsIoManagementMenu;
use BITS\GroupsIOSync\Admin\SubgroupManagementPage;
use BITS\GroupsIOSync\Admin\UserAssignmentPage;
use WP_UnitTestCase;

final class GroupsIoManagementMenuTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $menu, $submenu;
		$menu    = array();
		$submenu = array();

		set_current_screen( 'dashboard' );
	}

	public function test_add_menu_pages_registers_top_level_and_three_submenu_slugs(): void {
		GroupsIoManagementMenu::add_menu_pages();

		global $submenu;

		$slugs = array_column( $submenu[ GroupsIoManagementMenu::SLUG_USER_ASSIGNMENT ], 2 );

		$this->assertContains( GroupsIoManagementMenu::SLUG_USER_ASSIGNMENT, $slugs );
		$this->assertContains( FeatureControlsPage::SLUG, $slugs );
		$this->assertContains( SubgroupManagementPage::SLUG, $slugs );
	}

	public function test_top_level_menu_click_lands_on_user_assignment_slug(): void {
		GroupsIoManagementMenu::add_menu_pages();

		global $menu;

		$top_level_slugs = array_column( $menu, 2 );

		$this->assertContains( GroupsIoManagementMenu::SLUG_USER_ASSIGNMENT, $top_level_slugs );
	}

	public function test_user_assignment_page_renders_heading_for_capable_user(): void {
		ob_start();
		UserAssignmentPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'User Assignment', $output );
	}

	public function test_subgroup_management_page_renders_heading_for_capable_user(): void {
		ob_start();
		SubgroupManagementPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Subgroup Management', $output );
	}

	public function test_feature_controls_page_delegates_to_settings_render_page(): void {
		ob_start();
		FeatureControlsPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Feature Controls', $output );
		$this->assertStringContainsString( '<form action="options.php"', $output );
	}
}
