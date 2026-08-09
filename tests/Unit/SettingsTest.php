<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\AuditLog;
use BITS\GroupsIOSync\Settings;
use WP_UnitTestCase;

final class SettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		AuditLog::create_table();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AuditLog::table_name() );
		delete_option( Settings::OPTION_NAME );
	}

	private function last_audit_row(): ?array {
		global $wpdb;

		$row = $wpdb->get_row( 'SELECT * FROM ' . AuditLog::table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		return $row ?: null;
	}

	public function test_clamp_int_clamps_below_minimum(): void {
		$this->assertSame( 0, Settings::clamp_int( -5, 0, 30 ) );
	}

	public function test_clamp_int_clamps_above_maximum(): void {
		$this->assertSame( 30, Settings::clamp_int( 999, 0, 30 ) );
	}

	public function test_clamp_int_passes_through_in_range_value(): void {
		$this->assertSame( 15, Settings::clamp_int( 15, 0, 30 ) );
	}

	public function test_sanitize_lines_handles_array_input_without_corrupting_it(): void {
		$this->assertSame(
			array( 'announcements', 'general' ),
			Settings::sanitize_lines( array( 'announcements', 'general' ) )
		);
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
		$this->assertStringContainsString( 'Feature Controls', $output );
	}

	public function test_render_page_outputs_a_reset_button(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		Settings::register_setting();

		ob_start();
		Settings::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="reset"', $output );
	}

	public function test_render_page_outputs_a_disabled_dry_run_stub_with_description(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		Settings::register_setting();

		ob_start();
		Settings::render_page();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<button[^>]*disabled[^>]*aria-describedby="bits_groupsio_sync_dry_run_stub_description"/', $output );
		$this->assertStringContainsString( 'Run Dry-Run Now', $output );
		$this->assertStringContainsString( 'id="bits_groupsio_sync_dry_run_stub_description"', $output );
		$this->assertStringContainsString( 'Phase 5', $output );
	}

	public function test_render_page_outputs_nothing_for_a_non_administrator(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		ob_start();
		Settings::render_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_render_field_associates_label_via_aria_describedby_and_description(): void {
		ob_start();
		Settings::render_field( array( 'key' => 'grace_period_days' ) );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/aria-describedby="bits_groupsio_sync_settings_grace_period_days_description"/', $output );
		$this->assertStringContainsString( 'class="description" id="bits_groupsio_sync_settings_grace_period_days_description"', $output );
		$this->assertStringContainsString( 'days', $output );
	}

	public function test_render_field_adds_autofocus_only_to_first_field(): void {
		ob_start();
		Settings::render_field( array( 'key' => 'grace_period_days', 'first' => true ) );
		$first_output = ob_get_clean();

		ob_start();
		Settings::render_field( array( 'key' => 'grace_period_days', 'first' => false ) );
		$other_output = ob_get_clean();

		$this->assertStringContainsString( 'autofocus', $first_output );
		$this->assertStringNotContainsString( 'autofocus', $other_output );
	}

	public function test_register_setting_wraps_field_labels_in_label_for(): void {
		global $wp_settings_fields;

		Settings::register_setting();

		$field = $wp_settings_fields['bits-groupsio-sync']['bits_groupsio_sync_main']['grace_period_days'];

		$this->assertStringContainsString( '<label for="bits_groupsio_sync_settings_grace_period_days">', $field['title'] );
	}

	public function test_register_setting_runs_without_error(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		Settings::register_setting();

		$this->assertTrue( true );
	}

	public function test_log_change_records_a_boolean_field_toggle(): void {
		Settings::log_change(
			array_merge( self::default_settings(), array( 'kill_switch' => false ) ),
			array_merge( self::default_settings(), array( 'kill_switch' => true ) )
		);

		$row = $this->last_audit_row();
		$this->assertNotNull( $row );
		$this->assertSame( 'settings_update', $row['action'] );
		$this->assertSame( 'success', $row['outcome'] );
		$this->assertSame( '', $row['target_email'] );
		$this->assertSame( '', $row['subgroup_id'] );
		$this->assertStringContainsString( 'kill_switch: false → true', $row['api_response_detail'] );
	}

	public function test_log_change_records_a_numeric_field_change(): void {
		Settings::log_change(
			array_merge( self::default_settings(), array( 'grace_period_days' => 3 ) ),
			array_merge( self::default_settings(), array( 'grace_period_days' => 10 ) )
		);

		$this->assertStringContainsString( 'grace_period_days: 3 → 10', $this->last_audit_row()['api_response_detail'] );
	}

	public function test_log_change_records_an_array_field_change(): void {
		Settings::log_change(
			array_merge( self::default_settings(), array( 'global_mandatory_groups' => array() ) ),
			array_merge( self::default_settings(), array( 'global_mandatory_groups' => array( 'announcements', 'general' ) ) )
		);

		$this->assertStringContainsString(
			'global_mandatory_groups: [] → [announcements, general]',
			$this->last_audit_row()['api_response_detail']
		);
	}

	public function test_log_change_summarizes_every_changed_field_in_one_row(): void {
		Settings::log_change(
			array_merge( self::default_settings(), array( 'kill_switch' => false, 'grace_period_days' => 3 ) ),
			array_merge( self::default_settings(), array( 'kill_switch' => true, 'grace_period_days' => 10 ) )
		);

		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AuditLog::table_name() );
		$this->assertSame( 1, $count, 'One save changing two fields must produce exactly one audit row.' );

		$detail = $this->last_audit_row()['api_response_detail'];
		$this->assertStringContainsString( 'kill_switch: false → true', $detail );
		$this->assertStringContainsString( 'grace_period_days: 3 → 10', $detail );
	}

	public function test_log_change_records_the_saving_admins_identity(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		Settings::log_change(
			array_merge( self::default_settings(), array( 'kill_switch' => false ) ),
			array_merge( self::default_settings(), array( 'kill_switch' => true ) )
		);

		$this->assertSame( (string) $admin_id, $this->last_audit_row()['user_id'] );
	}

	public function test_log_change_records_nothing_when_no_field_actually_changed(): void {
		Settings::log_change( self::default_settings(), self::default_settings() );

		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AuditLog::table_name() );
		$this->assertSame( 0, $count );
	}

	public function test_a_real_update_option_call_triggers_audit_logging_via_the_registered_hook(): void {
		Settings::register();
		update_option( Settings::OPTION_NAME, self::default_settings() );

		// The setup above is itself the "first save" (add_option path) -
		// clear it out so this test isolates a genuine update to an
		// already-existing option.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AuditLog::table_name() );

		update_option( Settings::OPTION_NAME, array_merge( self::default_settings(), array( 'kill_switch' => true ) ) );

		$this->assertStringContainsString( 'kill_switch: false → true', $this->last_audit_row()['api_response_detail'] );
	}

	public function test_the_very_first_save_on_a_fresh_install_is_diffed_against_defaults(): void {
		// set_up() already delete_option()'d Settings::OPTION_NAME, so
		// this update_option() call is a brand-new row - WordPress takes
		// the add_option() path, firing add_option_{option} rather than
		// update_option_{option}.
		Settings::register();
		update_option( Settings::OPTION_NAME, array_merge( self::default_settings(), array( 'kill_switch' => true ) ) );

		$this->assertStringContainsString( 'kill_switch: false → true', $this->last_audit_row()['api_response_detail'] );
	}

	private static function default_settings(): array {
		return array(
			'global_mandatory_groups'              => array(),
			'grace_period_days'                    => 3,
			'log_retention_days'                   => 90,
			'kill_switch'                           => false,
			'mass_action_threshold_count'          => 20,
			'mass_action_threshold_window_minutes' => 10,
			'magic_link_rate_limit_per_email_hour' => 3,
			'magic_link_rate_limit_per_ip_hour'    => 10,
			'dry_run_mode'                         => true,
		);
	}
}
