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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = AuditLog::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			target_email VARCHAR(254) NOT NULL,
			subgroup_id VARCHAR(64) NOT NULL,
			action VARCHAR(20) NOT NULL,
			outcome VARCHAR(20) NOT NULL,
			api_response_detail TEXT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset_collate;";
		$result = dbDelta( $sql );

		$raw_query_result = $wpdb->query( str_replace( $table_name, $table_name . '_raw', $sql ) );
		$raw_error        = $wpdb->last_error;

		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		$all_tables = $wpdb->get_col( 'SHOW TABLES' );

		$this->assertSame(
			$table_name,
			$exists,
			'wpdb->last_error: ' . $wpdb->last_error . ' | dbDelta result: ' . wp_json_encode( $result )
			. ' | dbname: ' . DB_NAME . ' | all tables: ' . wp_json_encode( $all_tables )
			. ' | raw query result: ' . wp_json_encode( $raw_query_result ) . ' | raw error: ' . $raw_error
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
