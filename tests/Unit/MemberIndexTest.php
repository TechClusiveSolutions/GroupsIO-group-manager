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

	private function member_row( string $email, string $full_name = '' ): array {
		return array( 'email' => $email, 'full_name' => $full_name );
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
}
