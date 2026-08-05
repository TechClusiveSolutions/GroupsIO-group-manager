import { defineConfig, devices } from '@playwright/test';

/**
 * E2E config for the BITS Groups.io Group Manager admin UI. Runs against
 * an already-started wp-env dev instance (see tests/e2e/README.md) - this
 * config does not itself start/stop wp-env, since its Docker lifecycle is
 * already managed by the same scripts CI and local development use.
 *
 * Auth is done once in globalSetup and reused via storageState, per
 * Playwright's recommended pattern, rather than logging in inside every
 * test file.
 */
export default defineConfig({
	testDir: './tests/e2e/tests',
	globalSetup: require.resolve( './tests/e2e/global-setup.ts' ),
	fullyParallel: false, // Tests share one wp-env instance's mock state; run serially to avoid cross-test interference.
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		storageState: './tests/e2e/.auth/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
