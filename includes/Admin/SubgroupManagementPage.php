<?php
/**
 * GroupsIO Management > Subgroup Management admin page.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder for this pass: the menu scaffold (#33) registers this
 * page ahead of its real functionality (create/list/update/delete
 * subgroups and view membership), which is built separately (#34), per
 * subgroup-crud-and-admin-pages-design.md sections 7 and 9.
 */
final class SubgroupManagementPage {

	public const SLUG = 'bits-groupsio-subgroup-management';

	/**
	 * Renders the page. Callback for add_submenu_page().
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Subgroup Management', 'bits-groupsio-sync' ) . '</h1>';
		echo '<p>' . esc_html__( 'Not yet available: subgroup create/list/update/delete has not been built yet.', 'bits-groupsio-sync' ) . '</p>';
		echo '</div>';
	}
}
