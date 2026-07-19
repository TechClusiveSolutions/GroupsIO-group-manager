<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\Settings;
use WP_UnitTestCase;

final class SettingsTest extends WP_UnitTestCase {

	public function test_clamp_int_clamps_below_minimum(): void {
		$this->assertSame( 0, Settings::clamp_int( -5, 0, 30 ) );
	}

	public function test_clamp_int_clamps_above_maximum(): void {
		$this->assertSame( 30, Settings::clamp_int( 999, 0, 30 ) );
	}

	public function test_clamp_int_passes_through_in_range_value(): void {
		$this->assertSame( 15, Settings::clamp_int( 15, 0, 30 ) );
	}

	public function test_sanitize_lines_trims_and_drops_empty_lines(): void {
		$raw = "  psychology \n\nsociology\n  \nresearch";

		$this->assertSame( array( 'psychology', 'sociology', 'research' ), Settings::sanitize_lines( $raw ) );
	}

	public function test_sanitize_clamps_all_numeric_fields(): void {
		$input = array(
			'grace_period_days'                    => -100,
			'log_retention_days'                   => 999999,
			'mass_action_threshold_count'          => 0,
			'mass_action_threshold_window_minutes' => 999999,
			'magic_link_rate_limit_per_email_hour' => 0,
			'magic_link_rate_limit_per_ip_hour'    => 999999,
		);

		$result = Settings::sanitize( $input );

		$this->assertSame( 0, $result['grace_period_days'] );
		$this->assertSame( 3650, $result['log_retention_days'] );
		$this->assertSame( 1, $result['mass_action_threshold_count'] );
		$this->assertSame( 1440, $result['mass_action_threshold_window_minutes'] );
		$this->assertSame( 1, $result['magic_link_rate_limit_per_email_hour'] );
		$this->assertSame( 1000, $result['magic_link_rate_limit_per_ip_hour'] );
	}

	public function test_sanitize_parses_global_mandatory_groups_textarea(): void {
		$result = Settings::sanitize( array( 'global_mandatory_groups' => "announcements\ngeneral" ) );

		$this->assertSame( array( 'announcements', 'general' ), $result['global_mandatory_groups'] );
	}

	public function test_sanitize_casts_checkboxes_to_booleans(): void {
		$checked = Settings::sanitize( array( 'kill_switch' => '1' ) );
		$this->assertTrue( $checked['kill_switch'] );

		$unchecked = Settings::sanitize( array() );
		$this->assertFalse( $unchecked['kill_switch'] );
	}

	public function test_get_returns_default_when_option_not_set(): void {
		delete_option( Settings::OPTION_NAME );

		$this->assertSame( 3, Settings::get( 'grace_period_days' ) );
	}

	public function test_get_returns_saved_value(): void {
		update_option( Settings::OPTION_NAME, array( 'grace_period_days' => 7 ) );

		$this->assertSame( 7, Settings::get( 'grace_period_days' ) );
	}

	public function test_render_field_outputs_a_number_input_with_current_value(): void {
		update_option( Settings::OPTION_NAME, array( 'grace_period_days' => 5 ) );

		ob_start();
		Settings::render_field( array( 'key' => 'grace_period_days' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<input', $output );
		$this->assertStringContainsString( 'type="number"', $output );
		$this->assertStringContainsString( 'value="5"', $output );
		$this->assertStringContainsString( 'min="0"', $output );
		$this->assertStringContainsString( 'max="30"', $output );
	}

	public function test_render_field_outputs_a_checked_checkbox(): void {
		update_option( Settings::OPTION_NAME, array( 'kill_switch' => true ) );

		ob_start();
		Settings::render_field( array( 'key' => 'kill_switch' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'checked', $output );
	}

	public function test_render_field_outputs_a_textarea_with_lines(): void {
		update_option( Settings::OPTION_NAME, array( 'global_mandatory_groups' => array( 'announcements', 'general' ) ) );

		ob_start();
		Settings::render_field( array( 'key' => 'global_mandatory_groups' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<textarea', $output );
		$this->assertStringContainsString( "announcements\ngeneral", $output );
	}

	public function test_render_page_outputs_a_form_for_an_administrator(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		Settings::register_setting();

		ob_start();
		Settings::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form', $output );
		$this->assertStringContainsString( 'BITS Groups.io Sync', $output );
	}

	public function test_render_page_outputs_nothing_for_a_non_administrator(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		ob_start();
		Settings::render_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_add_menu_page_and_register_setting_run_without_error(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		Settings::add_menu_page();
		Settings::register_setting();

		$this->assertTrue( true );
	}
}
