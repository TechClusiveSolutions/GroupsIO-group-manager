import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';

/**
 * A full member-lifecycle walkthrough spanning both admin surfaces this
 * plugin introduces: log in, create a subgroup via Subgroup Management,
 * then use User Assignment to add a real member to it, remove them from
 * it, remove them from the parent group entirely, and finally re-add
 * them to just the parent group. Every state-changing action here is
 * asynchronous (QueuedExecutionEngine) - the Sync button is pressed
 * after each one to force the queued job to actually execute before the
 * next step depends on its result, exactly as an admin doing this by
 * hand would need to.
 *
 * Deliberately verifies at two independent layers after the add step,
 * per the primary contributor's own request: member-level (User
 * Assignment's Details view, backed by the local MemberIndex) and
 * group-level (Subgroup Management's Details view, which reads the
 * subgroup's live member list straight from Groups.io on every load -
 * served here by the E2E mock mu-plugin, not the real API). Confirming
 * both agree is a stronger check than either alone: MemberIndex could
 * drift from what Groups.io actually reports, and Subgroup
 * Management's own live read wouldn't catch that on its own since nothing
 * else in this test suite cross-checks it against the local index.
 *
 * Uses a freshly seeded member (rather than the shared
 * fixture-member-1/2, which are already members of the suite's own
 * seeded fixture-subgroup for the whole run - reusing either would make
 * "verify the member is in no groups" below false regardless of what
 * this test does) with exactly one starting row: the parent group,
 * matching what a real MemberIndex::reconcile_parent_membership() add
 * would have produced. Seeded/cleaned up directly via `wp eval`
 * (matching user-assignment-actions.spec.ts's own established pattern),
 * and a freshly created, uniquely named subgroup, so this test is
 * repeatable and independent of other spec files' state.
 *
 * Deliberately does not reuse the shared storageState other spec files
 * use, so this test genuinely starts from "not logged in," matching
 * end-to-end.spec.ts's own convention for the one full login-based path.
 */
test.use( { storageState: { cookies: [], origins: [] } } );

const PARENT_SLUG = 'bits-local-dev';
// The E2E mock's fixed getgroup() id for the parent group (see
// tests/e2e/mu-plugins/groupsio-api-mock.php's getgroup case) - stable
// for the whole suite run.
const PARENT_ID = 999999;

function wpEval( code: string ): void {
	execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', '--', 'wp', 'eval', code ],
		{ stdio: 'pipe', shell: process.platform === 'win32' }
	);
}

function seedMemberInParentOnly( email: string, displayName: string ): void {
	wpEval(
		`\\BITS\\GroupsIOSync\\MemberIndex::apply_add( 0, '${ email }', '${ displayName }', ${ PARENT_ID }, '${ PARENT_SLUG }', '', 1 );`
	);

	// The mock tracks parent-group membership separately from the local
	// MemberIndex row above (see groupsio-api-mock.php's parent_members
	// list) - seeding it here too means the first Sync below resolves a
	// real member_info_id for this member via a genuine getmembers()
	// read-back, exactly as it would for a real member, so a later
	// removal from the parent group is a real, verifiable API call
	// rather than one that can only ever fail for lack of a target id.
	wpEval(
		'$state = bits_e2e_mock_get_state(); ' +
			`$state['parent_members'][] = array( 'id' => 800001, 'email' => '${ email }', 'full_name' => '${ displayName }' ); ` +
			'bits_e2e_mock_save_state( $state );'
	);
}

function deleteMemberRows( email: string ): void {
	wpEval(
		'global $wpdb; ' +
			`$wpdb->delete( \\BITS\\GroupsIOSync\\MemberIndex::table_name(), array( 'email' => '${ email }' ) );`
	);

	wpEval(
		'$state = bits_e2e_mock_get_state(); ' +
			`$state['parent_members'] = array_values( array_filter( $state['parent_members'], static function ( $m ) { return $m['email'] !== '${ email }'; } ) ); ` +
			'bits_e2e_mock_save_state( $state );'
	);
}

