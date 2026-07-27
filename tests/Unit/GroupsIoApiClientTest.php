<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\GroupsIoApiClient;
use BITS\GroupsIOSync\GroupsIoApiException;
use BITS\GroupsIOSync\GroupsIoRateLimitException;
use BITS\GroupsIOSync\GroupsIoTransportException;
use WP_UnitTestCase;

final class GroupsIoApiClientTest extends WP_UnitTestCase {

	private static ?array $last_request = null;

	public function set_up(): void {
		parent::set_up();

		self::$last_request = null;

		if ( ! defined( 'GROUPS_IO_API_KEY' ) ) {
			define( 'GROUPS_IO_API_KEY', 'fake-test-key-not-real' );
		}
	}

	private function mock_response( array $response ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( $response ) {
				GroupsIoApiClientTest::$last_request = array(
					'url'  => $url,
					'args' => $parsed_args,
				);

				return $response;
			},
			10,
			3
		);
	}

	private function json_response( int $status, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}

	public function test_direct_add_sends_post_with_repeated_subgroupid_fields(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'ok' ) ) );

		$result = GroupsIoApiClient::direct_add( 'bits', array( 'a@example.test', 'b@example.test' ), array( 111, 222 ) );

		$this->assertSame( array( 'object' => 'ok' ), $result );
		$this->assertSame( 'POST', self::$last_request['args']['method'] );

		$body = self::$last_request['args']['body'];
		$this->assertStringContainsString( 'group_name=bits', $body );
		$this->assertStringContainsString( 'emails=a%40example.test%2Cb%40example.test', $body );
		$this->assertSame( 2, substr_count( $body, 'subgroupid=' ) );
		$this->assertStringContainsString( 'subgroupid=111', $body );
		$this->assertStringContainsString( 'subgroupid=222', $body );
	}

	public function test_remove_member_sends_post_with_single_member_info_id(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'ok' ) ) );

		GroupsIoApiClient::remove_member( 999 );

		$this->assertSame( 'POST', self::$last_request['args']['method'] );
		$this->assertSame( array( 'member_info_id' => 999 ), self::$last_request['args']['body'] );
	}

	public function test_create_subgroup_sends_post_with_expected_fields(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'group', 'id' => 152999 ) ) );

		$result = GroupsIoApiClient::create_subgroup( 'perception-is-all', 'new-subgroup' );

		$this->assertSame( array( 'object' => 'group', 'id' => 152999 ), $result );
		$this->assertSame( 'POST', self::$last_request['args']['method'] );
		$this->assertStringContainsString( 'createsubgroup', self::$last_request['url'] );
		$this->assertSame(
			array(
				'group_name'      => 'perception-is-all',
				'sub_group_name'  => 'new-subgroup',
				'desc'            => '',
				'accept_policies' => 'true',
			),
			self::$last_request['args']['body']
		);
	}

	public function test_create_subgroup_sends_provided_description(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'group', 'id' => 152999 ) ) );

		GroupsIoApiClient::create_subgroup( 'perception-is-all', 'new-subgroup', 'A test subgroup.' );

		$this->assertSame( 'A test subgroup.', self::$last_request['args']['body']['desc'] );
	}

	public function test_create_subgroup_duplicate_name_throws_api_exception(): void {
		$this->mock_response( $this->json_response( 400, array(
			'object' => 'error',
			'type'   => 'subgroup_exists',
			'extra'  => 'sub_group_name',
		) ) );

		try {
			GroupsIoApiClient::create_subgroup( 'perception-is-all', 'sociology' );
			$this->fail( 'Expected GroupsIoApiException.' );
		} catch ( GroupsIoApiException $exception ) {
			$this->assertSame( 'subgroup_exists', $exception->get_error_type() );
		}
	}

	public function test_remove_subgroup_sends_post_with_group_id_and_understand(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'ok' ) ) );

		GroupsIoApiClient::remove_subgroup( 152999 );

		$this->assertSame( 'POST', self::$last_request['args']['method'] );
		$this->assertStringContainsString( 'deletegroup', self::$last_request['url'] );
		$this->assertSame(
			array(
				'group_id'   => 152999,
				'understand' => 'I understand',
			),
			self::$last_request['args']['body']
		);
	}

	public function test_remove_subgroup_not_found_throws_api_exception(): void {
		$this->mock_response( $this->json_response( 400, array(
			'object' => 'error',
			'type'   => 'group_not_found',
			'extra'  => '',
		) ) );

		try {
			GroupsIoApiClient::remove_subgroup( 999999 );
			$this->fail( 'Expected GroupsIoApiException.' );
		} catch ( GroupsIoApiException $exception ) {
			$this->assertSame( 'group_not_found', $exception->get_error_type() );
		}
	}

	public function test_get_group_sends_get_with_group_name_query_arg(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'group' ) ) );

		GroupsIoApiClient::get_group( 'perception-is-all' );

		$this->assertSame( 'GET', self::$last_request['args']['method'] );
		$this->assertStringContainsString( 'getgroup', self::$last_request['url'] );
		$this->assertStringContainsString( 'group_name=perception-is-all', self::$last_request['url'] );
	}

	public function test_get_subgroups_sends_get_with_group_name_query_arg(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'list' ) ) );

		GroupsIoApiClient::get_subgroups( 'perception-is-all' );

		$this->assertSame( 'GET', self::$last_request['args']['method'] );
		$this->assertStringContainsString( 'getsubgroups', self::$last_request['url'] );
	}

	public function test_get_members_sends_get_with_numeric_group_id(): void {
		$this->mock_response( $this->json_response( 200, array( 'object' => 'list' ) ) );

		GroupsIoApiClient::get_members( 152360 );

		$this->assertSame( 'GET', self::$last_request['args']['method'] );
		$this->assertStringContainsString( 'group_id=152360', self::$last_request['url'] );
	}

	public function test_unauthorized_error_throws_with_correct_error_type(): void {
		$this->mock_response( $this->json_response( 400, array(
			'object' => 'error',
			'type'   => 'unauthorized_error',
			'extra'  => '',
		) ) );

		try {
			GroupsIoApiClient::get_group( 'bits' );
			$this->fail( 'Expected GroupsIoApiException.' );
		} catch ( GroupsIoApiException $exception ) {
			$this->assertSame( 'unauthorized_error', $exception->get_error_type() );
		}
	}

	public function test_inadequate_permissions_throws_with_correct_error_type(): void {
		$this->mock_response( $this->json_response( 400, array(
			'object' => 'error',
			'type'   => 'inadequate_permissions',
			'extra'  => '',
		) ) );

		try {
			GroupsIoApiClient::get_group( 'bits' );
			$this->fail( 'Expected GroupsIoApiException.' );
		} catch ( GroupsIoApiException $exception ) {
			$this->assertSame( 'inadequate_permissions', $exception->get_error_type() );
		}
	}

	public function test_group_not_found_throws_with_correct_error_type(): void {
		$this->mock_response( $this->json_response( 400, array(
			'object' => 'error',
			'type'   => 'group_not_found',
			'extra'  => '',
		) ) );

		try {
			GroupsIoApiClient::get_group( 'bits' );
			$this->fail( 'Expected GroupsIoApiException.' );
		} catch ( GroupsIoApiException $exception ) {
			$this->assertSame( 'group_not_found', $exception->get_error_type() );
		}
	}

	public function test_unrecognized_error_type_is_still_surfaced_correctly(): void {
		$this->mock_response( $this->json_response( 400, array(
			'object' => 'error',
			'type'   => 'some_future_error_type',
			'extra'  => 'diagnostic detail',
		) ) );

		try {
			GroupsIoApiClient::get_group( 'bits' );
			$this->fail( 'Expected GroupsIoApiException.' );
		} catch ( GroupsIoApiException $exception ) {
			$this->assertSame( 'some_future_error_type', $exception->get_error_type() );
			$this->assertSame( 'diagnostic detail', $exception->get_extra() );
		}
	}

	public function test_rate_limit_parses_retry_after_header(): void {
		$this->mock_response( array(
			'response' => array( 'code' => 429 ),
			'body'     => '',
			'headers'  => array( 'retry-after' => '30' ),
		) );

		try {
			GroupsIoApiClient::get_group( 'bits' );
			$this->fail( 'Expected GroupsIoRateLimitException.' );
		} catch ( GroupsIoRateLimitException $exception ) {
			$this->assertSame( 30, $exception->get_retry_after_seconds() );
		}
	}

	public function test_wp_error_transport_failure_throws_transport_exception(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$this->expectException( GroupsIoTransportException::class );

		GroupsIoApiClient::get_group( 'bits' );
	}

	public function test_5xx_response_throws_transport_exception(): void {
		$this->mock_response( array(
			'response' => array( 'code' => 503 ),
			'body'     => 'Service Unavailable',
			'headers'  => array(),
		) );

		$this->expectException( GroupsIoTransportException::class );

		GroupsIoApiClient::get_group( 'bits' );
	}

	public function test_unexpected_status_without_error_body_still_throws_api_exception(): void {
		$this->mock_response( array(
			'response' => array( 'code' => 418 ),
			'body'     => 'I am a teapot',
			'headers'  => array(),
		) );

		try {
			GroupsIoApiClient::get_group( 'bits' );
			$this->fail( 'Expected GroupsIoApiException.' );
		} catch ( GroupsIoApiException $exception ) {
			$this->assertSame( 'unexpected_status', $exception->get_error_type() );
		}
	}

	public function test_debug_log_line_never_contains_the_api_key(): void {
		$response = $this->json_response( 200, array( 'object' => 'ok' ) );

		$line = GroupsIoApiClient::build_debug_log_line(
			'GET',
			'https://groups.io/api/v1/getgroup?group_name=bits',
			array(),
			$response
		);

		$this->assertStringNotContainsString( GROUPS_IO_API_KEY, $line );
		$this->assertStringNotContainsString( 'Authorization', $line );
	}

	public function test_debug_log_line_includes_method_url_and_response_status(): void {
		$response = $this->json_response( 200, array( 'object' => 'ok' ) );

		$line = GroupsIoApiClient::build_debug_log_line(
			'GET',
			'https://groups.io/api/v1/getgroup?group_name=bits',
			array(),
			$response
		);

		$this->assertStringContainsString( 'GET', $line );
		$this->assertStringContainsString( 'getgroup', $line );
		$this->assertStringContainsString( '"status":200', $line );
	}
}
