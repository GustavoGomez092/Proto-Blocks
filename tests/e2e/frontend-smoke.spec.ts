import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Proto-Blocks front end smoke', () => {
	test( 'post with an example block renders without console errors', async ( {
		page,
		requestUtils,
	} ) => {
		// Example-blocks option took effect: the card block type is registered.
		const blockType = await requestUtils.rest( {
			path: '/wp/v2/block-types/proto-blocks/card',
		} );
		expect( blockType ).toMatchObject( { name: 'proto-blocks/card' } );

		const post = await requestUtils.createPost( {
			title: 'E2E Proto-Blocks smoke',
			status: 'publish',
			content: '<!-- wp:proto-blocks/card /-->',
		} );

		try {
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
			await expect(
				page.locator( '.wp-block-proto-blocks-card' ).first()
			).toBeVisible();
			expect( errors ).toEqual( [] );
		} finally {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ post.id }`,
				params: { force: true },
			} );
		}
	} );
} );
