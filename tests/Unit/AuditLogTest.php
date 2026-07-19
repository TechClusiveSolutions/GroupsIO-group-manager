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

		$result       = AuditLog::create_table();
		$commit_ok    = $wpdb->query( 'COMMIT' );
		$commit_error = $wpdb->last_error;

		$table_name = AuditLog::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$this->assertSame(
			$table_name,
			$exists,
			'commit_ok: ' . wp_json_encode( $commit_ok ) . ' | commit_error: ' . $commit_error
			. ' | wpdb dbh class: ' . get_class( $wpdb->dbh ?? new \stdClass() )
			. ' | use_mysqli: ' . wp_json_encode( $wpdb->use_mysqli ?? null )
		);
	}
}
