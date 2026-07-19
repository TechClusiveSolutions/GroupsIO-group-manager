<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\AuditLog;
use WP_UnitTestCase;

final class AuditLogTest extends WP_UnitTestCase {

	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . AuditLog::table_name() );
		$wpdb->query( 'COMMIT' );

		parent::tear_down();
	}

	public function test_table_name_uses_wpdb_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'bits_groupsio_audit', AuditLog::table_name() );
	}

	public function test_create_table_creates_the_audit_table(): void {
		global $wpdb;

		// MySQL 8's Atomic DDL makes CREATE TABLE participate in the
		// ambient transaction WP_UnitTestCase wraps every test in, so an
		// explicit COMMIT is needed for the table to actually become
		// visible (tear_down() above cleans it up afterward, since
		// WP_UnitTestCase's automatic rollback no longer covers it once
		// committed).
		AuditLog::create_table();
		$wpdb->query( 'COMMIT' );

		$table_name = AuditLog::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$this->assertSame( $table_name, $exists );
	}

	public function test_create_table_is_idempotent(): void {
		global $wpdb;

		AuditLog::create_table();
		AuditLog::create_table();
		$wpdb->query( 'COMMIT' );

		$table_name = AuditLog::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$this->assertSame( $table_name, $exists );
	}
}
