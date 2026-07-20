<?php
/**
 * Level-specific mandatory groups field on the PMPro Edit Level screen.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and persists the per-PMPro-level mandatory Groups.io
 * subgroup list, stored as level meta. Single responsibility: this
 * class does not itself act on the stored slugs — the sync engine
 * added in later phases reads them.
 */
final class LevelMandatoryGroups {

	public const META_KEY = 'bits_groupsio_level_mandatory_groups';

	private const FIELD_NAME = 'bits_groupsio_level_mandatory_groups';
	private const NONCE_NAME = 'bits_groupsio_level_mandatory_groups_nonce';

	/**
	 * Registers the render/save hooks. Called once from Plugin's
	 * constructor.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'pmpro_membership_level_after_other_settings', array( self::class, 'render_field' ) );
		add_action( 'pmpro_save_membership_level', array( self::class, 'save_field' ) );
	}

	/**
	 * Renders the mandatory-groups textarea on the Edit Level screen.
	 *
	 * @param int $level_id The membership level being edited.
	 * @return void
	 */
	public static function render_field( int $level_id ): void {
		$value = self::get_for_level( $level_id );

		wp_nonce_field( 'bits_groupsio_level_mandatory_groups', self::NONCE_NAME );

		printf(
			'<table class="form-table"><tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea id="%1$s" name="%3$s" rows="5" cols="40">%4$s</textarea></td></tr></table>',
			esc_attr( self::FIELD_NAME ),
			esc_html__( 'Mandatory Groups.io subgroups (one slug per line)', 'bits-groupsio-sync' ),
			esc_attr( self::FIELD_NAME ),
			esc_textarea( implode( "\n", $value ) )
		);
	}

	/**
	 * Persists the mandatory-groups field when a level is saved.
	 *
	 * @param int $level_id The membership level being saved.
	 * @return void
	 */
	public static function save_field( int $level_id ): void {
		if (
			! isset( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), 'bits_groupsio_level_mandatory_groups' )
		) {
			return;
		}

		$raw   = isset( $_POST[ self::FIELD_NAME ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) ) : '';
		$lines = Settings::sanitize_lines( $raw );

		update_option( self::option_key( $level_id ), $lines );
	}

	/**
	 * Reads the mandatory subgroup slugs for a given level.
	 *
	 * @param int $level_id The membership level.
	 * @return array<int, string>
	 */
	public static function get_for_level( int $level_id ): array {
		$value = get_option( self::option_key( $level_id ), array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Builds the per-level option key used to store this level's list.
	 *
	 * @param int $level_id The membership level.
	 * @return string
	 */
	public static function option_key( int $level_id ): string {
		return self::META_KEY . '_' . $level_id;
	}
}
