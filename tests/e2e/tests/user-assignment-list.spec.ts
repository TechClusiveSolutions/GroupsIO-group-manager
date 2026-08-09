import { test, expect } from '@playwright/test';

/**
 * Covers #60: the User Assignment List view. Replaces the earlier
 * placeholder spec (user-assignment-placeholder.spec.ts, removed) now
 * that #33's placeholder text no longer exists.
 *
 * This environment's local member index isn't reset between runs the
 * way the mocked-subgroup option is (see global-setup.ts) - Action
 * Scheduler's hourly sync job can populate it from the e2e mock's
 * fixture data at any point a run happens to be alive long enough for
 * it to fire, so whether any member rows exist on a plain page load is
 * not deterministic here. This only asserts on what's genuinely
 * state-independent: the heading and the labeled search form always
 * render, and a search term that cannot possibly match anything real
 * always produces the empty-index message regardless of what the
 * index otherwise contains. Row-level content (name/email/group count,
 * real matches, pagination) is covered by UserAssignmentPageTest
 * (PHPUnit), which seeds the index directly and isn't subject to this
 * timing issue.
 */
test.describe( 'User Assignment List view', () => {
	test( 'loads with a heading and a labeled search form', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-user-assignment' );

		await expect( page.getByRole( 'heading', { name: 'User Assignment' } ) ).toBeVisible();
		await expect( page.getByRole( 'searchbox', { name: /search members or groups/i } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Search' } ) ).toBeVisible();
	} );

	test( 'a search submitted with no possible matches shows the empty-index message', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-user-assignment' );

		await page.getByRole( 'searchbox', { name: /search members or groups/i } ).fill( 'nobody-matches-this-e2e-search-term' );
		await page.getByRole( 'button', { name: 'Search' } ).click();

		await expect( page ).toHaveURL( /s=nobody-matches-this-e2e-search-term/ );
		await expect( page.getByText( 'No members found.' ) ).toBeVisible();
	} );
} );
