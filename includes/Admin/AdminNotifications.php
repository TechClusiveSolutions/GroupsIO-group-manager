<?php
/**
 * Persisted admin notification queue for queued-job outcomes.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress has no built-in persistent notification center - a
 * page-load-only admin_notices hook can't represent an outcome that
 * becomes known after the request that triggered it, since
 * QueuedExecutionEngine's jobs run asynchronously via Action Scheduler.
 * This class stores pending notification records in a single
 * autoload-false wp_options row, renders each as its own distinct,
 * individually dismissible admin notice, and removes a record once its
 * notice is dismissed (server-side, via AJAX - WordPress core's own
 * is-dismissible notices only hide the DOM node client-side and don't
 * persist dismissal).
 *
 * Single responsibility: this class only owns the notification queue's
 * storage and rendering - it does not itself decide when a notification
 * is warranted, which is QueuedExecutionEngine's job.
 */
final class AdminNotifications {

	private const OPTION_NAME  = 'bits_groupsio_admin_notifications';
	private const AJAX_ACTION  = 'bits_groupsio_dismiss_notification';
	private const NONCE_ACTION = 'bits_groupsio_dismiss_notification';

	/**
	 * Registers the rendering and dismiss-AJAX hooks. Called once from
	 * Plugin's constructor (on plugins_loaded).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle_dismiss' ) );
	}

	/**
	 * Queues one notification. Called by QueuedExecutionEngine on every
	 * job outcome - both success and failure, per explicit direction.
	 *
	 * @param string $type    'success' or 'failure'.
	 * @param string $message Notice text - already fully composed, human-readable, and distinguishing the outcome in the text itself (not by type/color alone).
	 * @return void
	 */
	public static function add( string $type, string $message ): void {
		$notifications   = self::get_all();
		$notifications[] = array(
			'id'      => wp_generate_uuid4(),
			'type'    => $type,
			'message' => $message,
		);

		update_option( self::OPTION_NAME, $notifications, false );
	}

	/**
	 * Renders every pending notification as its own distinct,
	 * individually dismissible admin notice. Bound to admin_notices.
	 *
	 * @return void
	 */
	public static function render(): void {
		$notifications = self::get_all();
		if ( empty( $notifications ) ) {
			return;
		}

		$nonce = wp_create_nonce( self::NONCE_ACTION );

		foreach ( $notifications as $notification ) {
			$css_class = 'failure' === $notification['type'] ? 'notice-error' : 'notice-success';

			printf(
				'<div class="notice %1$s is-dismissible bits-groupsio-notification" data-notification-id="%2$s" data-nonce="%3$s"><p>%4$s</p></div>',
				esc_attr( $css_class ),
				esc_attr( $notification['id'] ),
				esc_attr( $nonce ),
				esc_html( $notification['message'] )
			);
		}

		// WordPress core's own is-dismissible JS (common.js) only hides
		// the notice's DOM node on click - it doesn't know about, or
		// call, this plugin's dismiss-AJAX endpoint. This inline script
		// listens for that same dismiss click and additionally tells the
		// server to remove the underlying record, so it doesn't
		// reappear on the next page load.
		$ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
		$action   = esc_js( self::AJAX_ACTION );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $action/$ajax_url are pre-escaped above via esc_js()/esc_url(); the rest is static plugin markup.
		echo "<script>
document.addEventListener( 'click', function ( event ) {
	var notice = event.target.closest( '.bits-groupsio-notification' );
	if ( ! notice || ! event.target.classList.contains( 'notice-dismiss' ) ) {
		return;
	}

	var body = new URLSearchParams();
	body.append( 'action', '{$action}' );
	body.append( 'id', notice.getAttribute( 'data-notification-id' ) );
	body.append( 'nonce', notice.getAttribute( 'data-nonce' ) );

	fetch( '{$ajax_url}', { method: 'POST', credentials: 'same-origin', body: body } );
} );
</script>";
	}

	/**
	 * AJAX handler that removes one notification record once its notice
	 * has been dismissed client-side. Bound to
	 * wp_ajax_bits_groupsio_dismiss_notification.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() above.
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( null, 400 );
		}

		$notifications = array_values(
			array_filter(
				self::get_all(),
				static function ( array $notification ) use ( $id ): bool {
					return $notification['id'] !== $id;
				}
			)
		);

		update_option( self::OPTION_NAME, $notifications, false );

		wp_send_json_success();
	}

	/**
	 * Reads the full pending-notification list.
	 *
	 * @return array<int, array{id: string, type: string, message: string}>
	 */
	private static function get_all(): array {
		$notifications = get_option( self::OPTION_NAME, array() );

		return is_array( $notifications ) ? $notifications : array();
	}
}
