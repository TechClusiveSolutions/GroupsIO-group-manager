import { test, expect } from '@playwright/test';

/**
 * Edge cases for #34 beyond the happy-path lifecycle already covered
 * end-to-end in end-to-end.spec.ts: the List view's content, field
 * validation, error surfacing, the Details view's inline delete
 * confirmation cancel path, and the seeded fixture's member list.
 * Relies on the mock's seeded fixture subgroup
 * (tests/e2e/mu-plugins/groupsio-api-mock.php), which persists for the
 * whole suite run.
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

	test( 'creating a subgroup with a name that already exists surfaces a plain-language error', async ( { page } ) => {
		await page.getByRole( 'link', { name: 'Create new subgroup' } ).click();
		await page.getByLabel( 'Name', { exact: true } ).fill( 'fixture-subgroup' );
		await page.getByRole( 'button', { name: 'Create', exact: true } ).click();

		await expect( page.getByText( /Could not create the subgroup/ ) ).toBeVisible();
		await expect( page.getByText( 'name already taken' ) ).toBeVisible();
		// Never a raw API error dump.
		await expect( page.getByText( '{"object"' ) ).toHaveCount( 0 );
	} );

	test( 'Details view shows current values, live members, and lets a title-only update through', async ( { page } ) => {
		await page.getByRole( 'link', { name: /fixture-subgroup@/ } ).click();

		await expect( page.getByRole( 'heading', { name: 'Subgroup Details' } ) ).toBeVisible();
		await expect( page.getByText( 'fixture-member-1@example.test' ) ).toBeVisible();
		await expect( page.getByText( 'fixture-member-2@example.test' ) ).toBeVisible();

		await page.getByLabel( 'Title (optional)' ).fill( 'Fixture Title' );
		await page.getByRole( 'button', { name: 'Update' } ).click();

		await expect( page.getByText( 'Subgroup updated.' ) ).toBeVisible();
		await expect( page.getByLabel( 'Title (optional)' ) ).toHaveValue( 'Fixture Title' );
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
		// Create a second subgroup so there are two links to distinguish between.
		const secondName = `distinct-row-${ Date.now() }`;
		await page.getByRole( 'link', { name: 'Create new subgroup' } ).click();
		await page.getByLabel( 'Name', { exact: true } ).fill( secondName );
		await page.getByRole( 'button', { name: 'Create', exact: true } ).click();
		await expect( page.getByText( 'Subgroup created.' ) ).toBeVisible();

		await expect( page.getByRole( 'link', { name: /fixture-subgroup@/ } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: new RegExp( `${ secondName }@` ) } ) ).toBeVisible();

		// Clean up so this test is repeatable within the same suite run.
		await page.getByRole( 'link', { name: new RegExp( `${ secondName }@` ) } ).click();
		await page.getByRole( 'link', { name: 'Delete this subgroup' } ).click();
		await page.getByRole( 'button', { name: 'Yes, delete this subgroup' } ).click();
		await expect( page.getByText( 'Subgroup deleted.' ) ).toBeVisible();
	} );
} );
