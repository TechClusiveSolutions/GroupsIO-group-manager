<?php
/**
 * Queued execution engine for User Assignment add/remove actions.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

use BITS\GroupsIOSync\Admin\AdminNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First real implementation of the queued-execution design (issue #50),
 * scoped to User Assignment's add/remove actions - Subgroup Management's
 * create/update/delete actions remain synchronous and are unaffected.
 *
 * Submitting an add or remove only ever schedules an Action Scheduler
 * job here (queue_add()/queue_remove()) - no Groups.io API call happens
 * inside the request that queues it. Each job (execute()) performs the
 * actual direct_add()/remove_member() call, retrying up to 3 attempts
 * (manual reschedule with a fixed backoff - Action Scheduler has no
 * automatic retry of its own) before recording a failure outcome.
 *
 * Single responsibility: this class only owns queuing and executing
 * these jobs - it delegates the actual index update to
 * MemberIndex::apply_add()/apply_remove(), the audit write to
 * AuditLog::record(), and the admin-facing outcome to
 * AdminNotifications::add(). process_due_jobs() (the admin-facing
 * "Sync" control's handler) is the one exception - it also forces
 * MemberIndex::sync() itself to run, per #99's follow-up, since that's
 * the one other on-demand "make everything current now" action the
 * Sync button needs to cover.
 */
final class QueuedExecutionEngine {

