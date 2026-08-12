import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';

/**
 * Covers the state-changing actions on User Assignment's Details and Add
 * Groups views (#61/#62), the Sync control (#83), and the group-owner
 * parent-removal safeguard (#89) - the real gap flagged on #44: neither
 * of these action flows had any e2e coverage before this file, which is
 * exactly why #78 (forms rendering a blank page on submit) and the
 * dev/e2e mock's missing directadd/removemember support both slipped
 * past CI.
 *
 * Deliberately scoped to the *queued* redirect+notice only, never to a
 * queued job's actual async completion - Action Scheduler's own
 * execution timing is not something this suite takes on as a concern
 * (see docs/testing-standard.md section 4; PHPUnit's
 * QueuedExecutionLifecycleIntegrationTest already covers real
 * schedule -> execute round trips against the live test Groups.io
 * group). This keeps every test here deterministic without waiting on
 * or forcing Action Scheduler's queue runner.
 *
 * MemberIndex only ever populates itself from the local index (never
 * live-aggregated on page load) and its state doesn't reset between
 * spec files the way the mock's own subgroup option does (see
 * user-assignment-list.spec.ts's own comment on this) - each test here
 * seeds and cleans up its own rows via direct WP-CLI `wp eval` calls
 * (matching global-setup.ts's own execFileSync pattern) rather than
 * relying on any pre-existing fixture state, so this file has no
 * dependency on run order relative to other spec files. Safe to do
 * unconditionally: playwright.config.ts runs with fullyParallel:false
 * and workers:1, so no other spec is ever running concurrently.
 */

const PARENT_SLUG = 'bits-local-dev';

function wpEval( code: string ): void {
	execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', '--', 'wp', 'eval', code ],
		{ stdio: 'pipe', shell: process.platform === 'win32' }
	);
}

function resetMemberIndex(): void {
	wpEval( 'global $wpdb; $wpdb->query( "TRUNCATE TABLE " . \\BITS\\GroupsIOSync\\MemberIndex::table_name() );' );
}

function seedGroup( email: string, displayName: string, subgroupId: number, subgroupSlug: string, subgroupTitle: string ): void {
	wpEval(
		`\\BITS\\GroupsIOSync\\MemberIndex::apply_add( 0, '${ email }', '${ displayName }', ${ subgroupId }, '${ subgroupSlug }', '${ subgroupTitle }', 1 );`
	);
}

// apply_add() has no is_owner parameter (that flag only ever comes from a
// real sync() reading get_members()'s mod_status) - so marking a seeded row
// as the owner needs a direct write against the table, matching how
// resetMemberIndex() also reaches straight into $wpdb for the same reason.
function markOwnerOfParent( email: string ): void {
	wpEval(
		'global $wpdb; ' +
			`$wpdb->update( \\BITS\\GroupsIOSync\\MemberIndex::table_name(), array( 'is_owner' => 1 ), array( 'email' => '${ email }', 'subgroup_slug' => '${ PARENT_SLUG }' ) );`
	);
}

