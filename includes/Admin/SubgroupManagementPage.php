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
		'created'         => array( 'success', 'Subgroup created.' ),
		'updated'         => array( 'success', 'Subgroup updated.' ),
		'deleted'         => array( 'success', 'Subgroup deleted.' ),
		'create_failed'   => array( 'error', 'Could not create the subgroup: %s' ),
		'update_failed'   => array( 'error', 'Could not update the subgroup: %s' ),
		'delete_failed'   => array( 'error', 'Could not delete the subgroup: %s' ),
		'invalid_request' => array( 'error', 'The request could not be processed. Please try again.' ),
		'not_found'       => array( 'error', 'That subgroup could not be found under the configured parent group. It may have already been renamed or deleted.' ),
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
	 * read-back verified. Kept separate from maybe_handle_post() (which
	 * redirects + exits) purely so this branch is unit testable without
	 * terminating the test process. Not part of this class's rendering
	 * API; only called by maybe_handle_post() and tests.
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

		$created = self::find_subgroup_by_slug( $expected_slug );
		if ( null === $created ) {
			return array( 'create_failed', __( 'the subgroup could not be confirmed after creation.', 'bits-groupsio-sync' ) );
		}

		if ( '' !== $title ) {
			try {
				GroupsIoApiClient::update_subgroup( (int) $created['id'], array( 'title' => $title ) );
			} catch ( GroupsIoApiException $exception ) {
				return array( 'create_failed', self::friendly_error( $exception ) );
			} catch ( GroupsIoTransportException $exception ) {
				return array( 'create_failed', __( 'a connection problem occurred while setting the title.', 'bits-groupsio-sync' ) );
			}

			$with_title = self::find_subgroup_by_id( (int) $created['id'] );
			if ( null === $with_title || $with_title['title'] !== $title ) {
				return array( 'create_failed', __( 'the title could not be confirmed after creation.', 'bits-groupsio-sync' ) );
			}
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
	 * #34's acceptance criteria. Invalidates the SubgroupIdCache entry
	 * under the *old* slug if the name changed. Does not touch any
	 * level's stored mandatory-groups list (Phase 1's
	 * LevelMandatoryGroups is a free-text field, not a live selector) —
	 * the limitation is surfaced as description text in the Details
	 * view itself, not here.
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

		$existing = self::find_subgroup_by_id( $subgroup_id );
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

		try {
			GroupsIoApiClient::update_subgroup( $subgroup_id, $fields );
		} catch ( GroupsIoApiException $exception ) {
			return array( 'update_failed', self::friendly_error( $exception ) );
		} catch ( GroupsIoTransportException $exception ) {
			return array( 'update_failed', __( 'a connection problem occurred.', 'bits-groupsio-sync' ) );
		}

		if ( isset( $fields['name'] ) ) {
			SubgroupIdCache::invalidate( $current_slug );
		}

		$after = self::find_subgroup_by_id( $subgroup_id );
		if ( null === $after ) {
			return array( 'update_failed', __( 'the update could not be confirmed.', 'bits-groupsio-sync' ) );
		}
		foreach ( $fields as $key => $value ) {
			if ( ( $after[ $key ] ?? null ) !== $value ) {
				return array( 'update_failed', __( 'the update could not be confirmed.', 'bits-groupsio-sync' ) );
			}
		}

		return array( 'updated', '' );
	}

	/**
	 * The redirect-free half of "Delete subgroup" handling. See
	 * process_update() for why the id+slug pair is re-validated against
	 * a fresh listing before acting, and why a post-action read-back
	 * confirms the outcome rather than trusting the write call's own
	 * response.
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

		$existing = self::find_subgroup_by_id( $subgroup_id );
		if ( null === $existing || $existing['name'] !== $slug ) {
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

		if ( null !== self::find_subgroup_by_id( $subgroup_id ) ) {
			return array( 'delete_failed', __( 'the deletion could not be confirmed.', 'bits-groupsio-sync' ) );
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
	 * controls.
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

		try {
			$subgroups = GroupsIoApiClient::get_subgroups( self::parent_group() );
			$rows      = (array) ( $subgroups['data'] ?? array() );
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
			$rows = array();
		} catch ( GroupsIoTransportException $exception ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Could not load subgroups: a connection problem occurred.', 'bits-groupsio-sync' )
			);
			$rows = array();
		}

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

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( self::create_url() ),
			esc_html__( 'Create new subgroup', 'bits-groupsio-sync' )
		);

		if ( empty( $rows ) ) {
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
	 * confirmation view, per the low-density-screen goal).
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

		$subgroup = 0 === $subgroup_id ? null : self::find_subgroup_by_id( $subgroup_id );

		if ( null === $subgroup ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'That subgroup could not be found. It may have already been renamed or deleted.', 'bits-groupsio-sync' )
			);
			return;
		}

		$slug = (string) $subgroup['name'];

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_UPDATE );
		echo '<input type="hidden" name="bits_groupsio_action" value="update" />';
		printf( '<input type="hidden" name="subgroup_id" value="%s" />', esc_attr( (string) $subgroup_id ) );
		printf( '<input type="hidden" name="current_slug" value="%s" />', esc_attr( $slug ) );

		self::render_name_title_desc_fields(
			self::subgroup_name_segment( $slug ),
			(string) $subgroup['title'],
			(string) $subgroup['desc'],
			true
		);

		submit_button( __( 'Update', 'bits-groupsio-sync' ) );
		echo '</form>';

		self::render_delete_section( $subgroup_id, $slug );

		echo '<h2>' . esc_html__( 'Members', 'bits-groupsio-sync' ) . '</h2>';
		self::render_member_list( $subgroup_id );
	}

	/**
	 * Renders the Delete button and, when requested via the
	 * `confirm_delete` query arg, an inline confirmation step in place
	 * of the button.
	 *
	 * @param int    $subgroup_id Numeric subgroup id.
	 * @param string $slug        Current full slug.
	 * @return void
	 */
	private static function render_delete_section( int $subgroup_id, string $slug ): void {
		$confirming = isset( $_GET['confirm_delete'] ) && '1' === $_GET['confirm_delete']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation flag, no state change itself.

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
	 * @param string $name        Current/initial name segment (without the parent prefix).
	 * @param string $title       Current/initial title.
	 * @param string $description Current/initial description.
	 * @param bool   $autofocus_name Whether the Name field should carry autofocus (it is always this view's first/primary field).
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
			esc_html__( "Determines the subgroup's list address and URL. Must be unique under the parent group.", 'bits-groupsio-sync' )
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
	 * the row matching the given numeric id, or null if not found (or
	 * if the listing itself fails to load). Used both to validate that
	 * a client-supplied subgroup_id actually belongs to the configured
	 * parent before update/delete act on it, and as the read-back that
	 * confirms create/update/delete actually took effect on Groups.io.
	 *
	 * @param int $subgroup_id Numeric subgroup id to look up.
	 * @return array<string, mixed>|null
	 */
	private static function find_subgroup_by_id( int $subgroup_id ): ?array {
		try {
			$subgroups = GroupsIoApiClient::get_subgroups( self::parent_group() );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return null;
		}

		foreach ( (array) ( $subgroups['data'] ?? array() ) as $subgroup ) {
			if ( (int) ( $subgroup['id'] ?? 0 ) === $subgroup_id ) {
				return $subgroup;
			}
		}

		return null;
	}

	/**
	 * Same as find_subgroup_by_id(), but matches by full slug — used as
	 * the post-create read-back, where the new subgroup's numeric id
	 * isn't already known to the caller ahead of time.
	 *
	 * @param string $slug Full slug ("parent+sub" form) to look up.
	 * @return array<string, mixed>|null
	 */
	private static function find_subgroup_by_slug( string $slug ): ?array {
		try {
			$subgroups = GroupsIoApiClient::get_subgroups( self::parent_group() );
		} catch ( GroupsIoApiException | GroupsIoTransportException $exception ) {
			return null;
		}

		foreach ( (array) ( $subgroups['data'] ?? array() ) as $subgroup ) {
			if ( ( $subgroup['name'] ?? null ) === $slug ) {
				return $subgroup;
			}
		}

		return null;
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
