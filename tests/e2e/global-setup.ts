import { execSync } from 'child_process';
import path from 'path';
import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Logs in as admin, saves the storage state, and enables example blocks.
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

	// Enable the bundled example blocks (card, hero, ...) for front-end tests.
	execSync(
		'npx wp-env run tests-cli wp option update proto_blocks_example_blocks 1',
		{ stdio: 'inherit', cwd: path.join( __dirname, '../..' ) }
	);
}
