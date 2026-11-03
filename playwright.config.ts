import path from 'path';
import { defineConfig, devices } from '@playwright/test';

const STORAGE_STATE_PATH = path.join( __dirname, 'tests/e2e/.auth/admin.json' );
// Read by @wordpress/e2e-test-utils-playwright fixtures (requestUtils).
process.env.STORAGE_STATE_PATH ||= STORAGE_STATE_PATH;
process.env.WP_BASE_URL ||= 'http://localhost:8889';

export default defineConfig( {
	testDir: 'tests/e2e',
	outputDir: 'test-results',
	snapshotPathTemplate: 'tests/e2e/__screenshots__/{testFilePath}/{arg}{-projectName}{-snapshotSuffix}{ext}',
	globalSetup: require.resolve( './tests/e2e/global-setup.ts' ),
	forbidOnly: !! process.env.CI,
	retries: 1,
	workers: 1,
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	expect: {
		toHaveScreenshot: { maxDiffPixelRatio: 0.01 },
	},
	use: {
		baseURL: process.env.WP_BASE_URL,
		storageState: STORAGE_STATE_PATH,
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } },
	],
} );
