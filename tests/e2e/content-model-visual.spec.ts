import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import {
	deleteAllOfType,
	prepareFixtureSite,
	wpCli,
} from './content-model-utils';

/**
 * Visual checks for the Post Types and Taxonomies screens. Baselines live in
 * tests/e2e/__screenshots__ and are platform-specific (see README.md).
 */

const TYPES = 'page=proto-blocks-post-types';
const TAXONOMIES = 'page=proto-blocks-taxonomies';

/**
 * Masked: the admin bar (site name), counts and the footer (WordPress version).
 * @param page     The current page.
 * @param fullPage Capture the whole page, not only the viewport.
 */
function shot( page: Page, fullPage = true ) {
	return {
		fullPage,
		mask: [
			page.locator( '#wpadminbar' ),
			page.locator( '[data-testid=count]' ),
			page.locator( '#wpfooter' ),
		],
	};
}

async function visit( page: Page, query: string ) {
	await page.goto( `/wp-admin/admin.php?${ query }` );
	await expect( page.locator( '[aria-busy="true"]' ) ).toHaveCount( 0 );
}

test.describe( 'Content model screens (visual)', () => {
	let previousTheme = '';

	test.beforeAll( async ( { requestUtils } ) => {
		previousTheme = await prepareFixtureSite( requestUtils );
		await deleteAllOfType( requestUtils, 'book' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( previousTheme ) {
			await requestUtils.activateTheme( previousTheme );
		}
	} );

	test( 'post types list', async ( { page } ) => {
		await visit( page, TYPES );
		await expect(
			page.getByRole( 'cell', { name: 'Books', exact: true } )
		).toBeVisible();
		await expect( page ).toHaveScreenshot(
			'post-types-list.png',
			shot( page )
		);
	} );

	test( 'post type edit form', async ( { page } ) => {
		await visit( page, `${ TYPES }&type=book` );
		await expect(
			page.getByRole( 'heading', { name: 'Effective settings' } )
		).toBeVisible();
		// Viewport only: the form's sticky save bar would be painted over
		// the middle of a full-page capture.
		await expect( page ).toHaveScreenshot(
			'post-types-edit.png',
			shot( page, false )
		);
	} );

	test( 'post types list, read-only', async ( { page } ) => {
		wpCli( 'wp config set DISALLOW_FILE_MODS true --raw' );
		try {
			// PHP's opcache rechecks wp-config.php every 2 seconds.
			await expect( async () => {
				await visit( page, TYPES );
				await expect(
					page.getByText(
						'File modifications are disabled on this site'
					)
				).toBeVisible( { timeout: 1000 } );
			} ).toPass( { timeout: 15000 } );
			await expect( page ).toHaveScreenshot(
				'post-types-readonly.png',
				shot( page )
			);
		} finally {
			wpCli( 'wp config delete DISALLOW_FILE_MODS' );
		}
	} );

	test( 'taxonomies list', async ( { page } ) => {
		// Past the opcache window of the read-only test's wp-config change.
		await expect( async () => {
			await visit( page, TAXONOMIES );
			await expect(
				page.getByRole( 'cell', { name: 'Topics', exact: true } )
			).toBeVisible( { timeout: 1000 } );
			await expect(
				page.getByText( 'File modifications are disabled on this site' )
			).toHaveCount( 0 );
		} ).toPass( { timeout: 15000 } );
		await expect( page ).toHaveScreenshot(
			'taxonomies-list.png',
			shot( page )
		);
	} );

	test( 'taxonomy edit form', async ( { page } ) => {
		await visit( page, `${ TAXONOMIES }&taxonomy=topic` );
		await expect(
			page.getByRole( 'checkbox', { name: /^Books\b/ } )
		).toBeChecked();
		await expect( page ).toHaveScreenshot(
			'taxonomies-edit.png',
			shot( page )
		);
	} );
} );
