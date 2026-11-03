import { execSync } from 'child_process';
import path from 'path';
import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Logs in as admin, saves the storage state, enables example blocks and
 * marks the setup wizard completed.
 */
export default async function globalSetup( config: FullConfig ) {
	const { baseURL, storageState } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string'
			? storageState
			: path.join( __dirname, '.auth/admin.json' );

	const requestUtils = await RequestUtils.setup( {
		baseURL,
		storageStatePath,
	} );
	await requestUtils.setupRest();

	const cwd = path.join( __dirname, '../..' );

	// Enable the bundled example blocks (card, hero, ...) for front-end tests.
	execSync(
		'npx wp-env run tests-cli wp option update proto_blocks_example_blocks 1',
		{ stdio: 'inherit', cwd }
	);

	// Until the wizard is completed every ?page=proto-blocks* request is
	// redirected to it, so the screen smoke tests would only ever see the
	// wizard. The wizard test deletes and restores this itself.
	execSync(
		'npx wp-env run tests-cli wp option update proto_blocks_wizard_completed 1',
		{ stdio: 'inherit', cwd }
	);
}
