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
	 * Constructor.
	 *
	 * @param string $error_type Raw Groups.io error type, or an internal marker.
	 * @param string $extra      Raw Groups.io "extra" detail, or diagnostic detail.
	 */
	public function __construct( string $error_type, string $extra = '' ) {
		parent::__construct( sprintf( 'Groups.io API error: %s', $error_type ) );

		$this->error_type = $error_type;
		$this->extra      = $extra;
	}

	/**
	 * Returns the raw Groups.io error type, or an internal marker.
	 *
	 * @return string
	 */
	public function get_error_type(): string {
		return $this->error_type;
	}

	/**
	 * Returns the raw Groups.io "extra" detail, or diagnostic detail.
	 *
	 * @return string
	 */
	public function get_extra(): string {
		return $this->extra;
	}

	/**
	 * Formats this exception as plain language, never a raw API error
	 * dump or machine-oriented error type/code - the single shared
	 * implementation of the "never expose a raw error to an admin"
	 * requirement (originally SubgroupManagementPage's own
	 * friendly_error(), extracted here once SubgroupExecutionEngine
	 * needed the identical logic - see
	 * subgroup-crud-and-admin-pages-design.md section 8). 'unexpected_status'
	 * carries a raw HTTP status/body dump in its extra field (see
	 * GroupsIoApiClient::request()), not a Groups.io-authored
	 * human-readable detail like every other error type, so it's never
	 * surfaced verbatim. GroupsIoRateLimitException overrides this with
	 * its own fixed message, since its "extra" field holds a numeric
	 * retry-after value, not human-readable text.
	 *
	 * @return string
	 */
	public function friendly_message(): string {
		if ( 'unexpected_status' === $this->error_type ) {
			return __( 'an unexpected error occurred.', 'bits-groupsio-sync' );
		}

		return '' !== $this->extra ? $this->extra : __( 'an unexpected error occurred.', 'bits-groupsio-sync' );
	}
}
