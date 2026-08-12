<?php
/**
 * Queued execution engine for Subgroup Management's create/update/delete
 * actions.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

use BITS\GroupsIOSync\Admin\AdminNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements #50: Subgroup Management's create/update/delete actions are
 * queued and retried (up to 3 attempts) rather than performed
 * synchronously inside the admin's request, per
 * subgroup-crud-and-admin-pages-design.md section 8.
 *
 * Deliberately not folded into QueuedExecutionEngine (User Assignment's
 * own queued engine) - a subgroup action has no target member email, and
 * this engine's notification policy is failure-only, not every-outcome.
 *
 * Create and Update validate directly against the write call's own
 * response object (create_subgroup()/update_subgroup() both already
 * return the resulting object synchronously and authoritatively - no
 * extra get_subgroups() read-back call). Delete has no such object to
 * trust for confirming an absence, so it still performs one follow-up
 * get_subgroups() call per attempt - the one genuine case among the
 * three where Groups.io's list endpoint's own eventual consistency (per
 * SubgroupLifecycleIntegrationTest's assert_eventually()) is the actual
 * problem being retried around.
 *
 * Single responsibility: this class only owns queuing and executing
 * these jobs - it delegates the audit write to AuditLog::record() and
 * the admin-facing failure notice to AdminNotifications::add().
 */
final class SubgroupExecutionEngine {

	private const HOOK = 'bits_groupsio_execute_queued_subgroup_action';

	private const MAX_ATTEMPTS = 3;

	private const RETRY_DELAY_SECONDS = MINUTE_IN_SECONDS;

	/**
	 * Registers the job-execution hook. Called once from Plugin's
	 * constructor (on plugins_loaded).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'execute' ), 10, 1 );
	}

	/**
	 * Queues a Create job. $name/$title/$description are exactly what
	 * SubgroupManagementPage::process_create() already validated (a
	 * non-empty $name) - this method does no further validation of its
	 * own.
	 *
	 * @param string $name          New subgroup name segment (without the parent prefix).
	 * @param string $title         Optional cosmetic title, '' if none.
	 * @param string $description   Optional description, '' if none.
	 * @param int    $admin_user_id WP user id of the admin who submitted Create.
	 * @return void
	 */
	public static function queue_create( string $name, string $title, string $description, int $admin_user_id ): void {
		self::schedule(
			array(
				'job_action'    => 'create',
				'name'          => $name,
				'title'         => $title,
				'description'   => $description,
				'expected_slug' => self::parent_group() . '+' . $name,
				'admin_user_id' => $admin_user_id,
				'attempt'       => 1,
			),
			time()
		);
	}

	/**
	 * Queues an Update job. $fields is exactly the already-computed diff
	 * SubgroupManagementPage::process_update() built (only the keys that
	 * actually changed) - this method sends it to update_subgroup() as-is
	 * on each attempt.
	 *
	 * @param int                  $subgroup_id   Numeric subgroup id.
	 * @param string               $current_slug  Full slug before this update.
	 * @param array<string,string> $fields        Changed fields only: any of 'name', 'title', 'desc'.
	 * @param int                  $admin_user_id WP user id of the admin who submitted Update.
	 * @return void
	 */
	public static function queue_update( int $subgroup_id, string $current_slug, array $fields, int $admin_user_id ): void {
		self::schedule(
			array(
				'job_action'    => 'update',
				'subgroup_id'   => $subgroup_id,
				'current_slug'  => $current_slug,
				'fields'        => $fields,
				'admin_user_id' => $admin_user_id,
				'attempt'       => 1,
			),
			time()
		);
	}

	/**
	 * Queues a Delete job.
	 *
	 * @param int    $subgroup_id   Numeric subgroup id.
	 * @param string $slug          Full slug, for cache invalidation and the notification message.
	 * @param int    $admin_user_id WP user id of the admin who submitted Delete.
	 * @return void
	 */
	public static function queue_delete( int $subgroup_id, string $slug, int $admin_user_id ): void {
		self::schedule(
			array(
				'job_action'    => 'delete',
				'subgroup_id'   => $subgroup_id,
				'slug'          => $slug,
				'admin_user_id' => $admin_user_id,
				'attempt'       => 1,
			),
			time()
		);
	}

