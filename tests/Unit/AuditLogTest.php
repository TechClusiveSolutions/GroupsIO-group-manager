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

		$fresh   = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$fresh_r = mysqli_query( $fresh, "SHOW TABLES LIKE '$table_name'" );
		$fresh_row = $fresh_r ? mysqli_fetch_row( $fresh_r ) : null;
		$conn_id = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$fresh_conn_id = mysqli_query( $fresh, 'SELECT CONNECTION_ID()' );
		$fresh_conn_id_row = $fresh_conn_id ? mysqli_fetch_row( $fresh_conn_id ) : null;
		$autocommit = $wpdb->get_var( 'SELECT @@autocommit' );

		$this->assertSame(
			$table_name,
			$exists,
			'fresh conn table check: ' . wp_json_encode( $fresh_row )
			. ' | wpdb conn_id: ' . $conn_id . ' | fresh conn_id: ' . wp_json_encode( $fresh_conn_id_row )
			. ' | autocommit: ' . $autocommit . ' | mysqli error: ' . mysqli_error( $fresh )
		);
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
