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
 * Full create/list/rename/delete lifecycle for Groups.io subgroups
 * under the configured parent group, per
 * subgroup-crud-and-admin-pages-design.md section 9. A plain
 * POST-per-action page (no JS/AJAX), consistent with the rest of this
 * admin surface (Settings/FeatureControlsPage) — state-changing actions
 * redirect afterward (POST-redirect-GET) to avoid resubmission on
 * refresh, with the outcome passed as a fixed-vocabulary notice code in
 * the query string rather than free text, so nothing user-influenced
 * ends up unescaped in a redirect target.
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

	private const NONCE_ACTION_CREATE = 'bits_groupsio_create_subgroup';
	private const NONCE_ACTION_RENAME = 'bits_groupsio_rename_subgroup';
	private const NONCE_ACTION_DELETE = 'bits_groupsio_delete_subgroup';

	private const NOTICES = array(
		'created'         => array( 'success', 'Subgroup created.' ),
		'renamed'         => array( 'success', 'Subgroup renamed.' ),
		'deleted'         => array( 'success', 'Subgroup deleted.' ),
		'create_failed'   => array( 'error', 'Could not create the subgroup: %s' ),
		'rename_failed'   => array( 'error', 'Could not rename the subgroup: %s' ),
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
				break;
			case 'rename':
				list( $code, $detail ) = self::process_rename();
				break;
			case 'delete':
				list( $code, $detail ) = self::process_delete();
				break;
			default:
				list( $code, $detail ) = array( 'invalid_request', '' );
		}

		self::redirect_with_notice( $code, $detail );
	}

	/**
	 * Renders the page. Callback for add_submenu_page().
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::render_page();
	}

	/**
	 * The redirect-free half of "Create subgroup" handling: verifies the
	 * nonce, calls the API, then re-fetches the subgroup list as a
	 * read-back to confirm the new subgroup actually exists before
	 * reporting success, per #34's acceptance criteria. Kept separate
	 * from maybe_handle_post() (which redirects + exits) purely so this
	 * branch is unit testable without terminating the test process. Not
	 * part of this class's rendering API; only called by
	 * maybe_handle_post() and tests.
	 *
	 * @return array{0: string, 1: string} Notice code and optional detail.
	 */
	public static function process_create(): array {
		check_admin_referer( self::NONCE_ACTION_CREATE );

		$name        = isset( $_POST['sub_group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_group_name'] ) ) : '';
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

		if ( null === self::find_subgroup_by_slug( $expected_slug ) ) {
			return array( 'create_failed', __( 'the subgroup could not be confirmed after creation.', 'bits-groupsio-sync' ) );
		}

		return array( 'created', '' );
	}

	/**
	 * The redirect-free half of "Rename subgroup" handling. Re-fetches
	 * the parent group's subgroup listing first and requires an exact
	 * id+slug match before calling the API at all — the submitted
	 * subgroup_id/old_slug come from editable hidden form fields, so
	 * this confirms the target actually belongs to the configured
	 * parent rather than trusting client-supplied identifiers for a
	 * destructive-adjacent action. Re-fetches again afterward as a
	 * read-back to confirm the rename actually took effect, per #34's
	 * acceptance criteria. Invalidates the SubgroupIdCache entry under
	 * the *old* slug on success — a later lookup under the new slug
	 * resolves fresh from get_subgroups() on its next miss. Does not
	 * touch any level's stored mandatory-groups list (Phase 1's
	 * LevelMandatoryGroups is a free-text field, not a live selector) —
	 * the limitation is surfaced as description text in the rename form
	 * itself, not here.
	 *
	 * @return array{0: string, 1: string} Notice code and optional detail.
	 */
	public static function process_rename(): array {
		check_admin_referer( self::NONCE_ACTION_RENAME );

		$subgroup_id = isset( $_POST['subgroup_id'] ) ? absint( $_POST['subgroup_id'] ) : 0;
		$old_slug    = isset( $_POST['old_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['old_slug'] ) ) : '';
		$new_name    = isset( $_POST['new_subgroup_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_subgroup_name'] ) ) : '';

		if ( 0 === $subgroup_id || '' === $old_slug || '' === $new_name ) {
			return array( 'invalid_request', '' );
		}

		$existing = self::find_subgroup_by_id( $subgroup_id );
		if ( null === $existing || $existing['name'] !== $old_slug ) {
			return array( 'not_found', '' );
		}

		try {
			GroupsIoApiClient::update_subgroup( $subgroup_id, self::parent_group(), $new_name );
		} catch ( GroupsIoApiException $exception ) {
			return array( 'rename_failed', self::friendly_error( $exception ) );
		} catch ( GroupsIoTransportException $exception ) {
			return array( 'rename_failed', __( 'a connection problem occurred.', 'bits-groupsio-sync' ) );
		}

		SubgroupIdCache::invalidate( $old_slug );

		$expected_slug = self::parent_group() . '+' . $new_name;
		$after         = self::find_subgroup_by_id( $subgroup_id );
		if ( null === $after || $after['name'] !== $expected_slug ) {
			return array( 'rename_failed', __( 'the rename could not be confirmed.', 'bits-groupsio-sync' ) );
		}

		return array( 'renamed', '' );
	}

	/**
	 * The redirect-free half of "Delete subgroup" handling. See
	 * process_rename() for why the id+slug pair is re-validated against
	 * a fresh listing before acting, and why a post-action read-back
	 * confirms the outcome rather than trusting the write call's own
	 * response.
	 *
	 * @return array{0: string, 1: string} Notice code and optional detail.
	 */
	public static function process_delete(): array {
		check_admin_referer( self::NONCE_ACTION_DELETE );

		$subgroup_id = isset( $_POST['subgroup_id'] ) ? absint( $_POST['subgroup_id'] ) : 0;
		$slug        = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

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
	 * Renders the full page: notice banner, create form, subgroup list,
	 * and (depending on GET params) a member-list expansion or a
	 * delete-confirmation step. The create form always renders even if
	 * the subgroup list fails to load — a transient Groups.io outage
	 * shouldn't also block the one action (create) that doesn't depend
	 * on already having a list.
	 *
	 * @return void
	 */
	private static function render_page(): void {
		$view_members_id = isset( $_GET['view_members'] ) ? absint( $_GET['view_members'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.
		$confirm_delete  = isset( $_GET['confirm_delete'] ) ? absint( $_GET['confirm_delete'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation into a nonce-protected confirmation form, no state change itself.

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Subgroup Management', 'bits-groupsio-sync' ) . '</h1>';

		self::render_notice();

		$rows       = array();
		$load_error = '';

		try {
			$subgroups = GroupsIoApiClient::get_subgroups( self::parent_group() );
			$rows      = (array) ( $subgroups['data'] ?? array() );
		} catch ( GroupsIoApiException $exception ) {
			$load_error = self::friendly_error( $exception );
		} catch ( GroupsIoTransportException $exception ) {
			$load_error = __( 'a connection problem occurred.', 'bits-groupsio-sync' );
		}

		if ( $confirm_delete > 0 ) {
			self::render_delete_confirmation( $confirm_delete, $rows );
		}

		self::render_create_form( 0 === $confirm_delete );

		if ( '' !== $load_error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: friendly error detail. */
						__( 'Could not load subgroups: %s', 'bits-groupsio-sync' ),
						$load_error
					)
				)
			);
		} else {
			self::render_subgroup_list( $rows, $view_members_id );
		}

		echo '</div>';
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
	 * Renders the "Create subgroup" form.
	 *
	 * @param bool $autofocus_first_field Whether this form's first field should carry autofocus (only when it is this view's primary action).
	 * @return void
	 */
	private static function render_create_form( bool $autofocus_first_field ): void {
		echo '<h2>' . esc_html__( 'Create Subgroup', 'bits-groupsio-sync' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_CREATE );
		echo '<input type="hidden" name="bits_groupsio_action" value="create" />';

		echo '<table class="form-table"><tr>';
		printf(
			'<th scope="row"><label for="bits_groupsio_sub_group_name">%s</label></th>',
			esc_html__( 'Subgroup name', 'bits-groupsio-sync' )
		);
		printf(
			'<td><input type="text" id="bits_groupsio_sub_group_name" name="sub_group_name" required aria-describedby="bits_groupsio_sub_group_name_description"%s /><p class="description" id="bits_groupsio_sub_group_name_description">%s</p></td>',
			esc_attr( $autofocus_first_field ? ' autofocus' : '' ),
			esc_html__( "Becomes part of the subgroup's list address and URL. Must be unique under the parent group.", 'bits-groupsio-sync' )
		);
		echo '</tr><tr>';
		printf(
			'<th scope="row"><label for="bits_groupsio_description">%s</label></th>',
			esc_html__( 'Description (optional)', 'bits-groupsio-sync' )
		);
		printf(
			'<td><textarea id="bits_groupsio_description" name="description" rows="3" cols="40" aria-describedby="bits_groupsio_description_description"></textarea><p class="description" id="bits_groupsio_description_description">%s</p></td>',
			esc_html__( 'Shown to members when browsing this subgroup on Groups.io.', 'bits-groupsio-sync' )
		);
		echo '</tr></table>';

		submit_button( __( 'Create Subgroup', 'bits-groupsio-sync' ) );
		echo '</form>';
	}

	/**
	 * Renders the subgroup list, with a per-row rename form, delete
	 * link, and view-members expansion. Every per-row control's
	 * accessible name includes the subgroup's slug (via aria-label),
	 * since visible text like "Rename" or "Delete subgroup" is
	 * identical across every row and would otherwise be ambiguous to a
	 * screen reader user navigating by links/buttons rather than
	 * reading surrounding row context.
	 *
	 * @param array<int, array<string, mixed>> $rows            Subgroup objects from get_subgroups().
	 * @param int                              $view_members_id Numeric subgroup id currently expanded, or 0.
	 * @return void
	 */
	private static function render_subgroup_list( array $rows, int $view_members_id ): void {
		echo '<h2>' . esc_html__( 'Existing Subgroups', 'bits-groupsio-sync' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No subgroups exist yet.', 'bits-groupsio-sync' ) . '</p>';
			return;
		}

		foreach ( $rows as $subgroup ) {
			$id    = (int) ( $subgroup['id'] ?? 0 );
			$slug  = (string) ( $subgroup['name'] ?? '' );
			$count = (int) ( $subgroup['subs_count'] ?? 0 );

			if ( 0 === $id || '' === $slug ) {
				continue;
			}

			echo '<div class="bits-groupsio-subgroup-row" style="margin-bottom:1.5em;padding-bottom:1em;border-bottom:1px solid #ccd0d4;">';
			printf(
				'<h3>%1$s <span style="font-weight:normal;">(%2$s)</span></h3>',
				esc_html( $slug ),
				esc_html(
					sprintf(
						/* translators: %d: member count. */
						_n( '%d member', '%d members', $count, 'bits-groupsio-sync' ),
						$count
					)
				)
			);

			$expanded = $view_members_id === $id;
			$view_url = $expanded
				? remove_query_arg( 'view_members' )
				: add_query_arg(
					array(
						'page'         => self::SLUG,
						'view_members' => $id,
					),
					admin_url( 'admin.php' )
				);
			printf(
				'<p><a href="%1$s" aria-label="%2$s">%3$s</a></p>',
				esc_url( $view_url ),
				esc_attr(
					$expanded
						/* translators: %s: subgroup slug. */
						? sprintf( __( 'Hide members for %s', 'bits-groupsio-sync' ), $slug )
						/* translators: %s: subgroup slug. */
						: sprintf( __( 'View members for %s', 'bits-groupsio-sync' ), $slug )
				),
				$expanded
					? esc_html__( 'Hide members', 'bits-groupsio-sync' )
					: esc_html__( 'View members', 'bits-groupsio-sync' )
			);

			if ( $expanded ) {
				self::render_member_list( $id );
			}

			self::render_rename_form( $id, $slug );

			$delete_url = add_query_arg(
				array(
					'page'           => self::SLUG,
					'confirm_delete' => $id,
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<p><a href="%1$s" class="button" aria-label="%2$s">%3$s</a></p>',
				esc_url( $delete_url ),
				esc_attr(
					sprintf(
						/* translators: %s: subgroup slug. */
						__( 'Delete subgroup %s', 'bits-groupsio-sync' ),
						$slug
					)
				),
				esc_html__( 'Delete subgroup', 'bits-groupsio-sync' )
			);

			echo '</div>';
		}
	}

	/**
	 * Renders the live member list for one subgroup (the "view members"
	 * expansion), read fresh from get_members() every time rather than
	 * any cached value, per #34's acceptance criteria.
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
	 * Renders one subgroup's inline "Rename" form.
	 *
	 * @param int    $subgroup_id Numeric subgroup id.
	 * @param string $slug        Current full slug ("parent+sub" form).
	 * @return void
	 */
	private static function render_rename_form( int $subgroup_id, string $slug ): void {
		$field_id = 'bits_groupsio_rename_' . $subgroup_id;
		$desc_id  = $field_id . '_description';
		$current  = self::subgroup_name_segment( $slug );

		echo '<form method="post" style="margin-bottom:0.5em;">';
		wp_nonce_field( self::NONCE_ACTION_RENAME );
		echo '<input type="hidden" name="bits_groupsio_action" value="rename" />';
		printf( '<input type="hidden" name="subgroup_id" value="%s" />', esc_attr( (string) $subgroup_id ) );
		printf( '<input type="hidden" name="old_slug" value="%s" />', esc_attr( $slug ) );

		printf(
			'<label for="%1$s">%2$s</label> ',
			esc_attr( $field_id ),
			esc_html__( 'New subgroup name:', 'bits-groupsio-sync' )
		);
		printf(
			'<input type="text" id="%1$s" name="new_subgroup_name" value="%2$s" aria-describedby="%3$s" /> ',
			esc_attr( $field_id ),
			esc_attr( $current ),
			esc_attr( $desc_id )
		);
		submit_button(
			__( 'Rename', 'bits-groupsio-sync' ),
			'secondary',
			'submit',
			false,
			array(
				'aria-label' => sprintf(
					/* translators: %s: subgroup slug. */
					__( 'Rename %s', 'bits-groupsio-sync' ),
					$slug
				),
			)
		);
		printf(
			'<p class="description" id="%1$s">%2$s</p>',
			esc_attr( $desc_id ),
			esc_html__( "Changes the subgroup's list address, URL, and subject tag. Does not update any membership level's mandatory-groups list that references the old name - update those manually.", 'bits-groupsio-sync' )
		);
		echo '</form>';
	}

	/**
	 * Renders the nonce-protected delete confirmation step for one
	 * subgroup, in place of the create form (the confirmation becomes
	 * this view's primary action). Renders an error, with no delete
	 * form, if the requested id doesn't match a subgroup in the
	 * (possibly failed-to-load) $rows listing — a confirmation for an
	 * unidentified target is not safe to present, per the same
	 * id/slug-verification requirement process_delete() enforces
	 * server-side.
	 *
	 * @param int                              $subgroup_id Numeric subgroup id to confirm deletion of.
	 * @param array<int, array<string, mixed>> $rows        Subgroup objects from get_subgroups(), to look up the slug for display.
	 * @return void
	 */
	private static function render_delete_confirmation( int $subgroup_id, array $rows ): void {
		$slug = '';
		foreach ( $rows as $subgroup ) {
			if ( (int) ( $subgroup['id'] ?? 0 ) === $subgroup_id ) {
				$slug = (string) ( $subgroup['name'] ?? '' );
				break;
			}
		}

		if ( '' === $slug ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'That subgroup could not be found. It may have already been renamed or deleted, or the subgroup list failed to load.', 'bits-groupsio-sync' )
			);
			return;
		}

		echo '<div class="notice notice-warning">';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: subgroup slug. */
					__( 'Are you sure you want to delete the subgroup "%s"? This cannot be undone.', 'bits-groupsio-sync' ),
					$slug
				)
			)
		);

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_DELETE );
		echo '<input type="hidden" name="bits_groupsio_action" value="delete" />';
		printf( '<input type="hidden" name="subgroup_id" value="%s" />', esc_attr( (string) $subgroup_id ) );
		printf( '<input type="hidden" name="slug" value="%s" />', esc_attr( $slug ) );
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
	 * Extracts the "sub" segment from a "parent+sub" slug, for
	 * pre-filling the rename field with the part an admin actually
	 * edits (not the parent prefix, which stays fixed).
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
	 * parent before rename/delete act on it, and as the read-back that
	 * confirms create/rename/delete actually took effect on Groups.io.
	 *
	 * @param int $subgroup_id Numeric subgroup id to look up.
	 * @return array<string, mixed>|null
	 */
	private static function find_subgroup_by_id( int $subgroup_id ): ?array {
		try {
			$subgroups = GroupsIoApiClient::get_subgroups( self::parent_group() );
		} catch ( GroupsIoApiException $exception ) {
			return null;
		} catch ( GroupsIoTransportException $exception ) {
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
		} catch ( GroupsIoApiException $exception ) {
			return null;
		} catch ( GroupsIoTransportException $exception ) {
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
	 * generic message rather than exposing the raw `type` value (e.g.
	 * `group_not_found`), which is a machine-oriented token, not plain
	 * language.
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
	 * Redirects back to this page with a fixed-vocabulary notice code
	 * and exits. Never called with anything but a code from self::NOTICES
	 * and, optionally, a plain-language detail string that is itself
	 * escaped on output by render_notice(), never trusted as markup.
	 * The detail is passed unencoded — add_query_arg() below already
	 * URL-encodes every value it's given, so pre-encoding here would
	 * double-encode it (e.g. a space would survive as a literal `%20`
	 * in the displayed notice instead of decoding back to a space).
	 *
	 * @param string $code   One of the keys in self::NOTICES.
	 * @param string $detail Optional detail to interpolate into the notice template.
	 * @return void
	 * @codeCoverageIgnore Calls exit; cannot run inside the test process. Its pure input-building logic is trivial (array literal + add_query_arg).
	 */
	private static function redirect_with_notice( string $code, string $detail = '' ): void {
		$args = array(
			'page'        => self::SLUG,
			'bits_notice' => $code,
		);

		if ( '' !== $detail ) {
			$args['bits_notice_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
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
