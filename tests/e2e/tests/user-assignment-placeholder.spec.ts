import { test, expect } from '@playwright/test';

/**
 * User Assignment (#35) hasn't been built yet - this only covers what
 * actually exists today: the placeholder page registered by #33's menu
 * scaffold. Replace/expand once #35 lands.
 */
test( 'User Assignment shows its not-yet-available placeholder', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=bits-groupsio-user-assignment' );

	await expect( page.getByRole( 'heading', { name: 'User Assignment' } ) ).toBeVisible();
	await expect( page.getByText( /not yet available/i ) ).toBeVisible();
} );
