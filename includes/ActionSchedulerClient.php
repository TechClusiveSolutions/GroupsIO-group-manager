<?php
/**
 * The one seam wrapping Action Scheduler's as_*() calls directly.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Introduced for #112, per the design in
 * subgroup-crud-and-admin-pages-design.md section 8 ("Action Scheduler
 * status tracking"): QueuedExecutionEngine::schedule() previously
 * called as_schedule_single_action() inline with no equivalent
 * status-read seam existing anywhere. This class owns nothing but
 * "talk to Action Scheduler" - queuing/retry logic itself stays in
 * QueuedExecutionEngine, matching CLAUDE.md's one-class-one-purpose
 * rule.
 */
final class ActionSchedulerClient {

	/**
	 * Schedules a single Action Scheduler action.
	 *
	 * @param array<string, mixed> $job       Job payload, passed through to the hook callback unchanged.
	 * @param int                  $timestamp Unix timestamp to run the job at.
	 * @return int The scheduled action's id, or 0 if Action Scheduler is unavailable.
	 */
	public static function schedule( array $job, int $timestamp ): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}

		return (int) as_schedule_single_action( $timestamp, QueuedExecutionEngine::HOOK, array( $job ), '', false );
	}

	/**
	 * Reads an action's current status from Action Scheduler's own
	 * store.
	 *
	 * @param int $action_id Action id, as returned by schedule().
	 * @return string|null One of Action Scheduler's own status strings
	 *                      ('pending' / 'in-progress' / 'complete' /
	 *                      'failed' / 'canceled'), or null if the id is
	 *                      unknown or Action Scheduler isn't available.
	 */
	public static function get_status( int $action_id ): ?string {
		if ( 0 === $action_id || ! class_exists( 'ActionScheduler_Store' ) ) {
			return null;
		}

		try {
			return \ActionScheduler_Store::instance()->get_status( $action_id );
		} catch ( \Exception $exception ) {
			return null;
		}
	}

	/**
	 * Convenience wrapper over get_status(): whether the action has
	 * reached a terminal state.
	 *
	 * @param int $action_id Action id, as returned by schedule().
	 * @return bool True for 'complete'/'failed'/'canceled', false for
	 *              'pending'/'in-progress' or an unknown/unavailable id.
	 */
	public static function is_finished( int $action_id ): bool {
		$status = self::get_status( $action_id );

		if ( null === $status ) {
			return false;
		}

		// Action Scheduler's own status strings (ActionScheduler_Store::STATUS_*)
		// - not referenced as class constants since that class isn't loaded
		// at analysis time (bundled with the WordPress runtime, not this
		// plugin's own dependencies).
		return in_array( $status, array( 'complete', 'failed', 'canceled' ), true );
	}
}
