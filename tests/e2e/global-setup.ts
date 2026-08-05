import { execFileSync } from 'node:child_process';
import { chromium, type FullConfig } from '@playwright/test';

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';
const STORAGE_STATE_PATH = './tests/e2e/.auth/admin.json';

/**
 * Runs once before the whole suite: resets the E2E mock's subgroup
 * state and sets a known admin password (both via wp-env's own
 * WP-CLI, the same tool CI and local dev already use - not a raw DB
 * query), so this suite doesn't depend on wp-env's own default
 * credentials being any particular value on a fresh instance. Then
 * logs in once and saves the session as storageState for every test
 * file to reuse.
 */
export default async function globalSetup( config: FullConfig ): Promise<void> {
	resetMockState();
	setAdminPassword();

	const baseURL = config.projects[ 0 ]?.use?.baseURL ?? 'http://localhost:8888';

	const browser = await chromium.launch();
	const page = await browser.newPage();

	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin\/?$/ );

	await page.context().storageState( { path: STORAGE_STATE_PATH } );
	await browser.close();
}

function resetMockState(): void {
	try {
		execFileSync(
			'npx',
			[ 'wp-env', 'run', 'cli', '--', 'wp', 'option', 'delete', 'bits_groupsio_e2e_mock_subgroups' ],
			{ stdio: 'pipe', shell: process.platform === 'win32' }
		);
	} catch ( error ) {
		// wp option delete exits non-zero if the option doesn't exist yet
		// (fresh environment, never seeded) - that's a normal starting
		// state, not a setup failure, so swallow it here.
	}
}

function setAdminPassword(): void {
	execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', '--', 'wp', 'user', 'update', ADMIN_USER, `--user_pass=${ ADMIN_PASS }` ],
		{ stdio: 'pipe', shell: process.platform === 'win32' }
	);
}
