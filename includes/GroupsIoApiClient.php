<?php
/**
 * Groups.io API client: directadd, removemember, and read-only lookups.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the confirmed Groups.io API contract (PRD sections 4.2-4.3, 7)
 * over wp_remote_request. Single responsibility: build correct requests
 * and dispatch failures into a catchable, typed shape — it does not
 * itself decide what a caller should do about a given failure (retry,
 * skip, hard-stop), which belongs to the Phase 3+ callers that use it.
 */
final class GroupsIoApiClient {

	private const BASE_URL = 'https://groups.io/api/v1/';

	/**
	 * Adds one or more emails to the parent group and one or more
	 * subgroups in a single call. Batches across subgroups via repeated
	 * subgroupid fields (PRD section 4.2); does not batch across
	 * multiple direct_add() calls.
	 *
	 * @param string             $group_name   Parent group name.
	 * @param array<int, string> $emails       Email addresses to add.
	 * @param array<int, int>    $subgroup_ids Numeric subgroup IDs to add to.
	 * @return array<string, mixed>
	 *
	 * @throws GroupsIoTransportException On a network-level failure or 5xx.
	 * @throws GroupsIoRateLimitException On HTTP 429.
	 * @throws GroupsIoApiException On any other non-2xx response.
	 */
	public static function direct_add( string $group_name, array $emails, array $subgroup_ids ): array {
		$body_string = self::encode_body_with_repeated_field(
			array(
				'group_name' => $group_name,
				// Comma-separated per Groups.io's documented convention for
				// a multi-email single field; unlike subgroupid, PRD section
				// 4.2 does not describe emails as a repeated field.
				'emails'     => implode( ',', $emails ),
			),
			'subgroupid',
			$subgroup_ids
		);

		return self::request( 'POST', 'directadd', array(), $body_string );
	}

	/**
	 * Removes a single membership record (parent-level or subgroup-level).
	 * Does not batch or loop — removemember only accepts one
	 * member_info_id per call and silently ignores extras (PRD section
	 * 4.2). Removing a member from multiple subgroups requires one call
	 * per subgroup, looped by the caller.
	 *
	 * @param int $member_info_id The specific membership record's ID.
	 * @return array<string, mixed>
	 *
	 * @throws GroupsIoTransportException On a network-level failure or 5xx.
	 * @throws GroupsIoRateLimitException On HTTP 429.
	 * @throws GroupsIoApiException On any other non-2xx response.
	 */
	public static function remove_member( int $member_info_id ): array {
		return self::request(
			'POST',
			'removemember',
			array(),
			array( 'member_info_id' => $member_info_id )
		);
	}

	/**
	 * Creates a subgroup under the given parent group. The creating
	 * account is auto-added as the subgroup's sole owner-member
	 * (confirmed by live trial, 2026-07-25 — see
	 * Groups.io-API-Reference.md). Sends accept_policies=true even though
	 * live calls succeeded without it — the docs say it's required, and
	 * production code follows the documented contract rather than the
	 * looser observed behavior.
	 *
	 * @param string $parent_group_name Parent group name.
	 * @param string $subgroup_name     New subgroup name.
	 * @return array<string, mixed> Decoded response, including the new subgroup's numeric id.
	 *
	 * @throws GroupsIoTransportException On a network-level failure or 5xx.
	 * @throws GroupsIoRateLimitException On HTTP 429.
	 * @throws GroupsIoApiException On any other non-2xx response.
	 */
	public static function create_subgroup( string $parent_group_name, string $subgroup_name ): array {
		return self::request(
			'POST',
			'createsubgroup',
			array(),
			array(
				'group_name'      => $parent_group_name,
				'sub_group_name'  => $subgroup_name,
				'accept_policies' => 'true',
			)
		);
	}

