import { test, expect } from '@playwright/test';

/**
 * Edge cases for #34 beyond the happy-path lifecycle already covered
 * end-to-end in end-to-end.spec.ts: the List view's content, field
 * validation, the Details view's inline delete confirmation cancel
 * path, and the seeded fixture's member list. Relies on the mock's
 * seeded fixture subgroup (tests/e2e/mu-plugins/groupsio-api-mock.php),
 * which persists for the whole suite run.
 *
 * Create/Update/Delete moved from synchronous to queued+retried
 * (SubgroupExecutionEngine, #50) - each submission now returns an
 * immediate "submitted" notice, and the actual Groups.io write happens
 * in a queued job. This suite uses the page's own Sync button to force
 * that job to run synchronously within the request, matching the same
 * scoping decision user-assignment-actions.spec.ts already established
 * for User Assignment's own queued actions: exercise the queued
 * redirect+notice and one forced-immediate execution via Sync, never a
 * queued job's eventual failure after retries (that's
 * SubgroupExecutionEngineTest's job, in PHPUnit, since simulating
 * Action Scheduler's real minute-scale retry backoff isn't practical
 * for a deterministic e2e run).
 */
test.describe( 'Subgroup Management edge cases', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-subgroup-management' );
	} );

	test( 'List view shows the parent address and the seeded fixture as a link', async ( { page } ) => {
		await expect( page.getByText( /Parent group: main@/ ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: /fixture-subgroup@/ } ) ).toBeVisible();
		await expect( page.getByText( '2 members' ) ).toBeVisible();
	} );

	test( 'submitting Create with a blank name does not create anything', async ( { page } ) => {
		await page.getByRole( 'link', { name: 'Create new subgroup' } ).click();
		await page.getByRole( 'button', { name: 'Create', exact: true } ).click();

		// HTML5 "required" blocks submission client-side; the page should
		// simply still be the create form.
		await expect( page.getByRole( 'heading', { name: 'Create Subgroup' } ) ).toBeVisible();
	} );

	test( 'submitting Create returns an immediate "submitted" notice, not a synchronous result', async ( { page } ) => {
		// #50: process_create() no longer validates the name against
		// Groups.io at all (that moved into the queued job) - even a
		// name that will eventually fail (already taken) still gets an
		// immediate "submitted" response here, not a synchronous error.
		await page.getByRole( 'link', { name: 'Create new subgroup' } ).click();
		await page.getByLabel( 'Name', { exact: true } ).fill( 'fixture-subgroup' );
		await page.getByRole( 'button', { name: 'Create', exact: true } ).click();

		await expect( page.getByText( 'Subgroup creation submitted' ) ).toBeVisible();
	} );

	test( 'Details view shows current values, live members, and a title-only update completes via Sync', async ( { page } ) => {
		await page.getByRole( 'link', { name: /fixture-subgroup@/ } ).click();

		await expect( page.getByRole( 'heading', { name: 'Subgroup Details' } ) ).toBeVisible();
		await expect( page.getByText( 'fixture-member-1@example.test' ) ).toBeVisible();
		await expect( page.getByText( 'fixture-member-2@example.test' ) ).toBeVisible();

		await page.getByLabel( 'Title (optional)' ).fill( 'Fixture Title' );
		await page.getByRole( 'button', { name: 'Update' } ).click();

		await expect( page.getByText( 'Subgroup update submitted' ) ).toBeVisible();
		// Not yet applied - the queued job hasn't run yet.
		await expect( page.getByLabel( 'Title (optional)' ) ).not.toHaveValue( 'Fixture Title' );

		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();
		await expect( page.getByLabel( 'Title (optional)' ) ).toHaveValue( 'Fixture Title' );

		// Clean up so this test is repeatable within the same suite run.
		await page.getByLabel( 'Title (optional)' ).fill( '' );
		await page.getByRole( 'button', { name: 'Update' } ).click();
		await page.getByRole( 'button', { name: 'Sync' } ).click();
	} );

	test( 'delete confirmation on the Details view can be cancelled without deleting anything', async ( { page } ) => {
		await page.getByRole( 'link', { name: /fixture-subgroup@/ } ).click();
		await page.getByRole( 'link', { name: 'Delete this subgroup' } ).click();

		await expect( page.getByText( /Are you sure you want to delete this subgroup/ ) ).toBeVisible();

		await page.getByRole( 'link', { name: 'Cancel' } ).click();

		await expect( page.getByText( /Are you sure you want to delete/ ) ).toHaveCount( 0 );
		await expect( page.getByRole( 'heading', { name: 'Subgroup Details' } ) ).toBeVisible();
	} );

	test( 'every subgroup link on the List view has a distinct accessible name', async ( { page } ) => {
		// Create a second subgroup so there are two links to distinguish
		// between - Sync forces the queued create to actually run so it
		// appears in the list within this same test.
		const secondName = `distinct-row-${ Date.now() }`;
		await page.getByRole( 'link', { name: 'Create new subgroup' } ).click();
		await page.getByLabel( 'Name', { exact: true } ).fill( secondName );
		await page.getByRole( 'button', { name: 'Create', exact: true } ).click();
		await expect( page.getByText( 'Subgroup creation submitted' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();

		await expect( page.getByRole( 'link', { name: /fixture-subgroup@/ } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: new RegExp( `${ secondName }@` ) } ) ).toBeVisible();

		// Clean up so this test is repeatable within the same suite run.
		await page.getByRole( 'link', { name: new RegExp( `${ secondName }@` ) } ).click();
		await page.getByRole( 'link', { name: 'Delete this subgroup' } ).click();
		await page.getByRole( 'button', { name: 'Yes, delete this subgroup' } ).click();
		await expect( page.getByText( 'Subgroup deletion submitted' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: new RegExp( `${ secondName }@` ) } ) ).toHaveCount( 0 );
	} );
} );
