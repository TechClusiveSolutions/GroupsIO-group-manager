<?php
/**
 * GroupsIO Management > User Assignment admin page.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder for this pass: the menu scaffold (#33) registers this
 * page as the default GroupsIO Management landing page ahead of its
 * real functionality (manual per-member subgroup add/remove and the
 * sticky-override flag), which is built separately (#35), per
 * subgroup-crud-and-admin-pages-design.md sections 7 and 10.
 */
final class UserAssignmentPage {

	/**
	 * Renders the page. Callback for add_menu_page()/add_submenu_page().
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'User Assignment', 'bits-groupsio-sync' ) . '</h1>';
		echo '<p>' . esc_html__( 'Not yet available: manual per-member subgroup assignment has not been built yet.', 'bits-groupsio-sync' ) . '</p>';
		echo '</div>';
	}
}