	/**
	 * Deletes a subgroup by its numeric ID. deletegroup is the only
	 * deletion endpoint — removesubgroup/deletesubgroup do not exist
	 * (confirmed by live trial, 2026-07-25 — see
	 * Groups.io-API-Reference.md). Works while members are still present;
	 * no separate member-removal step is required first.
	 *
	 * @param int $subgroup_id Numeric subgroup ID.
	 * @return array<string, mixed>
	 *
	 * @throws GroupsIoTransportException On a network-level failure or 5xx.
	 * @throws GroupsIoRateLimitException On HTTP 429.
	 * @throws GroupsIoApiException On any other non-2xx response.
	 */
	public static function remove_subgroup( int $subgroup_id ): array {
		return self::request(
			'POST',
			'deletegroup',
			array(),
			array(
				'group_id'   => $subgroup_id,
				'understand' => 'I understand',
			)
		);
	}

	/**
	 * Looks up a group or subgroup by its name string. Valid at the
	 * parent/listing level (PRD section 4.2). Confirmed GET by live
	 * trial against the test group (see
	 * app/docs/groupsio-api-client-design.md section 7).
	 *
	 * @param string $group_name Bare slug, or "parent+subgroup" form.
	 * @return array<string, mixed>
	 *
	 * @throws GroupsIoTransportException On a network-level failure or 5xx.
	 * @throws GroupsIoRateLimitException On HTTP 429.
	 * @throws GroupsIoApiException On any other non-2xx response.
	 */
	public static function get_group( string $group_name ): array {
		return self::request( 'GET', 'getgroup', array( 'group_name' => $group_name ) );
	}

	/**
	 * Lists a parent group's subgroups. Confirmed GET by live trial
	 * against the test group (see
	 * app/docs/groupsio-api-client-design.md section 7).
	 *
	 * @param string $parent_group_name Bare parent group slug.
	 * @return array<string, mixed>
	 *
	 * @throws GroupsIoTransportException On a network-level failure or 5xx.
	 * @throws GroupsIoRateLimitException On HTTP 429.
	 * @throws GroupsIoApiException On any other non-2xx response.
	 */
	public static function get_subgroups( string $parent_group_name ): array {
		return self::request( 'GET', 'getsubgroups', array( 'group_name' => $parent_group_name ) );
	}

	/**
	 * Lists a subgroup's members. Requires the numeric group_id — the
	 * name-string form returns group_not_found for member-level calls
	 * (PRD section 4.2). Confirmed GET by live trial against the test
	 * group (see app/docs/groupsio-api-client-design.md section 7).
	 *
	 * @param int $group_id Numeric subgroup ID.
	 * @return array<string, mixed>
	 *
	 * @throws GroupsIoTransportException On a network-level failure or 5xx.
	 * @throws GroupsIoRateLimitException On HTTP 429.
	 * @throws GroupsIoApiException On any other non-2xx response.
	 */
	public static function get_members( int $group_id ): array {
		return self::request( 'GET', 'getmembers', array( 'group_id' => $group_id ) );
	}

	/**
	 * Builds a raw urlencoded body string with one field repeated
	 * multiple times, which PHP associative arrays cannot represent
	 * (duplicate keys collapse to the last one) — so wp_remote_request's
	 * array-body form can't be used for directadd's repeated subgroupid
	 * field.
	 *
	 * @param array<string, mixed> $scalar_fields   Ordinary single-value fields.
	 * @param string               $repeated_key    Field name to repeat.
	 * @param array<int, mixed>    $repeated_values Values for the repeated field.
	 * @return string
	 */
	private static function encode_body_with_repeated_field( array $scalar_fields, string $repeated_key, array $repeated_values ): string {
		$parts = array();

		foreach ( $scalar_fields as $key => $value ) {
			$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		foreach ( $repeated_values as $value ) {
			$parts[] = rawurlencode( $repeated_key ) . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $parts );
	}

	/**
	 * Issues the HTTP request and dispatches the response, per the
	 * confirmed contract in PRD sections 4.2 and 7.
	 *
	 * @param string                      $method     'GET' or 'POST'.
	 * @param string                      $endpoint   Endpoint name (no leading slash).
	 * @param array<string, mixed>        $query_args Query-string args (GET).
	 * @param array<string, mixed>|string $body       Request body (POST); array or pre-encoded string.
	 * @return array<string, mixed>
	 *
	 * @throws GroupsIoTransportException On a network-level failure or 5xx.
	 * @throws GroupsIoRateLimitException On HTTP 429.
	 * @throws GroupsIoApiException On any other non-2xx response.
	 */
	private static function request( string $method, string $endpoint, array $query_args = array(), $body = array() ): array {
		$url = self::BASE_URL . $endpoint;

		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . ( defined( 'GROUPS_IO_API_KEY' ) ? GROUPS_IO_API_KEY : '' ),
			),
		);

