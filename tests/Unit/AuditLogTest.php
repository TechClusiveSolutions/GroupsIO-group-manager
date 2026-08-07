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

	public function test_activate_creates_the_audit_table(): void {
		global $wpdb;

		AuditLog::activate();

		$wpdb->query( 'SELECT 1 FROM ' . AuditLog::table_name() . ' LIMIT 1' );

		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_record_inserts_a_row_with_all_fields(): void {
		global $wpdb;

		AuditLog::create_table();

		AuditLog::record( 'add', 'success', 'member@example.com', 'subgroup-1', 7, 'HTTP 200 OK' );

		$row = $wpdb->get_row(
			'SELECT * FROM ' . AuditLog::table_name() . ' ORDER BY id DESC LIMIT 1',
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'add', $row['action'] );
		$this->assertSame( 'success', $row['outcome'] );
		$this->assertSame( 'member@example.com', $row['target_email'] );
		$this->assertSame( 'subgroup-1', $row['subgroup_id'] );
		$this->assertSame( '7', $row['user_id'] );
		$this->assertSame( 'HTTP 200 OK', $row['api_response_detail'] );
		$this->assertNotEmpty( $row['created_at'] );
	}

	public function test_record_defaults_api_response_detail_to_empty_string(): void {
		global $wpdb;

		AuditLog::create_table();

		AuditLog::record( 'remove', 'failure', 'member@example.com', 'subgroup-2', 3 );

		$row = $wpdb->get_row(
			'SELECT * FROM ' . AuditLog::table_name() . ' ORDER BY id DESC LIMIT 1',
			ARRAY_A
		);

		$this->assertSame( '', $row['api_response_detail'] );
	}

	public function test_record_supports_zero_user_id_for_system_initiated_actions(): void {
		global $wpdb;

		AuditLog::create_table();

		AuditLog::record( 'remove', 'success', 'member@example.com', 'subgroup-3', 0 );

		$row = $wpdb->get_row(
			'SELECT * FROM ' . AuditLog::table_name() . ' ORDER BY id DESC LIMIT 1',
			ARRAY_A
		);

		$this->assertSame( '0', $row['user_id'] );
	}

	public function test_record_records_multiple_entries_independently(): void {
		global $wpdb;

		AuditLog::create_table();

		AuditLog::record( 'add', 'success', 'a@example.com', 'subgroup-1', 1 );
		AuditLog::record( 'add', 'failure', 'b@example.com', 'subgroup-1', 1, 'HTTP 500' );

		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AuditLog::table_name() );

		$this->assertSame( '2', $count );
	}
}
