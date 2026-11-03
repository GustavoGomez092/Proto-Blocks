import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Proto-Blocks front end smoke', () => {
	test( 'post with an example block renders without console errors', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Proto-Blocks smoke',
			status: 'publish',
			content: '<!-- wp:proto-blocks/card /-->',
		} );

		const errors: string[] = [];
		page.on( 'console', ( msg ) => {
			if ( msg.type() === 'error' ) {
				errors.push( msg.text() );
			}
		} );
		page.on( 'pageerror', ( err ) => errors.push( err.message ) );

		const response = await page.goto( post.link );
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Fatal error'
		);
		expect( errors ).toEqual( [] );

		await requestUtils.deleteAllPosts();
	} );
} );
