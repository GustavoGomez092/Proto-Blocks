import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { wpCli } from './content-model-utils';
import {
	activateFieldsTheme,
	createPost,
	deletePost,
	restoreTheme,
	setClassicEditor,
} from './fields-utils';

/**
 * The front end reads: the Block Bindings source, the `event-card` block
 * (`$template->field()` and `proto_rows()`), escaping, visibility, and the
 * ACF-compatible `get_field()` shim (ACF is not installed).
 */

const SUBTITLE = 'A <b>bold</b> & <script>window.__pbSubtitle=1</script> night';
const NOTES = '<b>kept</b><script>window.__pbNotes=1</script>';

let previousTheme = '';
const created: number[] = [];

test.describe( 'Fields on the front end', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		previousTheme = await activateFieldsTheme( requestUtils );
		setClassicEditor( false );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const id of created ) {
			await deletePost( requestUtils, 'event', id );
		}
		await restoreTheme( requestUtils, previousTheme );
	} );

	/**
	 * An event with the meta written through REST as the admin.
	 * @param requestUtils The worker's RequestUtils.
	 * @param status       Post status.
	 */
	async function event(
		requestUtils: Parameters< typeof createPost >[ 0 ],
		status: string
	): Promise< { id: number; link: string } > {
		const id = await createPost( requestUtils, 'event', {
			title: `Front end ${ status }`,
			status,
			content:
				'<!-- wp:proto-blocks/event-card /-->',
			meta: {
				subtitle: SUBTITLE,
				agenda: [
					{ time: '', title: 'Opening <i>act</i>', notes: NOTES },
					{ time: '', title: 'Closing', notes: '' },
				],
			},
		} );
		created.push( id );
		const post = await requestUtils.rest< { link: string } >( {
			path: `/wp/v2/event/${ id }`,
			params: { context: 'edit' },
		} );
		return { id, link: post.link };
	}

	test( 'the bound paragraph and the event-card block escape values', async ( {
		page,
		requestUtils,
	} ) => {
		const { link } = await event( requestUtils, 'publish' );
		await page.goto( link );

		const bound = page.locator( '.pb-bound-subtitle' );
		await expect( bound ).toHaveCount( 1 );
		// Markup in a text field is shown as text.
		await expect( bound ).toContainText( '<b>bold</b>' );
		await expect( bound.locator( 'b, script' ) ).toHaveCount( 0 );

		const card = page.locator( '.pb-event-card' );
		await expect( card.locator( '.pb-event-subtitle' ) ).toContainText(
			'<b>bold</b>'
		);
		await expect( card.locator( '.pb-event-subtitle script' ) ).toHaveCount(
			0
		);
		const items = card.locator( '.pb-event-agenda li' );
		await expect( items ).toHaveCount( 2 );
		await expect( items.nth( 0 ) ).toContainText( 'Opening <i>act</i>' );
		await expect( items.nth( 1 ) ).toContainText( 'Closing' );

		// Nothing the values carried ran.
		expect(
			await page.evaluate( () => [
				( window as unknown as Record< string, unknown > ).__pbSubtitle,
				( window as unknown as Record< string, unknown > ).__pbNotes,
			] )
		).toEqual( [ undefined, undefined ] );
	} );

	test( 'wysiwyg output keeps safe markup and drops scripts', async ( {
		page,
		requestUtils,
	} ) => {
		const { id, link } = await event( requestUtils, 'publish' );
		const html = wpCli(
			`wp eval 'foreach (proto_rows("agenda", ${ id }) as $r) { echo $r["notes"], "|"; }'`
		);
		expect( html ).toContain( '<b>kept</b>' );
		expect( html ).not.toContain( '<script' );
		const response = await page.goto( link );
		expect( response?.status() ).toBe( 200 );
	} );

	test( 'the bound paragraph shows nothing to a logged-out visitor on a private event', async ( {
		browser,
		requestUtils,
	} ) => {
		const { id } = await event( requestUtils, 'private' );
		const visitor = await browser.newContext( {
			baseURL: process.env.WP_BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		try {
			const page = await visitor.newPage();
			const response = await page.goto( `/?post_type=event&p=${ id }` );
			expect( response?.status() ).toBe( 404 );
			await expect( page.locator( '.pb-bound-subtitle' ) ).toHaveCount( 0 );
			expect( await page.content() ).not.toContain( 'bold' );
		} finally {
			await visitor.close();
		}
	} );

	test( 'get_field() equals proto_field() with ACF inactive', async ( {
		requestUtils,
	} ) => {
		const { id } = await event( requestUtils, 'publish' );
		const same = wpCli(
			`wp eval 'echo get_field("subtitle", ${ id }) === proto_field("subtitle", ${ id }) ? "same" : "different";'`
		);
		expect( same.trim() ).toBe( 'same' );
		expect(
			wpCli( `wp eval 'echo function_exists("acf_get_field") ? "acf" : "no-acf";'` ).trim()
		).toBe( 'no-acf' );
	} );
} );
