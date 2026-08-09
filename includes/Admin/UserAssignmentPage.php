<?php
/**
 * GroupsIO Management > User Assignment admin page.
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync\Admin;

use BITS\GroupsIOSync\MemberIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List view only in this pass (#60) - the Details and Add Groups views
 * (#61/#62) are separate units of work, per
 * subgroup-crud-and-admin-pages-design.md section 12. This is the
 * default landing page for the "GroupsIO Management" top-level menu.
 *
 * Read-only: no add/remove/queued actions live on this page (those are
 * on the Details and Add Groups views), so unlike SubgroupManagementPage
 * this class has no POST handling at all - only a GET-based search and
 * pagination.
 */
final class UserAssignmentPage {

	public const SLUG = 'bits-groupsio-user-assignment';

	private const PER_PAGE = 20;

	/**
	 * Renders the page. Callback for add_menu_page()/add_submenu_page().
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'User Assignment', 'bits-groupsio-sync' ) . '</h1>';

		self::render_list_view();

		echo '</div>';
	}

	/**
	 * Renders the paginated, searchable member table, per section 12's
	 * List view spec. Search matches against member name/email or
	 * subgroup name/slug (MemberIndex::search_where() does the actual
	 * matching); pagination and the search term round-trip through GET
	 * query args (`s`, `paged`) - this page is read-only, so a GET form
	 * is correct here (contrast with SubgroupManagementPage's POST
	 * actions).
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
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'bits-groupsio-sync' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders the member table itself. Each member's name (falling back
	 * to their email, if no display name is on file) links to the
	 * Details view (#61) - not yet built in this pass, but the link
	 * target already matches the URL scheme section 12 specifies for it.
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
					$details_url  = add_query_arg(
						array(
							'page'   => self::SLUG,
							'view'   => 'details',
							'member' => rawurlencode( $member['email'] ),
						),
						admin_url( 'admin.php' )
					);
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
}
