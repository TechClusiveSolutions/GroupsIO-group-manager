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

		$table_name = AuditLog::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$this->assertSame( $table_name, $exists, 'wpdb->last_error: ' . $wpdb->last_error );
	}

	public function test_create_table_is_idempotent(): void {
		AuditLog::create_table();
		AuditLog::create_table();

		global $wpdb;
		$table_name = AuditLog::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$this->assertSame( $table_name, $exists );
	}
}
