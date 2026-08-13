<?php
/**
 * E2E test-only mock for the Groups.io API, loaded as a wp-env mu-plugin
 * (see .wp-env.json's "mappings" mapping - never bundled into the
 * plugin's own distributed files or autoloaded in production).
 *
 * Intercepts pre_http_request for calls to groups.io/api and returns
 * deterministic canned responses instead of hitting the real API, so
 * Playwright E2E tests exercise the full request path (browser -> WP
 * admin -> GroupsIoApiClient -> HTTP call -> response handling ->
 * read-back -> cache invalidation -> redirect -> notice) without live
 * credentials, network access, or nondeterministic external state.
 *
 * Safety: only activates when GROUPS_IO_API_KEY is one of this
 * project's established fake dev/test values - never intercepts a call
 * made with a real credential, so this file is harmless even if it
 * were accidentally loaded somewhere it shouldn't be.
 *
 * Endpoints handled: getgroup, getsubgroups, getmembers, createsubgroup,
 * updategroup, deletegroup, directadd, removemember. Added 2026-08-11:
 * directadd/removemember - previously unhandled here (fell through to
 * the generic "unhandled endpoint" 400), which silently broke every
 * queued add/remove job (#59/#61/#62) on this environment, since
 * QueuedExecutionEngine's real HTTP calls always failed with
 * not_found. Anything calling this mock via the local wp-env dev site
 * (not just Playwright) is affected by what this file does and does
 * not implement - see .wp-env.json's mapping.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BITS_E2E_MOCK_FAKE_KEYS = array( 'fake-local-dev-key-not-real', 'fake-test-key-not-real' );
const BITS_E2E_MOCK_OPTION    = 'bits_groupsio_e2e_mock_subgroups';
// Matches the id getgroup() below has always returned - real Groups.io's
// parent group is itself just a group, queryable via getmembers like any
// subgroup, so the mock tracks its membership the same way (in a
// separate parent_members list, since it is never part of getsubgroups()'
// own results).
const BITS_E2E_MOCK_PARENT_ID  = 999999;

if ( ! defined( 'GROUPS_IO_API_KEY' ) || ! in_array( GROUPS_IO_API_KEY, BITS_E2E_MOCK_FAKE_KEYS, true ) ) {
	return;
}

/**
 * Seeds and returns the mock's in-memory (option-backed) subgroup list.
 * Seeded once with one fixture subgroup so list/details tests have
 * something to see on a fresh environment; every other test either
 * resets this option (see tests/e2e/global-setup.ts) or builds on top
 * of it deterministically.
 *
 * @return array<string, mixed>
 */
function bits_e2e_mock_get_state(): array {
	$state = get_option( BITS_E2E_MOCK_OPTION, null );

	if ( null === $state ) {
		$parent = defined( 'GROUPS_IO_PARENT_GROUP' ) ? GROUPS_IO_PARENT_GROUP : 'bits-local-dev';
		$state  = array(
			'next_id'        => 200002,
			'parent_members' => array(),
			'subgroups'      => array(
				200001 => array(
					'id'         => 200001,
					'name'       => $parent . '+fixture-subgroup',
					'title'      => '',
					'desc'       => 'A seeded fixture subgroup for E2E tests.',
					'subs_count' => 2,
					'members'    => array(
						array( 'id' => 300001, 'email' => 'fixture-member-1@example.test', 'full_name' => 'Fixture Member One' ),
						array( 'id' => 300002, 'email' => 'fixture-member-2@example.test', 'full_name' => 'Fixture Member Two' ),
					),
				),
			),
		);
		update_option( BITS_E2E_MOCK_OPTION, $state );
	}

	return $state;
}

/**
 * @param array<string, mixed> $state
 */
function bits_e2e_mock_save_state( array $state ): void {
	update_option( BITS_E2E_MOCK_OPTION, $state );
}

/**
 * @return array{response: array{code: int}, body: string, headers: array<string, string>}
 */
function bits_e2e_mock_response( int $status, array $body ): array {
	return array(
		'response' => array( 'code' => $status ),
		'body'     => (string) wp_json_encode( $body ),
		'headers'  => array(),
	);
}

/**
 * @return array{response: array{code: int}, body: string, headers: array<string, string>}
 */
function bits_e2e_mock_error( string $type, string $extra = '' ): array {
	return bits_e2e_mock_response( 400, array(
		'object' => 'error',
		'type'   => $type,
		'extra'  => $extra,
	) );
}

