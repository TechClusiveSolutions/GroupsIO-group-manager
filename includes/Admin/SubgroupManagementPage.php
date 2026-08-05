<?php
/**
 * GroupsIO Management > Subgroup Management admin page.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

use BITS\GroupsIOSync\GroupsIoApiClient;
use BITS\GroupsIOSync\GroupsIoApiException;
use BITS\GroupsIOSync\GroupsIoRateLimitException;
use BITS\GroupsIOSync\GroupsIoTransportException;
use BITS\GroupsIOSync\SubgroupIdCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three distinct views for the Subgroup Management surface, per
 * subgroup-crud-and-admin-pages-design.md section 9 (redesigned
 * 2026-08-05 for a lower-density, screen-reader-friendlier layout than
 * a single combined page): List (default), Create, and Details. State
 * transitions between views happen via a `view` query arg (and
 * `subgroup_id` for Details), never inline expansion within one page.
 *
 * POST handling is registered on this page's `load-{$hook_suffix}`
 * action (see GroupsIoManagementMenu::add_menu_pages()), not inside
 * render(). WordPress's admin.php already prints the admin header/nav
 * before a page's own render callback runs, so a wp_safe_redirect()
 * issued from inside render() always fails with "headers already
 * sent" on a real submission — load-{hook} fires early, before any
 * output, which is the standard WordPress hook for exactly this.
 */
final class SubgroupManagementPage {

	public const SLUG = 'bits-groupsio-subgroup-management';

	private const VIEW_CREATE  = 'create';
	private const VIEW_DETAILS = 'details';

	private const NONCE_ACTION_CREATE = 'bits_groupsio_create_subgroup';
	private const NONCE_ACTION_UPDATE = 'bits_groupsio_update_subgroup';
	private const NONCE_ACTION_DELETE = 'bits_groupsio_delete_subgroup';

	private const NOTICES = array(
		'created'              => array( 'success', 'Subgroup created.' ),
		'updated'              => array( 'success', 'Subgroup updated.' ),
		'deleted'              => array( 'success', 'Subgroup deleted.' ),
		'create_failed'        => array( 'error', 'Could not create the subgroup: %s' ),
		'created_title_failed' => array( 'error', 'The subgroup was created, but its title could not be set: %s Find it in the list below and set the title from its Details page.' ),
		'update_failed'        => array( 'error', 'Could not update the subgroup: %s' ),
		'delete_failed'        => array( 'error', 'Could not delete the subgroup: %s' ),
		'invalid_request'      => array( 'error', 'The request could not be processed. Please try again.' ),
		'not_found'            => array( 'error', 'That subgroup could not be found under the configured parent group. It may have already been renamed or deleted.' ),
	);

	/**
	 * Handles this page's own POST actions, if any, ending the request
	 * via redirect + exit. Registered on `load-{$hook_suffix}` by
	 * GroupsIoManagementMenu — fires before any admin HTML is output,
	 * unlike render(), which WordPress always calls after the admin
	 * header has already been printed.
	 *
	 * @return void
	 * @codeCoverageIgnore Dispatch-then-exit wrapper; process_*() below carries the tested logic.
	 */
	public static function maybe_handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' !== $request_method ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only reads the action name to dispatch; each process_*() method below verifies its own nonce via check_admin_referer() before touching any other POST data or taking action.
		$action = isset( $_POST['bits_groupsio_action'] ) ? sanitize_key( wp_unslash( $_POST['bits_groupsio_action'] ) ) : '';

