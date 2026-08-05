import { test, expect } from '@playwright/test';

/**
 * Covers #33: the "GroupsIO Management" top-level menu and its three
 * submenu pages. Uses the shared logged-in storageState (see
 * global-setup.ts) rather than logging in itself - end-to-end.spec.ts
 * is this project's one dedicated fresh-login test.
 */
test.describe( 'GroupsIO Management admin menu', () => {
	test( 'shows three submenu items under the top-level menu', async ( { page } ) => {
		await page.goto( '/wp-admin/' );

		const menu = page.locator( '#toplevel_page_bits-groupsio-user-assignment' );
		await expect( menu.getByRole( 'link', { name: 'GroupsIO Management' } ) ).toBeVisible();
		await expect( menu.getByRole( 'link', { name: 'User Assignment' } ) ).toBeVisible();
		await expect( menu.getByRole( 'link', { name: 'Feature Controls' } ) ).toBeVisible();
		await expect( menu.getByRole( 'link', { name: 'Subgroup Management' } ) ).toBeVisible();
	} );

	test( 'clicking the top-level menu item lands on User Assignment', async ( { page } ) => {
		await page.goto( '/wp-admin/' );
		await page
			.locator( '#toplevel_page_bits-groupsio-user-assignment' )
			.getByRole( 'link', { name: 'GroupsIO Management', exact: true } )
			.click();

		await expect( page ).toHaveURL( /page=bits-groupsio-user-assignment/ );
		await expect( page.getByRole( 'heading', { name: 'User Assignment' } ) ).toBeVisible();
	} );

	test( 'Feature Controls page loads the relocated Phase 1 settings form', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-feature-controls' );

		await expect( page.getByRole( 'heading', { name: 'Feature Controls' } ) ).toBeVisible();
		await expect( page.locator( 'form[action="options.php"]' ) ).toBeVisible();
	} );

	test( 'Subgroup Management page loads on its List view', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-subgroup-management' );

		await expect( page.getByRole( 'heading', { name: 'Subgroup Management', exact: true } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Create new subgroup' } ) ).toBeVisible();
	} );
} );
