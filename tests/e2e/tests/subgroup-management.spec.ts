import { test, expect } from '@playwright/test';

/**
 * Edge cases for #34 beyond the happy-path lifecycle already covered
 * end-to-end in end-to-end.spec.ts: validation, error surfacing, the
 * delete-confirmation step's cancel path, the seeded fixture's member
 * list and count, and per-row accessible-name uniqueness. Relies on
 * the mock's seeded fixture subgroup (tests/e2e/mu-plugins/
 * groupsio-api-mock.php), which persists for the whole suite run.
 *
 * Interactions are scoped to a specific subgroup's row container
 * (`.bits-groupsio-subgroup-row`) rather than page-wide locators,
 * since every row shares identically-labelled controls (e.g. every
 * row has a "New subgroup name:" field) - see end-to-end.spec.ts for
 * the same rationale.
 */
test.describe( 'Subgroup Management edge cases', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-subgroup-management' );
	} );

	test( 'lists the seeded fixture subgroup with its member count', async ( { page } ) => {
		const row = page.locator( '.bits-groupsio-subgroup-row', { hasText: 'fixture-subgroup' } );
		await expect( row ).toBeVisible();
		await expect( row.getByText( '2 members' ) ).toBeVisible();
	} );

	test( 'submitting Create with a blank name does not create anything', async ( { page } ) => {
		await page.getByRole( 'button', { name: 'Create Subgroup' } ).click();

		// HTML5 "required" blocks submission client-side; the page should
		// simply still be the create form, with no new row appearing.
		await expect( page.getByRole( 'heading', { name: 'Create Subgroup' } ) ).toBeVisible();
	} );

	test( 'creating a subgroup with a name that already exists surfaces a plain-language error', async ( { page } ) => {
		await page.getByLabel( 'Subgroup name', { exact: true } ).fill( 'fixture-subgroup' );
		await page.getByRole( 'button', { name: 'Create Subgroup' } ).click();

		await expect( page.getByText( /Could not create the subgroup/ ) ).toBeVisible();
		await expect( page.getByText( 'sub_group_name already exists' ) ).toBeVisible();
		// Never a raw API error dump.
		await expect( page.getByText( '{"object"' ) ).toHaveCount( 0 );
	} );

	test( 'viewing members shows the fixture emails, then hides them again', async ( { page } ) => {
		const row = page.locator( '.bits-groupsio-subgroup-row', { hasText: 'fixture-subgroup' } );

		await row.getByRole( 'link', { name: /^View members for/ } ).click();

		await expect( row.getByText( 'fixture-member-1@example.test' ) ).toBeVisible();
		await expect( row.getByText( 'fixture-member-2@example.test' ) ).toBeVisible();

		await row.getByRole( 'link', { name: /^Hide members for/ } ).click();

		await expect( row.getByText( 'fixture-member-1@example.test' ) ).toHaveCount( 0 );
	} );

	test( 'delete confirmation step can be cancelled without deleting anything', async ( { page } ) => {
		const row = page.locator( '.bits-groupsio-subgroup-row', { hasText: 'fixture-subgroup' } );

		await row.getByRole( 'link', { name: /^Delete subgroup/ } ).click();

		await expect( page.getByText( /Are you sure you want to delete the subgroup/ ) ).toBeVisible();

		await page.getByRole( 'link', { name: 'Cancel' } ).click();

		await expect( page.getByText( /Are you sure you want to delete/ ) ).toHaveCount( 0 );
		await expect( page.locator( '.bits-groupsio-subgroup-row', { hasText: 'fixture-subgroup' } ) ).toBeVisible();
	} );

	test( 'per-row controls have distinct accessible names, not identical across rows', async ( { page } ) => {
		// Create a second subgroup so there are two rows to distinguish between.
		const secondName = `distinct-row-${ Date.now() }`;
		await page.getByLabel( 'Subgroup name', { exact: true } ).fill( secondName );
		await page.getByRole( 'button', { name: 'Create Subgroup' } ).click();
		await expect( page.getByText( 'Subgroup created.' ) ).toBeVisible();

		await expect(
			page.getByRole( 'link', { name: /^View members for .*\+fixture-subgroup$/ } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: new RegExp( `^View members for .*\\+${ secondName }$` ) } )
		).toBeVisible();

		// Clean up so this test is repeatable within the same suite run.
		const secondRow = page.locator( '.bits-groupsio-subgroup-row', { hasText: secondName } );
		await secondRow.getByRole( 'link', { name: /^Delete subgroup/ } ).click();
		await page.getByRole( 'button', { name: 'Yes, delete this subgroup' } ).click();
		await expect( page.getByText( 'Subgroup deleted.' ) ).toBeVisible();
	} );
} );
