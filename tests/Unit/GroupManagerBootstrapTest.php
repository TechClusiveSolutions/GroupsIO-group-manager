<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use WP_UnitTestCase;

/**
 * Regression coverage for #114: group-manager.php's own require_once
 * guard for Action Scheduler pointed at vendor/woocommerce/action-scheduler/,
 * a path composer never actually installs to (action-scheduler declares
 * itself "type": "wordpress-plugin", which this project's own
 * installer-paths config redirects to vendor/wordpress-plugins/ instead).
 * The file_exists() guard silently failed, so Action Scheduler never
 * loaded on a real deployment - every queued add/remove silently no-opped.
 *
 * This escaped the rest of the test suite because tests/bootstrap.php
 * loads Paid Memberships Pro directly, which bundles its own separate
 * copy of Action Scheduler and loads it independently of
 * group-manager.php's own (broken) path - so as_schedule_single_action()
 * existed in the PHPUnit process regardless of this bug. These tests
 * check the bootstrap file's own literal path instead of relying on
 * runtime function availability, so they can't be papered over the same
 * way again.
 */
final class GroupManagerBootstrapTest extends WP_UnitTestCase {

	private function bootstrap_file_contents(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/group-manager.php' );
	}

	public function test_bootstrap_file_does_not_reference_the_nonexistent_woocommerce_vendor_path(): void {
		$this->assertStringNotContainsString(
			'vendor/woocommerce/action-scheduler/action-scheduler.php',
			$this->bootstrap_file_contents(),
			'This path is never actually installed to by composer - see #114.'
		);
	}

	public function test_bootstrap_file_references_the_actual_installed_action_scheduler_path(): void {
		$this->assertStringContainsString(
			'vendor/wordpress-plugins/action-scheduler/action-scheduler.php',
			$this->bootstrap_file_contents()
		);
	}

	public function test_the_action_scheduler_path_bootstrap_php_references_actually_exists(): void {
		$this->assertFileExists(
			dirname( __DIR__, 2 ) . '/vendor/wordpress-plugins/action-scheduler/action-scheduler.php',
			'If this ever fails, group-manager.php\'s own file_exists() guard will also silently fail, and Action Scheduler will never load on a real deployment - see #114.'
		);
	}
}
