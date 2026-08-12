import { test, expect } from '@playwright/test';

/**
 * The one full end-to-end path this project requires: a real login,
 * through the GroupsIO Management menu, to a full subgroup lifecycle
 * across all three Subgroup Management views (List, Create, Details) -
 * each action a real "Groups.io update" as far as the plugin's own
 * code is concerned (browser -> WP admin -> nonce-checked POST ->
 * queued via SubgroupExecutionEngine -> forced to run immediately via
 * the Sync control -> GroupsIoApiClient -> HTTP call -> response
 * handling -> SubgroupIdCache invalidation -> redirect -> notice).
 * The Groups.io API call itself is served by the E2E mock mu-plugin
 * (tests/e2e/mu-plugins/groupsio-api-mock.php), not the real
 * groups.io - everything else in the path is real, including the real
 * Action Scheduler queue/execute round trip triggered by Sync (#50).
 *
 * Deliberately does not reuse the shared storageState other spec files
 * use, so this one test genuinely starts from "not logged in."
 */
test.use( { storageState: { cookies: [], origins: [] } } );

test( 'login through a full subgroup create/update/delete lifecycle', async ( { page } ) => {
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

	await test.step( 'go to Create Subgroup, create one, and Sync to force the queued create through (a real Groups.io update)', async () => {
		await page.getByRole( 'link', { name: 'Create new subgroup' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Create Subgroup' } ) ).toBeVisible();

		await page.getByLabel( 'Name', { exact: true } ).fill( initialName );
		await page.getByRole( 'button', { name: 'Create', exact: true } ).click();

		await expect( page.getByText( 'Subgroup creation submitted' ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: new RegExp( `${ initialName }@` ) } ) ).toHaveCount( 0 );

		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed/ ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: new RegExp( `${ initialName }@` ) } ) ).toBeVisible();
	} );

	await test.step( 'open its Details view and view its member list (the API account is auto-added as owner on creation)', async () => {
		await page.getByRole( 'link', { name: new RegExp( `${ initialName }@` ) } ).click();
		await expect( page.getByRole( 'heading', { name: 'Subgroup Details' } ) ).toBeVisible();
		await expect( page.getByText( 'e2e-owner@example.test' ) ).toBeVisible();
	} );

	await test.step( 'rename it via the Details view and Sync to force the queued update through (another Groups.io update)', async () => {
		await page.getByLabel( 'Name', { exact: true } ).fill( renamedName );
		await page.getByRole( 'button', { name: 'Update' } ).click();

		await expect( page.getByText( 'Subgroup update submitted' ) ).toBeVisible();
		await expect( page.getByLabel( 'Name', { exact: true } ) ).not.toHaveValue( renamedName );

		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed/ ) ).toBeVisible();
		await expect( page.getByLabel( 'Name', { exact: true } ) ).toHaveValue( renamedName );
	} );

	await test.step( 'delete it via the inline confirmation and Sync to force the queued delete through (a final Groups.io update)', async () => {
		await page.getByRole( 'link', { name: 'Delete this subgroup' } ).click();
		await expect( page.getByText( /Are you sure you want to delete this subgroup/ ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Yes, delete this subgroup' } ).click();

		await expect( page.getByText( 'Subgroup deletion submitted' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed/ ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: new RegExp( `${ renamedName }@` ) } ) ).toHaveCount( 0 );
	} );
} );
