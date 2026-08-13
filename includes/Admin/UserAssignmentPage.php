<?php
/**
 * GroupsIO Management > User Assignment admin page.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

use BITS\GroupsIOSync\MemberIndex;
use BITS\GroupsIOSync\QueuedExecutionEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List view (#60), Details view (#61), and Add Groups view (#62), per
 * subgroup-crud-and-admin-pages-design.md section 12. This is the
 * default landing page for the "GroupsIO Management" top-level menu.
 *
 * The List view is read-only (no POST handling). The Details view adds
 * three state-changing actions: "Remove Selected" (queues a remove job
 * per checked group, via QueuedExecutionEngine) and "Clear override"
 * (a synchronous, pure local write via MemberIndex::clear_override() -
 * no Groups.io call, so it's a nonce-protected GET action link,
 * matching core's own "Trash" link convention, rather than a full POST
 * form). The Add Groups view adds a fourth: "Add Selected" (queues an
 * add job per checked group, mirroring "Remove Selected" - adding a
 * group is never dangerous enough to warrant a confirmation step the
 * way removing the parent group is). All are handled on this page's
 * own `load-{hook}` action (see GroupsIoManagementMenu::add_menu_pages()),
 * not inside render() - WordPress's admin.php already prints the admin
 * header/nav before a page's own render callback runs, so a
 * wp_safe_redirect() issued from inside render() on a real submission
 * always fails with "headers already sent" - load-{hook} fires early,
 * before any output, which is the standard WordPress hook for exactly
 * this (same pattern SubgroupManagementPage already uses).
 */
final class UserAssignmentPage {

	public const SLUG = 'bits-groupsio-user-assignment';

	private const VIEW_DETAILS    = 'details';
	private const VIEW_ADD_GROUPS = 'add-groups';

	private const PER_PAGE = 20;

	/**
	 * A member realistically belongs to a small handful of groups
	 * (parent + a few subgroups) - this caps the un-paginated fetch
	 * process_remove_selected()/process_confirm_parent_remove() use to
	 * re-validate submitted subgroup ids against the member's own
	 * indexed rows.
	 */
	private const MAX_MEMBER_GROUPS = 500;

	private const NONCE_ACTION_REMOVE_SELECTED       = 'bits_groupsio_remove_selected';
	private const NONCE_ACTION_CONFIRM_PARENT_REMOVE = 'bits_groupsio_confirm_parent_remove';
	private const NONCE_ACTION_CLEAR_OVERRIDE        = 'bits_groupsio_clear_override';
	private const NONCE_ACTION_ADD_SELECTED          = 'bits_groupsio_add_selected';
	private const NONCE_ACTION_SYNC                  = 'bits_groupsio_sync';

