<?php
/**
 * Base exception for a confirmed Groups.io API error response.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown for the confirmed {"object":"error","type":"...","extra":"..."}
 * response shape (PRD section 7), and for any other unexpected non-2xx
 * status (using the internal "unexpected_status" type marker so callers
 * still have one consistent exception type to catch). Callers branch on
 * get_error_type() to decide what a given failure means (hard-stop,
 * skip-and-continue, etc.) — that decision belongs to the caller, not
 * to this class.
 */
class GroupsIoApiException extends \RuntimeException {

	/**
	 * The raw Groups.io error `type` value, or an internal marker such
	 * as "unexpected_status".
	 *
	 * @var string
	 */
	private string $error_type;

	/**
	 * The raw Groups.io error `extra` value, or diagnostic detail for an
	 * internally-marked case.
	 *
	 * @var string
	 */
	private string $extra;

	/**
	 * @param string $error_type Raw Groups.io error type, or an internal marker.
	 * @param string $extra      Raw Groups.io "extra" detail, or diagnostic detail.
	 */
	public function __construct( string $error_type, string $extra = '' ) {
		parent::__construct( sprintf( 'Groups.io API error: %s', $error_type ) );

		$this->error_type = $error_type;
		$this->extra      = $extra;
	}

	/**
	 * @return string
	 */
	public function get_error_type(): string {
		return $this->error_type;
	}

	/**
	 * @return string
	 */
	public function get_extra(): string {
		return $this->extra;
	}
}
