<?php
/**
 * Registers the "GroupsIO Management" top-level admin menu and its
 * three submenu pages.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single responsibility: menu registration only. Each submenu page's
 * own rendering/data logic lives in its own class (UserAssignmentPage,
 * FeatureControlsPage, SubgroupManagementPage), per CLAUDE.md's
 * single-responsibility-per-class rule and the design doc's
 * includes/Admin/ layout (subgroup-crud-and-admin-pages-design.md
 * section 7).
 */
final class GroupsIoManagementMenu {

	/**
	 * Also used as the top-level menu_slug, so that the auto-duplicated
	 * first submenu item WordPress creates for add_menu_page() is
	 * overridden by the explicit User Assignment add_submenu_page()
	 * call below (same slug), making it the default landing page.
	 */
	public const SLUG_USER_ASSIGNMENT = 'bits-groupsio-user-assignment';

	/**
	 * Registers the admin_menu hook. Called once from Plugin's constructor.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu_pages' ) );
	}

	/**
	 * Adds the top-level "GroupsIO Management" menu and its three
	 * submenu pages: User Assignment (default), Feature Controls,
	 * Subgroup Management.
	 *
	 * @return void
	 */
	public static function add_menu_pages(): void {
		add_menu_page(
			__( 'GroupsIO Management', 'bits-groupsio-sync' ),
			__( 'GroupsIO Management', 'bits-groupsio-sync' ),
			'manage_options',
			self::SLUG_USER_ASSIGNMENT,
			array( UserAssignmentPage::class, 'render' ),
			'dashicons-groups'
		);

		add_submenu_page(
			self::SLUG_USER_ASSIGNMENT,
			__( 'User Assignment', 'bits-groupsio-sync' ),
			__( 'User Assignment', 'bits-groupsio-sync' ),
			'manage_options',
			self::SLUG_USER_ASSIGNMENT,
			array( UserAssignmentPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG_USER_ASSIGNMENT,
			__( 'Feature Controls', 'bits-groupsio-sync' ),
			__( 'Feature Controls', 'bits-groupsio-sync' ),
			'manage_options',
			FeatureControlsPage::SLUG,
			array( FeatureControlsPage::class, 'render' )
		);

		$subgroup_management_hook = add_submenu_page(
			self::SLUG_USER_ASSIGNMENT,
			__( 'Subgroup Management', 'bits-groupsio-sync' ),
			__( 'Subgroup Management', 'bits-groupsio-sync' ),
			'manage_options',
			SubgroupManagementPage::SLUG,
			array( SubgroupManagementPage::class, 'render' )
		);

		// SubgroupManagementPage handles its own POST actions on
		// load-{$hook_suffix} rather than inside render(): WordPress
		// always prints the admin header/nav before a page's render
		// callback runs, so a redirect issued from inside render() on a
		// real submission fails with "headers already sent" — the
		// load-{hook} action fires before any output, which is the
		// standard WordPress hook for a page's own early
		// processing/redirect needs.
		if ( false !== $subgroup_management_hook ) {
			add_action( "load-{$subgroup_management_hook}", array( SubgroupManagementPage::class, 'maybe_handle_post' ) );
		}
	}
}