	/**
	 * Schedules one job as an Action Scheduler single action.
	 *
	 * @param array<string, mixed> $job       Job data - see queue_create()/queue_update()/queue_delete().
	 * @param int                  $timestamp Unix timestamp to run the job at.
	 * @return void
	 */
	private static function schedule( array $job, int $timestamp ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action( $timestamp, self::HOOK, array( $job ), '', false );
	}

	/**
	 * The job callback itself. Bound to self::HOOK via register(). On a
	 * caught API/transport failure with attempts remaining, reschedules
	 * itself with the attempt counter incremented - otherwise records the
	 * outcome (audit log + failure-only admin notification) and stops.
	 *
	 * @param array<string, mixed> $job Job data - see queue_create()/queue_update()/queue_delete().
	 * @return void
	 */
	public static function execute( array $job ): void {
		$attempt = (int) ( $job['attempt'] ?? 1 );

		try {
			switch ( $job['job_action'] ) {
				case 'create':
					self::execute_create( $job );
					break;
				case 'update':
					self::execute_update( $job );
					break;
				default:
					self::execute_delete( $job );
			}
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			if ( $attempt < self::MAX_ATTEMPTS ) {
				$job['attempt'] = $attempt + 1;
				self::schedule( $job, time() + self::RETRY_DELAY_SECONDS );
				return;
			}

			self::record_outcome( $job, 'failure', self::friendly_detail( $exception ) );
		}
	}

	/**
	 * Performs one Create attempt: invalidates the expected slug's cache
	 * entry, calls create_subgroup(), and validates the description
	 * directly against that response object - no separate read-back
	 * call, since create_subgroup()'s own response already reflects the
	 * change synchronously and authoritatively. If a title was submitted,
	 * a follow-up update_subgroup() call sets it (createsubgroup has no
	 * title parameter) and both title and description are then validated
	 * against *that* response instead, being the fresher one.
	 *
	 * On a retried attempt (attempt > 1) that hits a "name already
	 * exists"-shaped error, treats it as idempotent success - a prior
	 * attempt likely already created it, but the response was lost to
	 * the same transient failure now being retried - by looking the
	 * subgroup up by slug and continuing from there, rather than
	 * reporting a spurious failure for a create that actually succeeded.
	 *
	 * @param array<string, mixed> $job Job data.
	 * @return void
	 *
	 * @throws GroupsIoApiException On a confirmed Groups.io API error response, or an unconfirmed title/description after the write. GroupsIoTransportException can also propagate from create_subgroup()/update_subgroup() on a network-level failure - phpcs's throw-tag sniff only checks tags against explicit "throw" statements in this method's own body, so that one isn't separately listed here.
	 */
	private static function execute_create( array $job ): void {
		$name          = (string) $job['name'];
		$title         = (string) $job['title'];
		$description   = (string) $job['description'];
		$attempt       = (int) ( $job['attempt'] ?? 1 );
		$expected_slug = (string) $job['expected_slug'];

		SubgroupIdCache::invalidate( $expected_slug );

		try {
			$created = GroupsIoApiClient::create_subgroup( self::parent_group(), $name, $description );
		} catch ( GroupsIoApiException $exception ) {
			if ( $attempt > 1 && self::looks_like_duplicate_name( $exception ) ) {
				$created = self::fetch_subgroup_by_slug( $expected_slug );
				if ( null === $created ) {
					throw $exception;
				}
			} else {
				throw $exception;
			}
		}

		if ( '' !== $title ) {
			$created = GroupsIoApiClient::update_subgroup( (int) $created['id'], array( 'title' => $title ) );
			if ( ( $created['title'] ?? null ) !== $title ) {
				throw new GroupsIoApiException( 'not_yet_confirmed', 'the title could not be confirmed.' );
			}
		}

		if ( (string) ( $created['desc'] ?? '' ) !== $description ) {
			throw new GroupsIoApiException( 'not_yet_confirmed', 'the description could not be confirmed.' );
		}

		self::record_outcome( $job, 'success' );
	}

	/**
	 * Whether a caught GroupsIoApiException looks like Groups.io's
	 * "the name is already taken" rejection - Groups.io documents this
	 * as a bad_request with a human-readable `extra` string (e.g. "name
	 * exists", "name already taken"), not a stable machine-readable
	 * code, so this matches on a couple of expected substrings rather
	 * than one exact string.
	 *
	 * @param GroupsIoApiException $exception Caught exception.
	 * @return bool
	 */
	private static function looks_like_duplicate_name( GroupsIoApiException $exception ): bool {
		if ( 'bad_request' !== $exception->get_error_type() ) {
			return false;
		}

		$extra = strtolower( $exception->get_extra() );

		return false !== strpos( $extra, 'exist' ) || false !== strpos( $extra, 'taken' );
	}

