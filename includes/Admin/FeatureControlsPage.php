<?php
/**
 * GroupsIO Management > Feature Controls admin page.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

use BITS\GroupsIOSync\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relocates the existing Phase 1 Settings page under the new GroupsIO
 * Management menu. The underlying Settings class and its option
 * storage are not rebuilt — this class only owns the new menu slug and
 * delegates rendering straight to Settings::render_page(), per
 * subgroup-crud-and-admin-pages-design.md section 8.
 */
final class FeatureControlsPage {

	public const SLUG = 'bits-groupsio-feature-controls';

	/**
	 * Renders the page. Callback for add_submenu_page().
	 *
	 * @return void
	 */
	public static function render(): void {
		Settings::render_page();
	}
}
