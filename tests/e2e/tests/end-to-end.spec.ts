import { test, expect } from '@playwright/test';

/**
 * The one full end-to-end path this project requires: a real login,
 * through the GroupsIO Management menu, to a full subgroup CRUD
 * lifecycle - each action a real "Groups.io update" as far as the
 * plugin's own code is concerned (browser -> WP admin -> nonce-checked
 * POST -> GroupsIoApiClient -> HTTP call -> response handling ->
 * read-back verification -> SubgroupIdCache invalidation -> redirect
 * -> notice). The Groups.io API call itself is served by the E2E mock
 * mu-plugin (tests/e2e/mu-plugins/groupsio-api-mock.php), not the real
 * groups.io - everything else in the path is real.
 *
 * Deliberately does not reuse the shared storageState other spec files
 * use, so this one test genuinely starts from "not logged in."
 *
 * Interactions are scoped to the specific subgroup's row container
 * (`.bits-groupsio-subgroup-row`) rather than page-wide locators - the
 * fixture subgroup seeded by the mock, and other rows created by other
 * spec files sharing this environment, also have a "New subgroup
 * name:" field and identically-labelled controls, so an unscoped
 * locator would be ambiguous.
 */
test.use( { storageState: { cookies: [], origins: [] } } );

test( 'login through a full subgroup create/rename/delete lifecycle', async ( { page } ) => {
	const timestamp = Date.now();
	const initialName = `e2e-created-${ timestamp }`;
	const renamedName = `e2e-renamed-${ timestamp }`;

	await test.step( 'log in', async () => {
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await expect( page ).toHaveURL( /wp-admin\/?$/ );
	} );

	await test.step( 'navigate to Subgroup Management via the GroupsIO Management menu', async () => {
		await page.getByRole( 'link', { name: 'GroupsIO Management' } ).click();
		await page.getByRole( 'link', { name: 'Subgroup Management' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Subgroup Management', exact: true } ) ).toBeVisible();
	} );

	await test.step( 'create a subgroup (a real Groups.io update)', async () => {
		await page.getByLabel( 'Subgroup name', { exact: true } ).fill( initialName );
		await page.getByRole( 'button', { name: 'Create Subgroup' } ).click();

		await expect( page.getByText( 'Subgroup created.' ) ).toBeVisible();
		await expect( page.locator( '.bits-groupsio-subgroup-row', { hasText: initialName } ) ).toBeVisible();
	} );

	const row = page.locator( '.bits-groupsio-subgroup-row', { hasText: initialName } );

	await test.step( 'view its (empty) member list', async () => {
		await row.getByRole( 'link', { name: /^View members for/ } ).click();
		await expect( row.getByText( 'No members.' ) ).toBeVisible();
	} );

	await test.step( 'rename it (another Groups.io update)', async () => {
		await row.getByLabel( 'New subgroup name:' ).fill( renamedName );
		await row.getByRole( 'button', { name: /^Rename/ } ).click();

		await expect( page.getByText( 'Subgroup renamed.' ) ).toBeVisible();
		await expect( page.locator( '.bits-groupsio-subgroup-row', { hasText: renamedName } ) ).toBeVisible();
		await expect( page.locator( '.bits-groupsio-subgroup-row', { hasText: initialName } ) ).toHaveCount( 0 );
	} );

	const renamedRow = page.locator( '.bits-groupsio-subgroup-row', { hasText: renamedName } );

	await test.step( 'delete it via the nonce-protected confirmation step (a final Groups.io update)', async () => {
		await renamedRow.getByRole( 'link', { name: /^Delete subgroup/ } ).click();
		await expect( page.getByText( /Are you sure you want to delete the subgroup/ ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Yes, delete this subgroup' } ).click();

		await expect( page.getByText( 'Subgroup deleted.' ) ).toBeVisible();
		await expect( page.locator( '.bits-groupsio-subgroup-row', { hasText: renamedName } ) ).toHaveCount( 0 );
	} );
} );