/**
 * Derives the "segment@parent.groups.io" address real Groups.io uses,
 * from a stored "parent+segment" slug - matches the pattern confirmed
 * by live trial (Groups.io-API-Reference.md section 4).
 */
function bits_e2e_mock_email_address( string $slug ): string {
	$pos = strpos( $slug, '+' );
	if ( false === $pos ) {
		return $slug . '.groups.io';
	}
	$parent  = substr( $slug, 0, $pos );
	$segment = substr( $slug, $pos + 1 );
	return $segment . '@' . $parent . '.groups.io';
}

/**
 * @param array<string, mixed> $subgroup
 */
function bits_e2e_mock_group_object( array $subgroup ): array {
	return array(
		'id'            => $subgroup['id'],
		'object'        => 'group',
		'name'          => $subgroup['name'],
		'title'         => $subgroup['title'],
		'desc'          => $subgroup['desc'],
		'subs_count'    => $subgroup['subs_count'],
		'email_address' => bits_e2e_mock_email_address( $subgroup['name'] ),
	);
}

add_filter(
	'pre_http_request',
	static function ( $preempt, array $parsed_args, string $url ) {
		if ( false === strpos( $url, 'groups.io/api/v1/' ) ) {
			return $preempt;
		}

		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$endpoint = basename( $path );
		$method   = strtoupper( (string) ( $parsed_args['method'] ?? 'GET' ) );

		if ( 'GET' === $method ) {
			$query_string = (string) wp_parse_url( $url, PHP_URL_QUERY );
			parse_str( $query_string, $params );
		} else {
			$body = $parsed_args['body'] ?? array();
			if ( is_string( $body ) ) {
				parse_str( $body, $params );
			} else {
				$params = (array) $body;
			}
		}

		$state = bits_e2e_mock_get_state();

		switch ( $endpoint ) {
			case 'getgroup':
				$parent = defined( 'GROUPS_IO_PARENT_GROUP' ) ? GROUPS_IO_PARENT_GROUP : 'bits-local-dev';
				return bits_e2e_mock_response( 200, array(
					'id'            => BITS_E2E_MOCK_PARENT_ID,
					'object'        => 'group',
					'name'          => $parent,
					'title'         => '',
					'desc'          => 'E2E mock parent group.',
					'subs_count'    => count( $state['subgroups'] ),
					'email_address' => 'main@' . $parent . '.groups.io',
				) );

			case 'getsubgroups':
				$rows = array_values( array_map( 'bits_e2e_mock_group_object', $state['subgroups'] ) );
				return bits_e2e_mock_response( 200, array(
					'object' => 'list',
					'data'   => $rows,
				) );

			case 'getmembers':
				$group_id = (int) ( $params['group_id'] ?? 0 );
				if ( BITS_E2E_MOCK_PARENT_ID === $group_id ) {
					return bits_e2e_mock_response( 200, array(
						'object' => 'list',
						'data'   => $state['parent_members'],
					) );
				}
				if ( ! isset( $state['subgroups'][ $group_id ] ) ) {
					return bits_e2e_mock_error( 'group_not_found' );
				}
				return bits_e2e_mock_response( 200, array(
					'object' => 'list',
					'data'   => $state['subgroups'][ $group_id ]['members'],
				) );

			case 'createsubgroup':
				$parent_name = (string) ( $params['group_name'] ?? '' );
				$sub_name    = (string) ( $params['sub_group_name'] ?? '' );
				$full_slug   = $parent_name . '+' . $sub_name;

				foreach ( $state['subgroups'] as $existing ) {
					if ( $existing['name'] === $full_slug ) {
						return bits_e2e_mock_error( 'bad_request', 'name already taken' );
					}
				}

				$new_id = $state['next_id'];
				$state['next_id']++;
				// Matches the confirmed createsubgroup contract: "A subscription
				// is created for the user to the group with owner
				// permissions" - the API-key-owning account becomes the
				// subgroup's sole member/owner immediately, never an empty
				// member list.
				$state['subgroups'][ $new_id ] = array(
					'id'         => $new_id,
					'name'       => $full_slug,
					'title'      => '',
					'desc'       => (string) ( $params['desc'] ?? '' ),
					'subs_count' => 1,
					'members'    => array(
						array( 'id' => $new_id * 10, 'email' => 'e2e-owner@example.test' ),
					),
				);
				bits_e2e_mock_save_state( $state );

				return bits_e2e_mock_response( 200, bits_e2e_mock_group_object( $state['subgroups'][ $new_id ] ) );

			case 'updategroup':
				$group_id = (int) ( $params['group_id'] ?? 0 );
				if ( ! isset( $state['subgroups'][ $group_id ] ) ) {
					return bits_e2e_mock_error( 'group_not_found' );
				}

				if ( isset( $params['name'] ) ) {
					$new_name = (string) $params['name'];

					foreach ( $state['subgroups'] as $id => $existing ) {
						if ( $id !== $group_id && $existing['name'] === $new_name ) {
							return bits_e2e_mock_error( 'bad_request', 'name exists' );
						}
					}

					$state['subgroups'][ $group_id ]['name'] = $new_name;
				}

				if ( isset( $params['title'] ) ) {
					$state['subgroups'][ $group_id ]['title'] = (string) $params['title'];
				}

				if ( isset( $params['desc'] ) ) {
					$state['subgroups'][ $group_id ]['desc'] = (string) $params['desc'];
				}

				bits_e2e_mock_save_state( $state );

				return bits_e2e_mock_response( 200, bits_e2e_mock_group_object( $state['subgroups'][ $group_id ] ) );

			case 'directadd':
				$emails       = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) ( $params['emails'] ?? '' ) ) ) );
				$subgroup_ids = array_filter( array_map( 'intval', explode( ',', (string) ( $params['subgroupids'] ?? '' ) ) ) );

				if ( ! isset( $state['next_member_id'] ) ) {
					$state['next_member_id'] = 900001;
				}

				foreach ( $subgroup_ids as $group_id ) {
					$is_parent = BITS_E2E_MOCK_PARENT_ID === $group_id;
					if ( ! $is_parent && ! isset( $state['subgroups'][ $group_id ] ) ) {
						continue;
					}

					foreach ( $emails as $email ) {
						$existing_members = $is_parent ? $state['parent_members'] : $state['subgroups'][ $group_id ]['members'];

						$already_member = false;
						foreach ( $existing_members as $member ) {
							if ( $member['email'] === $email ) {
								$already_member = true;
								break;
							}
						}
						if ( $already_member ) {
							continue;
						}

						$new_member = array(
							'id'    => $state['next_member_id'],
							'email' => $email,
						);
						$state['next_member_id']++;

						if ( $is_parent ) {
							$state['parent_members'][] = $new_member;
						} else {
							$state['subgroups'][ $group_id ]['members'][] = $new_member;
							$state['subgroups'][ $group_id ]['subs_count']++;
						}
					}
				}
				bits_e2e_mock_save_state( $state );

				return bits_e2e_mock_response( 200, array( 'object' => 'ok' ) );

			case 'removemember':
				$member_info_id = (int) ( $params['member_info_id'] ?? 0 );

				foreach ( $state['parent_members'] as $index => $member ) {
					if ( (int) $member['id'] === $member_info_id ) {
						unset( $state['parent_members'][ $index ] );
						$state['parent_members'] = array_values( $state['parent_members'] );
						break;
					}
				}

				foreach ( $state['subgroups'] as $group_id => $subgroup ) {
					foreach ( $subgroup['members'] as $index => $member ) {
						if ( (int) $member['id'] === $member_info_id ) {
							unset( $state['subgroups'][ $group_id ]['members'][ $index ] );
							$state['subgroups'][ $group_id ]['members'] = array_values( $state['subgroups'][ $group_id ]['members'] );
							$state['subgroups'][ $group_id ]['subs_count']--;
							break 2;
						}
					}
				}
				bits_e2e_mock_save_state( $state );

				return bits_e2e_mock_response( 200, array( 'object' => 'ok' ) );

			case 'deletegroup':
				$group_id = (int) ( $params['group_id'] ?? 0 );
				if ( ! isset( $state['subgroups'][ $group_id ] ) ) {
					return bits_e2e_mock_error( 'group_not_found' );
				}

				unset( $state['subgroups'][ $group_id ] );
				bits_e2e_mock_save_state( $state );

				return bits_e2e_mock_response( 200, array() );

			default:
				return bits_e2e_mock_error( 'not_found', "e2e mock: unhandled endpoint {$endpoint}" );
		}
	},
	5,
	3
);
