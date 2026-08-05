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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BITS_E2E_MOCK_FAKE_KEYS = array( 'fake-local-dev-key-not-real', 'fake-test-key-not-real' );
const BITS_E2E_MOCK_OPTION    = 'bits_groupsio_e2e_mock_subgroups';

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
			'next_id'   => 200002,
			'subgroups' => array(
				200001 => array(
					'id'         => 200001,
					'name'       => $parent . '+fixture-subgroup',
					'title'      => '',
					'desc'       => 'A seeded fixture subgroup for E2E tests.',
					'subs_count' => 2,
					'members'    => array(
						array( 'id' => 300001, 'email' => 'fixture-member-1@example.test' ),
						array( 'id' => 300002, 'email' => 'fixture-member-2@example.test' ),
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
					'id'            => 999999,
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
				$state['subgroups'][ $new_id ] = array(
					'id'         => $new_id,
					'name'       => $full_slug,
					'title'      => '',
					'desc'       => (string) ( $params['desc'] ?? '' ),
					'subs_count' => 1,
					'members'    => array(),
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
