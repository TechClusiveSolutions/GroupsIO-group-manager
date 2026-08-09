<?php

namespace BITS\GroupsIOSync\Tests\Unit\Admin;

use BITS\GroupsIOSync\Admin\AdminNotifications;
use WP_Ajax_UnitTestCase;

final class AdminNotificationsTest extends WP_Ajax_UnitTestCase {

	private const OPTION_NAME = 'bits_groupsio_admin_notifications';

	public function set_up(): void {
		parent::set_up();

		delete_option( self::OPTION_NAME );
	}

	public function test_add_stores_a_notification_record(): void {
		AdminNotifications::add( 'success', 'Added member@example.test to the group.' );

		$stored = get_option( self::OPTION_NAME );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'success', $stored[0]['type'] );
		$this->assertSame( 'Added member@example.test to the group.', $stored[0]['message'] );
		$this->assertNotEmpty( $stored[0]['id'] );
	}

	public function test_add_appends_without_overwriting_existing_notifications(): void {
		AdminNotifications::add( 'success', 'first' );
		AdminNotifications::add( 'failure', 'second' );

		$stored = get_option( self::OPTION_NAME );
		$this->assertCount( 2, $stored );
		$this->assertSame( 'first', $stored[0]['message'] );
		$this->assertSame( 'second', $stored[1]['message'] );
	}

	public function test_render_outputs_a_distinct_notice_per_notification(): void {
		AdminNotifications::add( 'success', 'Added a@example.test to the group.' );
		AdminNotifications::add( 'failure', 'Failed to remove b@example.test from the group after 3 attempts.' );

		ob_start();
		AdminNotifications::render();
		$output = ob_get_clean();

		$this->assertSame( 2, substr_count( $output, 'data-notification-id=' ) );
		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Added a@example.test to the group.', $output );
		$this->assertStringContainsString( 'Failed to remove b@example.test from the group after 3 attempts.', $output );
	}

	public function test_render_outputs_nothing_when_no_notifications_are_pending(): void {
		ob_start();
		AdminNotifications::render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_handle_dismiss_removes_only_the_matching_record(): void {
		AdminNotifications::add( 'success', 'keep' );
		$stored = get_option( self::OPTION_NAME );
		$keep_id = $stored[0]['id'];

		AdminNotifications::add( 'success', 'remove-me' );
		$stored     = get_option( self::OPTION_NAME );
		$dismiss_id = $stored[1]['id'];

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_POST['id']       = $dismiss_id;
		$_POST['nonce']    = wp_create_nonce( 'bits_groupsio_dismiss_notification' );
		// check_ajax_referer() reads the superglobal $_REQUEST, not $_POST
		// directly - PHP doesn't keep $_REQUEST in sync with values
		// assigned to $_POST after script start, so it must be set here too.
		$_REQUEST['nonce'] = $_POST['nonce'];

		ob_start();
		try {
			AdminNotifications::handle_dismiss();
			$this->fail( 'Expected wp_send_json_success() to short-circuit via WPAjaxDieContinueException.' );
		} catch ( \WPAjaxDieContinueException $exception ) {
			// Expected - wp_send_json_success() calls wp_die(), and
			// WP_Ajax_UnitTestCase's die handler converts that into this
			// exception instead of a real process exit. The die handler
			// requires an active output buffer (started above) to tell a
			// normal JSON-then-die completion apart from an error-only die.
		}

		$remaining = get_option( self::OPTION_NAME );
		$this->assertCount( 1, $remaining );
		$this->assertSame( $keep_id, $remaining[0]['id'] );
	}
}