	/**
	 * Performs one Update attempt: invalidates the old and new slug's
	 * cache entries (if renaming), calls update_subgroup() with the
	 * already-computed field diff, and validates every changed field
	 * directly against that response object - no separate read-back
	 * call.
	 *
	 * @param array<string, mixed> $job Job data.
	 * @return void
	 *
	 * @throws GroupsIoApiException On a confirmed Groups.io API error response, or an unconfirmed field after the write. GroupsIoTransportException can also propagate from update_subgroup() on a network-level failure - phpcs's throw-tag sniff only checks tags against explicit "throw" statements in this method's own body, so that one isn't separately listed here.
	 */
	private static function execute_update( array $job ): void {
		$subgroup_id  = (int) $job['subgroup_id'];
		$current_slug = (string) $job['current_slug'];
		$fields       = (array) $job['fields'];

		if ( isset( $fields['name'] ) ) {
			SubgroupIdCache::invalidate( $current_slug );
			SubgroupIdCache::invalidate( (string) $fields['name'] );
		}

		$after = GroupsIoApiClient::update_subgroup( $subgroup_id, $fields );

		foreach ( $fields as $key => $value ) {
			if ( ( $after[ $key ] ?? null ) !== $value ) {
				throw new GroupsIoApiException( 'not_yet_confirmed', 'the update could not be confirmed.' );
			}
		}

		self::record_outcome( $job, 'success' );
	}

	/**
	 * Performs one Delete attempt: calls remove_subgroup() (already
	 * idempotent-tolerant of group_not_found, matching the pre-existing
	 * synchronous logic), then confirms absence via one get_subgroups()
	 * call - the one case among the three where there's no "here's the
	 * result" response object to trust instead, so this attempt-and-retry
	 * loop is also functioning as the polling the eventual-consistency
	 * problem actually needs.
	 *
	 * @param array<string, mixed> $job Job data.
	 * @return void
	 *
	 * @throws GroupsIoApiException On a confirmed Groups.io API error response (other than an idempotent group_not_found), or if the subgroup is still listed after the delete call. GroupsIoTransportException can also propagate from remove_subgroup()/get_subgroups() on a network-level failure - phpcs's throw-tag sniff only checks tags against explicit "throw" statements in this method's own body, so that one isn't separately listed here.
	 */
	private static function execute_delete( array $job ): void {
		$subgroup_id = (int) $job['subgroup_id'];
		$slug        = (string) $job['slug'];

		try {
			GroupsIoApiClient::remove_subgroup( $subgroup_id );
		} catch ( GroupsIoApiException $exception ) {
			if ( 'group_not_found' !== $exception->get_error_type() ) {
				throw $exception;
			}
			// Idempotent: an earlier attempt (or a concurrent delete)
			// already removed it - proceed to confirm absence below.
		}

		SubgroupIdCache::invalidate( $slug );

		if ( null !== self::fetch_subgroup_by_id( $subgroup_id ) ) {
			throw new GroupsIoApiException( 'not_yet_confirmed', 'the deletion could not be confirmed.' );
		}

		self::record_outcome( $job, 'success' );
	}

	/**
	 * Re-fetches the configured parent's subgroup listing and returns the
	 * row matching the given numeric id, or null if not present.
	 *
	 * @param int $subgroup_id Numeric subgroup id to look up.
	 * @return array<string, mixed>|null
	 *
	 * @throws GroupsIoApiException Propagated from the client on lookup failure.
	 * @throws GroupsIoTransportException Propagated from the client on a transport failure.
	 */
	private static function fetch_subgroup_by_id( int $subgroup_id ): ?array {
		$subgroups = GroupsIoApiClient::get_subgroups( self::parent_group() );

		foreach ( (array) ( $subgroups['data'] ?? array() ) as $subgroup ) {
			if ( (int) ( $subgroup['id'] ?? 0 ) === $subgroup_id ) {
				return $subgroup;
			}
		}

		return null;
	}

