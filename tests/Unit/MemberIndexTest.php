<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\LevelMandatoryGroups;
use BITS\GroupsIOSync\MemberIndex;
use BITS\GroupsIOSync\Settings;
use WP_UnitTestCase;

final class MemberIndexTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'GROUPS_IO_API_KEY' ) ) {
			define( 'GROUPS_IO_API_KEY', 'fake-test-key-not-real' );
		}
		if ( ! defined( 'GROUPS_IO_PARENT_GROUP' ) ) {
			define( 'GROUPS_IO_PARENT_GROUP', 'perception-is-all' );
		}

		MemberIndex::create_table();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MemberIndex::table_name() );
		delete_option( Settings::OPTION_NAME );
	}

	private array $pending_responses = array();

	private function queue_responses( array $responses ): void {
		$this->pending_responses = $responses;

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				if ( empty( $this->pending_responses ) ) {
					throw new \RuntimeException( "queue_responses() exhausted for URL: {$url}" );
				}

				return array_shift( $this->pending_responses );
			},
			10,
			3
		);
	}

	private function json_response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	private function group_response( int $id, string $name, string $title = '' ): array {
		return $this->json_response( 200, array( 'object' => 'group', 'id' => $id, 'name' => $name, 'title' => $title ) );
	}

	private function subgroups_list_response( array $rows ): array {
		return $this->json_response( 200, array( 'object' => 'list', 'data' => $rows ) );
	}

	private function subgroup_row( int $id, string $name, string $title = '' ): array {
		return array( 'id' => $id, 'name' => $name, 'title' => $title );
	}

	private function members_list_response( array $rows ): array {
		return $this->json_response( 200, array( 'object' => 'list', 'data' => $rows ) );
	}

	private function member_row( string $email, string $full_name = '', ?int $id = null ): array {
		$row = array( 'email' => $email, 'full_name' => $full_name );
		if ( null !== $id ) {
			$row['id'] = $id;
		}

		return $row;
	}

	private function fetch_row( string $email, int $subgroup_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MemberIndex::table_name() . ' WHERE email = %s AND subgroup_id = %d',
				$email,
				$subgroup_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function test_create_table_is_idempotent_and_table_exists(): void {
		MemberIndex::create_table();
		MemberIndex::create_table();

		global $wpdb;
		$wpdb->insert(
			MemberIndex::table_name(),
			array(
				'email'         => 'table-exists-check@example.test',
				'subgroup_id'   => 999999,
				'subgroup_slug' => 'perception-is-all+table-exists-check',
				'synced_at'     => current_time( 'mysql', true ),
			)
		);

		$row = $this->fetch_row( 'table-exists-check@example.test', 999999 );
		$this->assertNotNull( $row, 'wpdb last_error: ' . $wpdb->last_error );
	}

	public function test_sync_indexes_every_member_against_the_parent_group_too(): void {
		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all', 'Perception Is All' ),
			$this->members_list_response( array( $this->member_row( 'member@example.test' ) ) ),
			$this->subgroups_list_response( array() ),
		) );

		MemberIndex::sync();

		$row = $this->fetch_row( 'member@example.test', 900001 );
		$this->assertNotNull( $row );
		$this->assertSame( '1', $row['pmpro_expected'], 'The parent group must always be PMPro-expected.' );
	}

	public function test_sync_indexes_members_of_every_subgroup(): void {
		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array() ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 900002, 'perception-is-all+announcements' ),
			) ),
			$this->members_list_response( array( $this->member_row( 'member@example.test', 'Test Member' ) ) ),
		) );

		MemberIndex::sync();

		$row = $this->fetch_row( 'member@example.test', 900002 );
		$this->assertNotNull( $row );
		$this->assertSame( 'Test Member', $row['display_name'] );
		$this->assertSame( 'perception-is-all+announcements', $row['subgroup_slug'] );
	}

	public function test_sync_flags_global_mandatory_group_as_expected_even_without_a_matched_wp_user(): void {
		update_option( 'bits_groupsio_sync_settings', array( 'global_mandatory_groups' => array( 'perception-is-all+announcements' ) ) );

		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array() ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 900002, 'perception-is-all+announcements' ),
			) ),
			$this->members_list_response( array( $this->member_row( 'not-a-wp-user@example.test' ) ) ),
		) );

		MemberIndex::sync();

		$row = $this->fetch_row( 'not-a-wp-user@example.test', 900002 );
		$this->assertNotNull( $row );
		$this->assertSame( '1', $row['pmpro_expected'] );
		$this->assertSame( '0', $row['user_id'], 'No matched WP account should store 0, not fail.' );
	}

	public function test_sync_flags_level_mandatory_group_as_expected_for_a_member_with_that_level(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->pmpro_membership_levels,
			array(
				'name'              => 'Index Test Level',
				'description'       => '',
				'confirmation'      => '',
				'allow_signups'     => 1,
				'initial_payment'   => 0,
				'billing_amount'    => 0,
				'cycle_number'      => 0,
				'cycle_period'      => 'Month',
				'billing_limit'     => 0,
				'trial_amount'      => 0,
				'trial_limit'       => 0,
				'expiration_number' => 0,
				'expiration_period' => 'Month',
			)
		);
		$level_id = (int) $wpdb->insert_id;
		update_option( LevelMandatoryGroups::option_key( $level_id ), array( 'perception-is-all+sustaining' ) );

		$user_id = self::factory()->user->create( array( 'user_email' => 'sustaining-member@example.test' ) );
		$wpdb->insert(
			$wpdb->pmpro_memberships_users,
			array(
				'user_id'      => $user_id,
				'membership_id' => $level_id,
				'status'       => 'active',
			)
		);

		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array() ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 900002, 'perception-is-all+sustaining' ),
			) ),
			$this->members_list_response( array( $this->member_row( 'sustaining-member@example.test' ) ) ),
		) );

		MemberIndex::sync();

		$row = $this->fetch_row( 'sustaining-member@example.test', 900002 );
		$this->assertNotNull( $row );
		$this->assertSame( '1', $row['pmpro_expected'] );
		$this->assertSame( (string) $user_id, $row['user_id'] );
	}

	public function test_sync_does_not_flag_a_non_mandatory_group_as_expected(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'plain-member@example.test' ) );

		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array() ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 900002, 'perception-is-all+optional-list' ),
			) ),
			$this->members_list_response( array( $this->member_row( 'plain-member@example.test' ) ) ),
		) );

		MemberIndex::sync();

		$row = $this->fetch_row( 'plain-member@example.test', 900002 );
		$this->assertNotNull( $row );
		$this->assertSame( '0', $row['pmpro_expected'] );
		$this->assertSame( (string) $user_id, $row['user_id'] );
	}

	public function test_sync_leaves_an_existing_override_flag_untouched(): void {
		global $wpdb;

		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array( $this->member_row( 'member@example.test' ) ) ),
			$this->subgroups_list_response( array() ),
		) );
		MemberIndex::sync();

		$wpdb->update(
			MemberIndex::table_name(),
			array( 'override_type' => 'added', 'override_by' => 1, 'override_at' => current_time( 'mysql', true ) ),
			array( 'email' => 'member@example.test', 'subgroup_id' => 900001 )
		);

		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array( $this->member_row( 'member@example.test' ) ) ),
			$this->subgroups_list_response( array() ),
		) );
		MemberIndex::sync();

		$row = $this->fetch_row( 'member@example.test', 900001 );
		$this->assertSame( 'added', $row['override_type'], 'A re-sync must not clear an existing override flag.' );
	}

	public function test_a_failed_subgroup_lookup_does_not_abort_syncing_the_rest(): void {
		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array() ),
			$this->subgroups_list_response( array(
				$this->subgroup_row( 900002, 'perception-is-all+broken' ),
				$this->subgroup_row( 900003, 'perception-is-all+fine' ),
			) ),
			$this->json_response( 400, array( 'object' => 'error', 'type' => 'group_not_found', 'extra' => '' ) ),
			$this->members_list_response( array( $this->member_row( 'member@example.test' ) ) ),
		) );

		MemberIndex::sync();

		$this->assertNull( $this->fetch_row( 'member@example.test', 900002 ) );
		$this->assertNotNull( $this->fetch_row( 'member@example.test', 900003 ) );
	}

	public function test_clear_overrides_for_user_only_clears_that_users_rows(): void {
		global $wpdb;

		$user_id       = self::factory()->user->create( array( 'user_email' => 'override-owner@example.test' ) );
		$other_user_id = self::factory()->user->create( array( 'user_email' => 'other-owner@example.test' ) );

		$wpdb->insert( MemberIndex::table_name(), array(
			'user_id' => $user_id, 'email' => 'override-owner@example.test', 'subgroup_id' => 1,
			'subgroup_slug' => 'perception-is-all+a', 'override_type' => 'added', 'override_by' => 1,
			'override_at' => current_time( 'mysql', true ), 'synced_at' => current_time( 'mysql', true ),
		) );
		$wpdb->insert( MemberIndex::table_name(), array(
			'user_id' => $other_user_id, 'email' => 'other-owner@example.test', 'subgroup_id' => 1,
			'subgroup_slug' => 'perception-is-all+a', 'override_type' => 'removed', 'override_by' => 1,
			'override_at' => current_time( 'mysql', true ), 'synced_at' => current_time( 'mysql', true ),
		) );

		MemberIndex::clear_overrides_for_user( $user_id );

		$cleared = $this->fetch_row( 'override-owner@example.test', 1 );
		$this->assertNull( $cleared['override_type'] );

		$untouched = $this->fetch_row( 'other-owner@example.test', 1 );
		$this->assertSame( 'removed', $untouched['override_type'] );
	}

	public function test_sync_stores_member_info_id_from_getmembers_response(): void {
		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array( $this->member_row( 'member@example.test', '', 555 ) ) ),
			$this->subgroups_list_response( array() ),
		) );

		MemberIndex::sync();

		$row = $this->fetch_row( 'member@example.test', 900001 );
		$this->assertSame( '555', $row['member_info_id'] );
	}

	public function test_sync_leaves_member_info_id_null_when_getmembers_response_omits_id(): void {
		$this->queue_responses( array(
			$this->group_response( 900001, 'perception-is-all' ),
			$this->members_list_response( array( $this->member_row( 'member@example.test' ) ) ),
			$this->subgroups_list_response( array() ),
		) );

		MemberIndex::sync();

		$row = $this->fetch_row( 'member@example.test', 900001 );
		$this->assertNull( $row['member_info_id'] );
	}

	public function test_get_member_info_id_returns_stored_value(): void {
		global $wpdb;

		$wpdb->insert( MemberIndex::table_name(), array(
			'email' => 'lookup@example.test', 'subgroup_id' => 42, 'subgroup_slug' => 'perception-is-all+a',
			'member_info_id' => 777, 'synced_at' => current_time( 'mysql', true ),
		) );

		$this->assertSame( 777, MemberIndex::get_member_info_id( 'lookup@example.test', 42 ) );
	}

	public function test_get_member_info_id_returns_null_when_no_matching_row(): void {
		$this->assertNull( MemberIndex::get_member_info_id( 'nobody@example.test', 999 ) );
	}

	public function test_apply_add_upserts_row_and_sets_override_flag(): void {
		MemberIndex::apply_add( 5, 'added-member@example.test', 'Added Member', 900002, 'perception-is-all+list', 'List', 9 );

		$row = $this->fetch_row( 'added-member@example.test', 900002 );
		$this->assertNotNull( $row );
		$this->assertSame( 'Added Member', $row['display_name'] );
		$this->assertSame( 'added', $row['override_type'] );
		$this->assertSame( '9', $row['override_by'] );
		$this->assertNotEmpty( $row['override_at'] );
	}

	public function test_apply_remove_sets_override_flag_on_existing_row(): void {
		global $wpdb;

		$wpdb->insert( MemberIndex::table_name(), array(
			'email' => 'removed-member@example.test', 'subgroup_id' => 900003,
			'subgroup_slug' => 'perception-is-all+list', 'synced_at' => current_time( 'mysql', true ),
		) );

		MemberIndex::apply_remove( 'removed-member@example.test', 900003, 4 );

		$row = $this->fetch_row( 'removed-member@example.test', 900003 );
		$this->assertSame( 'removed', $row['override_type'] );
		$this->assertSame( '4', $row['override_by'] );
	}

	public function test_count_members_counts_distinct_members_not_rows(): void {
		MemberIndex::apply_add( 0, 'a@example.test', 'A', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'a@example.test', 'A', 2, 'perception-is-all+x', '', 1 );
		MemberIndex::apply_add( 0, 'b@example.test', 'B', 1, 'perception-is-all', '', 1 );

		$this->assertSame( 2, MemberIndex::count_members() );
	}

	public function test_count_members_with_search_matches_email(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'bob@example.test', 'Bob', 1, 'perception-is-all', '', 1 );

		$this->assertSame( 1, MemberIndex::count_members( 'alice@' ) );
	}

	public function test_count_members_with_search_matches_subgroup_slug(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', '', 1 );
		MemberIndex::apply_add( 0, 'bob@example.test', 'Bob', 1, 'perception-is-all', '', 1 );

		$this->assertSame( 1, MemberIndex::count_members( 'announcements' ) );
	}

	public function test_get_members_page_returns_name_email_and_total_group_count(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', '', 1 );

		$rows = MemberIndex::get_members_page( 1, 20 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'alice@example.test', $rows[0]['email'] );
		$this->assertSame( 'Alice', $rows[0]['display_name'] );
		$this->assertSame( 2, $rows[0]['group_count'] );
	}

	public function test_get_members_page_search_by_subgroup_returns_full_group_count(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', '', 1 );
		MemberIndex::apply_add( 0, 'bob@example.test', 'Bob', 1, 'perception-is-all', '', 1 );

		$rows = MemberIndex::get_members_page( 1, 20, 'announcements' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'alice@example.test', $rows[0]['email'] );
		$this->assertSame( 2, $rows[0]['group_count'], 'Full group count, not just the matched subgroup.' );
	}

	public function test_get_members_page_respects_page_and_per_page(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			MemberIndex::apply_add( 0, "member{$i}@example.test", sprintf( 'Member %d', $i ), 1, 'perception-is-all', '', 1 );
		}

		$page_one = MemberIndex::get_members_page( 1, 2 );
		$page_two = MemberIndex::get_members_page( 2, 2 );
		$page_three = MemberIndex::get_members_page( 3, 2 );

		$this->assertCount( 2, $page_one );
		$this->assertCount( 2, $page_two );
		$this->assertCount( 1, $page_three );
		$this->assertNotSame( $page_one[0]['email'], $page_two[0]['email'] );
	}

	public function test_get_display_name_returns_stored_value(): void {
		MemberIndex::apply_add( 0, 'named@example.test', 'Named Member', 1, 'perception-is-all', '', 1 );

		$this->assertSame( 'Named Member', MemberIndex::get_display_name( 'named@example.test' ) );
	}

	public function test_get_display_name_returns_empty_string_when_no_row_exists(): void {
		$this->assertSame( '', MemberIndex::get_display_name( 'nobody@example.test' ) );
	}

	public function test_get_member_groups_returns_this_members_rows_only(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		MemberIndex::apply_add( 0, 'bob@example.test', 'Bob', 1, 'perception-is-all', '', 1 );

		$rows = MemberIndex::get_member_groups( 'alice@example.test', 1, 20 );

		$this->assertCount( 2, $rows );
		$slugs = array_column( $rows, 'subgroup_slug' );
		$this->assertContains( 'perception-is-all', $slugs );
		$this->assertContains( 'perception-is-all+announcements', $slugs );
	}

	public function test_get_member_groups_excludes_rows_with_removed_override(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		MemberIndex::apply_remove( 'alice@example.test', 2, 1 );

		$rows = MemberIndex::get_member_groups( 'alice@example.test', 1, 20 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'perception-is-all', $rows[0]['subgroup_slug'] );
	}

	public function test_get_member_groups_search_matches_subgroup_slug_or_title(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', 'Perception Is All', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$rows = MemberIndex::get_member_groups( 'alice@example.test', 1, 20, 'announcements' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'perception-is-all+announcements', $rows[0]['subgroup_slug'] );
	}

	public function test_get_member_groups_reports_pmpro_expected_and_override_type(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );

		$rows = MemberIndex::get_member_groups( 'alice@example.test', 1, 20 );

		$this->assertTrue( $rows[0]['pmpro_expected'], 'The parent group is always PMPro-expected.' );
		$this->assertSame( 'added', $rows[0]['override_type'] );
	}

	public function test_get_member_groups_respects_page_and_per_page(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', $i, "perception-is-all+list{$i}", "List {$i}", 1 );
		}

		$page_one = MemberIndex::get_member_groups( 'alice@example.test', 1, 2 );
		$page_two = MemberIndex::get_member_groups( 'alice@example.test', 2, 2 );

		$this->assertCount( 2, $page_one );
		$this->assertCount( 2, $page_two );
		$this->assertNotSame( $page_one[0]['subgroup_id'], $page_two[0]['subgroup_id'] );
	}

	public function test_count_member_groups_excludes_removed_override_rows(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', '', 1 );
		MemberIndex::apply_remove( 'alice@example.test', 2, 1 );

		$this->assertSame( 1, MemberIndex::count_member_groups( 'alice@example.test' ) );
	}

	public function test_count_member_groups_with_search(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', '', 1 );

		$this->assertSame( 1, MemberIndex::count_member_groups( 'alice@example.test', 'announcements' ) );
	}

	public function test_clear_override_removes_the_flag_from_a_single_row(): void {
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'alice@example.test', 'Alice', 2, 'perception-is-all+announcements', '', 1 );

		MemberIndex::clear_override( 'alice@example.test', 2 );

		$cleared   = $this->fetch_row( 'alice@example.test', 2 );
		$untouched = $this->fetch_row( 'alice@example.test', 1 );
		$this->assertNull( $cleared['override_type'] );
		$this->assertSame( 'added', $untouched['override_type'], 'clear_override() must only touch the one targeted row.' );
	}

	public function test_get_addable_groups_excludes_groups_the_member_is_already_in(): void {
		// Seed the universe of known groups via another member - a
		// subgroup only appears in the index once someone has synced
		// into it.
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 3, 'perception-is-all+sustaining', 'Sustaining', 1 );

		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );

		$rows  = MemberIndex::get_addable_groups( 'target@example.test', 1, 20 );
		$slugs = array_column( $rows, 'subgroup_slug' );

		$this->assertNotContains( 'perception-is-all', $slugs );
		$this->assertContains( 'perception-is-all+announcements', $slugs );
		$this->assertContains( 'perception-is-all+sustaining', $slugs );
	}

	public function test_get_addable_groups_includes_a_group_with_a_removed_override(): void {
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'target@example.test', 'Target', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		MemberIndex::apply_remove( 'target@example.test', 2, 1 );

		$rows  = MemberIndex::get_addable_groups( 'target@example.test', 1, 20 );
		$slugs = array_column( $rows, 'subgroup_slug' );

		$this->assertContains( 'perception-is-all+announcements', $slugs, 'A manually-removed group must reappear as addable.' );
	}

	public function test_get_addable_groups_search_matches_subgroup_slug_or_title(): void {
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 2, 'perception-is-all+announcements', 'Announcements', 1 );
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 3, 'perception-is-all+sustaining', 'Sustaining', 1 );

		$rows = MemberIndex::get_addable_groups( 'target@example.test', 1, 20, 'announcements' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'perception-is-all+announcements', $rows[0]['subgroup_slug'] );
	}

	public function test_count_addable_groups_with_search(): void {
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 1, 'perception-is-all', '', 1 );
		MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', 2, 'perception-is-all+announcements', 'Announcements', 1 );

		$this->assertSame( 1, MemberIndex::count_addable_groups( 'target@example.test', 'announcements' ) );
	}

	public function test_get_addable_groups_respects_page_and_per_page(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			MemberIndex::apply_add( 0, 'seed@example.test', 'Seed', $i, "perception-is-all+list{$i}", sprintf( 'List %02d', $i ), 1 );
		}

		$page_one = MemberIndex::get_addable_groups( 'target@example.test', 1, 2 );
		$page_two = MemberIndex::get_addable_groups( 'target@example.test', 2, 2 );

		$this->assertCount( 2, $page_one );
		$this->assertCount( 2, $page_two );
		$this->assertNotSame( $page_one[0]['subgroup_id'], $page_two[0]['subgroup_id'] );
	}
}
