<?php
/**
 * Shared accesskey label helper for the plugin's admin pages.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends a visible "(Alt+<key>)" hint to a control's label so its
 * accesskey is discoverable without relying on visual-only affordance
 * (it becomes part of the control's accessible name), per
 * docs/ux-accessibility.md section 6.
 */
final class AccessKeys {

	/**
	 * Builds a label with its accesskey hint appended.
	 *
	 * @param string $label Visible label text.
	 * @param string $key   Single-letter accesskey, per the global mapping in docs/ux-accessibility.md section 6.
	 * @return string
	 */
	public static function label( string $label, string $key ): string {
		return sprintf(
			/* translators: 1: control label, 2: single-letter access key */
			__( '%1$s (Alt+%2$s)', 'bits-groupsio-sync' ),
			$label,
			$key
		);
	}
}