test( 'full member lifecycle: create a subgroup, add/remove a member, remove/re-add them at the parent level', async ( { page } ) => {
	// This walkthrough covers substantially more ground than any other
	// single spec in this suite (13 steps, several full page loads, and
	// four separate Sync round-trips) - the project-wide 60s default
	// (playwright.config.ts) is not enough for it specifically.
	test.setTimeout( 120_000 );

	const timestamp = Date.now();
	const subgroupName = `e2e-lifecycle-${ timestamp }`;
	const memberEmail = `e2e-lifecycle-member-${ timestamp }@example.test`;
	const memberDisplayName = 'E2E Lifecycle Member';

	seedMemberInParentOnly( memberEmail, memberDisplayName );

	try {
		await test.step( 'log in', async () => {
			await page.goto( '/wp-login.php' );
			await page.fill( '#user_login', 'admin' );
			await page.fill( '#user_pass', 'password' );
			await page.click( '#wp-submit' );
			await expect( page ).toHaveURL( /wp-admin\/?$/ );
		} );

		await test.step( 'navigate to Subgroup Management and list all groups', async () => {
			await page.getByRole( 'link', { name: 'GroupsIO Management' } ).click();
			await page.getByRole( 'link', { name: 'Subgroup Management' } ).click();
			await expect( page.getByRole( 'heading', { name: 'Subgroup Management', exact: true } ) ).toBeVisible();
		} );

		await test.step( 'add a subgroup', async () => {
			await page.getByRole( 'link', { name: 'Create new subgroup' } ).click();
			await expect( page.getByRole( 'heading', { name: 'Create Subgroup' } ) ).toBeVisible();

			await page.getByLabel( 'Name', { exact: true } ).fill( subgroupName );
			await page.getByRole( 'button', { name: 'Create (Alt+C)', exact: true } ).click();

			await expect( page.getByText( 'Subgroup created.' ) ).toBeVisible();
		} );

		await test.step( 'verify the subgroup was added', async () => {
			await expect( page.getByRole( 'link', { name: new RegExp( `${ subgroupName }@` ) } ) ).toBeVisible();
		} );

		await test.step( 'navigate to User Assignment, sync, and list all members', async () => {
			await page.getByRole( 'link', { name: 'User Assignment' } ).click();
			await expect( page.getByRole( 'heading', { name: 'User Assignment' } ) ).toBeVisible();

			// Forces MemberIndex::sync() to run immediately (rather than
			// waiting on its hourly schedule), so the subgroup just
			// created above is reflected in the local index the Add
			// Groups view below reads from (i.e. it becomes addable).
			await page.getByRole( 'button', { name: 'Sync' } ).click();
			await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();

			await page.getByLabel( 'Search members or groups' ).fill( memberEmail );
			await page.getByRole( 'button', { name: 'Search' } ).click();
			await expect( page.getByRole( 'link', { name: memberDisplayName } ) ).toBeVisible();
		} );

		await test.step( 'open the member\'s Details page', async () => {
			await page.getByRole( 'link', { name: memberDisplayName } ).click();
			await expect( page.getByRole( 'heading', { name: new RegExp( `User Assignment: ${ memberDisplayName }` ) } ) ).toBeVisible();
		} );

		await test.step( 'add the member to the newly created group', async () => {
			await page.getByRole( 'link', { name: 'Add groups' } ).click();
			await expect( page.getByRole( 'heading', { name: new RegExp( `Add Groups: ${ memberDisplayName }` ) } ) ).toBeVisible();

			await page.getByRole( 'checkbox', { name: new RegExp( subgroupName ) } ).check();
			await page.getByRole( 'button', { name: 'Add Selected' } ).click();

			await expect( page ).toHaveURL( /view=details/ );
			await expect( page.getByText( '1 group addition(s) queued.' ) ).toBeVisible();

			// Forces the queued add job to actually execute (a real
			// directadd call, per the E2E mock) before verifying it below.
			await page.getByRole( 'button', { name: 'Sync' } ).click();
			await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();
		} );

		await test.step( 'verify the member was added, at the member level', async () => {
			await expect( page.getByRole( 'checkbox', { name: new RegExp( subgroupName ) } ) ).toBeVisible();
		} );

		await test.step( 'verify the member was added, at the group level (Subgroup Management)', async () => {
			await page.getByRole( 'link', { name: 'GroupsIO Management' } ).click();
			await page.getByRole( 'link', { name: 'Subgroup Management' } ).click();
			await page.getByRole( 'link', { name: new RegExp( `${ subgroupName }@` ) } ).click();

			await expect( page.getByRole( 'heading', { name: 'Subgroup Details' } ) ).toBeVisible();
			await expect( page.getByText( memberEmail, { exact: true } ) ).toBeVisible();
		} );

		await test.step( 'return to the member\'s Details page', async () => {
			await page.getByRole( 'link', { name: 'GroupsIO Management' } ).click();
			await page.getByRole( 'link', { name: 'User Assignment' } ).click();
			await page.getByLabel( 'Search members or groups' ).fill( memberEmail );
			await page.getByRole( 'button', { name: 'Search' } ).click();
			await page.getByRole( 'link', { name: memberDisplayName } ).click();
			await expect( page.getByRole( 'heading', { name: new RegExp( `User Assignment: ${ memberDisplayName }` ) } ) ).toBeVisible();
		} );

		await test.step( 'remove the member from the newly created group', async () => {
			await page.getByRole( 'checkbox', { name: new RegExp( subgroupName ) } ).check();
			await page.getByRole( 'button', { name: 'Remove Selected' } ).click();

			await expect( page.getByText( '1 group removal(s) queued.' ) ).toBeVisible();

			await page.getByRole( 'button', { name: 'Sync' } ).click();
			await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();

			await expect( page.getByRole( 'checkbox', { name: new RegExp( subgroupName ) } ) ).toHaveCount( 0 );
		} );

		await test.step( 'remove the member from the parent group', async () => {
			// This member has exactly one row left at this point (the
			// parent), so the checkbox labeled with the exact "slug
			// (slug)" text unambiguously identifies it.
			await page.getByRole( 'checkbox', { name: `Select ${ PARENT_SLUG } (${ PARENT_SLUG })`, exact: true } ).check();
			await page.getByRole( 'button', { name: 'Remove Selected' } ).click();

			await expect( page.getByText( /removes this member from all of BITS/ ) ).toBeVisible();
			await page.getByRole( 'button', { name: 'Yes, remove from the parent group' } ).click();

			await expect( page.getByText( '1 group removal(s) queued.' ) ).toBeVisible();

			await page.getByRole( 'button', { name: 'Sync' } ).click();
			await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();
		} );

		await test.step( 'verify the member is in no groups', async () => {
			await expect( page.getByText( 'No currently subscribed groups found.' ) ).toBeVisible();
		} );

		await test.step( 'add the member to the parent group', async () => {
			await page.getByRole( 'link', { name: 'Add groups' } ).click();
			await expect( page.getByRole( 'heading', { name: new RegExp( `Add Groups: ${ memberDisplayName }` ) } ) ).toBeVisible();

			await page.getByRole( 'checkbox', { name: `Select ${ PARENT_SLUG } (${ PARENT_SLUG })`, exact: true } ).check();
			await page.getByRole( 'button', { name: 'Add Selected' } ).click();

			await expect( page.getByText( '1 group addition(s) queued.' ) ).toBeVisible();

			await page.getByRole( 'button', { name: 'Sync' } ).click();
			await expect( page.getByText( /queued action\(s\) processed|No queued actions were due/ ) ).toBeVisible();
		} );

		await test.step( 'verify the member is only in the parent group', async () => {
			await expect( page.getByRole( 'checkbox', { name: `Select ${ PARENT_SLUG } (${ PARENT_SLUG })`, exact: true } ) ).toBeVisible();
			await expect( page.getByRole( 'checkbox', { name: new RegExp( subgroupName ) } ) ).toHaveCount( 0 );
			await expect( page.getByText( 'No currently subscribed groups found.' ) ).toHaveCount( 0 );
		} );
	} finally {
		deleteMemberRows( memberEmail );
	}
} );
