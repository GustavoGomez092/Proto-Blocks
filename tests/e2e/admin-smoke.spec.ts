import { execSync } from 'child_process';
import path from 'path';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

const ROOT = path.join( __dirname, '../..' );

/** Runs a WP-CLI command on the wp-env tests site. */
function wp( command: string ) {
	execSync( `npx wp-env run tests-cli wp ${ command }`, {
		stdio: 'inherit',
		cwd: ROOT,
	} );
}

/** Collects console errors and uncaught page errors while a test runs. */
function trackErrors( page: Page ): string[] {
	const errors: string[] = [];
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			errors.push( message.text() );
		}
	} );
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	return errors;
}

const SCREENS = [
	{ slug: 'proto-blocks', heading: 'Blocks' },
	{ slug: 'proto-blocks-settings', heading: 'Tailwind Settings' },
	{ slug: 'proto-blocks-system', heading: 'System Status' },
	{ slug: 'proto-blocks-previews', heading: 'Preview Capture' },
];

test.describe( 'Proto-Blocks admin smoke', () => {
	for ( const { slug, heading } of SCREENS ) {
		test( `${ slug } renders the React screen`, async ( { page } ) => {
			const errors = trackErrors( page );
			const response = await page.goto(
				`/wp-admin/admin.php?page=${ slug }`
			);

			expect( response?.status() ).toBe( 200 );
			// Not redirected to the setup wizard (global setup completes it).
			expect( new URL( page.url() ).searchParams.get( 'page' ) ).toBe(
				slug
			);
			const app = page.locator( '#proto-blocks-admin' );
			await expect( app ).toBeVisible();
			await expect(
				app.getByRole( 'heading', { level: 1, name: heading } )
			).toBeVisible();
			await expect( page.locator( 'body' ) ).not.toContainText(
				/Fatal error|There has been a critical error/
			);
			expect( errors ).toEqual( [] );
		} );
	}

	test( 'the setup wizard starts on step 1 until it is completed', async ( {
		page,
	} ) => {
		const errors = trackErrors( page );
		wp( 'option delete proto_blocks_wizard_completed' );
		try {
			const response = await page.goto(
				'/wp-admin/admin.php?page=proto-blocks-wizard'
			);

			expect( response?.status() ).toBe( 200 );
			const app = page.locator( '#proto-blocks-admin' );
			await expect( app ).toBeVisible();
			await expect(
				app.getByRole( 'heading', {
					level: 1,
					name: 'Welcome to Proto Blocks',
				} )
			).toBeVisible();
			const progress = app.getByRole( 'list', {
				name: 'Setup progress',
			} );
			await expect( progress.getByRole( 'listitem' ) ).toHaveCount( 3 );
			expect( errors ).toEqual( [] );
		} finally {
			wp( 'option update proto_blocks_wizard_completed 1' );
		}
	} );
} );