		if ( 'POST' === $method ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		// Deliberately never logs $args['headers'] (which is where the
		// only copy of the Authorization/API-key value lives in this
		// request) — satisfies the Security document's redaction
		// requirement (sections 2 and 4) by omission, rather than by
		// logging headers and then trying to scrub the key back out.
		self::maybe_log_debug( $method, $url, $body, $response );

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal exception message, never rendered as HTML output; escaping functions do not apply here.
			throw new GroupsIoTransportException( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			return is_array( $decoded ) ? $decoded : array();
		}

		if ( 429 === $status ) {
			throw new GroupsIoRateLimitException( (int) wp_remote_retrieve_header( $response, 'retry-after' ) );
		}

		if ( $status >= 500 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal exception message, never rendered as HTML output; escaping functions do not apply here.
			throw new GroupsIoTransportException( sprintf( 'Groups.io API returned HTTP %d', $status ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $decoded ) && isset( $decoded['type'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception data (the confirmed Groups.io error type/extra), never rendered as HTML output; escaping functions do not apply here.
			throw new GroupsIoApiException( (string) $decoded['type'], (string) ( $decoded['extra'] ?? '' ) );
		}

		throw new GroupsIoApiException(
			'unexpected_status',
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal exception message, never rendered as HTML output; escaping functions do not apply here.
			sprintf( 'HTTP %d: %s', $status, wp_remote_retrieve_body( $response ) )
		);
	}

	/**
	 * Writes the request/response to the WordPress debug log when
	 * WP_DEBUG is active — required by PRD section 6.
	 *
	 * @param string                         $method   HTTP method used.
	 * @param string                         $url      Request URL.
	 * @param array<string, mixed>|string    $body     Request body.
	 * @param array<string, mixed>|\WP_Error $response Raw response from wp_remote_request.
	 * @return void
	 */
	private static function maybe_log_debug( string $method, string $url, $body, $response ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( self::build_debug_log_line( $method, $url, $body, $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate PRD section 6 debug-log behavior, gated on WP_DEBUG.
	}

	/**
	 * Builds the debug log line. A pure function, separated from
	 * maybe_log_debug() so the redaction behavior is directly unit
	 * testable without needing to toggle the WP_DEBUG constant (which
	 * PHP cannot redefine once set). Deliberately never includes the
	 * request headers (the only place the Authorization/API-key value
	 * lives in a request) — satisfies the Security document's redaction
	 * requirement (sections 2 and 4) by omission, rather than by logging
	 * headers and then trying to scrub the key back out.
	 *
	 * @param string                         $method   HTTP method used.
	 * @param string                         $url      Request URL.
	 * @param array<string, mixed>|string    $body     Request body.
	 * @param array<string, mixed>|\WP_Error $response Raw response from wp_remote_request.
	 * @return string
	 */
	public static function build_debug_log_line( string $method, string $url, $body, $response ): string {
		$response_summary = is_wp_error( $response )
			? $response->get_error_message()
			: wp_json_encode(
				array(
					'status' => wp_remote_retrieve_response_code( $response ),
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);

		return sprintf(
			'[BITS Groups.io Sync] %s %s body=%s response=%s',
			$method,
			$url,
			wp_json_encode( is_array( $body ) ? $body : array( 'raw' => $body ) ),
			$response_summary
		);
	}
}
