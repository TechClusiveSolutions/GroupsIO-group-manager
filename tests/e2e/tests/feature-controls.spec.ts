import { test, expect } from '@playwright/test';

/**
 * Covers the Phase 1 Settings page as relocated to GroupsIO Management
 * > Feature Controls (#33) - pure WP-options storage, no Groups.io API
 * calls, so no mock is involved here.
 */
test.describe( 'Feature Controls', () => {
	test( 'every field has a native label and a visible description', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-feature-controls' );

		await expect( page.getByLabel( /Grace period/ ) ).toBeVisible();
		await expect( page.getByLabel( /Log retention/ ) ).toBeVisible();
		await expect( page.getByLabel( /Kill switch/ ) ).toBeVisible();
		await expect( page.getByLabel( /Global mandatory groups/ ) ).toBeVisible();

		const gracePeriodField = page.getByLabel( /Grace period/ );
		const describedBy = await gracePeriodField.getAttribute( 'aria-describedby' );
		expect( describedBy ).toBeTruthy();
		await expect( page.locator( `#${ describedBy }` ) ).toBeVisible();
	} );

	test( 'editing and saving a value persists it after reload', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-feature-controls' );

		const gracePeriodField = page.getByLabel( /Grace period/ );
		await gracePeriodField.fill( '7' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect( page.getByLabel( /Grace period/ ) ).toHaveValue( '7' );

		await page.reload();
		await expect( page.getByLabel( /Grace period/ ) ).toHaveValue( '7' );
	} );

	test( 'has a Reset button and a disabled, explained Run Dry-Run Now stub', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-feature-controls' );

		await expect( page.getByRole( 'button', { name: 'Reset' } ) ).toBeVisible();

		const dryRunButton = page.getByRole( 'button', { name: 'Run Dry-Run Now' } );
		await expect( dryRunButton ).toBeDisabled();

		const describedBy = await dryRunButton.getAttribute( 'aria-describedby' );
		expect( describedBy ).toBeTruthy();
		await expect( page.locator( `#${ describedBy }` ) ).toContainText( /not yet available/i );
	} );
} );