		switch ( $action ) {
			case 'create':
				list( $code, $detail ) = self::process_create();
				self::redirect_with_notice( self::list_url(), $code, $detail );
				return;
			case 'update':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only reads subgroup_id to build the redirect target; process_update() re-reads and verifies it (and the nonce) itself before touching any data.
				$subgroup_id           = isset( $_POST['subgroup_id'] ) ? absint( $_POST['subgroup_id'] ) : 0;
				list( $code, $detail ) = self::process_update();
				self::redirect_with_notice( self::details_url( $subgroup_id ), $code, $detail );
				return;
			case 'delete':
				list( $code, $detail ) = self::process_delete();
				self::redirect_with_notice( self::list_url(), $code, $detail );
				return;
			default:
				self::redirect_with_notice( self::list_url(), 'invalid_request' );
		}
	}

	/**
	 * Renders the page. Callback for add_submenu_page(). Dispatches to
	 * one of the three views based on the `view` query arg.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.

		echo '<div class="wrap">';

		if ( self::VIEW_CREATE === $view ) {
			self::render_create_view();
		} elseif ( self::VIEW_DETAILS === $view ) {
			$subgroup_id = isset( $_GET['subgroup_id'] ) ? absint( $_GET['subgroup_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.
			self::render_details_view( $subgroup_id );
		} else {
			self::render_list_view();
		}

		echo '</div>';
	}

	/**
	 * The redirect-free half of "Create subgroup" handling: verifies the
	 * nonce, calls the API, then re-fetches the subgroup list as a
	 * read-back to confirm the new subgroup actually exists before
	 * reporting success, per #34's acceptance criteria. If a Title was
	 * provided, a follow-up update_subgroup() call sets it (createsubgroup
	 * has no title parameter — confirmed against the live docs), also
	 * read-back verified — but reported via a distinct 'created_title_failed'
	 * code rather than 'create_failed', since the subgroup itself was
	 * already successfully created and confirmed by that point; reporting
	 * it as a creation failure would invite a retry that collides with
	 * the subgroup that already exists. Kept separate from
	 * maybe_handle_post() (which redirects + exits) purely so this branch
	 * is unit testable without terminating the test process. Not part of
	 * this class's rendering API; only called by maybe_handle_post() and
	 * tests.
	 *
	 * @return array{0: string, 1: string} Notice code and optional detail.
	 */
	public static function process_create(): array {
		check_admin_referer( self::NONCE_ACTION_CREATE );

		$name        = isset( $_POST['sub_group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_group_name'] ) ) : '';
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( '' === $name ) {
			return array( 'invalid_request', '' );
		}

		try {
			GroupsIoApiClient::create_subgroup( self::parent_group(), $name, $description );
		} catch ( GroupsIoApiException $exception ) {
			return array( 'create_failed', self::friendly_error( $exception ) );
		} catch ( GroupsIoTransportException $exception ) {
			return array( 'create_failed', __( 'a connection problem occurred.', 'bits-groupsio-sync' ) );
		}

		$expected_slug = self::parent_group() . '+' . $name;

		// Defensive: clear any stale cache entry a previous, since-deleted
		// subgroup with this same slug may have left behind, before the
		// read-back below re-resolves it fresh.
		SubgroupIdCache::invalidate( $expected_slug );

		try {
			$created = self::fetch_subgroup_by_slug( $expected_slug );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return self::lookup_failure_result( 'create_failed', $exception );
		}
		if ( null === $created ) {
			return array( 'create_failed', __( 'the subgroup could not be confirmed after creation. This can happen if Groups.io hasn\'t finished propagating the change yet - try refreshing in a moment.', 'bits-groupsio-sync' ) );
		}

		if ( '' === $title ) {
			return array( 'created', '' );
		}

		try {
			GroupsIoApiClient::update_subgroup( (int) $created['id'], array( 'title' => $title ) );
		} catch ( GroupsIoApiException $exception ) {
			return array( 'created_title_failed', self::friendly_error( $exception ) );
		} catch ( GroupsIoTransportException $exception ) {
			return array( 'created_title_failed', __( 'a connection problem occurred while setting the title.', 'bits-groupsio-sync' ) );
		}

		try {
			$with_title = self::fetch_subgroup_by_id( (int) $created['id'] );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return self::lookup_failure_result( 'created_title_failed', $exception );
		}
		if ( null === $with_title || $with_title['title'] !== $title ) {
			return array( 'created_title_failed', __( 'the title could not be confirmed after creation. This can happen if Groups.io hasn\'t finished propagating the change yet - try refreshing in a moment.', 'bits-groupsio-sync' ) );
		}

		return array( 'created', '' );
	}

	/**
	 * The redirect-free half of "Update subgroup" handling. Re-fetches
	 * the parent group's subgroup listing first and requires an exact
	 * id+slug match before calling the API at all — the submitted
	 * subgroup_id/current_slug come from editable hidden form fields, so
	 * this confirms the target actually belongs to the configured
	 * parent rather than trusting client-supplied identifiers for a
	 * rename-capable action. Only the fields that actually changed are
	 * sent (a true partial update). Re-fetches again afterward as a
	 * read-back to confirm each changed field actually took effect, per
	 * #34's acceptance criteria. If the name changed, invalidates the
	 * SubgroupIdCache entry under *both* the old and new slug before the
	 * write — the new slug also needs clearing in case an unrelated,
	 * since-deleted subgroup previously used it and left a stale mapping
	 * behind. Does not touch any level's stored mandatory-groups list
	 * (Phase 1's LevelMandatoryGroups is a free-text field, not a live
	 * selector) — the limitation is surfaced as description text on the
	 * Name field itself, not here.
	 *
	 * @return array{0: string, 1: string} Notice code and optional detail.
	 */
	public static function process_update(): array {
		check_admin_referer( self::NONCE_ACTION_UPDATE );

		$subgroup_id  = isset( $_POST['subgroup_id'] ) ? absint( $_POST['subgroup_id'] ) : 0;
		$current_slug = isset( $_POST['current_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['current_slug'] ) ) : '';
		$new_name     = isset( $_POST['sub_group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_group_name'] ) ) : '';
		$new_title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$new_desc     = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( 0 === $subgroup_id || '' === $current_slug || '' === $new_name ) {
			return array( 'invalid_request', '' );
		}

		try {
			$existing = self::fetch_subgroup_by_id( $subgroup_id );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return self::lookup_failure_result( 'update_failed', $exception );
		}
		if ( null === $existing || $existing['name'] !== $current_slug ) {
			return array( 'not_found', '' );
		}

		$expected_slug = self::parent_group() . '+' . $new_name;

		$fields = array();
		if ( $expected_slug !== $current_slug ) {
			$fields['name'] = $expected_slug;
		}
		if ( $new_title !== (string) $existing['title'] ) {
			$fields['title'] = $new_title;
		}
		if ( $new_desc !== (string) $existing['desc'] ) {
			$fields['desc'] = $new_desc;
		}

		if ( empty( $fields ) ) {
			return array( 'updated', '' );
		}

		if ( isset( $fields['name'] ) ) {
			SubgroupIdCache::invalidate( $current_slug );
			SubgroupIdCache::invalidate( $expected_slug );
		}

		try {
			GroupsIoApiClient::update_subgroup( $subgroup_id, $fields );
		} catch ( GroupsIoApiException $exception ) {
			return array( 'update_failed', self::friendly_error( $exception ) );
		} catch ( GroupsIoTransportException $exception ) {
			return array( 'update_failed', __( 'a connection problem occurred.', 'bits-groupsio-sync' ) );
		}

		try {
			$after = self::fetch_subgroup_by_id( $subgroup_id );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return self::lookup_failure_result( 'update_failed', $exception );
		}
		if ( null === $after ) {
			return array( 'update_failed', __( 'the update could not be confirmed. This can happen if Groups.io hasn\'t finished propagating the change yet - try refreshing in a moment.', 'bits-groupsio-sync' ) );
		}
		foreach ( $fields as $key => $value ) {
			if ( ( $after[ $key ] ?? null ) !== $value ) {
				return array( 'update_failed', __( 'the update could not be confirmed. This can happen if Groups.io hasn\'t finished propagating the change yet - try refreshing in a moment.', 'bits-groupsio-sync' ) );
			}
		}

		return array( 'updated', '' );
	}

	/**
	 * The redirect-free half of "Delete subgroup" handling. See
	 * process_update() for why the id+slug pair is re-validated against
	 * a fresh listing before acting. If the pre-check finds the target
	 * already absent entirely (not merely a slug mismatch), this treats
	 * that as an already-achieved success rather than an error — a
	 * retried or duplicate delete request shouldn't fail just because an
	 * earlier attempt already succeeded, per this project's
	 * idempotent-tolerance requirement for Groups.io-touching operations
	 * (.github/instructions/security-sensitive.instructions.md). A slug
	 * *mismatch* against a still-present id is kept as a genuine
	 * 'not_found' result, since that indicates the id/slug pairing is
	 * stale or was tampered with, not that the delete already happened.
	 *
	 * @return array{0: string, 1: string} Notice code and optional detail.
	 */
	public static function process_delete(): array {
		check_admin_referer( self::NONCE_ACTION_DELETE );

		$subgroup_id = isset( $_POST['subgroup_id'] ) ? absint( $_POST['subgroup_id'] ) : 0;
		$slug        = isset( $_POST['current_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['current_slug'] ) ) : '';

		if ( 0 === $subgroup_id || '' === $slug ) {
			return array( 'invalid_request', '' );
		}

		try {
			$existing = self::fetch_subgroup_by_id( $subgroup_id );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return self::lookup_failure_result( 'delete_failed', $exception );
		}

		if ( null === $existing ) {
			SubgroupIdCache::invalidate( $slug );
			return array( 'deleted', '' );
		}

		if ( $existing['name'] !== $slug ) {
			return array( 'not_found', '' );
		}

		try {
			GroupsIoApiClient::remove_subgroup( $subgroup_id );
		} catch ( GroupsIoApiException $exception ) {
			return array( 'delete_failed', self::friendly_error( $exception ) );
		} catch ( GroupsIoTransportException $exception ) {
			return array( 'delete_failed', __( 'a connection problem occurred.', 'bits-groupsio-sync' ) );
		}

		SubgroupIdCache::invalidate( $slug );

		try {
			$after = self::fetch_subgroup_by_id( $subgroup_id );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return self::lookup_failure_result( 'delete_failed', $exception );
		}
		if ( null !== $after ) {
			return array( 'delete_failed', __( 'the deletion could not be confirmed. This can happen if Groups.io hasn\'t finished propagating the change yet - try refreshing in a moment.', 'bits-groupsio-sync' ) );
		}

		return array( 'deleted', '' );
	}

	/**
	 * Renders the List view: subgroup count, the parent group's own
	 * full address, a link to the Create view, and a plain list of
	 * subgroup links (one per subgroup, link text = that subgroup's
	 * full address) — each link's text doubles as its accessible name,
	 * so no per-row aria-label is needed to disambiguate rows, unlike
	 * the prior single-page design's identically-labelled per-row
	 * controls. The subgroup count is only shown when the listing
	 * actually loaded — showing "0 subgroups provisioned" during a load
	 * failure would misleadingly suggest every subgroup vanished.
	 *
	 * @return void
	 */
	private static function render_list_view(): void {
		echo '<h1>' . esc_html__( 'Subgroup Management', 'bits-groupsio-sync' ) . '</h1>';

		self::render_notice();

		try {
			$parent = GroupsIoApiClient::get_group( self::parent_group() );
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: parent group's full address. */
						__( 'Parent group: %s', 'bits-groupsio-sync' ),
						(string) ( $parent['email_address'] ?? self::parent_group() )
					)
				)
			);
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			// Non-fatal: the list below is the primary content of this view.
			echo '<p>' . esc_html__( 'Parent group: (could not be loaded)', 'bits-groupsio-sync' ) . '</p>';
		}

		$rows = null;

		try {
			$subgroups = GroupsIoApiClient::get_subgroups( self::parent_group() );
			$rows      = (array) ( $subgroups['data'] ?? array() );
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of subgroups. */
						_n( '%d subgroup provisioned.', '%d subgroups provisioned.', count( $rows ), 'bits-groupsio-sync' ),
						count( $rows )
					)
				)
			);
		} catch ( GroupsIoApiException $exception ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: friendly error detail. */
						__( 'Could not load subgroups: %s', 'bits-groupsio-sync' ),
						self::friendly_error( $exception )
					)
				)
			);
		} catch ( GroupsIoTransportException $exception ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Could not load subgroups: a connection problem occurred.', 'bits-groupsio-sync' )
			);
		}

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( self::create_url() ),
			esc_html__( 'Create new subgroup', 'bits-groupsio-sync' )
		);

		if ( null === $rows || empty( $rows ) ) {
			return;
		}

		echo '<ul>';
		foreach ( $rows as $subgroup ) {
			$id      = (int) ( $subgroup['id'] ?? 0 );
			$address = (string) ( $subgroup['email_address'] ?? '' );
			$count   = (int) ( $subgroup['subs_count'] ?? 0 );

			if ( 0 === $id || '' === $address ) {
				continue;
			}

			echo '<li>';
			printf(
				'<a href="%1$s">%2$s</a> — %3$s',
				esc_url( self::details_url( $id ) ),
				esc_html( $address ),
				esc_html(
					sprintf(
						/* translators: %d: member count. */
						_n( '%d member', '%d members', $count, 'bits-groupsio-sync' ),
						$count
					)
				)
			);
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Renders the Create view: Name/Title/Description fields and a
	 * Create button.
	 *
	 * @return void
	 */
	private static function render_create_view(): void {
		echo '<h1>' . esc_html__( 'Create Subgroup', 'bits-groupsio-sync' ) . '</h1>';

		self::render_notice();

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( self::list_url() ),
			esc_html__( 'Back to Subgroup Management', 'bits-groupsio-sync' )
		);

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_CREATE );
		echo '<input type="hidden" name="bits_groupsio_action" value="create" />';

		self::render_name_title_desc_fields( '', '', '', true );

		submit_button( __( 'Create', 'bits-groupsio-sync' ) );
		echo '</form>';
	}

	/**
	 * Renders the Details view: editable Name/Title/Description fields
	 * pre-filled with the subgroup's current values, its live member
	 * list, and Update/Delete controls (Delete reveals an inline
	 * confirmation on this same page rather than a separate
	 * confirmation view, per the low-density-screen goal). While the
	 * delete confirmation is showing, the Name field's autofocus is
	 * suppressed so the confirmation button's autofocus is the only one
	 * present on the page — per the HTML spec, only the first
	 * `autofocus` element in document order actually receives focus, so
	 * having both present at once silently defeated the confirmation
	 * button's autofocus every time.
	 *
	 * @param int $subgroup_id Numeric subgroup id.
	 * @return void
	 */
	private static function render_details_view( int $subgroup_id ): void {
		echo '<h1>' . esc_html__( 'Subgroup Details', 'bits-groupsio-sync' ) . '</h1>';

		self::render_notice();

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( self::list_url() ),
			esc_html__( 'Back to Subgroup Management', 'bits-groupsio-sync' )
		);

		$subgroup      = null;
		$lookup_failed = '';

		if ( 0 !== $subgroup_id ) {
			try {
				$subgroup = self::fetch_subgroup_by_id( $subgroup_id );
			} catch ( GroupsIoApiException $exception ) {
				$lookup_failed = self::friendly_error( $exception );
			} catch ( GroupsIoTransportException $exception ) {
				$lookup_failed = __( 'a connection problem occurred.', 'bits-groupsio-sync' );
			}
		}

		if ( '' !== $lookup_failed ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: friendly error detail. */
						__( 'Could not load this subgroup: %s', 'bits-groupsio-sync' ),
						$lookup_failed
					)
				)
			);
			return;
		}

		if ( null === $subgroup ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'That subgroup could not be found. It may have already been renamed or deleted.', 'bits-groupsio-sync' )
			);
			return;
		}

		$slug       = (string) $subgroup['name'];
		$confirming = self::is_confirming_delete();

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_UPDATE );
		echo '<input type="hidden" name="bits_groupsio_action" value="update" />';
		printf( '<input type="hidden" name="subgroup_id" value="%s" />', esc_attr( (string) $subgroup_id ) );
		printf( '<input type="hidden" name="current_slug" value="%s" />', esc_attr( $slug ) );

		self::render_name_title_desc_fields(
			self::subgroup_name_segment( $slug ),
			(string) $subgroup['title'],
			(string) $subgroup['desc'],
			! $confirming
		);

		submit_button( __( 'Update', 'bits-groupsio-sync' ) );
		echo '</form>';

		self::render_delete_section( $subgroup_id, $slug, $confirming );

		echo '<h2>' . esc_html__( 'Members', 'bits-groupsio-sync' ) . '</h2>';
		self::render_member_list( $subgroup_id );
	}

	/**
	 * Whether the delete confirmation should currently be showing.
	 *
	 * @return bool
	 */
	private static function is_confirming_delete(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation flag, no state change itself.
		return isset( $_GET['confirm_delete'] ) && '1' === $_GET['confirm_delete'];
	}

	/**
	 * Renders the Delete button and, when requested via the
	 * `confirm_delete` query arg, an inline confirmation step in place
	 * of the button.
	 *
	 * @param int    $subgroup_id Numeric subgroup id.
	 * @param string $slug        Current full slug.
	 * @param bool   $confirming  Whether the inline confirmation should render.
	 * @return void
	 */
	private static function render_delete_section( int $subgroup_id, string $slug, bool $confirming ): void {
		if ( ! $confirming ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( add_query_arg( 'confirm_delete', '1' ) ),
				esc_html__( 'Delete this subgroup', 'bits-groupsio-sync' )
			);
			return;
		}

		echo '<div class="notice notice-warning">';
		echo '<p>' . esc_html__( 'Are you sure you want to delete this subgroup? This cannot be undone.', 'bits-groupsio-sync' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_DELETE );
		echo '<input type="hidden" name="bits_groupsio_action" value="delete" />';
		printf( '<input type="hidden" name="subgroup_id" value="%s" />', esc_attr( (string) $subgroup_id ) );
		printf( '<input type="hidden" name="current_slug" value="%s" />', esc_attr( $slug ) );
		submit_button( __( 'Yes, delete this subgroup', 'bits-groupsio-sync' ), 'primary delete', 'submit', false, array( 'autofocus' => 'autofocus' ) );
		echo ' ';
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( remove_query_arg( 'confirm_delete' ) ),
			esc_html__( 'Cancel', 'bits-groupsio-sync' )
		);
		echo '</form></div>';
	}

	/**
	 * Renders the live member list for one subgroup, read fresh from
	 * get_members() every time rather than any cached value, per #34's
	 * acceptance criteria.
	 *
	 * @param int $subgroup_id Numeric subgroup id.
	 * @return void
	 */
	private static function render_member_list( int $subgroup_id ): void {
		try {
			$members = GroupsIoApiClient::get_members( $subgroup_id );
		} catch ( GroupsIoApiException $exception ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: friendly error detail. */
						__( 'Could not load members: %s', 'bits-groupsio-sync' ),
						self::friendly_error( $exception )
					)
				)
			);
			return;
		} catch ( GroupsIoTransportException $exception ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Could not load members: a connection problem occurred.', 'bits-groupsio-sync' )
			);
			return;
		}

		$data = (array) ( $members['data'] ?? array() );

		if ( empty( $data ) ) {
			echo '<p>' . esc_html__( 'No members.', 'bits-groupsio-sync' ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $data as $member ) {
			$email = (string) ( $member['email'] ?? '' );
			if ( '' === $email ) {
				continue;
			}
			echo '<li>' . esc_html( $email ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Renders the shared Name/Title/Description field markup used by
	 * both the Create and Details views.
	 *
	 * @param string $name           Current/initial name segment (without the parent prefix).
	 * @param string $title          Current/initial title.
	 * @param string $description    Current/initial description.
	 * @param bool   $autofocus_name Whether the Name field should carry autofocus (suppressed on the Details view while the delete confirmation is showing, so its autofocus isn't defeated by this field's).
	 * @return void
	 */
	private static function render_name_title_desc_fields( string $name, string $title, string $description, bool $autofocus_name ): void {
		echo '<table class="form-table"><tr>';
		printf(
			'<th scope="row"><label for="bits_groupsio_sub_group_name">%s</label></th>',
			esc_html__( 'Name', 'bits-groupsio-sync' )
		);
		printf(
			'<td><input type="text" id="bits_groupsio_sub_group_name" name="sub_group_name" value="%2$s" required aria-describedby="bits_groupsio_sub_group_name_description"%1$s /><p class="description" id="bits_groupsio_sub_group_name_description">%3$s</p></td>',
			esc_attr( $autofocus_name ? ' autofocus' : '' ),
			esc_attr( $name ),
			esc_html__( "Determines the subgroup's list address and URL. Must be unique under the parent group. If this is changed later, no membership level's mandatory-groups list referencing the previous address is updated automatically - those must be updated manually.", 'bits-groupsio-sync' )
		);
		echo '</tr><tr>';
		printf(
			'<th scope="row"><label for="bits_groupsio_title">%s</label></th>',
			esc_html__( 'Title (optional)', 'bits-groupsio-sync' )
		);
		printf(
			'<td><input type="text" id="bits_groupsio_title" name="title" value="%1$s" aria-describedby="bits_groupsio_title_description" /><p class="description" id="bits_groupsio_title_description">%2$s</p></td>',
			esc_attr( $title ),
			esc_html__( 'A cosmetic display label only - does not change the address or URL.', 'bits-groupsio-sync' )
		);
		echo '</tr><tr>';
		printf(
			'<th scope="row"><label for="bits_groupsio_description">%s</label></th>',
			esc_html__( 'Description (optional)', 'bits-groupsio-sync' )
		);
		printf(
			'<td><textarea id="bits_groupsio_description" name="description" rows="3" cols="40" aria-describedby="bits_groupsio_description_description">%1$s</textarea><p class="description" id="bits_groupsio_description_description">%2$s</p></td>',
			esc_textarea( $description ),
			esc_html__( 'Shown to members when browsing this subgroup on Groups.io.', 'bits-groupsio-sync' )
		);
		echo '</tr></table>';
	}

	/**
	 * Renders a fixed-vocabulary notice banner from the `bits_notice`
	 * (and optional `bits_notice_detail`) query args, if present.
	 *
	 * @return void
	 */
	private static function render_notice(): void {
		$code = isset( $_GET['bits_notice'] ) ? sanitize_key( wp_unslash( $_GET['bits_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a fixed-vocabulary code, no state change.

		if ( '' === $code || ! isset( self::NOTICES[ $code ] ) ) {
			return;
		}

		list( $type, $template ) = self::NOTICES[ $code ];

		$message = $template;
		if ( false !== strpos( $template, '%s' ) ) {
			$detail  = isset( $_GET['bits_notice_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['bits_notice_detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display, no state change.
			$message = sprintf( $template, '' !== $detail ? $detail : __( 'an unknown error occurred.', 'bits-groupsio-sync' ) );
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( 'success' === $type ? 'success' : 'error' ),
			esc_html( $message )
		);
	}

	/**
	 * Extracts the "sub" segment from a "parent+sub" slug, for
	 * pre-filling the Name field with the part an admin actually edits
	 * (not the parent prefix, which stays fixed).
	 *
	 * @param string $slug Full slug.
	 * @return string
	 */
	private static function subgroup_name_segment( string $slug ): string {
		$pos = strpos( $slug, '+' );

		return false === $pos ? $slug : substr( $slug, $pos + 1 );
	}

	/**
	 * Re-fetches the configured parent's subgroup listing and returns
	 * the row matching the given numeric id, or null if genuinely not
	 * present in that listing. Unlike an earlier version of this method,
	 * a transport/API failure is *not* swallowed into the same null
	 * return — it propagates, so callers can distinguish "confirmed not
	 * present" from "couldn't check" (a failed check must never be
	 * reported as a confirmed deletion, rename, or "not found").
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
	 * Same as fetch_subgroup_by_id(), but matches by full slug — used as
	 * the post-create read-back, where the new subgroup's numeric id
	 * isn't already known to the caller ahead of time.
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
	 * Converts a caught lookup-failure exception (from fetch_subgroup_by_id()/
	 * fetch_subgroup_by_slug()) into a notice code/detail pair, keeping
	 * every process_*() method's catch block for this case one line
	 * instead of duplicating the API-vs-transport branch everywhere.
	 *
	 * @param string                                          $code      Notice code to use (e.g. 'delete_failed').
	 * @param GroupsIoApiException|GroupsIoTransportException $exception Caught exception.
	 * @return array{0: string, 1: string}
	 */
	private static function lookup_failure_result( string $code, $exception ): array {
		if ( $exception instanceof GroupsIoApiException ) {
			return array( $code, self::friendly_error( $exception ) );
		}

		return array( $code, __( 'a connection problem occurred while verifying the subgroup.', 'bits-groupsio-sync' ) );
	}

	/**
	 * Formats a GroupsIoApiException as plain language, never a raw API
	 * error dump or machine-oriented error type/code, per #34's
	 * acceptance criteria. Uses the `extra` detail when present
	 * (Groups.io's own human-readable detail string, per
	 * Groups.io-API-Reference.md's documented error convention) since
	 * that's already written for a human reader; falls back to a
	 * generic message rather than exposing the raw `type` value.
	 *
	 * @param GroupsIoApiException $exception Caught exception.
	 * @return string
	 */
	private static function friendly_error( GroupsIoApiException $exception ): string {
		if ( $exception instanceof GroupsIoRateLimitException ) {
			return __( 'Groups.io is rate-limiting requests right now. Please try again shortly.', 'bits-groupsio-sync' );
		}

		$extra = $exception->get_extra();

		return '' !== $extra ? $extra : __( 'an unexpected error occurred.', 'bits-groupsio-sync' );
	}

	/**
	 * Redirects to the given URL with a fixed-vocabulary notice code
	 * and exits. Never called with anything but a code from
	 * self::NOTICES and, optionally, a plain-language detail string
	 * that is itself escaped on output by render_notice(), never
	 * trusted as markup. The detail is passed unencoded — add_query_arg()
	 * already URL-encodes every value it's given, so pre-encoding here
	 * would double-encode it.
	 *
	 * @param string $target_url Base URL to redirect to (list_url()/details_url()).
	 * @param string $code       One of the keys in self::NOTICES.
	 * @param string $detail     Optional detail to interpolate into the notice template.
	 * @return void
	 * @codeCoverageIgnore Calls exit; cannot run inside the test process. Its pure input-building logic is trivial (array literal + add_query_arg).
	 */
	private static function redirect_with_notice( string $target_url, string $code, string $detail = '' ): void {
		$args = array( 'bits_notice' => $code );

		if ( '' !== $detail ) {
			$args['bits_notice_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, $target_url ) );
		exit;
	}

	/**
	 * Builds the List view's URL.
	 *
	 * @return string
	 */
	private static function list_url(): string {
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Builds the Create view's URL.
	 *
	 * @return string
	 */
	private static function create_url(): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'view' => self::VIEW_CREATE,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Builds a subgroup's Details view URL.
	 *
	 * @param int $subgroup_id Numeric subgroup id.
	 * @return string
	 */
	private static function details_url( int $subgroup_id ): string {
		return add_query_arg(
			array(
				'page'        => self::SLUG,
				'view'        => self::VIEW_DETAILS,
				'subgroup_id' => $subgroup_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Returns the configured parent group name.
	 *
	 * @return string
	 */
	private static function parent_group(): string {
		return defined( 'GROUPS_IO_PARENT_GROUP' ) ? GROUPS_IO_PARENT_GROUP : '';
	}
}
