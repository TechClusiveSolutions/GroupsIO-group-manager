<?php
/**
 * Thrown when Groups.io returns HTTP 429.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A distinct subclass of GroupsIoApiException — not just another
 * get_error_type() value — because HTTP 429 has no
 * {"object":"error","type":...} body at all (PRD section 7 treats it as
 * a distinct case from the confirmed error-type family). Carries the
 * parsed Retry-After value; actually rescheduling with jitter is the
 * caller's responsibility, per PRD section 7.
 */
final class GroupsIoRateLimitException extends GroupsIoApiException {

	/**
	 * @var int
	 */
	private int $retry_after_seconds;

	/**
	 * @param int $retry_after_seconds Parsed Retry-After header value, in seconds.
	 */
	public function __construct( int $retry_after_seconds ) {
		parent::__construct( 'rate_limited', (string) $retry_after_seconds );

		$this->retry_after_seconds = $retry_after_seconds;
	}

	/**
	 * @return int
	 */
	public function get_retry_after_seconds(): int {
		return $this->retry_after_seconds;
	}
}
