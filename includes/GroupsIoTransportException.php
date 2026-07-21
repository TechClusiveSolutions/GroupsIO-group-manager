<?php
/**
 * Thrown for network-level failures and HTTP 5xx responses.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deliberately not part of the GroupsIoApiException hierarchy: a
 * transport-level failure (WP_Error from wp_remote_request, or a 5xx
 * response) has no Groups.io-originated error `type` to expose at all,
 * unlike GroupsIoApiException's confirmed error-type family. Retrying is
 * Action Scheduler's responsibility (per PRD section 7), not this
 * client's — this class only signals the failure.
 */
final class GroupsIoTransportException extends \RuntimeException {}
