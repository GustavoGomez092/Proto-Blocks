import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const PAGES = [ 'proto-blocks', 'proto-blocks-settings', 'proto-blocks-system' ];

test.describe( 'Proto-Blocks admin smoke', () => {
	for ( const slug of PAGES ) {
		test( `${ slug } returns 200 with no fatal`, async ( { page } ) => {
			const response = await page.goto(
				`/wp-admin/admin.php?page=${ slug }`
			);
			expect( response?.status() ).toBe( 200 );
			await expect( page.locator( '.wrap, #proto-blocks-admin' ).first() ).toBeVisible();
			await expect( page.locator( 'body' ) ).not.toContainText(
				/Fatal error|There has been a critical error/
			);
			if ( slug === 'proto-blocks' ) {
				await expect( page ).toHaveTitle( /Proto/ );
			}
		} );
	}
} );
