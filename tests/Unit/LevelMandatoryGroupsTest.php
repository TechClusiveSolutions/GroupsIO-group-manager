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

		ob_start();
		LevelMandatoryGroups::render_field( $level_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<textarea', $output );
		$this->assertStringContainsString( '<label for=', $output );
		$this->assertStringContainsString( 'premium-only', $output );
	}

	public function test_register_runs_without_error(): void {
		LevelMandatoryGroups::register();

		$this->assertTrue( true );
	}
}
