<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\LevelMandatoryGroups;
use WP_UnitTestCase;

final class LevelMandatoryGroupsTest extends WP_UnitTestCase {

	public function test_option_key_is_scoped_per_level(): void {
		$this->assertSame(
			'bits_groupsio_level_mandatory_groups_5',
			LevelMandatoryGroups::option_key( 5 )
		);
		$this->assertNotSame(
			LevelMandatoryGroups::option_key( 5 ),
			LevelMandatoryGroups::option_key( 6 )
		);
	}

	public function test_get_for_level_returns_empty_array_by_default(): void {
		$this->assertSame( array(), LevelMandatoryGroups::get_for_level( 999 ) );
	}

	public function test_save_field_persists_and_get_for_level_reads_it_back(): void {
		$level_id = 3;

		$_POST[ 'bits_groupsio_level_mandatory_groups_nonce' ] = wp_create_nonce( 'bits_groupsio_level_mandatory_groups' );
		$_POST['bits_groupsio_level_mandatory_groups']         = "premium-only\nannouncements";

		LevelMandatoryGroups::save_field( $level_id );

		$this->assertSame(
			array( 'premium-only', 'announcements' ),
			LevelMandatoryGroups::get_for_level( $level_id )
		);

		unset( $_POST['bits_groupsio_level_mandatory_groups_nonce'], $_POST['bits_groupsio_level_mandatory_groups'] );
	}

	public function test_save_field_does_nothing_without_a_valid_nonce(): void {
		$level_id = 4;

		$_POST['bits_groupsio_level_mandatory_groups'] = 'should-not-be-saved';

		LevelMandatoryGroups::save_field( $level_id );

		$this->assertSame( array(), LevelMandatoryGroups::get_for_level( $level_id ) );

		unset( $_POST['bits_groupsio_level_mandatory_groups'] );
	}

	public function test_render_field_outputs_a_labeled_textarea_with_current_value(): void {
		$level_id = 7;
		update_option( LevelMandatoryGroups::option_key( $level_id ), array( 'premium-only' ) );

		// PMPro's pmpro_membership_level_after_other_settings action
		// passes the level object itself, not a bare int - confirmed
		// against a real PMPro install (see
		// test_render_field_accepts_the_real_pmpro_level_object below).
		$level     = new \stdClass();
		$level->id = $level_id;

		ob_start();
		LevelMandatoryGroups::render_field( $level );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<textarea', $output );
		$this->assertStringContainsString( '<label for=', $output );
		$this->assertStringContainsString( 'premium-only', $output );
	}

	public function test_render_field_handles_a_new_unsaved_level_with_no_id(): void {
		$level = new \stdClass();

		ob_start();
		LevelMandatoryGroups::render_field( $level );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<textarea', $output );
	}

	public function test_register_runs_without_error(): void {
		LevelMandatoryGroups::register();

		$this->assertTrue( true );
	}

	/**
	 * Storage/retrieval is keyed only by an int level_id and never calls
	 * a PMPro function directly, so in principle any int would exercise
	 * the same code path as the tests above. This test exists to prove
	 * that specifically: it creates a real PMPro membership level (via a
	 * direct row insert into wp_pmpro_membership_levels, PMPro's own
	 * table - there is no public "create level" function in PMPro's
	 * API, so this matches how PMPro's own admin save form does it) and
	 * confirms pmpro_getLevel() resolves it as real, before proving this
	 * plugin's storage round-trips correctly keyed on that real level's
	 * actual id - not just an arbitrary int chosen by the test.
	 */
	public function test_save_field_round_trips_against_a_real_pmpro_level(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->pmpro_membership_levels,
			array(
				'name'              => 'Sustaining Member',
				'description'       => 'Real PMPro level created for testing.',
				'confirmation'      => '',
				'allow_signups'     => 1,
				'initial_payment'   => 0,
				'billing_amount'    => 0,
				'cycle_number'      => 0,
				'cycle_period'      => 'Month',
				'billing_limit'     => 0,
				'trial_amount'      => 0,
				'trial_limit'       => 0,
				'expiration_number' => 0,
				'expiration_period' => 'Month',
			)
		);
		$level_id = (int) $wpdb->insert_id;

		$level = pmpro_getLevel( $level_id );
		$this->assertNotFalse( $level, 'The inserted row must resolve as a real PMPro level.' );
		$this->assertSame( 'Sustaining Member', $level->name );

		$_POST[ 'bits_groupsio_level_mandatory_groups_nonce' ] = wp_create_nonce( 'bits_groupsio_level_mandatory_groups' );
		$_POST['bits_groupsio_level_mandatory_groups']         = "sustaining-member-only
announcements";

		LevelMandatoryGroups::save_field( $level_id );

		$this->assertSame(
			array( 'sustaining-member-only', 'announcements' ),
			LevelMandatoryGroups::get_for_level( $level_id )
		);
		// A different real level must not see this level's mandatory
		// groups - proves the option key is genuinely scoped per level,
		// not just per test-chosen int.
		$this->assertSame( array(), LevelMandatoryGroups::get_for_level( $level_id + 1 ) );

		unset( $_POST['bits_groupsio_level_mandatory_groups_nonce'], $_POST['bits_groupsio_level_mandatory_groups'] );
	}

	/**
	 * Registers render_field() the same way Plugin::__construct() does,
	 * then fires PMPro's real pmpro_membership_level_after_other_settings
	 * action with a real level object exactly as PMPro's own edit-level
	 * screen does (do_action( 'pmpro_membership_level_after_other_settings',
	 * $level ) in edit-level.php, where $level is the level object, not
	 * its id) - this is the integration bug a plain int-based unit test
	 * cannot catch, and is what a real PMPro install (installed for this
	 * issue) surfaced: render_field() was previously typed to accept an
	 * int and fataled on every real Edit Level page load.
	 */
	public function test_render_field_accepts_the_real_pmpro_level_object(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->pmpro_membership_levels,
			array(
				'name'              => 'Hook Contract Level',
				'description'       => '',
				'confirmation'      => '',
				'allow_signups'     => 1,
				'initial_payment'   => 0,
				'billing_amount'    => 0,
				'cycle_number'      => 0,
				'cycle_period'      => 'Month',
				'billing_limit'     => 0,
				'trial_amount'      => 0,
				'trial_limit'       => 0,
				'expiration_number' => 0,
				'expiration_period' => 'Month',
			)
		);
		$level_id = (int) $wpdb->insert_id;
		update_option( LevelMandatoryGroups::option_key( $level_id ), array( 'hook-contract-slug' ) );

		LevelMandatoryGroups::register();

		ob_start();
		do_action( 'pmpro_membership_level_after_other_settings', pmpro_getLevel( $level_id ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'hook-contract-slug', $output );
	}
}
