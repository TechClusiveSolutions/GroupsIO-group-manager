<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\GroupIndex;
use WP_UnitTestCase;

final class GroupIndexTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		GroupIndex::create_table();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . GroupIndex::table_name() );
	}

	private function fetch_row( int $subgroup_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . GroupIndex::table_name() . ' WHERE subgroup_id = %d',
				$subgroup_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function test_create_table_is_idempotent_and_table_exists(): void {
		GroupIndex::create_table();
		GroupIndex::create_table();

		global $wpdb;
		$wpdb->insert(
			GroupIndex::table_name(),
			array(
				'subgroup_id' => 999999,
				'slug'        => 'perception-is-all+table-exists-check',
				'title'       => 'Table Exists Check',
				'is_parent'   => 0,
				'sync_status' => 'pending',
				'modified_at' => current_time( 'mysql', true ),
			)
		);

		$row = $this->fetch_row( 999999 );
		$this->assertNotNull( $row, 'wpdb last_error: ' . $wpdb->last_error );
	}

	public function test_unique_key_on_subgroup_id_rejects_duplicates(): void {
		global $wpdb;

		$wpdb->insert(
			GroupIndex::table_name(),
			array(
				'subgroup_id' => 999998,
				'slug'        => 'perception-is-all+dup-check',
				'is_parent'   => 0,
				'sync_status' => 'pending',
				'modified_at' => current_time( 'mysql', true ),
			)
		);

		// The duplicate insert is expected to fail - suppress wpdb's own
		// error output around it so PHPUnit doesn't flag this test risky
		// for unexpected output.
		$suppress = $wpdb->suppress_errors( true );
		$second_insert = $wpdb->insert(
			GroupIndex::table_name(),
			array(
				'subgroup_id' => 999998,
				'slug'        => 'perception-is-all+dup-check-again',
				'is_parent'   => 0,
				'sync_status' => 'pending',
				'modified_at' => current_time( 'mysql', true ),
			)
		);
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $second_insert, 'A duplicate subgroup_id must be rejected by the unique key.' );
	}

	public function test_ensure_table_recreates_a_missing_table(): void {
		// Simulates the confirmed real-world failure (issue #108, see
		// MemberIndex::ensure_table()'s docblock): register_activation_hook
		// ran but the table never actually got created - ensure_table()
		// (called from register(), i.e. every plugins_loaded) must
		// self-heal it without needing another activation.
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . GroupIndex::table_name() );

		GroupIndex::ensure_table();

		$wpdb->insert(
			GroupIndex::table_name(),
			array(
				'subgroup_id' => 999997,
				'slug'        => 'perception-is-all+ensure-table-check',
				'is_parent'   => 0,
				'sync_status' => 'pending',
				'modified_at' => current_time( 'mysql', true ),
			)
		);

		$row = $this->fetch_row( 999997 );
		$this->assertNotNull( $row, 'wpdb last_error: ' . $wpdb->last_error );
	}

	public function test_ensure_table_heals_when_schema_version_option_is_missing(): void {
		// Covers the "never set" case, matching
		// MemberIndexTest::test_ensure_table_heals_when_schema_version_option_is_missing() -
		// a missing/stale version option must always fall through to
		// create_table(), which re-sets the option as one of its own
		// side effects. Verified via the option's own value rather than
		// a raw DROP TABLE/SHOW TABLES check, since DDL statements
		// aren't reliably observable within WP core's transaction-wrapped
		// PHPUnit fixture.
		delete_option( 'bits_groupsio_group_index_schema_version' );

		GroupIndex::ensure_table();

		$this->assertSame( '1', get_option( 'bits_groupsio_group_index_schema_version' ) );
	}

	public function test_register_ensures_table_without_error(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . GroupIndex::table_name() );

		GroupIndex::register();

		$wpdb->insert(
			GroupIndex::table_name(),
			array(
				'subgroup_id' => 999996,
				'slug'        => 'perception-is-all+register-check',
				'is_parent'   => 1,
				'sync_status' => 'synced',
				'modified_at' => current_time( 'mysql', true ),
			)
		);

		$row = $this->fetch_row( 999996 );
		$this->assertNotNull( $row, 'wpdb last_error: ' . $wpdb->last_error );
		$this->assertSame( '1', $row['is_parent'] );
	}
}
