import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import {
	deleteAllOfType,
	prepareFixtureSite,
	themeFile,
	writeThemeFile,
} from './content-model-utils';

const THEME = 'pb-e2e-block';
const SCREEN = 'page=proto-blocks-taxonomies';
const EDITOR = {
	username: 'pb-e2e-editor',
	email: 'pb-e2e-editor@example.test',
	password: 'pb-e2e-editor-password',
};

/**
 * The Taxonomies table row whose Name cell is exactly `name`.
 * @param page The current page.
 * @param name Exact text of the Name cell (the plural label).
 */
function row( page: Page, name: string ) {
	return page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'cell', { name: new RegExp( `^${ name }( |$)` ) } ),
	} );
}

test.describe( 'Taxonomies', () => {
	let previousTheme = '';

	test.beforeAll( async ( { requestUtils } ) => {
		previousTheme = await prepareFixtureSite( requestUtils );
		await deleteAllOfType( requestUtils, 'book' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( THEME );
		await deleteAllOfType( requestUtils, 'book' );
		const terms = await requestUtils.rest< Array< { id: number } > >( {
			path: '/wp/v2/topic',
			params: { per_page: 100, hide_empty: false },
		} );
		for ( const term of terms ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/topic/${ term.id }`,
				params: { force: true },
			} );
		}
		if ( previousTheme ) {
			await requestUtils.activateTheme( previousTheme );
		}
	} );

	test( 'an inline taxonomy is edited in its post type', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', SCREEN );
		const genre = row( page, 'Genres' );
		await expect(
			genre.getByRole( 'link', { name: 'Edit in post type' } )
		).toHaveAttribute( 'href', /page=proto-blocks-post-types&type=book$/ );
	} );

	test( 'a standalone taxonomy has working term archives', async ( {
		page,
		requestUtils,
	} ) => {
		const term = await requestUtils.rest< { id: number; slug: string } >( {
			path: '/wp/v2/topic',
			method: 'POST',
			data: { name: 'Space Opera' },
		} );
		await requestUtils.rest( {
			path: '/wp/v2/book',
			method: 'POST',
			data: { title: 'Hyperion', status: 'publish', topic: [ term.id ] },
		} );

		const response = await page.goto( `/topic/${ term.slug }/` );
		expect( response?.status() ).toBe( 200 );
		await expect(
			page.getByRole( 'link', { name: 'Hyperion' } )
		).toBeVisible();
	} );

	test( 'detaching a post type saves to the file', async ( { page } ) => {
		const original = themeFile(
			THEME,
			'proto-taxonomies/topic/taxonomy.jsonc'
		);
		try {
			await page.goto( `/wp-admin/admin.php?${ SCREEN }&taxonomy=topic` );
			const posts = page.getByRole( 'checkbox', { name: /^Posts\b/ } );
			await expect( posts ).toBeChecked();
			await posts.uncheck();
			await page.getByRole( 'button', { name: 'Save to file' } ).click();
			await expect( page.getByText( /^Saved to / ) ).toBeVisible();

			const text = themeFile(
				THEME,
				'proto-taxonomies/topic/taxonomy.jsonc'
			);
			expect( text ).toContain( '// Standalone taxonomy' );
			expect( text ).toMatch( /"post_types": \[\s*"book"\s*\]/ );
		} finally {
			writeThemeFile(
				THEME,
				'proto-taxonomies/topic/taxonomy.jsonc',
				original
			);
		}
	} );

	test( 'the admin routes refuse anonymous requests', async ( {
		playwright,
	} ) => {
		const anonymous = await playwright.request.newContext( {
			baseURL: process.env.WP_BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		try {
			const response = await anonymous.get(
				'/wp-json/proto-blocks/v1/admin/taxonomies'
			);
			expect( response.status() ).toBe( 401 );
		} finally {
			await anonymous.dispose();
		}
	} );

	test( 'an editor cannot open the screen', async ( {
		browser,
		requestUtils,
	} ) => {
		const existing = await requestUtils.rest< Array< { id: number } > >( {
			path: '/wp/v2/users',
			params: { search: EDITOR.username, context: 'edit' },
		} );
		for ( const user of existing ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/users/${ user.id }`,
				params: { force: true, reassign: 1 },
			} );
		}
		const editor = await requestUtils.createUser( {
			...EDITOR,
			roles: [ 'editor' ],
		} );

		const context = await browser.newContext( {
			baseURL: process.env.WP_BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		try {
			const page = await context.newPage();
			await page.goto( '/wp-login.php' );
			await page.locator( '#user_login' ).fill( EDITOR.username );
			await page.locator( '#user_pass' ).fill( EDITOR.password );
			await page.locator( '#wp-submit' ).click();
			await page.waitForURL( /wp-admin/ );

			await page.goto( `/wp-admin/admin.php?${ SCREEN }` );
			await expect( page.locator( 'body' ) ).toContainText(
				'Sorry, you are not allowed to access this page.'
			);
		} finally {
			await context.close();
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/users/${ editor.id }`,
				params: { force: true, reassign: 1 },
			} );
		}
	} );
} );