test.describe( 'User Assignment Details/Add Groups actions', () => {
	test.beforeEach( () => {
		resetMemberIndex();
	} );

	test.afterEach( () => {
		resetMemberIndex();
	} );

	test( 'Remove Selected on a non-parent group redirects with a queued confirmation, not a blank page', async ( { page } ) => {
		const email = 'e2e-remove-target@example.test';
		seedGroup( email, 'E2E Remove Target', 500001, PARENT_SLUG, '' );
		seedGroup( email, 'E2E Remove Target', 500002, `${ PARENT_SLUG }+e2e-list`, 'E2E List' );

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=details&member=${ encodeURIComponent( email ) }` );

		await page.getByRole( 'checkbox', { name: /E2E List/i } ).check();
		await page.getByRole( 'button', { name: 'Remove Selected' } ).click();

		await expect( page ).toHaveURL( /bits_notice=removed_queued/ );
		await expect( page.getByText( '1 group removal(s) queued.' ) ).toBeVisible();
	} );

	test( 'checking the parent group and Remove Selected shows an inline confirmation, not an immediate removal', async ( { page } ) => {
		const email = 'e2e-parent-remove-target@example.test';
		seedGroup( email, 'E2E Parent Target', 500003, PARENT_SLUG, '' );

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=details&member=${ encodeURIComponent( email ) }` );

		await page.getByRole( 'checkbox', { name: new RegExp( PARENT_SLUG ) } ).check();
		await page.getByRole( 'button', { name: 'Remove Selected' } ).click();

		await expect( page ).toHaveURL( /confirm_remove_parent=/ );
		await expect( page.getByText( /removes this member from all of BITS/ ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Yes, remove from the parent group' } ).click();

		await expect( page ).toHaveURL( /bits_notice=removed_queued/ );
		await expect( page.getByText( '1 group removal(s) queued.' ) ).toBeVisible();
	} );

	test( 'cancelling the parent-removal confirmation leaves the group unchanged', async ( { page } ) => {
		const email = 'e2e-parent-cancel-target@example.test';
		seedGroup( email, 'E2E Parent Cancel', 500004, PARENT_SLUG, '' );

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=details&member=${ encodeURIComponent( email ) }` );

		await page.getByRole( 'checkbox', { name: new RegExp( PARENT_SLUG ) } ).check();
		await page.getByRole( 'button', { name: 'Remove Selected' } ).click();
		await expect( page.getByText( /removes this member from all of BITS/ ) ).toBeVisible();

		await page.getByRole( 'link', { name: 'Cancel' } ).click();

		await expect( page ).not.toHaveURL( /confirm_remove_parent=/ );
		await expect( page.getByRole( 'checkbox', { name: new RegExp( PARENT_SLUG ) } ) ).toBeVisible();
	} );

	test( 'checking the parent group for the group owner is blocked outright, with no confirmation step', async ( { page } ) => {
		const email = 'e2e-owner-remove-target@example.test';
		seedGroup( email, 'E2E Owner Target', 500008, PARENT_SLUG, '' );
		markOwnerOfParent( email );

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=details&member=${ encodeURIComponent( email ) }` );

		await page.getByRole( 'checkbox', { name: new RegExp( PARENT_SLUG ) } ).check();
		await page.getByRole( 'button', { name: 'Remove Selected' } ).click();

		await expect( page ).toHaveURL( /bits_notice=owner_removal_blocked/ );
		await expect( page ).not.toHaveURL( /confirm_remove_parent=/ );
		await expect( page.getByText( "The group owner can't be removed from the parent group." ) ).toBeVisible();

		// Confirm nothing was actually queued: the parent row is still
		// present and still checkable, not silently dropped from the list.
		await expect( page.getByRole( 'checkbox', { name: new RegExp( PARENT_SLUG ) } ) ).toBeVisible();
	} );

	test( 'the owner-removal safeguard is scoped to the parent group only - removing the owner from a subgroup is still allowed', async ( { page } ) => {
		const email = 'e2e-owner-subgroup-remove-target@example.test';
		seedGroup( email, 'E2E Owner Subgroup Target', 500009, PARENT_SLUG, '' );
		seedGroup( email, 'E2E Owner Subgroup Target', 500010, `${ PARENT_SLUG }+e2e-owner-list`, 'E2E Owner List' );
		markOwnerOfParent( email );

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=details&member=${ encodeURIComponent( email ) }` );

		await page.getByRole( 'checkbox', { name: /E2E Owner List/i } ).check();
		await page.getByRole( 'button', { name: 'Remove Selected' } ).click();

		await expect( page ).toHaveURL( /bits_notice=removed_queued/ );
		await expect( page.getByText( '1 group removal(s) queued.' ) ).toBeVisible();
	} );

	test( 'Add Selected on the Add Groups view redirects to Details with a queued confirmation', async ( { page } ) => {
		const email = 'e2e-add-target@example.test';
		// Seed the group in the universe (via a different member) so it's
		// addable, without the target member already being in it.
		seedGroup( 'e2e-add-seed@example.test', 'Seed', 500005, `${ PARENT_SLUG }+e2e-addable`, 'E2E Addable' );

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=add-groups&member=${ encodeURIComponent( email ) }` );

		await page.getByRole( 'checkbox', { name: /E2E Addable/i } ).check();
		await page.getByRole( 'button', { name: 'Add Selected' } ).click();

		await expect( page ).toHaveURL( /view=details/ );
		await expect( page ).toHaveURL( /bits_notice=added_queued/ );
		await expect( page.getByText( '1 group addition(s) queued.' ) ).toBeVisible();
	} );

	test( 'Clear override removes the override flag from a single row', async ( { page } ) => {
		const email = 'e2e-clear-override-target@example.test';
		seedGroup( email, 'E2E Clear Override', 500006, PARENT_SLUG, '' );
		// apply_add() already sets override_type = 'added' on the seeded row.

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=details&member=${ encodeURIComponent( email ) }` );

		await expect( page.getByText( 'Manually added' ) ).toBeVisible();

		await page.getByRole( 'link', { name: /Clear override/i } ).click();

		await expect( page.getByText( 'Override cleared.' ) ).toBeVisible();
		await expect( page.getByText( 'Manually added' ) ).toHaveCount( 0 );
	} );

	test( 'the Sync button is present and shows a notice on every User Assignment view', async ( { page } ) => {
		const email = 'e2e-sync-target@example.test';
		seedGroup( email, 'E2E Sync Target', 500007, PARENT_SLUG, '' );

		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-user-assignment' );
		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=details&member=${ encodeURIComponent( email ) }` );
		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();

		await page.goto( `/wp-admin/admin.php?page=bits-groupsio-user-assignment&view=add-groups&member=${ encodeURIComponent( email ) }` );
		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();
	} );

	test( 'the Sync button is present on Subgroup Management\'s List view too', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=bits-groupsio-subgroup-management' );
		await page.getByRole( 'button', { name: 'Sync' } ).click();
		await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();
	} );
} );
