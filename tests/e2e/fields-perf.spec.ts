import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	activateFieldsTheme,
	createPost,
	deletePost,
	restoreTheme,
	setClassicEditor,
	waitForBox,
} from './fields-utils';

/**
 * Editing performance of the 50-field `perf` group (tests/fixtures/fields/
 * perf-50.json) on a post, in the block editor:
 *  - the box mounts in <= 600 ms (proto-fields:start to proto-fields:mounted),
 *  - typing in field 25 stays at p95 <= 50 ms and max <= 100 ms per keystroke,
 *  - no picker chunk is requested until the relationship Add button is used.
 * The chunk check lives in the realistic group's spec test below, since the
 * perf group has only text fields.
 */

const BUDGET = { mountMs: 600, keyP95Ms: 50, keyMaxMs: 100 };
const CHUNKS = [
	'fields-picker-relationship',
	'fields-picker-gallery',
	'fields-wysiwyg',
];

let previousTheme = '';
const created: Array< [ string, number ] > = [];

test.describe( 'Field box performance', () => {
	test.describe.configure( { retries: 2 } );

	test.beforeAll( async ( { requestUtils } ) => {
		previousTheme = await activateFieldsTheme( requestUtils );
		setClassicEditor( false );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const [ base, id ] of created ) {
			await deletePost( requestUtils, base, id );
		}
		await restoreTheme( requestUtils, previousTheme );
	} );

	test( 'a 50-field box mounts fast and types smoothly', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPost( requestUtils, 'posts', {
			title: 'Perf 50',
		} );
		created.push( [ 'posts', id ] );
		const requests: string[] = [];
		page.on( 'request', ( r ) => requests.push( r.url() ) );

		await admin.editPost( id );
		const b = await waitForBox( page, undefined );
		await expect( b.locator( '[data-field]' ) ).toHaveCount( 50 );

		const mountMs = await page.evaluate( () => {
			const start = performance.getEntriesByName( 'proto-fields:start' )[ 0 ];
			const end = performance.getEntriesByName( 'proto-fields:mounted' ).pop();
			return start && end ? end.startTime - start.startTime : -1;
		} );

		const field = b.getByLabel( 'Field 25', { exact: true } );
		await field.scrollIntoViewIfNeeded();
		await field.click();
		await page.evaluate( () => {
			const w = window as unknown as { __pbKeys: number[] };
			w.__pbKeys = [];
			let down = 0;
			document.addEventListener(
				'keydown',
				() => {
					down = performance.now();
				},
				true
			);
			document.addEventListener(
				'input',
				() => {
					const t0 = down;
					requestAnimationFrame( () =>
						w.__pbKeys.push( performance.now() - t0 )
					);
				},
				true
			);
		} );
		for ( let i = 0; i < 40; i++ ) {
			await page.keyboard.type( 'x' );
			// Let the frame land before the next key.
			await page.evaluate( () => new Promise( requestAnimationFrame ) );
		}
		const keys = await page.evaluate(
			() => ( window as unknown as { __pbKeys: number[] } ).__pbKeys
		);
		expect( keys.length ).toBe( 40 );
		const sorted = [ ...keys ].sort( ( a, c ) => a - c );
		const p95 = sorted[ Math.ceil( sorted.length * 0.95 ) - 1 ];
		const max = sorted[ sorted.length - 1 ];

		test.info().annotations.push(
			{ type: 'mount-ms', description: mountMs.toFixed( 1 ) },
			{ type: 'keystroke-p95-ms', description: p95.toFixed( 1 ) },
			{ type: 'keystroke-max-ms', description: max.toFixed( 1 ) }
		);
		// eslint-disable-next-line no-console
		console.log(
			`perf: mount ${ mountMs.toFixed( 1 ) } ms, keystroke p95 ${ p95.toFixed(
				1
			) } ms, max ${ max.toFixed( 1 ) } ms`
		);

		expect( mountMs ).toBeGreaterThan( 0 );
		expect( mountMs ).toBeLessThanOrEqual( BUDGET.mountMs );
		expect( p95 ).toBeLessThanOrEqual( BUDGET.keyP95Ms );
		expect( max ).toBeLessThanOrEqual( BUDGET.keyMaxMs );
		expect(
			requests.filter( ( u ) => CHUNKS.some( ( c ) => u.includes( c ) ) )
		).toEqual( [] );
	} );

	test( 'no picker chunk loads until the relationship Add button is used', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPost( requestUtils, 'event', {
			title: 'Chunks',
		} );
		created.push( [ 'event', id ] );
		const chunkRequests: string[] = [];
		page.on( 'request', ( r ) => {
			if ( CHUNKS.some( ( c ) => r.url().includes( c ) ) ) {
				chunkRequests.push( r.url() );
			}
		} );

		await admin.editPost( id );
		const b = await waitForBox( page, 'Event details' );
		await page.getByRole( 'tab', { name: 'Program', exact: true } ).click();
		await expect(
			b.getByRole( 'button', { name: 'Add section' } )
		).toBeVisible();
		// Browsing the tabs and an idle box loads no picker.
		await page.getByRole( 'tab', { name: 'Media', exact: true } ).click();
		await expect( b.getByRole( 'button', { name: 'Select image' } ) ).toBeVisible();
		await page.getByRole( 'tab', { name: 'Program', exact: true } ).click();
		expect( chunkRequests ).toEqual( [] );

		await b.getByRole( 'button', { name: /^Add Speakers/ } ).click();
		await expect
			.poll( () => chunkRequests.length )
			.toBeGreaterThan( 0 );
		expect( chunkRequests.every( ( u ) => u.includes( 'fields-picker-relationship' ) ) ).toBe(
			true
		);
		expect( new Set( chunkRequests ).size ).toBe( 1 );
	} );
} );