	/**
	 * Same as fetch_subgroup_by_id(), but matches by full slug - used by
	 * execute_create()'s idempotent-retry path, where the id isn't known
	 * ahead of time.
	 *
	 * @param string $slug Full slug ("parent+sub" form) to look up.
	 * @return array<string, mixed>|null
	 *
	 * @throws GroupsIoApiException Propagated from the client on lookup failure.
	 * @throws GroupsIoTransportException Propagated from the client on a transport failure.
	 */
	private static function fetch_subgroup_by_slug( string $slug ): ?array {
		$subgroups = GroupsIoApiClient::get_subgroups( self::parent_group() );

		foreach ( (array) ( $subgroups['data'] ?? array() ) as $subgroup ) {
			if ( ( $subgroup['name'] ?? null ) === $slug ) {
				return $subgroup;
			}
		}

		return null;
	}

	/**
	 * Records one job outcome to the audit log - both success and
	 * failure, unlike the notification policy below, since the audit
	 * trail's own purpose is a complete record, not just alerting. Only
	 * a failure outcome also raises a persisted admin notification
	 * (AdminNotifications::add()) - the immediate `*_submitted` response
	 * the admin already saw when the action was queued is enough
	 * acknowledgment for a success, and a second notice later just
	 * confirming it worked would add noise for the common case.
	 *
	 * @param array<string, mixed> $job                 Job data.
	 * @param string               $outcome             'success' or 'failure'.
	 * @param string               $api_response_detail Raw failure detail, if any.
	 * @return void
	 */
	private static function record_outcome( array $job, string $outcome, string $api_response_detail = '' ): void {
		$action_map = array(
			'create' => 'subgroup_create',
			'update' => 'subgroup_update',
			'delete' => 'subgroup_delete',
		);
		$job_action = (string) $job['job_action'];
		$identifier = 'create' === $job_action ? (string) $job['expected_slug'] : (string) $job['subgroup_id'];

		AuditLog::record(
			$action_map[ $job_action ] ?? $job_action,
			$outcome,
			'',
			$identifier,
			(int) $job['admin_user_id'],
			$api_response_detail
		);

		if ( 'failure' === $outcome ) {
			AdminNotifications::add( 'failure', self::notification_message( $job, $api_response_detail ) );
		}
	}

	/**
	 * Composes the failure notification's message text, identifying the
	 * subgroup by name/slug and including the plain-language failure
	 * detail, consistent with this project's "never a raw error dump"
	 * requirement.
	 *
	 * @param array<string, mixed> $job    Job data.
	 * @param string               $detail Plain-language failure detail.
	 * @return string
	 */
	private static function notification_message( array $job, string $detail ): string {
		switch ( (string) $job['job_action'] ) {
			case 'create':
				/* translators: 1: subgroup name being created. 2: plain-language failure detail. */
				return sprintf( __( 'Could not create the subgroup "%1$s": %2$s', 'bits-groupsio-sync' ), (string) $job['name'], $detail );
			case 'update':
				/* translators: 1: subgroup slug being updated. 2: plain-language failure detail. */
				return sprintf( __( 'Could not update the subgroup "%1$s": %2$s', 'bits-groupsio-sync' ), (string) $job['current_slug'], $detail );
			default:
				/* translators: 1: subgroup slug being deleted. 2: plain-language failure detail. */
				return sprintf( __( 'Could not delete the subgroup "%1$s": %2$s', 'bits-groupsio-sync' ), (string) $job['slug'], $detail );
		}
	}

	/**
	 * Formats a caught exception as plain language for the audit log and
	 * the failure notification, never a raw API error dump, matching
	 * SubgroupManagementPage's own error-formatting requirement -
	 * GroupsIoApiException::friendly_message() is the shared
	 * implementation both now delegate to.
	 *
	 * @param GroupsIoApiException|GroupsIoTransportException $exception Caught exception.
	 * @return string
	 */
	private static function friendly_detail( GroupsIoApiException|GroupsIoTransportException $exception ): string {
		if ( $exception instanceof GroupsIoApiException ) {
			return $exception->friendly_message();
		}

		return __( 'a connection problem occurred.', 'bits-groupsio-sync' );
	}

	/**
	 * Reads the configured parent group slug.
	 *
	 * @return string
	 */
	private static function parent_group(): string {
		return defined( 'GROUPS_IO_PARENT_GROUP' ) ? GROUPS_IO_PARENT_GROUP : '';
	}
}
