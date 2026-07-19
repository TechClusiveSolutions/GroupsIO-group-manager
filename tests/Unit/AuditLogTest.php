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
		$sql             = "CREATE TABLE $table_name (\n\t\t\tid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n\t\t\tcreated_at DATETIME NOT NULL,\n\t\t\tPRIMARY KEY  (id)\n\t\t) $charset_collate;";

		$before_error = $wpdb->last_error;
		$dbdelta_result = dbDelta( $sql );
		$after_dbdelta_error = $wpdb->last_error;
		$after_dbdelta_query = $wpdb->last_query;

		$commit_ok    = $wpdb->query( 'COMMIT' );
		$commit_error = $wpdb->last_error;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$this->assertSame(
			$table_name,
			$exists,
			'dbdelta_result: ' . wp_json_encode( $dbdelta_result )
			. ' | before_error: ' . $before_error . ' | after_dbdelta_error: ' . $after_dbdelta_error
			. ' | last_query: ' . $after_dbdelta_query
			. ' | commit_ok: ' . wp_json_encode( $commit_ok ) . ' | commit_error: ' . $commit_error
		);
	}
}