	/**
	 * Also read by ActionSchedulerClient::schedule() - not private, since
	 * that class is the one place besides register()/execute() below that
	 * needs to know which hook a scheduled action id belongs to.
	 */
	public const HOOK = 'bits_groupsio_execute_queued_action';

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
	 * Queues one add job for a single (member, subgroup) pair. A bulk
	 * "Add Selected" action calls this once per checked group, not once
	 * for the whole batch. Also queues an add to the configured parent
	 * group if the member isn't already a current member of it (per
	 * MemberIndex::is_currently_in_group()'s "currently subscribed"
	 * definition) - a member can't meaningfully belong to one of BITS'
	 * Groups.io subgroups without also belonging to the parent group
	 * itself. See maybe_queue_parent_add() below for how the parent
	 * add is resolved and guarded against recursion.
	 *
	 * @param int    $user_id        Matched WP user id of the member, or 0 if none.
	 * @param string $email          Member's email address.
	 * @param string $display_name   Member's display name, if known.
	 * @param int    $subgroup_id    Numeric Groups.io group/subgroup id to add to.
	 * @param string $subgroup_slug  Full slug of the group/subgroup to add to.
	 * @param string $subgroup_title Cosmetic title, if any.
	 * @param int    $admin_user_id  WP user id of the admin queuing the action.
	 * @return int The scheduled action's id (0 if scheduling failed). The
	 *             auto-queued parent-add's own id (see
	 *             maybe_queue_parent_add() below) is never returned here -
	 *             only the id of the action the caller directly requested,
	 *             per the existing "does not change the visible
	 *             queued-count" precedent already established for that
	 *             auto-add.
	 */
	public static function queue_add(
		int $user_id,
		string $email,
		string $display_name,
		int $subgroup_id,
		string $subgroup_slug,
		string $subgroup_title,
		int $admin_user_id
	): int {
		$action_id = ActionSchedulerClient::schedule(
			array(
				'job_action'     => 'add',
				'user_id'        => $user_id,
				'email'          => $email,
				'display_name'   => $display_name,
				'subgroup_id'    => $subgroup_id,
				'subgroup_slug'  => $subgroup_slug,
				'subgroup_title' => $subgroup_title,
				'admin_user_id'  => $admin_user_id,
				'attempt'        => 1,
			),
			time()
		);

		self::maybe_queue_parent_add( $user_id, $email, $display_name, $subgroup_slug, $admin_user_id );

		return $action_id;
	}

	/**
	 * Queues an add to the configured parent group, if the member being
	 * added to $subgroup_slug isn't already a current member of the
	 * parent and the parent isn't the group already being added
	 * ($subgroup_slug itself). The parent's own numeric subgroup_id is
	 * resolved purely from the local index
	 * (MemberIndex::find_group_by_slug()) - no live Groups.io call
	 * happens here, consistent with queue_add()'s own "no synchronous
	 * Groups.io API call happens inside that request" design. If the
	 * parent has never been locally indexed yet (only possible before
	 * the first sync() run against a fresh install), the auto-add is
	 * silently skipped rather than making a live lookup call - the next
	 * scheduled sync() corrects this regardless.
	 *
	 * Guarded against infinite recursion by construction: this is only
	 * ever reached from queue_add(), and the $subgroup_slug === $parent_slug
	 * check below means the recursive queue_add() call this method makes
	 * (for the parent) always fails that same check on its own
	 * maybe_queue_parent_add() invocation, so it can never recurse a
	 * second level deep.
	 *
	 * @param int    $user_id       Matched WP user id of the member, or 0 if none.
	 * @param string $email         Member's email address.
	 * @param string $display_name  Member's display name, if known.
	 * @param string $subgroup_slug Full slug of the group/subgroup just queued for adding.
	 * @param int    $admin_user_id WP user id of the admin queuing the action.
	 * @return void
	 */
	private static function maybe_queue_parent_add( int $user_id, string $email, string $display_name, string $subgroup_slug, int $admin_user_id ): void {
		$parent_slug = self::parent_group();

		if ( '' === $parent_slug || $parent_slug === $subgroup_slug ) {
			return;
		}

		if ( MemberIndex::is_currently_in_group( $email, $parent_slug ) ) {
			return;
		}

		$parent = MemberIndex::find_group_by_slug( $parent_slug );
		if ( null === $parent ) {
			return;
		}

		self::queue_add(
			$user_id,
			$email,
			$display_name,
			$parent['subgroup_id'],
			$parent_slug,
			$parent['subgroup_title'],
			$admin_user_id
		);
	}

	/**
	 * Queues one remove job for a single (member, subgroup) pair. A bulk
	 * "Remove Selected" action calls this once per checked group, not
	 * once for the whole batch.
	 *
	 * @param string $email         Member's email address.
	 * @param int    $subgroup_id   Numeric Groups.io group/subgroup id to remove from.
	 * @param int    $admin_user_id WP user id of the admin queuing the action.
	 * @return int The scheduled action's id (0 if scheduling failed).
	 */
	public static function queue_remove( string $email, int $subgroup_id, int $admin_user_id ): int {
		return ActionSchedulerClient::schedule(
			array(
				'job_action'    => 'remove',
				'email'         => $email,
				'subgroup_id'   => $subgroup_id,
				'admin_user_id' => $admin_user_id,
				'attempt'       => 1,
			),
			time()
		);
	}

	/**
	 * The job callback itself. Bound to self::HOOK via register().
	 * Performs the actual direct_add()/remove_member() call; on a caught
	 * API/transport failure with attempts remaining, reschedules itself
	 * with the attempt counter incremented - otherwise records the
	 * outcome (audit log + admin notification) and stops.
	 *
	 * @param array<string, mixed> $job Job data - see queue_add()/queue_remove().
	 * @return void
	 */
	public static function execute( array $job ): void {
		$attempt = (int) ( $job['attempt'] ?? 1 );

		try {
			if ( 'add' === $job['job_action'] ) {
				self::execute_add( $job );
			} else {
				self::execute_remove( $job );
			}
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			if ( $attempt < self::MAX_ATTEMPTS ) {
				$job['attempt'] = $attempt + 1;
				ActionSchedulerClient::schedule( $job, time() + self::RETRY_DELAY_SECONDS );
				return;
			}

			self::record_outcome( $job, 'failure', $exception->getMessage() );
		}
	}

	/**
	 * Performs one add attempt and, on success, updates the index,
	 * writes the audit log, and raises a success notification.
	 *
	 * @param array<string, mixed> $job Job data.
	 * @return void
	 *
	 * @throws GroupsIoApiException On a confirmed Groups.io API error response.
	 * @throws GroupsIoTransportException On a network-level failure.
	 */
	private static function execute_add( array $job ): void {
		GroupsIoApiClient::direct_add(
			self::parent_group(),
			array( $job['email'] ),
			array( $job['subgroup_id'] )
		);

		MemberIndex::apply_add(
			(int) $job['user_id'],
			(string) $job['email'],
			(string) $job['display_name'],
			(int) $job['subgroup_id'],
			(string) $job['subgroup_slug'],
			(string) $job['subgroup_title'],
			(int) $job['admin_user_id']
		);

		self::record_outcome( $job, 'success' );
	}

	/**
	 * Performs one remove attempt and, on success, updates the index,
	 * writes the audit log, and raises a success notification. Resolves
	 * the target member_info_id from the local index rather than an
	 * extra live Groups.io lookup - if the index doesn't have one yet
	 * (row not backfilled by a sync() run), this attempt fails and
	 * retries per execute()'s normal retry path, since a later sync may
	 * fill it in before the next attempt.
	 *
	 * @param array<string, mixed> $job Job data.
	 * @return void
	 *
	 * @throws GroupsIoApiException On a confirmed Groups.io API error response, or a missing member_info_id. GroupsIoTransportException can also propagate from remove_member() on a network-level failure - phpcs's throw-tag sniff only checks tags against explicit "throw" statements in this method's own body, so that one isn't separately listed here.
	 */
	private static function execute_remove( array $job ): void {
		$email       = (string) $job['email'];
		$subgroup_id = (int) $job['subgroup_id'];

		$member_info_id = MemberIndex::get_member_info_id( $email, $subgroup_id );
		if ( null === $member_info_id ) {
			throw new GroupsIoApiException( 'missing_member_info_id', 'Local index has no member_info_id for this (email, subgroup) pair yet.' );
		}

		GroupsIoApiClient::remove_member( $member_info_id );

		MemberIndex::apply_remove( $email, $subgroup_id, (int) $job['admin_user_id'] );

		self::record_outcome( $job, 'success' );
	}

	/**
	 * Records one job outcome to the audit log and raises the matching
	 * admin notification, for either a success or failure outcome.
	 *
	 * @param array<string, mixed> $job                 Job data.
	 * @param string               $outcome             'success' or 'failure'.
	 * @param string               $api_response_detail Raw failure detail, if any.
	 * @return void
	 */
	private static function record_outcome( array $job, string $outcome, string $api_response_detail = '' ): void {
		$action = (string) $job['job_action'];

		AuditLog::record(
			$action,
			$outcome,
			(string) $job['email'],
			(string) $job['subgroup_id'],
			(int) $job['admin_user_id'],
			$api_response_detail
		);

		AdminNotifications::add( $outcome, self::notification_message( $job, $outcome ) );
	}

	/**
	 * Composes a notification message that distinguishes success/failure
	 * and the add/remove action in its text, not by notice type alone.
	 *
	 * @param array<string, mixed> $job     Job data.
	 * @param string               $outcome 'success' or 'failure'.
	 * @return string
	 */
	private static function notification_message( array $job, string $outcome ): string {
		$email      = (string) $job['email'];
		$is_add     = 'add' === $job['job_action'];
		$is_success = 'success' === $outcome;

		if ( $is_add ) {
			return $is_success
				/* translators: %s: member email address. */
				? sprintf( __( 'Added %s to the group.', 'bits-groupsio-sync' ), $email )
				/* translators: %s: member email address. */
				: sprintf( __( 'Failed to add %s to the group after 3 attempts.', 'bits-groupsio-sync' ), $email );
		}

		return $is_success
			/* translators: %s: member email address. */
			? sprintf( __( 'Removed %s from the group.', 'bits-groupsio-sync' ), $email )
			/* translators: %s: member email address. */
			: sprintf( __( 'Failed to remove %s from the group after 3 attempts.', 'bits-groupsio-sync' ), $email );
	}

	/**
	 * Backs the admin-facing "Sync" control on the GroupsIO Management
	 * pages: forces MemberIndex::sync() to run right now (it's normally
	 * only hourly-scheduled, per MemberIndex::maybe_schedule_sync()), then
	 * forces Action Scheduler to process any currently-due queued jobs
	 * immediately, rather than waiting on WP-Cron's own timing - this
	 * picks up not only pre-existing pending jobs but also any add jobs
	 * MemberIndex::sync()'s own parent-group reconciliation step (#100)
	 * just queued, in the same click. Direct, synchronous calls (not
	 * queued themselves) - the admin clicking "Sync" is explicitly asking
	 * to wait for this to happen now, the same tradeoff every other
	 * "Sync" click already makes.
	 *
	 * Found via manual testing (#99's follow-up): a subgroup created via
	 * Subgroup Management wasn't addable on User Assignment until the
	 * next hourly sync, and the "Sync" button didn't help, since it only
	 * ever processed already-due queued jobs - MemberIndex::sync() itself
	 * was never "due" outside its own hourly schedule.
	 *
	 * The Action Scheduler portion is a thin wrapper around Action
	 * Scheduler's own queue runner - the same call WP-Cron itself uses to
	 * process due actions - not a reimplementation of queue-draining
	 * logic. Not scoped to this plugin's own hook or to any particular
	 * member/page; it runs whatever Action Scheduler considers due,
	 * system-wide.
	 *
	 * @return int Number of queued actions processed (MemberIndex::sync()
	 *             itself isn't counted here - only jobs Action Scheduler
	 *             actually ran, which may include some sync() just queued).
	 */
	public static function process_due_jobs(): int {
		MemberIndex::sync();

		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return 0;
		}

		return \ActionScheduler_QueueRunner::instance()->run( 'BITS Groups.io Sync manual trigger' );
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