	/**
	 * Returns this page's fixed notice vocabulary as {code: [type,
	 * translated template]}.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function notices(): array {
		return array(
			/* translators: %s: number of group removals queued. */
			'removed_queued'        => array( 'success', __( '%s group removal(s) queued.', 'bits-groupsio-sync' ) ),
			/* translators: %s: number of group additions queued. */
			'added_queued'          => array( 'success', __( '%s group addition(s) queued.', 'bits-groupsio-sync' ) ),
			'override_cleared'      => array( 'success', __( 'Override cleared.', 'bits-groupsio-sync' ) ),
			/* translators: %s: number of queued actions processed. */
			'jobs_processed'        => array( 'success', __( '%s queued action(s) processed.', 'bits-groupsio-sync' ) ),
			'no_jobs_due'           => array( 'success', __( 'No queued actions were due.', 'bits-groupsio-sync' ) ),
			'invalid_request'       => array( 'error', __( 'The request could not be processed. Please try again.', 'bits-groupsio-sync' ) ),
			'owner_removal_blocked' => array( 'error', __( "The group owner can't be removed from the parent group.", 'bits-groupsio-sync' ) ),
		);
	}

	/**
	 * Handles this page's own state-changing actions, if any, ending the
	 * request via redirect + exit. Registered on `load-{$hook_suffix}` by
	 * GroupsIoManagementMenu. Dispatches POST actions (Remove Selected,
	 * confirming a parent-group removal, Add Selected) and the one GET
	 * action (Clear override).
	 *
	 * @return void
	 * @codeCoverageIgnore Dispatch-then-exit wrapper; process_*() below carries the tested logic.
	 */
	public static function maybe_handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' === $request_method ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only reads the action name to dispatch; each process_*() method below verifies its own nonce via check_admin_referer() before touching any other POST data or taking action.
			$action = isset( $_POST['bits_groupsio_action'] ) ? sanitize_key( wp_unslash( $_POST['bits_groupsio_action'] ) ) : '';

			if ( 'remove_selected' === $action ) {
				$result = self::process_remove_selected();

				// Owner-block takes priority over reporting a partial
				// "N queued" success - it's the more important message
				// for the admin to see, and a checked owner-parent-row-plus-
				// other-groups submission is a rare edge case, not the
				// everyday flow this notice text is optimized for.
				if ( $result['owner_blocked'] ) {
					self::redirect_with_notice( $result['email'], 'owner_removal_blocked' );
					return;
				}

				if ( $result['invalid'] ) {
					self::redirect_with_notice( $result['email'], 'invalid_request' );
					return;
				}

				$args = array();
				if ( $result['queued_count'] > 0 ) {
					$args['bits_notice']        = 'removed_queued';
					$args['bits_notice_detail'] = (string) $result['queued_count'];
				}
				if ( 0 !== $result['parent_id'] ) {
					$args['confirm_remove_parent'] = $result['parent_id'];
				}
				wp_safe_redirect( add_query_arg( $args, self::details_url( $result['email'] ) ) );
				exit;
			}

			if ( 'confirm_parent_remove' === $action ) {
				$result = self::process_confirm_parent_remove();

				if ( $result['owner_blocked'] ) {
					self::redirect_with_notice( $result['email'], 'owner_removal_blocked' );
					return;
				}

				self::redirect_with_notice(
					$result['email'],
					$result['invalid'] ? 'invalid_request' : 'removed_queued',
					$result['invalid'] ? '' : '1'
				);
				return;
			}

			if ( 'add_selected' === $action ) {
				$result = self::process_add_selected();
				self::redirect_with_notice(
					$result['email'],
					$result['invalid'] ? 'invalid_request' : 'added_queued',
					$result['invalid'] ? '' : (string) $result['queued_count']
				);
				return;
			}

			if ( 'sync' === $action ) {
				self::redirect_after_sync( self::process_sync() );
				return;
			}

			return;
		}

		// GET-based "Clear override" action link - a nonce-protected GET
		// request, matching core's own action-link convention (e.g. the
		// "Trash" links in WP_List_Table), since it's a pure local write
		// with nothing meaningfully destructive or irreversible enough to
		// warrant a full POST confirmation form.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads the action name to dispatch; process_clear_override() verifies its own nonce via check_admin_referer() before touching any data.
		$get_action = isset( $_GET['bits_groupsio_action'] ) ? sanitize_key( wp_unslash( $_GET['bits_groupsio_action'] ) ) : '';

		if ( 'clear_override' === $get_action ) {
			$result = self::process_clear_override();
			self::redirect_with_notice( $result['email'], $result['invalid'] ? 'invalid_request' : 'override_cleared' );
		}
	}

	/**
	 * The redirect-free half of "Remove Selected" handling: verifies the
	 * nonce, re-validates each checked subgroup id against the member's
	 * own indexed rows (never trusting client-submitted ids blindly),
	 * and queues an immediate remove job for every checked non-parent
	 * group. If the parent-group row is among the checked ids, its job
	 * is deliberately *not* queued here - the caller shows an inline
	 * confirmation step first (see render_parent_removal_confirmation()),
	 * per section 12's parent-removal semantics. **Exception**: if the
	 * member is the group's owner (`MemberIndex::is_owner_of_parent()`),
	 * the parent row is refused outright instead - no confirmation step
	 * is shown at all, and `owner_blocked` is set so the caller can
	 * surface a distinct notice. Non-parent rows checked alongside the
	 * owner's parent row still queue normally. Kept separate from
	 * maybe_handle_post() purely so this branch is unit testable without
	 * terminating the test process.
	 *
	 * @return array{queued_count: int, parent_id: int, owner_blocked: bool, email: string, invalid: bool}
	 */
	public static function process_remove_selected(): array {
		check_admin_referer( self::NONCE_ACTION_REMOVE_SELECTED );

		$email   = isset( $_POST['member'] ) ? sanitize_email( wp_unslash( $_POST['member'] ) ) : '';
		$checked = isset( $_POST['subgroup_ids'] ) && is_array( $_POST['subgroup_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['subgroup_ids'] ) )
			: array();

		if ( '' === $email || empty( $checked ) ) {
			return array(
				'queued_count'  => 0,
				'parent_id'     => 0,
				'owner_blocked' => false,
				'email'         => $email,
				'invalid'       => true,
			);
		}

		$parent_slug   = self::parent_group();
		$groups        = MemberIndex::get_member_groups( $email, 1, self::MAX_MEMBER_GROUPS );
		$admin_user_id = get_current_user_id();
		$queued_count  = 0;
		$parent_id     = 0;
		$owner_blocked = false;

		foreach ( $groups as $group ) {
			if ( ! in_array( $group['subgroup_id'], $checked, true ) ) {
				continue;
			}

			if ( $parent_slug === $group['subgroup_slug'] ) {
				if ( MemberIndex::is_owner_of_parent( $email ) ) {
					$owner_blocked = true;
				} else {
					$parent_id = $group['subgroup_id'];
				}
				continue;
			}

			QueuedExecutionEngine::queue_remove( $email, $group['subgroup_id'], $admin_user_id );
			++$queued_count;
		}

		return array(
			'queued_count'  => $queued_count,
			'parent_id'     => $parent_id,
			'owner_blocked' => $owner_blocked,
			'email'         => $email,
			'invalid'       => 0 === $queued_count && 0 === $parent_id && ! $owner_blocked,
		);
	}

	/**
	 * The redirect-free half of confirming a parent-group removal:
	 * verifies the nonce, then re-validates the submitted subgroup id is
	 * both one of this member's own indexed rows and actually the
	 * parent group (never trusting a client-submitted id blindly)
	 * before queuing the remove job. Independently re-checks
	 * `MemberIndex::is_owner_of_parent()` here too, rather than trusting
	 * process_remove_selected()'s earlier check alone - defense in
	 * depth against the member's owner status changing between the two
	 * requests (e.g. a re-sync completing in between).
	 *
	 * @return array{email: string, invalid: bool, owner_blocked: bool}
	 */
	public static function process_confirm_parent_remove(): array {
		check_admin_referer( self::NONCE_ACTION_CONFIRM_PARENT_REMOVE );

		$email       = isset( $_POST['member'] ) ? sanitize_email( wp_unslash( $_POST['member'] ) ) : '';
		$subgroup_id = isset( $_POST['subgroup_id'] ) ? absint( $_POST['subgroup_id'] ) : 0;

		if ( '' === $email || 0 === $subgroup_id ) {
			return array(
				'email'         => $email,
				'invalid'       => true,
				'owner_blocked' => false,
			);
		}

		$parent_slug = self::parent_group();
		$groups      = MemberIndex::get_member_groups( $email, 1, self::MAX_MEMBER_GROUPS );

		$is_parent = false;
		foreach ( $groups as $group ) {
			if ( $group['subgroup_id'] === $subgroup_id && $group['subgroup_slug'] === $parent_slug ) {
				$is_parent = true;
				break;
			}
		}

		if ( ! $is_parent ) {
			return array(
				'email'         => $email,
				'invalid'       => true,
				'owner_blocked' => false,
			);
		}

		if ( MemberIndex::is_owner_of_parent( $email ) ) {
			return array(
				'email'         => $email,
				'invalid'       => false,
				'owner_blocked' => true,
			);
		}

		QueuedExecutionEngine::queue_remove( $email, $subgroup_id, get_current_user_id() );

		return array(
			'email'         => $email,
			'invalid'       => false,
			'owner_blocked' => false,
		);
	}

	/**
	 * The redirect-free half of "Add Selected" handling: verifies the
	 * nonce, re-validates each checked subgroup id against the member's
	 * own addable set (never trusting client-submitted ids blindly), and
	 * queues an immediate add job for every checked group.
	 *
	 * @return array{queued_count: int, email: string, invalid: bool}
	 */
	public static function process_add_selected(): array {
		check_admin_referer( self::NONCE_ACTION_ADD_SELECTED );

		$email   = isset( $_POST['member'] ) ? sanitize_email( wp_unslash( $_POST['member'] ) ) : '';
		$checked = isset( $_POST['subgroup_ids'] ) && is_array( $_POST['subgroup_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['subgroup_ids'] ) )
			: array();

		if ( '' === $email || empty( $checked ) ) {
			return array(
				'queued_count' => 0,
				'email'        => $email,
				'invalid'      => true,
			);
		}

		$addable       = MemberIndex::get_addable_groups( $email, 1, self::MAX_MEMBER_GROUPS );
		$user          = get_user_by( 'email', $email );
		$user_id       = $user ? (int) $user->ID : 0;
		$display_name  = MemberIndex::get_display_name( $email );
		$admin_user_id = get_current_user_id();
		$queued_count  = 0;

		foreach ( $addable as $group ) {
			if ( ! in_array( $group['subgroup_id'], $checked, true ) ) {
				continue;
			}

			QueuedExecutionEngine::queue_add(
				$user_id,
				$email,
				$display_name,
				$group['subgroup_id'],
				$group['subgroup_slug'],
				$group['subgroup_title'],
				$admin_user_id
			);
			++$queued_count;
		}

		return array(
			'queued_count' => $queued_count,
			'email'        => $email,
			'invalid'      => 0 === $queued_count,
		);
	}

	/**
	 * The redirect-free half of "Sync" handling: verifies the nonce and
	 * forces Action Scheduler to process any currently-due queued jobs
	 * immediately (QueuedExecutionEngine::process_due_jobs()). Not scoped
	 * to the current member/view - it processes whatever is globally
	 * due. The submitted view/member round-trip back through the
	 * redirect purely so the admin lands back on the view they were on,
	 * not because the sync itself is scoped by them.
	 *
	 * @return array{count: int, view: string, member: string}
	 */
	public static function process_sync(): array {
		check_admin_referer( self::NONCE_ACTION_SYNC );

		$view   = isset( $_POST['sync_view'] ) ? sanitize_key( wp_unslash( $_POST['sync_view'] ) ) : '';
		$member = isset( $_POST['member'] ) ? sanitize_email( wp_unslash( $_POST['member'] ) ) : '';

		return array(
			'count'  => QueuedExecutionEngine::process_due_jobs(),
			'view'   => $view,
			'member' => $member,
		);
	}

	/**
	 * The redirect-free half of "Clear override" handling: verifies the
	 * nonce and, if a member/subgroup id pair was actually supplied,
	 * clears that row's sticky override flag. Deliberately does not
	 * re-validate the id against the member's own rows first (unlike the
	 * two methods above) - clear_override()'s UPDATE ... WHERE already
	 * only matches an existing (email, subgroup_id) row and is a no-op
	 * otherwise, so there is no unsafe action to guard against beyond
	 * the nonce check itself.
	 *
	 * @return array{email: string, invalid: bool}
	 */
	public static function process_clear_override(): array {
		check_admin_referer( self::NONCE_ACTION_CLEAR_OVERRIDE );

		$email       = isset( $_GET['member'] ) ? sanitize_email( wp_unslash( $_GET['member'] ) ) : '';
		$subgroup_id = isset( $_GET['subgroup_id'] ) ? absint( $_GET['subgroup_id'] ) : 0;

		if ( '' === $email || 0 === $subgroup_id ) {
			return array(
				'email'   => $email,
				'invalid' => true,
			);
		}

		MemberIndex::clear_override( $email, $subgroup_id );

		return array(
			'email'   => $email,
			'invalid' => false,
		);
	}

	/**
	 * Renders the page. Callback for add_menu_page()/add_submenu_page().
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.

		echo '<div class="wrap">';

		if ( self::VIEW_DETAILS === $view ) {
			$email = isset( $_GET['member'] ) ? sanitize_email( wp_unslash( $_GET['member'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.
			self::render_details_view( $email );
		} elseif ( self::VIEW_ADD_GROUPS === $view ) {
			$email = isset( $_GET['member'] ) ? sanitize_email( wp_unslash( $_GET['member'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.
			self::render_add_groups_view( $email );
		} else {
			echo '<h1>' . esc_html__( 'User Assignment', 'bits-groupsio-sync' ) . '</h1>';
			self::render_sync_button( '' );
			self::render_notice();
			self::render_list_view();
		}

		echo '</div>';
	}

	/**
	 * Renders the "Sync" control: a small nonce-protected POST button
	 * that forces Action Scheduler to process any currently-due queued
	 * jobs immediately (QueuedExecutionEngine::process_due_jobs()),
	 * rather than waiting on WP-Cron's own timing. Not scoped to the
	 * current view/member - it processes whatever is globally due; the
	 * view/member are only carried through so the redirect lands back
	 * on the same view. Per subgroup-crud-and-admin-pages-design.md
	 * section 8.
	 *
	 * @param string $view   Current view ('' for the List view, self::VIEW_DETAILS, or self::VIEW_ADD_GROUPS).
	 * @param string $member Current member's email address, if on the Details or Add Groups view.
	 * @return void
	 */
	private static function render_sync_button( string $view, string $member = '' ): void {
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_SYNC );
		echo '<input type="hidden" name="bits_groupsio_action" value="sync" />';
		printf( '<input type="hidden" name="sync_view" value="%s" />', esc_attr( $view ) );
		if ( '' !== $member ) {
			printf( '<input type="hidden" name="member" value="%s" />', esc_attr( $member ) );
		}
		submit_button( AccessKeys::label( __( 'Sync', 'bits-groupsio-sync' ), 'N' ), 'secondary', 'submit', false, array( 'accesskey' => 'N' ) );
		echo '</form>';
	}

	/**
	 * Renders the paginated, searchable member table, per section 12's
	 * List view spec. Search matches against member name/email or
	 * subgroup name/slug (MemberIndex::search_where() does the actual
	 * matching); pagination and the search term round-trip through GET
	 * query args (`s`, `paged`) - this view is read-only, so a GET form
	 * is correct here (contrast with the Details view's POST actions
	 * below).
	 *
	 * @return void
	 */
	private static function render_list_view(): void {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter, no state change.
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.

		self::render_search_form( $search );

		$total_items = MemberIndex::count_members( $search );

		if ( 0 === $total_items ) {
			echo '<p>' . esc_html__( 'No members found.', 'bits-groupsio-sync' ) . '</p>';
			return;
		}

		$total_pages = (int) ceil( $total_items / self::PER_PAGE );
		$paged       = min( $paged, $total_pages );
		$members     = MemberIndex::get_members_page( $paged, self::PER_PAGE, $search );

		self::render_member_table( $members );
		self::render_pagination( $paged, $total_pages, $search );
	}

	/**
	 * Renders the search box + button. A native GET <form> - submitting
	 * it reloads this same page with `s` (and no `paged`, so a new
	 * search always starts back on page 1) in the query string. The
	 * search field carries autofocus - the only control on this page -
	 * so a screen reader user's focus lands on a real control on page
	 * load rather than the body, matching the autofocus-on-first-field
	 * convention already established in Settings.php/SubgroupManagementPage.
	 *
	 * @param string $search Current search term, if any, for re-display.
	 * @return void
	 */
	private static function render_search_form( string $search ): void {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<label for="bits-groupsio-user-search"><?php esc_html_e( 'Search members or groups', 'bits-groupsio-sync' ); ?></label>
			<input
				type="search"
				id="bits-groupsio-user-search"
				name="s"
				value="<?php echo esc_attr( $search ); ?>"
				autofocus
			/>
			<button type="submit" class="button" accesskey="H"><?php echo esc_html( AccessKeys::label( __( 'Search', 'bits-groupsio-sync' ), 'H' ) ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders the member table itself. Each member's name (falling back
	 * to their email, if no display name is on file) links to the
	 * Details view.
	 *
	 * @param array<int, array{email: string, display_name: string, group_count: int}> $members One page of members.
	 * @return void
	 */
	private static function render_member_table( array $members ): void {
		?>
		<table class="wp-list-table widefat fixed striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'BITS members and their Groups.io membership counts', 'bits-groupsio-sync' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'bits-groupsio-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'bits-groupsio-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Groups', 'bits-groupsio-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $members as $member ) : ?>
					<?php
					$display_name = '' !== $member['display_name'] ? $member['display_name'] : $member['email'];
					$details_url  = self::details_url( $member['email'] );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $details_url ); ?>"><?php echo esc_html( $display_name ); ?></a></td>
						<td><?php echo esc_html( $member['email'] ); ?></td>
						<td><?php echo esc_html( (string) $member['group_count'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders pagination controls as a list of GET links preserving the
	 * current search term, wrapped in a labeled <nav> so a screen reader
	 * user can distinguish it from the table's own row-level links.
	 *
	 * @param int    $current_page Current 1-based page number.
	 * @param int    $total_pages  Total number of pages.
	 * @param string $search       Current search term, if any, to preserve across page links.
	 * @return void
	 */
	private static function render_pagination( int $current_page, int $total_pages, string $search ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<nav aria-label="' . esc_attr__( 'Members pagination', 'bits-groupsio-sync' ) . '">';
		echo '<ul class="bits-groupsio-pagination">';

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			$args = array(
				'page'  => self::SLUG,
				'paged' => $page,
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}
			$url = add_query_arg( $args, admin_url( 'admin.php' ) );

			echo '<li>';
			if ( $page === $current_page ) {
				printf(
					'<span aria-current="page">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: page number. */
							__( 'Page %d', 'bits-groupsio-sync' ),
							$page
						)
					)
				);
			} else {
				printf(
					'<a href="%s">%s</a>',
					esc_url( $url ),
					esc_html(
						sprintf(
							/* translators: %d: page number. */
							__( 'Page %d', 'bits-groupsio-sync' ),
							$page
						)
					)
				);
			}
			echo '</li>';
		}

		echo '</ul>';
		echo '</nav>';
	}

	/**
	 * Renders the Details view: a paginated, searchable list of one
	 * member's currently subscribed groups, with a "Remove Selected"
	 * bulk action, per-row "Clear override" controls, and a link to the
	 * Add Groups view.
	 *
	 * @param string $email Member's email address, from the `member` query arg.
	 * @return void
	 */
	private static function render_details_view( string $email ): void {
		echo '<h1>' . esc_html( self::details_heading( $email ) ) . '</h1>';

		printf(
			'<p><a href="%1$s" accesskey="B">%2$s</a></p>',
			esc_url( self::list_url() ),
			esc_html( AccessKeys::label( __( 'Back to User Assignment', 'bits-groupsio-sync' ), 'B' ) )
		);

		self::render_sync_button( self::VIEW_DETAILS, $email );
		self::render_notice();

		if ( '' === $email ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'No member specified.', 'bits-groupsio-sync' )
			);
			return;
		}

		$search            = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter, no state change.
		$paged             = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.
		$confirm_parent_id = isset( $_GET['confirm_remove_parent'] ) ? absint( wp_unslash( $_GET['confirm_remove_parent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change itself; the confirmation form it triggers carries its own nonce.

		// The confirmation button below carries the page's only other
		// autofocus candidate while it's showing - per the HTML spec,
		// only the first `autofocus` element in document order actually
		// receives focus, so having both present at once would silently
		// defeat the confirmation button's autofocus, same reasoning
		// SubgroupManagementPage's Details view already documents.
		self::render_details_search_form( $email, $search, 0 === $confirm_parent_id );

		$total_items = MemberIndex::count_member_groups( $email, $search );

		if ( 0 === $total_items ) {
			// Still reachable even with nothing currently subscribed (e.g.
			// a member manually removed from every group) - a member with
			// zero groups is exactly the case where "Add groups" is most
			// needed, so this link must not be gated behind having at
			// least one row to show.
			echo '<p>' . esc_html__( 'No currently subscribed groups found.', 'bits-groupsio-sync' ) . '</p>';
			self::render_add_groups_link( $email );
			return;
		}

		$total_pages = (int) ceil( $total_items / self::PER_PAGE );
		$paged       = min( $paged, $total_pages );
		$groups      = MemberIndex::get_member_groups( $email, $paged, self::PER_PAGE, $search );

		self::render_group_table( $email, $groups, $confirm_parent_id );
		self::render_details_pagination( $email, $paged, $total_pages, $search );
		self::render_add_groups_link( $email );
	}

	/**
	 * Renders the link to the Add Groups view.
	 *
	 * @param string $email Member's email address.
	 * @return void
	 */
	private static function render_add_groups_link( string $email ): void {
		printf(
			'<p><a href="%1$s" accesskey="A">%2$s</a></p>',
			esc_url( self::add_groups_url( $email ) ),
			esc_html( AccessKeys::label( __( 'Add groups', 'bits-groupsio-sync' ), 'A' ) )
		);
	}

	/**
	 * Builds the Details view's heading, preferring the member's stored
	 * display name and falling back to their email, matching the List
	 * view's own fallback convention.
	 *
	 * @param string $email Member's email address.
	 * @return string
	 */
	private static function details_heading( string $email ): string {
		if ( '' === $email ) {
			return __( 'User Assignment: Details', 'bits-groupsio-sync' );
		}

		$display_name = MemberIndex::get_display_name( $email );

		return sprintf(
			/* translators: %s: member's display name or email address. */
			__( 'User Assignment: %s', 'bits-groupsio-sync' ),
			'' !== $display_name ? $display_name : $email
		);
	}

	/**
	 * Renders the Details view's search box + button, filtering this
	 * member's own group list by subgroup name or title.
	 *
	 * @param string $email     Member's email address, round-tripped as a hidden field.
	 * @param string $search    Current search term, if any, for re-display.
	 * @param bool   $autofocus Whether the search field should carry autofocus (suppressed while the parent-removal confirmation is showing).
	 * @return void
	 */
	private static function render_details_search_form( string $email, string $search, bool $autofocus ): void {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="view" value="<?php echo esc_attr( self::VIEW_DETAILS ); ?>" />
			<input type="hidden" name="member" value="<?php echo esc_attr( $email ); ?>" />
			<label for="bits-groupsio-member-group-search"><?php esc_html_e( "Search this member's groups", 'bits-groupsio-sync' ); ?></label>
			<input
				type="search"
				id="bits-groupsio-member-group-search"
				name="s"
				value="<?php echo esc_attr( $search ); ?>"
				<?php echo $autofocus ? 'autofocus' : ''; ?>
			/>
			<button type="submit" class="button" accesskey="H"><?php echo esc_html( AccessKeys::label( __( 'Search', 'bits-groupsio-sync' ), 'H' ) ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders the member's group table (with checkboxes and the "Remove
	 * Selected" bulk action) and, when a parent-group removal is
	 * pending confirmation, the inline confirmation step below it.
	 *
	 * @param string                                                                                                                               $email             Member's email address.
	 * @param array<int, array{subgroup_id: int, subgroup_slug: string, subgroup_title: string, pmpro_expected: bool, override_type: string|null}> $groups            One page of this member's groups.
	 * @param int                                                                                                                                  $confirm_parent_id Numeric subgroup id of a pending parent-removal confirmation, or 0 if none.
	 * @return void
	 */
	private static function render_group_table( string $email, array $groups, int $confirm_parent_id ): void {
		// No explicit action attribute - submits back to the current URL
		// (preserving the page/view/member query args WordPress needs to
		// route the POST to this page's own load-{hook} handler), same
		// convention SubgroupManagementPage's own forms use. An explicit
		// action="{admin_url}/admin.php" here previously stripped the
		// page query arg entirely, so WordPress had nothing to route the
		// POST to and rendered a blank response.
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_REMOVE_SELECTED );
		echo '<input type="hidden" name="bits_groupsio_action" value="remove_selected" />';
		printf( '<input type="hidden" name="member" value="%s" />', esc_attr( $email ) );

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<caption class="screen-reader-text">' . esc_html__( "This member's current subscribed groups", 'bits-groupsio-sync' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col" class="check-column"><span class="screen-reader-text">' . esc_html__( 'Select', 'bits-groupsio-sync' ) . '</span></th>';
		echo '<th scope="col">' . esc_html__( 'Group', 'bits-groupsio-sync' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'PMPro expected', 'bits-groupsio-sync' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Override', 'bits-groupsio-sync' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'bits-groupsio-sync' ) . '</span></th>';
		echo '</tr></thead><tbody>';

		foreach ( $groups as $group ) {
			self::render_group_row( $email, $group );
		}

		echo '</tbody></table>';

		submit_button( AccessKeys::label( __( 'Remove Selected', 'bits-groupsio-sync' ), 'R' ), 'primary', 'submit', true, array( 'accesskey' => 'R' ) );
		echo '</form>';

		if ( 0 !== $confirm_parent_id ) {
			self::render_parent_removal_confirmation( $email, $confirm_parent_id );
		}
	}

	/**
	 * Renders one row of the group table.
	 *
	 * @param string                                                                                                                   $email Member's email address.
	 * @param array{subgroup_id: int, subgroup_slug: string, subgroup_title: string, pmpro_expected: bool, override_type: string|null} $group One group row.
	 * @return void
	 */
	private static function render_group_row( string $email, array $group ): void {
		$checkbox_id = 'bits-groupsio-group-' . $group['subgroup_id'];
		$label       = sprintf(
			/* translators: 1: group title, 2: group slug/namespace, reordered subgroup+parent. */
			__( '%1$s (%2$s)', 'bits-groupsio-sync' ),
			'' !== $group['subgroup_title'] ? $group['subgroup_title'] : self::subgroup_name_segment( $group['subgroup_slug'] ),
			self::reversed_slug_for_display( $group['subgroup_slug'] )
		);

		echo '<tr>';
		printf(
			'<td><input type="checkbox" id="%1$s" name="subgroup_ids[]" value="%2$s" /><label for="%1$s" class="screen-reader-text">%3$s</label></td>',
			esc_attr( $checkbox_id ),
			esc_attr( (string) $group['subgroup_id'] ),
			esc_html(
				sprintf(
					/* translators: %s: group label ("Title (namespace)"). */
					__( 'Select %s', 'bits-groupsio-sync' ),
					$label
				)
			)
		);
		echo '<td>' . esc_html( $label ) . '</td>';
		echo '<td>' . esc_html( $group['pmpro_expected'] ? __( 'Yes', 'bits-groupsio-sync' ) : __( 'No', 'bits-groupsio-sync' ) ) . '</td>';
		echo '<td>' . esc_html( self::override_label( $group['override_type'] ) ) . '</td>';
		echo '<td>';
		if ( null !== $group['override_type'] ) {
			self::render_clear_override_link( $email, $group['subgroup_id'], $label );
		}
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Extracts the "sub" segment from a "parent+sub" slug, for the
	 * fallback label a titleless group's row uses (#98) - the subgroup's
	 * own name should lead the label, not the full parent+sub slug
	 * (which is still shown as the label's parenthesized context, per
	 * render_group_row()/render_addable_group_table(), but reordered -
	 * see reversed_slug_for_display()). Mirrors SubgroupManagementPage's
	 * own identical helper.
	 *
	 * @param string $slug Full slug.
	 * @return string
	 */
	private static function subgroup_name_segment( string $slug ): string {
		$pos = strpos( $slug, '+' );

		return false === $pos ? $slug : substr( $slug, $pos + 1 );
	}

	/**
	 * Reorders a "parent+sub" slug to "sub+parent" for display in a
	 * group label's parenthesized context (#98 follow-up) - confirmed
	 * with the primary contributor that the subgroup segment should lead
	 * even in this secondary, fuller-context part of the label, not just
	 * the primary title/name fallback subgroup_name_segment() already
	 * handles. This is purely a display transformation - never used as
	 * an actual Groups.io slug/address (the real address is always
	 * parent-first; nothing here is sent back to the API or used to
	 * look anything up). The parent group's own row (no '+' in its slug)
	 * is returned unchanged, since there's nothing to reorder.
	 *
	 * @param string $slug Full slug, "parent+sub" form (or a bare parent slug).
	 * @return string
	 */
	private static function reversed_slug_for_display( string $slug ): string {
		$pos = strpos( $slug, '+' );

		if ( false === $pos ) {
			return $slug;
		}

		return substr( $slug, $pos + 1 ) . '+' . substr( $slug, 0, $pos );
	}

	/**
	 * Renders one row's "Clear override" action link: a nonce-protected
	 * GET link (see maybe_handle_post()'s doc comment for why this is a
	 * link, not a form).
	 *
	 * @param string $email       Member's email address.
	 * @param int    $subgroup_id Numeric Groups.io group/subgroup id for this row.
	 * @param string $label       This row's "Title (namespace)" label, for the link's accessible name.
	 * @return void
	 */
	private static function render_clear_override_link( string $email, int $subgroup_id, string $label ): void {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'page'                 => self::SLUG,
					'view'                 => self::VIEW_DETAILS,
					'member'               => rawurlencode( $email ),
					'bits_groupsio_action' => 'clear_override',
					'subgroup_id'          => $subgroup_id,
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_ACTION_CLEAR_OVERRIDE
		);

		printf(
			'<a href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( $url ),
			esc_html__( 'Clear override', 'bits-groupsio-sync' ),
			esc_html(
				sprintf(
					/* translators: %s: group label ("Title (namespace)"). */
					__( 'for %s', 'bits-groupsio-sync' ),
					$label
				)
			)
		);
	}

	/**
	 * Formats a row's override_type as text, per section 12's
	 * requirement that override state never be conveyed by color alone.
	 * 'removed' cannot actually occur here - get_member_groups() already
	 * excludes those rows - but is handled defensively rather than
	 * assumed unreachable.
	 *
	 * @param string|null $override_type Row's override_type column value.
	 * @return string
	 */
	private static function override_label( ?string $override_type ): string {
		if ( 'added' === $override_type ) {
			return __( 'Manually added', 'bits-groupsio-sync' );
		}
		if ( 'removed' === $override_type ) {
			return __( 'Manually removed', 'bits-groupsio-sync' );
		}
		return __( 'None', 'bits-groupsio-sync' );
	}

	/**
	 * Renders the inline parent-group removal confirmation step, shown
	 * when the `confirm_remove_parent` query arg carries a pending
	 * subgroup id (set by process_remove_selected()'s redirect). Mirrors
	 * SubgroupManagementPage's inline delete confirmation.
	 *
	 * @param string $email     Member's email address.
	 * @param int    $parent_id Numeric subgroup id of the parent group pending confirmation.
	 * @return void
	 */
	private static function render_parent_removal_confirmation( string $email, int $parent_id ): void {
		echo '<div class="notice notice-warning">';
		echo '<p>' . esc_html__( "Removing the parent group removes this member from all of BITS' Groups.io presence, not just one list. Are you sure?", 'bits-groupsio-sync' ) . '</p>';

		// No explicit action attribute - submits back to the current URL
		// (preserving the page/view/member query args WordPress needs to
		// route the POST to this page's own load-{hook} handler), same
		// convention SubgroupManagementPage's own forms use. An explicit
		// action="{admin_url}/admin.php" here previously stripped the
		// page query arg entirely, so WordPress had nothing to route the
		// POST to and rendered a blank response.
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_CONFIRM_PARENT_REMOVE );
		echo '<input type="hidden" name="bits_groupsio_action" value="confirm_parent_remove" />';
		printf( '<input type="hidden" name="member" value="%s" />', esc_attr( $email ) );
		printf( '<input type="hidden" name="subgroup_id" value="%s" />', esc_attr( (string) $parent_id ) );
		submit_button(
			AccessKeys::label( __( 'Yes, remove from the parent group', 'bits-groupsio-sync' ), 'Y' ),
			'primary delete',
			'submit',
			false,
			array(
				'autofocus' => 'autofocus',
				'accesskey' => 'Y',
			)
		);
		echo ' ';
		printf(
			'<a class="button" href="%s" accesskey="L">%s</a>',
			esc_url( self::details_url( $email ) ),
			esc_html( AccessKeys::label( __( 'Cancel', 'bits-groupsio-sync' ), 'L' ) )
		);
		echo '</form></div>';
	}

	/**
	 * Renders the Details view's pagination controls, preserving the
	 * current member and search term across page links.
	 *
	 * @param string $email        Member's email address.
	 * @param int    $current_page Current 1-based page number.
	 * @param int    $total_pages  Total number of pages.
	 * @param string $search       Current search term, if any, to preserve across page links.
	 * @return void
	 */
	private static function render_details_pagination( string $email, int $current_page, int $total_pages, string $search ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<nav aria-label="' . esc_attr__( 'Groups pagination', 'bits-groupsio-sync' ) . '">';
		echo '<ul class="bits-groupsio-pagination">';

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			$args = array(
				'page'   => self::SLUG,
				'view'   => self::VIEW_DETAILS,
				'member' => rawurlencode( $email ),
				'paged'  => $page,
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}
			$url = add_query_arg( $args, admin_url( 'admin.php' ) );

			echo '<li>';
			if ( $page === $current_page ) {
				printf(
					'<span aria-current="page">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: page number. */
							__( 'Page %d', 'bits-groupsio-sync' ),
							$page
						)
					)
				);
			} else {
				printf(
					'<a href="%s">%s</a>',
					esc_url( $url ),
					esc_html(
						sprintf(
							/* translators: %d: page number. */
							__( 'Page %d', 'bits-groupsio-sync' ),
							$page
						)
					)
				);
			}
			echo '</li>';
		}

		echo '</ul>';
		echo '</nav>';
	}

	/**
	 * Renders the Add Groups view: a paginated, searchable checkbox list
	 * of every group (parent + subgroups) the member is *not* currently
	 * in, per section 12. "Add Selected" queues an add job for each
	 * checked group and redirects to the Details view - the actual adds
	 * happen asynchronously; this page only confirms the jobs were
	 * queued, not that they've completed.
	 *
	 * @param string $email Member's email address, from the `member` query arg.
	 * @return void
	 */
	private static function render_add_groups_view( string $email ): void {
		echo '<h1>' . esc_html( self::add_groups_heading( $email ) ) . '</h1>';

		printf(
			'<p><a href="%1$s" accesskey="B">%2$s</a></p>',
			esc_url( self::details_url( $email ) ),
			esc_html( AccessKeys::label( __( 'Back to Details', 'bits-groupsio-sync' ), 'B' ) )
		);

		self::render_sync_button( self::VIEW_ADD_GROUPS, $email );
		self::render_notice();

		if ( '' === $email ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'No member specified.', 'bits-groupsio-sync' )
			);
			return;
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter, no state change.
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.

		self::render_add_groups_search_form( $email, $search );

		$total_items = MemberIndex::count_addable_groups( $email, $search );

		if ( 0 === $total_items ) {
			echo '<p>' . esc_html__( 'No groups available to add.', 'bits-groupsio-sync' ) . '</p>';
			return;
		}

		$total_pages = (int) ceil( $total_items / self::PER_PAGE );
		$paged       = min( $paged, $total_pages );
		$groups      = MemberIndex::get_addable_groups( $email, $paged, self::PER_PAGE, $search );

		self::render_addable_group_table( $email, $groups );
		self::render_add_groups_pagination( $email, $paged, $total_pages, $search );
	}

	/**
	 * Builds the Add Groups view's heading, matching the Details view's
	 * own display-name-preferring fallback convention.
	 *
	 * @param string $email Member's email address.
	 * @return string
	 */
	private static function add_groups_heading( string $email ): string {
		if ( '' === $email ) {
			return __( 'Add Groups', 'bits-groupsio-sync' );
		}

		$display_name = MemberIndex::get_display_name( $email );

		return sprintf(
			/* translators: %s: member's display name or email address. */
			__( 'Add Groups: %s', 'bits-groupsio-sync' ),
			'' !== $display_name ? $display_name : $email
		);
	}

	/**
	 * Renders the Add Groups view's search box + button, same subgroup
	 * name/title matching as the Details view.
	 *
	 * @param string $email  Member's email address, round-tripped as a hidden field.
	 * @param string $search Current search term, if any, for re-display.
	 * @return void
	 */
	private static function render_add_groups_search_form( string $email, string $search ): void {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="view" value="<?php echo esc_attr( self::VIEW_ADD_GROUPS ); ?>" />
			<input type="hidden" name="member" value="<?php echo esc_attr( $email ); ?>" />
			<label for="bits-groupsio-add-groups-search"><?php esc_html_e( 'Search groups to add', 'bits-groupsio-sync' ); ?></label>
			<input
				type="search"
				id="bits-groupsio-add-groups-search"
				name="s"
				value="<?php echo esc_attr( $search ); ?>"
				autofocus
			/>
			<button type="submit" class="button" accesskey="H"><?php echo esc_html( AccessKeys::label( __( 'Search', 'bits-groupsio-sync' ), 'H' ) ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders the addable-groups checkbox table and its "Add Selected"
	 * bulk action.
	 *
	 * @param string                                                                             $email  Member's email address.
	 * @param array<int, array{subgroup_id: int, subgroup_slug: string, subgroup_title: string}> $groups One page of groups the member is not currently in.
	 * @return void
	 */
	private static function render_addable_group_table( string $email, array $groups ): void {
		// No explicit action attribute - submits back to the current URL
		// (preserving the page/view/member query args WordPress needs to
		// route the POST to this page's own load-{hook} handler), same
		// convention SubgroupManagementPage's own forms use. An explicit
		// action="{admin_url}/admin.php" here previously stripped the
		// page query arg entirely, so WordPress had nothing to route the
		// POST to and rendered a blank response.
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION_ADD_SELECTED );
		echo '<input type="hidden" name="bits_groupsio_action" value="add_selected" />';
		printf( '<input type="hidden" name="member" value="%s" />', esc_attr( $email ) );

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Groups this member is not currently in', 'bits-groupsio-sync' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col" class="check-column"><span class="screen-reader-text">' . esc_html__( 'Select', 'bits-groupsio-sync' ) . '</span></th>';
		echo '<th scope="col">' . esc_html__( 'Group', 'bits-groupsio-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $groups as $group ) {
			$checkbox_id = 'bits-groupsio-addable-' . $group['subgroup_id'];
			$label       = sprintf(
				/* translators: 1: group title, 2: group slug/namespace, reordered subgroup+parent. */
				__( '%1$s (%2$s)', 'bits-groupsio-sync' ),
				'' !== $group['subgroup_title'] ? $group['subgroup_title'] : self::subgroup_name_segment( $group['subgroup_slug'] ),
				self::reversed_slug_for_display( $group['subgroup_slug'] )
			);

			echo '<tr>';
			printf(
				'<td><input type="checkbox" id="%1$s" name="subgroup_ids[]" value="%2$s" /><label for="%1$s" class="screen-reader-text">%3$s</label></td>',
				esc_attr( $checkbox_id ),
				esc_attr( (string) $group['subgroup_id'] ),
				esc_html(
					sprintf(
						/* translators: %s: group label ("Title (namespace)"). */
						__( 'Select %s', 'bits-groupsio-sync' ),
						$label
					)
				)
			);
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		submit_button( AccessKeys::label( __( 'Add Selected', 'bits-groupsio-sync' ), 'A' ), 'primary', 'submit', true, array( 'accesskey' => 'A' ) );
		echo '</form>';
	}

	/**
	 * Renders the Add Groups view's pagination controls, preserving the
	 * current member and search term across page links.
	 *
	 * @param string $email        Member's email address.
	 * @param int    $current_page Current 1-based page number.
	 * @param int    $total_pages  Total number of pages.
	 * @param string $search       Current search term, if any, to preserve across page links.
	 * @return void
	 */
	private static function render_add_groups_pagination( string $email, int $current_page, int $total_pages, string $search ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<nav aria-label="' . esc_attr__( 'Add groups pagination', 'bits-groupsio-sync' ) . '">';
		echo '<ul class="bits-groupsio-pagination">';

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			$args = array(
				'page'   => self::SLUG,
				'view'   => self::VIEW_ADD_GROUPS,
				'member' => rawurlencode( $email ),
				'paged'  => $page,
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}
			$url = add_query_arg( $args, admin_url( 'admin.php' ) );

			echo '<li>';
			if ( $page === $current_page ) {
				printf(
					'<span aria-current="page">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: page number. */
							__( 'Page %d', 'bits-groupsio-sync' ),
							$page
						)
					)
				);
			} else {
				printf(
					'<a href="%s">%s</a>',
					esc_url( $url ),
					esc_html(
						sprintf(
							/* translators: %d: page number. */
							__( 'Page %d', 'bits-groupsio-sync' ),
							$page
						)
					)
				);
			}
			echo '</li>';
		}

		echo '</ul>';
		echo '</nav>';
	}

	/**
	 * Renders a fixed-vocabulary notice banner from the `bits_notice`
	 * (and optional `bits_notice_detail`) query args, if present.
	 * Mirrors SubgroupManagementPage's own render_notice().
	 *
	 * @return void
	 */
	private static function render_notice(): void {
		$code = isset( $_GET['bits_notice'] ) ? sanitize_key( wp_unslash( $_GET['bits_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a fixed-vocabulary code, no state change.

		$notices = self::notices();

		if ( '' === $code || ! isset( $notices[ $code ] ) ) {
			return;
		}

		list( $type, $template ) = $notices[ $code ];

		$message = $template;
		if ( false !== strpos( $template, '%s' ) ) {
			$detail  = isset( $_GET['bits_notice_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['bits_notice_detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display, no state change.
			$message = sprintf( $template, $detail );
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( 'success' === $type ? 'success' : 'error' ),
			esc_html( $message )
		);
	}

	/**
	 * Redirects to the Details view for one member with a fixed-vocabulary
	 * notice code and exits.
	 *
	 * @param string $email  Member's email address.
	 * @param string $code   One of the keys in self::notices().
	 * @param string $detail Optional detail to interpolate into the notice template.
	 * @return void
	 * @codeCoverageIgnore Calls exit; cannot run inside the test process. Its pure input-building logic is trivial (array literal + add_query_arg).
	 */
	private static function redirect_with_notice( string $email, string $code, string $detail = '' ): void {
		$args = array( 'bits_notice' => $code );

		if ( '' !== $detail ) {
			$args['bits_notice_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, self::details_url( $email ) ) );
		exit;
	}

	/**
	 * Redirects back to whichever view the "Sync" button was submitted
	 * from (List, Details, or Add Groups), with a notice reporting how
	 * many queued actions were processed.
	 *
	 * @param array{count: int, view: string, member: string} $result process_sync()'s return value.
	 * @return void
	 * @codeCoverageIgnore Calls exit; cannot run inside the test process. Its pure input-building logic is trivial (array literal + add_query_arg).
	 */
	private static function redirect_after_sync( array $result ): void {
		$target = self::list_url();
		if ( self::VIEW_DETAILS === $result['view'] && '' !== $result['member'] ) {
			$target = self::details_url( $result['member'] );
		} elseif ( self::VIEW_ADD_GROUPS === $result['view'] && '' !== $result['member'] ) {
			$target = self::add_groups_url( $result['member'] );
		}

		$args = array( 'bits_notice' => $result['count'] > 0 ? 'jobs_processed' : 'no_jobs_due' );
		if ( $result['count'] > 0 ) {
			$args['bits_notice_detail'] = (string) $result['count'];
		}

		wp_safe_redirect( add_query_arg( $args, $target ) );
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
	 * Builds a member's Details view URL.
	 *
	 * @param string $email Member's email address.
	 * @return string
	 */
	private static function details_url( string $email ): string {
		return add_query_arg(
			array(
				'page'   => self::SLUG,
				'view'   => self::VIEW_DETAILS,
				'member' => rawurlencode( $email ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Builds a member's Add Groups view URL.
	 *
	 * @param string $email Member's email address.
	 * @return string
	 */
	private static function add_groups_url( string $email ): string {
		return add_query_arg(
			array(
				'page'   => self::SLUG,
				'view'   => self::VIEW_ADD_GROUPS,
				'member' => rawurlencode( $email ),
			),
			admin_url( 'admin.php' )
		);
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
