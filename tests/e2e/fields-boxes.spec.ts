import path from 'path';
import { createHash } from 'crypto';
import fs from 'fs';
import {
	test,
	expect,
	RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import { wpCli } from './content-model-utils';
import {
	FIELDS_THEME,
	PIXEL_PNG,
	activateFieldsTheme,
	box,
	createPost,
	deletePost,
	deleteUser,
	postMeta,
	restoreTheme,
	saveBlockEditor,
	saveClassicEditor,
	setClassicEditor,
	setAuthor,
	setPostMeta,
	userSession,
	waitForBox,
} from './fields-utils';

/**
 * Field boxes end to end: the realistic ACF export in both editors, the
 * server-side validation round trip, the trust boundary for users without
 * unfiltered_html, and the term and user forms.
 */

const REPO_FIXTURES = path.join( __dirname, '../fixtures/fields' );
const HIDDEN_CODE = 'Read-only for you';

let previousTheme = '';
const createdPosts: number[] = [];
const createdMedia: number[] = [];

async function tab( page: Page, name: string ) {
	await page.getByRole( 'tab', { name, exact: true } ).click();
}

/**
 * Fills the realistic group on the current screen (block or classic editor).
 * @param page     Editor page.
 * @param mediaId  Attachment to pick as the hero image.
 * @param isBlocks True in the block editor (the media modal is the same).
 */
async function fillRealistic( page: Page, mediaId: number ) {
	const b = await waitForBox( page, 'Event details' );
	await tab( page, 'Basics' );
	await b.getByLabel( 'Subtitle' ).fill( 'An evening of talks' );
	await b.getByLabel( 'Start date' ).fill( '2026-11-20' );
	await expect( b.getByLabel( 'Stream URL' ) ).toBeHidden();
	await b.getByRole( 'switch', { name: /Online event/ } ).click();
	await b.getByLabel( 'Stream URL' ).fill( 'https://example.test/live' );

	await tab( page, 'Media' );
	await b.getByRole( 'button', { name: 'Select image' } ).click();
	const modal = page.locator( '.media-modal' );
	await modal.getByRole( 'tab', { name: 'Media Library' } ).click();
	await modal.locator( `li.attachment[data-id="${ mediaId }"]` ).click();
	await modal.getByRole( 'button', { name: 'Use this' } ).click();
	await expect( b.getByRole( 'button', { name: 'Replace' } ) ).toBeVisible();

	await tab( page, 'Program' );
	const add = b.getByRole( 'button', { name: 'Add session' } );
	await add.click();
	await add.click();
	const rows = b.locator( '[data-row]' );
	await rows.nth( 0 ).getByLabel( /^Title/ ).fill( 'Opening' );
	await rows.nth( 1 ).getByLabel( /^Title/ ).fill( 'Closing' );
	await b.getByRole( 'button', { name: 'Add section' } ).click();
	await b.getByRole( 'button', { name: 'Quote', exact: true } ).click();
	await b.getByLabel( 'Cite' ).fill( 'Ada' );
}

async function expectRealistic( page: Page ) {
	const b = await waitForBox( page, 'Event details' );
	await tab( page, 'Basics' );
	await expect( b.getByLabel( 'Subtitle' ) ).toHaveValue(
		'An evening of talks'
	);
	await expect( b.getByLabel( 'Start date' ) ).toHaveValue( '2026-11-20' );
	await expect( b.getByLabel( 'Stream URL' ) ).toHaveValue(
		'https://example.test/live'
	);
	await tab( page, 'Media' );
	await expect( b.getByRole( 'button', { name: 'Replace' } ) ).toBeVisible();
	await tab( page, 'Program' );
	await expect( b.getByLabel( /^Title/ ).nth( 0 ) ).toHaveValue( 'Opening' );
	await expect( b.getByLabel( /^Title/ ).nth( 1 ) ).toHaveValue( 'Closing' );
	await expect( b.getByLabel( 'Cite' ) ).toHaveValue( 'Ada' );
}

function expectStoredRealistic( id: number, mediaId: number ) {
	expect( postMeta( id, 'subtitle' ) ).toBe( '"An evening of talks"' );
	expect( postMeta( id, 'hero' ) ).toMatch(
		new RegExp( `^"?${ mediaId }"?$` )
	);
	const agenda = JSON.parse( postMeta( id, 'agenda' ) ?? '[]' ) as Array<
		Record< string, string >
	>;
	expect( agenda ).toHaveLength( 2 );
	expect( agenda.map( ( r ) => r.title ) ).toEqual( [ 'Opening', 'Closing' ] );
}

test.describe( 'Field boxes', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		previousTheme = await activateFieldsTheme( requestUtils );
		setClassicEditor( false );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const id of createdPosts ) {
			await deletePost( requestUtils, 'event', id );
		}
		for ( const id of createdMedia ) {
			await requestUtils
				.rest( {
					method: 'DELETE',
					path: `/wp/v2/media/${ id }`,
					params: { force: true },
				} )
				.catch( () => undefined );
		}
		deleteUser( 'author1' );
		deleteUser( 'contributor1' );
		await restoreTheme( requestUtils, previousTheme );
	} );

	async function newEvent(
		requestUtils: RequestUtils,
		data: Record< string, unknown > = {}
	) {
		const id = await createPost( requestUtils, 'event', {
			title: 'Field box event',
			...data,
		} );
		createdPosts.push( id );
		return id;
	}

	async function pixel( requestUtils: RequestUtils ) {
		const media = await requestUtils.uploadMedia( PIXEL_PNG );
		createdMedia.push( media.id );
		return media.id;
	}

	test( 'the ACF export in the theme equals the repo fixture', () => {
		const repo = createHash( 'sha1' )
			.update(
				fs.readFileSync( path.join( REPO_FIXTURES, 'acf-export-realistic.json' ) )
			)
			.digest( 'hex' );
		const out = wpCli(
			`sha1sum wp-content/themes/${ FIELDS_THEME }/proto-fields/acf-export/group.json`
		);
		expect( out.split( /\s+/ )[ 0 ] ).toBe( repo );
	} );

	test( 'ACF export loads and edits unchanged (block editor)', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const mediaId = await pixel( requestUtils );
		const id = await newEvent( requestUtils );
		await admin.editPost( id );
		await fillRealistic( page, mediaId );

		await box( page, 'Event details' ).scrollIntoViewIfNeeded();
		await expect( box( page, 'Event details' ) ).toHaveScreenshot(
			'fields-box-block-editor.png',
			{
				maxDiffPixelRatio: 0.002,
				animations: 'disabled',
				caret: 'hide',
				mask: [ box( page, 'Event details' ).locator( 'img' ) ],
			}
		);

		await saveBlockEditor( page );
		await expect
			.poll( () => postMeta( id, 'subtitle' ) )
			.toBe( '"An evening of talks"' );
		expectStoredRealistic( id, mediaId );

		await page.reload();
		await expectRealistic( page );
	} );

	test( 'same, classic editor', async ( { admin, page, requestUtils } ) => {
		setClassicEditor( true );
		try {
			const mediaId = await pixel( requestUtils );
			const id = await newEvent( requestUtils );
			await admin.visitAdminPage( 'post.php', `post=${ id }&action=edit` );
			await fillRealistic( page, mediaId );
			await expect( box( page, 'Event details' ) ).toHaveScreenshot(
				'fields-box-classic.png',
				{
					maxDiffPixelRatio: 0.002,
					animations: 'disabled',
					caret: 'hide',
					mask: [ box( page, 'Event details' ).locator( 'img' ) ],
				}
			);
			await saveClassicEditor( page );
			expectStoredRealistic( id, mediaId );
			await expectRealistic( page );
			// The side Developer group renders too.
			await expect( box( page, 'Developer' ) ).toBeVisible();
		} finally {
			setClassicEditor( false );
		}
	} );

	test( 'validation error survives the save (block editor)', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await newEvent( requestUtils );
		setPostMeta( id, 'start_date', '2026-11-20' );
		await admin.editPost( id );
		const b = await waitForBox( page, 'Event details' );
		await b.getByLabel( 'Start date' ).fill( '' );
		await b.getByLabel( 'Subtitle' ).fill( 'Still saved' );
		await saveBlockEditor( page );

		await expect( b.getByText( 'This field is required.' ).first() ).toBeVisible();
		await expect
			.poll( () => postMeta( id, 'subtitle' ) )
			.toBe( '"Still saved"' );
		expect( postMeta( id, 'start_date' ) ).toContain( '2026-11-20' );
		const status = await requestUtils.rest< { status: string } >( {
			path: `/wp/v2/event/${ id }`,
		} );
		expect( status.status ).toBe( 'publish' );
	} );

	test( 'validation error survives the save (classic editor)', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		setClassicEditor( true );
		try {
			const id = await newEvent( requestUtils );
			await admin.visitAdminPage( 'post.php', `post=${ id }&action=edit` );
			const b = await waitForBox( page, 'Event details' );
			await b.getByLabel( 'Start date' ).fill( '' );
			await b.getByLabel( 'Subtitle' ).fill( 'Classic saved' );
			await saveClassicEditor( page );
			await expect(
				box( page, 'Event details' )
					.getByText( 'This field is required.' )
					.first()
			).toBeVisible();
			expect( postMeta( id, 'subtitle' ) ).toBe( '"Classic saved"' );
		} finally {
			setClassicEditor( false );
		}
	} );

	test( 'author cannot store markup or change code', async ( {
		browser,
		requestUtils,
	} ) => {
		const author = await userSession( browser, 'author1', 'author' );
		try {
			const id = await newEvent( requestUtils, {
				author: author.id,
				title: 'By the author',
			} );
			setAuthor( id, author.id );
			setPostMeta( id, 'snippet', '<script>ok()</script>' );

			await author.admin.editPost( id );
			const page = author.page;
			const b = await waitForBox( page, 'Event details' );
			await b.getByLabel( 'Subtitle' ).fill( '<script>alert(1)</script>Hi' );
			await b.getByRole( 'switch', { name: /Online event/ } ).click();
			await b.getByLabel( 'Stream URL' ).fill( 'javascript:alert(1)' );

			// The code value never reaches the browser, and the field is read-only.
			const dev = box( page, 'Developer' );
			await expect( dev ).toContainText( HIDDEN_CODE );
			expect( await page.content() ).not.toContain( 'ok()' );

			await saveBlockEditor( page );
			await expect
				.poll( () => postMeta( id, 'subtitle' ) ?? '' )
				.toContain( 'Hi' );
			expect( postMeta( id, 'subtitle' ) ).not.toContain( '<script' );
			expect( postMeta( id, 'stream_url' ) ?? '""' ).toMatch( /^(""|null)?$/ );
			expect( postMeta( id, 'snippet' ) ).toContain( 'ok()' );
		} finally {
			await author.context.close();
		}
	} );

	test( 'contributor REST meta', async ( {
		browser,
		playwright,
		requestUtils,
	} ) => {
		const contributor = await userSession(
			browser,
			'contributor1',
			'contributor'
		);
		const api = await playwright.request.newContext( {
			baseURL: process.env.WP_BASE_URL,
			storageState: path.join( __dirname, '.auth/contributor1.json' ),
		} );
		try {
			const id = await newEvent( requestUtils, {
				status: 'draft',
				author: contributor.id,
				title: 'Contributor draft',
			} );
			setAuthor( id, contributor.id );
			setPostMeta( id, 'snippet', 'before' );
			const nonce = await (
				await api.get( '/wp-admin/admin-ajax.php?action=rest-nonce' )
			 ).text();
			const post = ( meta: Record< string, unknown > ) =>
				api.post( `/?rest_route=/wp/v2/event/${ id }`, {
					headers: { 'X-WP-Nonce': nonce },
					data: { meta },
				} );

			const markup = await post( { subtitle: '<script>x</script>ok' } );
			expect( markup.status(), await markup.text() ).toBe( 200 );
			expect( postMeta( id, 'subtitle' ) ).not.toContain( '<script' );

			const code = await post( { snippet: 'x' } );
			// `code` fields are not exposed in REST by default, so the key is
			// ignored (not 403 rest_cannot_update); the stored value is what counts.
			expect( code.status() ).toBe( 200 );
			expect( ( await code.json() ).meta ).not.toHaveProperty( 'snippet' );
			expect( postMeta( id, 'snippet' ) ).toBe( '"before"' );

			const rows = Array.from( { length: 21 }, ( _, i ) => ( {
				time: '',
				title: `Row ${ i }`,
				notes: '',
			} ) );
			const tooMany = await post( { agenda: rows } );
			expect( tooMany.status() ).toBe( 400 );
			expect( ( await tooMany.json() ).code ).toMatch(
				/^rest_(invalid_param|too_many_items)$/
			);
		} finally {
			await api.dispose();
			await contributor.context.close();
		}
	} );

	test( 'term and user forms', async ( { admin, page } ) => {
		const name = `E2E term ${ Date.now() }`;
		let termId = '';
		try {
			await admin.visitAdminPage( 'edit-tags.php', 'taxonomy=category' );
			const add = await waitForBox( page, 'Term extra' );
			await expect( add.getByLabel( 'Term note' ) ).toBeVisible();
			await expect(
				box( page, 'Term extra' ).locator( 'xpath=ancestor::form[@id="addtag"]' )
			).toHaveCount( 1 );
			await page.locator( '#tag-name' ).fill( name );
			await add.getByLabel( 'Term note' ).fill( 'Added by ajax' );
			await expect( page ).toHaveScreenshot( 'fields-term-form.png', {
				maxDiffPixelRatio: 0.002,
				animations: 'disabled',
				caret: 'hide',
				clip: ( await page.locator( '#col-left' ).boundingBox() ) ?? undefined,
				mask: [
					page.locator( '#adminmenuwrap' ),
					page.locator( '#wpadminbar' ),
					page.locator( '#tag-name' ),
					page.locator( '#tag-slug' ),
				],
			} );
			await page.locator( '#submit' ).click();
			await expect( page.locator( '#the-list' ) ).toContainText( name );
			// The AJAX add resets the form, the field box included.
			await expect( page.locator( '#tag-name' ) ).toHaveValue( '' );
			await expect(
				box( page, 'Term extra' ).getByLabel( 'Term note' )
			).toHaveValue( '' );

			termId = wpCli(
				`wp term list category --name='${ name }' --field=term_id`
			).trim();
			await expect
				.poll( () => wpCli( `wp term meta get ${ termId } term_note` ).trim() )
				.toBe( 'Added by ajax' );

			await admin.visitAdminPage(
				'term.php',
				`taxonomy=category&tag_ID=${ termId }`
			);
			const edit = await waitForBox( page, 'Term extra' );
			await expect( edit.getByLabel( 'Term note' ) ).toHaveValue(
				'Added by ajax'
			);
			await edit.getByLabel( 'Term note' ).fill( 'Edited' );
			await page.getByRole( 'button', { name: 'Update' } ).click();
			await expect
				.poll( () => wpCli( `wp term meta get ${ termId } term_note` ).trim() )
				.toBe( 'Edited' );

			await admin.visitAdminPage( 'profile.php' );
			const user = await waitForBox( page, 'User extra' );
			await user.getByLabel( 'User note' ).fill( 'About me' );
			await page.getByRole( 'button', { name: 'Update Profile' } ).click();
			await expect
				.poll( () => wpCli( 'wp user meta get admin user_note' ).trim() )
				.toBe( 'About me' );
			await expect( box( page, 'User extra' ).getByLabel( 'User note' ) ).toHaveValue(
				'About me'
			);
		} finally {
			if ( termId ) {
				wpCli( `wp term delete category ${ termId }` );
			}
			try {
				wpCli( 'wp user meta delete admin user_note' );
			} catch {
				// Never written.
			}
		}
	} );
} );
