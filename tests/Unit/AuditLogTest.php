<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\AuditLog;
use WP_UnitTestCase;

final class AuditLogTest extends WP_UnitTestCase {

	public function test_table_name_uses_wpdb_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'bits_groupsio_audit', AuditLog::table_name() );
	}

	public function test_create_table_creates_the_audit_table(): void {
		global $wpdb;

		AuditLog::create_table();

		// The WP core test suite rewrites CREATE TABLE to CREATE TEMPORARY
		// TABLE for automatic cleanup between test runs, and MySQL
		// temporary tables are invisible to SHOW TABLES/information_schema
		// by design — so existence must be verified by querying the table
		// directly rather than via a catalog lookup.
		$wpdb->query( 'SELECT 1 FROM ' . AuditLog::table_name() . ' LIMIT 1' );

		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_create_table_is_idempotent(): void {
		global $wpdb;

		AuditLog::create_table();
		AuditLog::create_table();

		$wpdb->query( 'SELECT 1 FROM ' . AuditLog::table_name() . ' LIMIT 1' );

		$this->assertSame( '', $wpdb->last_error );
	}
}
