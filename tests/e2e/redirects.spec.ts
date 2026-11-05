import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext, Page } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { prepareFixtureSite, themeFile, wpCli } from './content-model-utils';
import {
	BASE_URL,
	PHP_OPTION,
	SCREEN,
	THEME,
	clearPhpRedirect,
	clearRedirectTables,
	createPost,
	deletePosts,
	groupExists,
	hop,
	raw,
	readGroup,
	removeRedirects,
	tableRows,
	writeGroup,
	writeGroupText,
	writeSettings,
} from './redirects-utils';

/**
 * The Redirects module end to end (spec
 * docs/superpowers/specs/2026-11-05-redirects-design.md §14): front-end
 * behaviour over raw HTTP (no redirect following), the edge cases observable
 * over HTTP, and the Redirects screen (rules, groups, test box, PHP origin,
 * 404s, log, import/export, settings, read-only, permalink monitor). Every
 * group file is written into a fresh copy of pb-e2e-block; `proto-redirects/`,
 * the hits/log tables and the mu-plugin option are cleared after every test.
 */

const TARGET = 'pb-e2e-redirect-target';
/** The fixture theme's index template prints post titles only: the page's title marks its body. */
const TARGET_BODY = `>${ TARGET }</a>`;

let anonymous: APIRequestContext;

/** Status and Location for a visitor. */
function visit( url: string, options: Parameters< typeof hop >[ 2 ] = {} ) {
	return hop( anonymous, url, options );
}

/** A plain WordPress 404: no redirect happened. */
async function expectNotRedirected( url: string ) {
	const result = await visit( url );
	expect( result, url ).toEqual( { status: 404, location: null, by: null } );
}

/** A proto-blocks redirect with this code and Location. */
async function expectRedirect(
	url: string,
	status: number,
	location: string,
	options: Parameters< typeof hop >[ 2 ] = {}
) {
	expect( await visit( url, options ), url ).toEqual( {
		status,
		location,
		by: 'proto-blocks',
	} );
}

/** The 404 log rows whose url is `url` (with or without a query). */
function notFoundRows( url: string ) {
	return tableRows( 'proto_404_log' ).filter(
		( row ) => row.url === url || row.url?.startsWith( `${ url }?` )
	);
}

/** GET admin/redirects. */
function index( requestUtils: RequestUtils ) {
	return requestUtils.rest< {
		groups: Array< {
			slug: string;
			double: unknown;
			rules: Array< {
				id: string;
				from: string | null;
				origin: string;
				hits: number;
				lastHit: string | null;
				issues: Array< { code: string } >;
			} >;
		} >;
	} >( { path: '/proto-blocks/v1/admin/redirects' } );
}

async function openScreen( page: Page, query = '' ) {
	await page.goto( `${ SCREEN }${ query }` );
	await expect( page.locator( '[aria-busy="true"]' ) ).toHaveCount( 0 );
}

/** The rules table row of a rule, by its source. */
function ruleRow( page: Page, source: string ) {
	return page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'switch', { name: `Enable ${ source }`, exact: true } ),
	} );
}

/**
 * An item of a rule row's "More actions for …" menu (Move up / Move down /
 * Duplicate / Delete), with the menu opened.
 * @param page   The current page.
 * @param source The rule's source.
 * @param name   The item's label, e.g. `Duplicate /old`.
 */
async function rowMenuItem( page: Page, source: string, name: string ) {
	await page.getByRole( 'button', { name: `More actions for ${ source }`, exact: true } ).click();
	return page.getByRole( 'dialog' ).getByRole( 'button', { name, exact: true } );
}

/** Clicks an item of a rule row's "More actions for …" menu. */
async function rowAction( page: Page, source: string, name: string ) {
	const item = await rowMenuItem( page, source, name );
	await expect( item ).toBeEnabled();
	await item.click();
}

/** Picks an option of a Radix select by the select's label. */
async function choose(
	scope: Page | ReturnType< Page[ 'getByRole' ] >,
	page: Page,
	label: string,
	option: string | RegExp
) {
	await scope.getByLabel( label, { exact: true } ).click();
	await page.getByRole( 'option', { name: option } ).click();
}

test.describe( 'Redirects', () => {
	let previousTheme = '';
	const pages: number[] = [];
	const posts: number[] = [];

	test.beforeAll( async ( { requestUtils, playwright } ) => {
		previousTheme = await prepareFixtureSite( requestUtils );
		removeRedirects();
		clearPhpRedirect();
		clearRedirectTables();
		pages.push(
			await createPost( requestUtils, 'pages', TARGET )
		);
		anonymous = await playwright.request.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
	} );

	test.afterEach( () => {
		removeRedirects();
		clearPhpRedirect();
		clearRedirectTables();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		removeRedirects();
		clearPhpRedirect();
		clearRedirectTables();
		await deletePosts( requestUtils, 'pages', pages );
		await deletePosts( requestUtils, 'posts', posts );
		await anonymous?.dispose();
		if ( previousTheme ) {
			await requestUtils.activateTheme( previousTheme );
		}
	} );

	test.describe( 'front end over HTTP', () => {
		test( 'url 301, 302 with query pass, 410 error, pass and nothing', async () => {
			writeGroup( 'e2e-http', {
				title: 'E2E HTTP',
				rules: [
					{ from: '/pb-e2e-old', to: '/pb-e2e-new' },
					{ from: '/pb-e2e-promo', to: '/pb-e2e-sale?src=promo', code: 302, query: 'pass' },
					{ from: '/pb-e2e-gone', action: 'error', code: 410 },
					{ from: '/pb-e2e-alias', action: 'pass', to: `/${ TARGET }/` },
					{ from: '/pb-e2e-ignored', action: 'nothing' },
				],
			} );

			await expectRedirect( '/pb-e2e-old', 301, '/pb-e2e-new' );
			// query: exact (the default) refuses extra parameters.
			await expectNotRedirected( '/pb-e2e-old?utm=1' );

			// query: pass appends the request's parameters; the target's own win.
			await expectRedirect( '/pb-e2e-promo?utm=mail', 302, '/pb-e2e-sale?src=promo&utm=mail' );
			await expectRedirect( '/pb-e2e-promo?src=other', 302, '/pb-e2e-sale?src=promo' );

			const gone = await raw( anonymous, '/pb-e2e-gone' );
			expect( gone.status() ).toBe( 410 );
			expect( gone.headers().location ).toBeUndefined();
			expect( gone.headers()[ 'x-redirect-by' ] ).toBe( 'proto-blocks' );
			// The theme's 404 template renders the body.
			expect( await gone.text() ).toContain( '</html>' );

			// pass: internal rewrite, the target's body under the old URL.
			const alias = await raw( anonymous, '/pb-e2e-alias' );
			expect( alias.status() ).toBe( 200 );
			expect( alias.headers().location ).toBeUndefined();
			expect( await alias.text() ).toContain( TARGET_BODY );

			// nothing: a plain 404 that is not logged; a real 404 is.
			await expectNotRedirected( '/pb-e2e-ignored' );
			await expectNotRedirected( '/pb-e2e-real-missing' );
			expect( notFoundRows( '/pb-e2e-ignored' ) ).toHaveLength( 0 );
			expect( notFoundRows( '/pb-e2e-real-missing' ) ).toHaveLength( 1 );
		} );

		test( 'regex capture, and a capture that would leave the site is collapsed to a path', async () => {
			writeGroup( 'e2e-regex', {
				title: 'E2E Regex',
				rules: [
					{ from: '^/pb-e2e-blog/(\\d+)/(.*)$', regex: true, to: '/news/$2?id=$1' },
					// Regex rules match path?query as one string.
					{ from: '^/pb-e2e-out\\?u=(.*)$', regex: true, to: '/$1' },
				],
			} );
			await expectRedirect( '/pb-e2e-blog/42/hello-world', 301, '/news/hello-world?id=42' );
			await expectNotRedirected( '/pb-e2e-blog/x/hello' );
			// "/" + "/evil.example" would be the protocol-relative //evil.example.
			await expectRedirect( '/pb-e2e-out?u=/evil.example', 301, '/evil.example' );
			const location = ( await visit( '/pb-e2e-out?u=//evil.example/x' ) ).location ?? '';
			expect( location.startsWith( '//' ) ).toBe( false );
			expect( location ).toMatch( /^\// );
		} );

		test( 'ignoreCase and ignoreTrailingSlash, on (default) and off', async () => {
			writeGroup( 'e2e-flags', {
				title: 'E2E Flags',
				rules: [
					{ from: '/pb-e2e-case-on', to: '/on' },
					{ from: '/Pb-E2e-Case-Off', to: '/off', ignoreCase: false },
					{ from: '/pb-e2e-slash-on', to: '/on' },
					{ from: '/pb-e2e-slash-off', to: '/off', ignoreTrailingSlash: false },
				],
			} );
			await expectRedirect( '/PB-E2E-CASE-ON', 301, '/on' );
			await expectRedirect( '/Pb-E2e-Case-Off', 301, '/off' );
			await expectNotRedirected( '/pb-e2e-case-off' );
			await expectRedirect( '/pb-e2e-slash-on/', 301, '/on' );
			await expectRedirect( '/pb-e2e-slash-off', 301, '/off' );
			await expectNotRedirected( '/pb-e2e-slash-off/' );
		} );

		test( 'when: login sends visitors to else and members to the target', async ( {
			page,
		} ) => {
			writeGroup( 'e2e-login', {
				title: 'E2E Login',
				rules: [
					{ from: '/pb-e2e-members', when: { type: 'login' }, to: '/pb-e2e-dashboard', else: '/pb-e2e-login' },
				],
			} );
			await expectRedirect( '/pb-e2e-members', 301, '/pb-e2e-login' );
			// page.request carries the admin session cookies.
			expect( await hop( page.request, '/pb-e2e-members' ) ).toEqual( {
				status: 301,
				location: '/pb-e2e-dashboard',
				by: 'proto-blocks',
			} );
		} );

		test( 'when: referrer (regex) and when: header', async () => {
			writeGroup( 'e2e-when', {
				title: 'E2E When',
				rules: [
					{ from: '/pb-e2e-landing', when: { type: 'referrer', value: 'google\\.', regex: true }, to: '/pb-e2e-landing-google' },
					{ from: '/pb-e2e-beta', when: { type: 'header', name: 'X-Pb-E2e', value: 'yes' }, to: '/pb-e2e-beta-on' },
				],
			} );
			await expectRedirect( '/pb-e2e-landing', 301, '/pb-e2e-landing-google', {
				headers: { Referer: 'https://www.google.com/search?q=x' },
			} );
			await expectNotRedirected( '/pb-e2e-landing' );
			expect(
				await visit( '/pb-e2e-landing', { headers: { Referer: 'https://bing.com/' } } )
			).toMatchObject( { status: 404, location: null } );

			await expectRedirect( '/pb-e2e-beta', 301, '/pb-e2e-beta-on', {
				headers: { 'x-pb-e2e': 'yes' },
			} );
			expect(
				await visit( '/pb-e2e-beta', { headers: { 'X-Pb-E2e': 'no' } } )
			).toMatchObject( { status: 404, location: null } );
			await expectNotRedirected( '/pb-e2e-beta' );
		} );

		test( 'when: 404 fires only when WordPress found nothing', async () => {
			writeGroup( 'e2e-404', {
				title: 'E2E 404',
				rules: [ { when: { type: '404' }, to: '/pb-e2e-caught' } ],
			} );
			await expectRedirect( '/pb-e2e-no-such-page', 301, '/pb-e2e-caught' );
			const found = await raw( anonymous, `/${ TARGET }/` );
			expect( found.status() ).toBe( 200 );
			expect( await found.text() ).toContain( TARGET_BODY );
			// A redirected 404 is not a logged 404.
			expect( notFoundRows( '/pb-e2e-no-such-page' ) ).toHaveLength( 0 );
		} );

		test( 'the REST prefix and wp-login.php are never redirected', async () => {
			writeGroup( 'e2e-excluded', {
				title: 'E2E Excluded',
				rules: [
					{ from: '^/wp-json/.*', regex: true, to: '/pb-e2e-nope' },
					{ from: '/wp-login.php', to: '/pb-e2e-nope' },
					{ when: { type: '404' }, to: '/pb-e2e-nope' },
				],
			} );
			const rest = await raw( anonymous, '/wp-json/' );
			expect( rest.status() ).toBe( 200 );
			expect( rest.headers()[ 'content-type' ] ).toContain( 'application/json' );
			// A REST 404 is the API's own JSON 404, not the catch-all rule.
			const restMissing = await raw( anonymous, '/wp-json/pb-e2e/v1/missing' );
			expect( restMissing.status() ).toBe( 404 );
			expect( restMissing.headers().location ).toBeUndefined();
			const login = await raw( anonymous, '/wp-login.php' );
			expect( login.status() ).toBe( 200 );
			expect( login.headers().location ).toBeUndefined();
			expect( notFoundRows( '/wp-json/pb-e2e/v1/missing' ) ).toHaveLength( 0 );
		} );

		test( 'POST is redirected; a 308 keeps the method (and 301 is still used for POST)', async () => {
			writeGroup( 'e2e-post', {
				title: 'E2E Post',
				rules: [
					{ from: '/pb-e2e-form', to: '/pb-e2e-form-new', code: 308 },
					{ from: '/pb-e2e-form-old', to: '/pb-e2e-form-new' },
				],
			} );
			await expectRedirect( '/pb-e2e-form', 308, '/pb-e2e-form-new', {
				method: 'POST',
				data: 'field=1',
			} );
			await expectRedirect( '/pb-e2e-form-old', 301, '/pb-e2e-form-new', {
				method: 'POST',
				data: 'field=1',
			} );
		} );

		test( 'a rule whose target is the request itself does not fire', async () => {
			writeGroup( 'e2e-loop', {
				title: 'E2E Loop',
				rules: [ { from: '^/pb-e2e-loop/?$', regex: true, to: '/pb-e2e-loop' } ],
			} );
			await expectNotRedirected( '/pb-e2e-loop' );
			await expectNotRedirected( '/pb-e2e-loop/' );
		} );

		test( 'hit counts increment per matched request', async ( { requestUtils } ) => {
			writeGroup( 'e2e-hits', {
				title: 'E2E Hits',
				rules: [
					{ id: 'r_0000e2e1', from: '/pb-e2e-counted', to: '/x' },
					{ id: 'r_0000e2e2', from: '/pb-e2e-uncounted', to: '/x', log: false },
				],
			} );
			for ( let i = 0; i < 3; i++ ) {
				await visit( '/pb-e2e-counted' );
				await visit( '/pb-e2e-uncounted' );
			}
			const group = ( await index( requestUtils ) ).groups.find( ( g ) => g.slug === 'e2e-hits' );
			const counted = group?.rules.find( ( r ) => r.id === 'r_0000e2e1' );
			const uncounted = group?.rules.find( ( r ) => r.id === 'r_0000e2e2' );
			expect( counted?.hits ).toBe( 3 );
			expect( counted?.lastHit ).toMatch( /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/ );
			expect( uncounted?.hits ).toBe( 0 );
			expect( tableRows( 'proto_redirect_log' ).filter( ( r ) => r.rule_id === 'r_0000e2e1' ) ).toHaveLength( 3 );
			expect( tableRows( 'proto_redirect_log' ).filter( ( r ) => r.rule_id === 'r_0000e2e2' ) ).toHaveLength( 0 );
		} );
	} );

	test.describe( 'edge cases', () => {
		test( 'an encoded slash (%2F) decodes to "/" in the engine; Apache refuses it before WordPress', async ( {
			requestUtils,
		} ) => {
			writeGroup( 'e2e-edge', { title: 'E2E Edge', rules: [ { from: '/pb-e2e-a/b', to: '/pb-e2e-ab' } ] } );
			const tested = await requestUtils.rest< { matched: boolean; target: string } >( {
				path: '/proto-blocks/v1/admin/redirects/test',
				params: { url: '/pb-e2e-a%2Fb' },
			} );
			expect( tested ).toMatchObject( { matched: true, target: '/pb-e2e-ab' } );
			// wp-env's Apache has AllowEncodedSlashes Off: its own 404, PHP never runs.
			const response = await raw( anonymous, '/pb-e2e-a%2Fb' );
			expect( response.status() ).toBe( 404 );
			expect( response.headers()[ 'x-redirect-by' ] ).toBeUndefined();
			expect( notFoundRows( '/pb-e2e-a/b' ) ).toHaveLength( 0 );
		} );

		test( 'double slashes in the request collapse', async () => {
			writeGroup( 'e2e-edge', { title: 'E2E Edge', rules: [ { from: '/pb-e2e-a/b', to: '/pb-e2e-ab' } ] } );
			await expectRedirect( '//pb-e2e-a//b', 301, '/pb-e2e-ab' );
			await expectRedirect( '/pb-e2e-a///b/', 301, '/pb-e2e-ab' );
		} );

		test( 'the root path / with and without a query', async () => {
			writeGroup( 'e2e-edge', { title: 'E2E Edge', rules: [ { from: '/', to: '/pb-e2e-home' } ] } );
			await expectRedirect( '/', 301, '/pb-e2e-home' );
			// query: exact, so the front page with a parameter is served.
			const withQuery = await raw( anonymous, '/?pb-e2e=1' );
			expect( withQuery.status() ).toBe( 200 );
		} );

		test( 'a unicode source matches the encoded and the literal request', async () => {
			writeGroup( 'e2e-edge', { title: 'E2E Edge', rules: [ { from: '/café', to: '/pb-e2e-coffee' } ] } );
			await expectRedirect( '/caf%C3%A9', 301, '/pb-e2e-coffee' );
			await expectRedirect( '/café', 301, '/pb-e2e-coffee' );
			await expectRedirect( '/CAF%C3%89', 301, '/pb-e2e-coffee' );
		} );

		test( 'query arrays a[]=1&a[]=2 match exactly and pass through', async () => {
			writeGroup( 'e2e-edge', {
				title: 'E2E Edge',
				rules: [
					{ from: '/pb-e2e-arr?a[]=1&a[]=2', to: '/pb-e2e-arr-exact' },
					{ from: '/pb-e2e-arr-pass', to: '/pb-e2e-arr-passed', query: 'pass' },
				],
			} );
			await expectRedirect( '/pb-e2e-arr?a[]=1&a[]=2', 301, '/pb-e2e-arr-exact' );
			await expectRedirect( '/pb-e2e-arr?a%5B%5D=1&a%5B%5D=2', 301, '/pb-e2e-arr-exact' );
			await expectNotRedirected( '/pb-e2e-arr?a[]=1' );
			const passed = ( await visit( '/pb-e2e-arr-pass?a[]=1&a[]=2' ) ).location ?? '';
			expect( decodeURIComponent( passed ) ).toBe( '/pb-e2e-arr-passed?a[]=1&a[]=2' );
		} );

		test( 'a fragment in the target moves after the passed query', async () => {
			writeGroup( 'e2e-edge', {
				title: 'E2E Edge',
				rules: [ { from: '/pb-e2e-frag', to: '/pb-e2e-frag-target#section', query: 'pass' } ],
			} );
			await expectRedirect( '/pb-e2e-frag?x=1', 301, '/pb-e2e-frag-target?x=1#section' );
			await expectRedirect( '/pb-e2e-frag', 301, '/pb-e2e-frag-target#section' );
		} );

		test( 'HEAD requests are redirected like GET', async () => {
			writeGroup( 'e2e-edge', { title: 'E2E Edge', rules: [ { from: '/pb-e2e-head', to: '/pb-e2e-head-new', code: 302 } ] } );
			await expectRedirect( '/pb-e2e-head', 302, '/pb-e2e-head-new', { method: 'HEAD' } );
		} );
	} );

	test.describe( 'the Redirects screen', () => {
		/** A group file with a hand-written comment between its rules. */
		const HAND = `// E2E hand-written header.
{
  "title": "E2E UI",
  "rules": [
    { "id": "r_e2e00001", "from": "/pb-e2e-fix-1", "to": "/pb-e2e-fix-1-new" },
    // keep me: a hand-written comment between rules
    { "id": "r_e2e00002", "from": "/pb-e2e-fix-2", "to": "/pb-e2e-fix-2-new" }
  ]
}
`;
		const COMMENT = '// keep me: a hand-written comment between rules';

		test( 'a logged 404 becomes a rule from the 404s tab, and its rows go away', async ( {
			page,
		} ) => {
			writeGroupText( 'e2e-ui', HAND );
			const lost = '/pb-e2e-lost-xyz';
			await expectNotRedirected( lost );
			await expectNotRedirected( lost );
			expect( notFoundRows( lost ) ).toHaveLength( 1 );

			await openScreen( page, '&tab=404s' );
			const row = page.getByRole( 'row' ).filter( { has: page.getByText( lost, { exact: true } ) } );
			await expect( row ).toBeVisible();
			await row.getByRole( 'button', { name: `Add redirect for ${ lost }` } ).click();

			const dialog = page.getByRole( 'dialog', { name: 'Add redirect for this 404' } );
			await expect( dialog.getByLabel( 'When a visitor requests', { exact: true } ) ).toHaveValue( lost );
			await expect( dialog.getByLabel( 'Send them to', { exact: true } ) ).toHaveValue( '' );
			await dialog.getByLabel( 'Send them to', { exact: true } ).fill( `/${ TARGET }/` );
			await dialog.getByRole( 'button', { name: 'Save redirect' } ).click();
			await expect( page.getByText( 'Redirect added.' ) ).toBeVisible();
			await expect( dialog ).toHaveCount( 0 );

			await expect.poll( () => readGroup( 'e2e-ui' ) ).toContain( `"from": "${ lost }"` );
			const text = readGroup( 'e2e-ui' );
			expect( text ).toContain( COMMENT );
			expect( text ).toContain( `"to": "/${ TARGET }/"` );
			await expect.poll( () => notFoundRows( lost ).length ).toBe( 0 );
			await expect( page.getByText( 'No 404s logged.' ) ).toBeVisible();
			await expectRedirect( lost, 301, `/${ TARGET }/` );
		} );

		test( 'create, edit, toggle, move, duplicate and delete rules; hand comments survive', async ( {
			page,
		} ) => {
			writeGroupText( 'e2e-ui', HAND );
			await openScreen( page );
			await expect( ruleRow( page, '/pb-e2e-fix-1' ) ).toContainText( 'E2E UI' );
			await expect( ruleRow( page, '/pb-e2e-fix-1' ) ).toContainText( 'File' );

			// Create.
			await page.getByRole( 'button', { name: 'Add redirect', exact: true } ).click();
			let dialog = page.getByRole( 'dialog', { name: 'Add redirect' } );
			await dialog.getByLabel( 'When a visitor requests', { exact: true } ).fill( '/pb-e2e-ui-a' );
			await dialog.getByLabel( 'Send them to', { exact: true } ).fill( '/pb-e2e-ui-b' );
			await dialog.getByLabel( /^Title/ ).fill( 'E2E created' );
			await dialog.getByRole( 'button', { name: 'Save redirect' } ).click();
			await expect( page.getByText( 'Redirect added.' ) ).toBeVisible();
			await expect( ruleRow( page, '/pb-e2e-ui-a' ) ).toBeVisible();
			let text = readGroup( 'e2e-ui' );
			expect( text ).toContain( COMMENT );
			expect( text ).toContain( '// E2E hand-written header.' );
			expect( text ).toMatch( /"id": "r_[a-f0-9]{8}", "title": "E2E created", "from": "\/pb-e2e-ui-a", "to": "\/pb-e2e-ui-b"/ );
			await expectRedirect( '/pb-e2e-ui-a', 301, '/pb-e2e-ui-b' );

			// Edit: new target, 302.
			await page.getByRole( 'button', { name: 'Edit /pb-e2e-ui-a', exact: true } ).click();
			dialog = page.getByRole( 'dialog', { name: 'Edit redirect' } );
			await expect( dialog.getByLabel( 'When a visitor requests', { exact: true } ) ).toHaveValue( '/pb-e2e-ui-a' );
			await dialog.getByLabel( 'Send them to', { exact: true } ).fill( '/pb-e2e-ui-c' );
			await choose( dialog, page, 'With status', '302 · Found (temporary)' );
			await dialog.getByRole( 'button', { name: 'Save redirect' } ).click();
			await expect( page.getByText( 'Redirect saved.' ) ).toBeVisible();
			await expect.poll( () => readGroup( 'e2e-ui' ) ).toContain( '"to": "/pb-e2e-ui-c"' );
			text = readGroup( 'e2e-ui' );
			expect( text ).toContain( '"code": 302' );
			expect( text ).toContain( COMMENT );
			await expectRedirect( '/pb-e2e-ui-a', 302, '/pb-e2e-ui-c' );

			// Toggle off and on.
			const toggle = ruleRow( page, '/pb-e2e-ui-a' ).getByRole( 'switch' );
			await toggle.click();
			await expect( toggle ).not.toBeChecked();
			await expect( toggle ).toBeEnabled();
			expect( readGroup( 'e2e-ui' ) ).toMatch( /"from": "\/pb-e2e-ui-a"[^\n]*"enabled": false|"enabled": false[^\n]*"from": "\/pb-e2e-ui-a"/ );
			await expectNotRedirected( '/pb-e2e-ui-a' );
			await toggle.click();
			await expect( toggle ).toBeChecked();
			await expect( toggle ).toBeEnabled();
			expect( readGroup( 'e2e-ui' ) ).not.toContain( '"enabled": false' );
			await expectRedirect( '/pb-e2e-ui-a', 302, '/pb-e2e-ui-c' );

			// Move: the new rule (last) goes up one, above fix-2.
			const order = () => [ ...readGroup( 'e2e-ui' ).matchAll( /"from": "([^"]+)"/g ) ].map( ( m ) => m[ 1 ] );
			expect( order() ).toEqual( [ '/pb-e2e-fix-1', '/pb-e2e-fix-2', '/pb-e2e-ui-a' ] );
			await rowAction( page, '/pb-e2e-ui-a', 'Move /pb-e2e-ui-a up' );
			await expect.poll( order ).toEqual( [ '/pb-e2e-fix-1', '/pb-e2e-ui-a', '/pb-e2e-fix-2' ] );
			await rowAction( page, '/pb-e2e-fix-1', 'Move /pb-e2e-fix-1 down' );
			await expect.poll( order ).toEqual( [ '/pb-e2e-ui-a', '/pb-e2e-fix-1', '/pb-e2e-fix-2' ] );
			expect( readGroup( 'e2e-ui' ) ).toContain( COMMENT );

			// Duplicate: a copy right below, with a new id.
			await rowAction( page, '/pb-e2e-fix-2', 'Duplicate /pb-e2e-fix-2' );
			await expect( page.getByText( 'Redirect duplicated.' ) ).toBeVisible();
			await expect.poll( order ).toEqual( [ '/pb-e2e-ui-a', '/pb-e2e-fix-1', '/pb-e2e-fix-2', '/pb-e2e-fix-2' ] );
			const ids = [ ...readGroup( 'e2e-ui' ).matchAll( /"id": "(r_[a-f0-9]{8})"/g ) ].map( ( m ) => m[ 1 ] );
			expect( new Set( ids ).size ).toBe( 4 );

			// Delete the created rule.
			await rowAction( page, '/pb-e2e-ui-a', 'Delete /pb-e2e-ui-a' );
			await page.getByRole( 'alertdialog' ).getByRole( 'button', { name: 'Delete 1 redirect' } ).click();
			await expect( page.getByText( 'Redirect deleted.' ) ).toBeVisible();
			await expect.poll( order ).toEqual( [ '/pb-e2e-fix-1', '/pb-e2e-fix-2', '/pb-e2e-fix-2' ] );
			await expect( ruleRow( page, '/pb-e2e-ui-a' ) ).toHaveCount( 0 );
			text = readGroup( 'e2e-ui' );
			expect( text ).toContain( COMMENT );
			expect( text ).toContain( '// E2E hand-written header.' );
			await expectNotRedirected( '/pb-e2e-ui-a' );
		} );

		test( 'create, rename, disable and delete a group', async ( { page } ) => {
			writeGroupText( 'e2e-ui', HAND );
			await openScreen( page );
			await page.getByRole( 'button', { name: 'Groups', exact: true } ).click();
			const dialog = page.getByRole( 'dialog', { name: 'Groups' } );
			await dialog.getByLabel( 'New group', { exact: true } ).fill( 'E2E Second' );
			await expect( dialog.getByText( 'proto-redirects/e2e-second/redirects.jsonc' ) ).toBeVisible();
			await dialog.getByRole( 'button', { name: 'Create group' } ).click();
			await expect( page.getByText( 'Created group E2E Second.' ) ).toBeVisible();
			await expect.poll( () => groupExists( 'e2e-second' ) ).toBe( true );
			expect( readGroup( 'e2e-second' ) ).toContain( '"title": "E2E Second"' );

			const item = dialog.getByRole( 'listitem' ).filter( { hasText: 'e2e-second' } );
			await item.getByLabel( 'Title', { exact: true } ).fill( 'E2E Renamed' );
			await item.getByLabel( 'Position', { exact: true } ).fill( '5' );
			await item.getByRole( 'button', { name: 'Save', exact: true } ).click();
			await expect( page.getByText( 'Saved group E2E Renamed.' ) ).toBeVisible();
			await expect.poll( () => readGroup( 'e2e-second' ) ).toContain( '"title": "E2E Renamed"' );
			expect( readGroup( 'e2e-second' ) ).toContain( '"position": 5' );

			// Disabling the hand-written group stops its rules; its comment stays.
			await expectRedirect( '/pb-e2e-fix-1', 301, '/pb-e2e-fix-1-new' );
			const ui = dialog.getByRole( 'listitem' ).filter( { hasText: 'e2e-ui' } );
			await ui.getByRole( 'switch', { name: 'Enable group E2E UI' } ).click();
			await expect( page.getByText( 'Group disabled.' ) ).toBeVisible();
			await expect.poll( () => readGroup( 'e2e-ui' ) ).toContain( '"enabled": false' );
			expect( readGroup( 'e2e-ui' ) ).toContain( COMMENT );
			await expectNotRedirected( '/pb-e2e-fix-1' );

			const renamed = dialog.getByRole( 'listitem' ).filter( { hasText: 'e2e-second' } );
			await renamed.getByRole( 'button', { name: 'Delete group E2E Renamed' } ).click();
			await page.getByRole( 'alertdialog' ).getByRole( 'button', { name: 'Delete group' } ).click();
			await expect( page.getByText( 'Group deleted.' ) ).toBeVisible();
			await expect.poll( () => groupExists( 'e2e-second' ) ).toBe( false );
			expect( groupExists( 'e2e-ui' ) ).toBe( true );
		} );

		test( 'the Test box shows which rule fires and the trace', async ( { page } ) => {
			writeGroup( 'e2e-test', {
				title: 'E2E Test',
				rules: [
					{ id: 'r_e2e0000a', from: '/pb-e2e-probe', to: '/first', enabled: false },
					{ id: 'r_e2e0000b', from: '/pb-e2e-probe', when: { type: 'header', name: 'X-Pb', value: '1' }, to: '/second' },
					{ id: 'r_e2e0000c', from: '/pb-e2e-probe', to: '/third', code: 307 },
				],
			} );
			await openScreen( page );
			await page.getByLabel( 'URL to test' ).fill( '/pb-e2e-probe' );
			await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
			await expect( page.getByText( 'Rule r_e2e0000c in e2e-test fires: Redirect to URL 307 → /third' ) ).toBeVisible();
			const trace = page.getByRole( 'table', { name: 'Evaluation trace' } );
			await expect( trace.getByRole( 'row' ).filter( { hasText: 'r_e2e0000a' } ) ).toContainText( 'Skipped' );
			await expect( trace.getByRole( 'row' ).filter( { hasText: 'r_e2e0000b' } ) ).toContainText( 'Skipped' );
			await expect( trace.getByRole( 'row' ).filter( { hasText: 'r_e2e0000c' } ) ).toContainText( 'Matched' );

			await page.getByLabel( 'URL to test' ).fill( '/pb-e2e-nothing-here' );
			await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
			await expect( page.getByText( 'No rule fires for this URL.' ) ).toBeVisible();
		} );

		test( 'a PHP rule shows its origin; saving it into a file group is declared twice', async ( {
			page,
			requestUtils,
		} ) => {
			writeGroupText( 'e2e-ui', HAND );
			wpCli( `wp option update ${ PHP_OPTION } /pb-e2e-php-src` );
			await expectRedirect( '/pb-e2e-php-src', 301, '/pb-e2e-php-target' );
			await openScreen( page );
			const php = ruleRow( page, '/pb-e2e-php-src' );
			await expect( php ).toContainText( 'PHP' );
			await expect( php ).toContainText( 'Declared in PHP' );
			await expect( php.getByRole( 'switch' ) ).toBeDisabled();

			await php.getByRole( 'button', { name: 'Edit /pb-e2e-php-src', exact: true } ).click();
			const dialog = page.getByRole( 'dialog', { name: 'Edit redirect' } );
			await expect( dialog ).toContainText( 'This rule is declared in PHP.' );
			await dialog.getByLabel( 'Send them to', { exact: true } ).fill( '/pb-e2e-php-file-target' );
			await dialog.getByRole( 'button', { name: 'Save redirect' } ).click();
			await expect( page.getByText( 'Redirect saved.' ) ).toBeVisible();
			await expect.poll( () => readGroup( 'e2e-ui' ) ).toContain( '"from": "/pb-e2e-php-src"' );

			// The file wins; the PHP twin is dropped with double_declaration.
			const file = ruleRow( page, '/pb-e2e-php-src' );
			await expect( file ).toHaveCount( 1 );
			await expect( file ).toContainText( 'File' );
			await expect( file.getByText( 'Declared twice' ) ).toBeVisible();
			const rules = ( await index( requestUtils ) ).groups.flatMap( ( g ) => g.rules );
			const twins = rules.filter( ( r ) => r.from === '/pb-e2e-php-src' );
			expect( twins ).toHaveLength( 1 );
			expect( twins[ 0 ].origin ).toBe( 'file' );
			expect( twins[ 0 ].issues.map( ( i ) => i.code ) ).toContain( 'double_declaration' );
			await expectRedirect( '/pb-e2e-php-src', 301, '/pb-e2e-php-file-target' );
		} );

		test( 'CSV import: preview, duplicate mode, file written', async ( { page } ) => {
			writeGroupText( 'e2e-ui', HAND );
			await openScreen( page, '&tab=import' );
			await page.getByLabel( 'Redirect file' ).setInputFiles( {
				name: 'import.csv',
				mimeType: 'text/csv',
				buffer: Buffer.from(
					[
						'source,target,regex,code',
						'/pb-e2e-imp-1,/pb-e2e-imp-1-new,0,301',
						'/pb-e2e-fix-1,/pb-e2e-fix-1-replaced,0,302',
						'/pb-e2e-imp-2,,0,410',
						'/pb-e2e-same,/pb-e2e-same,0,301',
						'',
					].join( '\n' )
				),
			} );
			await page.getByRole( 'button', { name: 'Preview', exact: true } ).first().click();
			const preview = page.getByRole( 'table', { name: 'Import preview' } );
			await expect( preview.getByRole( 'row' ).filter( { hasText: '/pb-e2e-imp-1' } ) ).toContainText( 'New' );
			await expect( preview.getByRole( 'row' ).filter( { hasText: '/pb-e2e-fix-1' } ) ).toContainText( 'Duplicate' );
			await expect( preview.getByRole( 'row' ).filter( { hasText: '/pb-e2e-imp-2' } ) ).toContainText( 'New' );
			await expect( page.getByRole( 'status' ).filter( { hasText: /new, \d+ duplicate/ } ) ).toContainText( '2 new, 1 duplicate' );

			await page.getByRole( 'radio', { name: 'Replace the existing rule' } ).click();
			await page.getByRole( 'button', { name: 'Import 3 rules' } ).click();
			await expect( page.getByText( 'Imported: 2 added, 1 replaced, 0 skipped.' ) ).toBeVisible();

			const text = readGroup( 'e2e-ui' );
			expect( text ).toContain( COMMENT );
			expect( text ).toContain( '"from": "/pb-e2e-imp-1"' );
			expect( text ).toContain( '"from": "/pb-e2e-imp-2"' );
			expect( text ).toContain( '"to": "/pb-e2e-fix-1-replaced"' );
			expect( text ).not.toContain( '/pb-e2e-same' );
			await expectRedirect( '/pb-e2e-imp-1', 301, '/pb-e2e-imp-1-new' );
			await expectRedirect( '/pb-e2e-fix-1', 302, '/pb-e2e-fix-1-replaced' );
			expect( ( await raw( anonymous, '/pb-e2e-imp-2' ) ).status() ).toBe( 410 );
		} );

		test( 'exports download as CSV, JSON, Apache and Nginx files', async ( { page } ) => {
			writeGroupText( 'e2e-ui', HAND );
			await openScreen( page, '&tab=import' );

			// The Download button saves the server's file.
			const [ csv ] = await Promise.all( [
				page.waitForEvent( 'download' ),
				page.getByRole( 'button', { name: 'Download', exact: true } ).click(),
			] );
			expect( csv.suggestedFilename() ).toMatch( /\.csv$/ );
			const saved = await csv.path();
			const fs = await import( 'fs' );
			const body = fs.readFileSync( saved, 'utf8' );
			// RFC 4180 line ends.
			expect( body.split( '\r\n' )[ 0 ] ).toBe( 'source,target,regex,code,type,hits,title,status,group' );
			expect( body ).toContain( '/pb-e2e-fix-1,/pb-e2e-fix-1-new' );

			await choose( page, page, 'Format', 'Nginx' );
			await choose( page, page, 'Group to export', 'E2E UI' );
			const [ nginx ] = await Promise.all( [
				page.waitForEvent( 'download' ),
				page.getByRole( 'button', { name: 'Download', exact: true } ).click(),
			] );
			expect( fs.readFileSync( await nginx.path(), 'utf8' ) ).toContain( 'pb-e2e-fix-1' );

			// The route itself: raw bodies with a Content-Disposition, not JSON strings.
			const nonce = await page.evaluate(
				() => ( window as unknown as { protoBlocksAdmin: { restNonce: string } } ).protoBlocksAdmin.restNonce
			);
			const get = ( format: string ) =>
				page.request.get( `/wp-json/proto-blocks/v1/admin/redirects/export?format=${ format }`, {
					headers: { 'X-WP-Nonce': nonce },
				} );
			for ( const [ format, type, marker ] of [
				[ 'csv', 'text/csv', 'source,target,regex,code' ],
				[ 'json', 'application/json', '"groups"' ],
				[ 'apache', 'text/plain', '# Proto-Blocks redirects' ],
				[ 'nginx', 'text/plain', 'rewrite ' ],
			] ) {
				const response = await get( format );
				expect( response.status(), format ).toBe( 200 );
				expect( response.headers()[ 'content-type' ], format ).toContain( type );
				expect( response.headers()[ 'content-disposition' ], format ).toMatch( /^attachment; filename="[^"]+"$/ );
				const text = await response.text();
				expect( text, format ).toContain( marker );
				expect( text.startsWith( '"' ), `${ format } is not a JSON-encoded string` ).toBe( false );
				expect( text, format ).toContain( 'pb-e2e-fix-1' );
			}
			const json = JSON.parse( await ( await get( 'json' ) ).text() );
			expect( json.groups[ 'e2e-ui' ].rules[ 0 ].from ).toBe( '/pb-e2e-fix-1' );
		} );

		test( 'settings save writes settings.jsonc; notFound -1 stops 404 logging', async ( {
			page,
		} ) => {
			await expectNotRedirected( '/pb-e2e-logged-before' );
			expect( notFoundRows( '/pb-e2e-logged-before' ) ).toHaveLength( 1 );

			await openScreen( page, '&tab=settings' );
			await choose( page, page, '404 log', 'Do not log' );
			await page.getByRole( 'button', { name: 'Save settings' } ).click();
			await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
			await expect
				.poll( () => themeFile( THEME, 'proto-redirects/settings.jsonc' ) )
				.toMatch( /"notFound": -1/ );

			await expectNotRedirected( '/pb-e2e-logged-after' );
			expect( notFoundRows( '/pb-e2e-logged-after' ) ).toHaveLength( 0 );
		} );

		test( 'the log tab lists hits with links to their rules', async ( { page } ) => {
			writeGroupText( 'e2e-ui', HAND );
			await visit( '/pb-e2e-fix-2' );
			await visit( '/pb-e2e-fix-2?' );
			await openScreen( page );
			await expect( ruleRow( page, '/pb-e2e-fix-2' ).getByRole( 'cell' ).nth( 4 ) ).toHaveText( '2' );
			await expect( ruleRow( page, '/pb-e2e-fix-1' ).getByRole( 'cell' ).nth( 4 ) ).toHaveText( '0' );

			await page.getByRole( 'tab', { name: 'Log' } ).click();
			await expect( page ).toHaveURL( /[?&]tab=log/ );
			const log = page.getByRole( 'table', { name: 'Redirect log' } );
			const rows = log.getByRole( 'row' ).filter( { hasText: '/pb-e2e-fix-2-new' } );
			await expect( rows ).toHaveCount( 2 );
			await expect( rows.first() ).toContainText( '301' );
			await expect( rows.first() ).toContainText( 'proto-blocks' );
			await rows.first().getByRole( 'button', { name: 'Edit rule r_e2e00002' } ).click();
			const dialog = page.getByRole( 'dialog', { name: 'Edit redirect' } );
			await expect( dialog.getByLabel( 'When a visitor requests', { exact: true } ) ).toHaveValue( '/pb-e2e-fix-2' );
		} );

		test( 'read-only when proto-redirects is not writable (chmod)', async ( {
			page,
			requestUtils,
		} ) => {
			writeGroupText( 'e2e-ui', HAND );
			const dir = `wp-content/themes/${ THEME }/proto-redirects`;
			wpCli( `chmod -R a-w ${ dir }` );
			try {
				const state = await requestUtils.rest< { createTarget: { writable: boolean } } >( {
					path: '/proto-blocks/v1/admin/redirects',
				} );
				// Docker Desktop for macOS (virtiofs bind mounts) ignores the
				// mode bits: PHP's is_writable() stays true and writes succeed.
				// Linux Docker honours them. The locked-group test below covers
				// the per-group lock on every platform.
				test.skip(
					state.createTarget.writable,
					'this Docker file share ignores chmod (is_writable() stays true)'
				);
				await openScreen( page );
				await expect( page.getByText( dir ).first() ).toBeVisible();
				await expect( page.getByRole( 'button', { name: 'Add redirect', exact: true } ) ).toBeDisabled();
				const row = ruleRow( page, '/pb-e2e-fix-1' );
				await expect( row.getByRole( 'switch' ) ).toBeDisabled();
				await expect( await rowMenuItem( page, '/pb-e2e-fix-1', 'Delete /pb-e2e-fix-1' ) ).toBeDisabled();
				await page.keyboard.press( 'Escape' );
				await expect( row.getByRole( 'button', { name: 'Edit /pb-e2e-fix-1', exact: true } ) ).toBeDisabled();
				// The rules still run.
				await expectRedirect( '/pb-e2e-fix-1', 301, '/pb-e2e-fix-1-new' );
			} finally {
				wpCli( `chmod -R a+rwX ${ dir }` );
			}
		} );

		test( 'a group the screen cannot write is read-only and still redirects', async ( {
			page,
			requestUtils,
		} ) => {
			writeGroupText( 'e2e-ui', HAND );
			wpCli( 'wp option update pb_e2e_redirects_readonly 1' );
			try {
				const locked = ( await index( requestUtils ) ).groups.find( ( g ) => g.slug === 'e2e-locked' ) as unknown as {
					writable: boolean;
					readOnlyReason: string | null;
				};
				expect( locked ).toMatchObject( { writable: false, readOnlyReason: 'read_only' } );
				await openScreen( page );
				const row = ruleRow( page, '/pb-e2e-locked' );
				await expect( row ).toContainText( 'E2E Locked' );
				await expect( row ).toContainText( 'Read-only: the file is outside the theme' );
				await expect( row.getByRole( 'switch' ) ).toBeDisabled();
				await expect( row.getByRole( 'button', { name: 'Edit /pb-e2e-locked', exact: true } ) ).toBeDisabled();
				for ( const name of [ 'Move /pb-e2e-locked down', 'Duplicate /pb-e2e-locked', 'Delete /pb-e2e-locked' ] ) {
					await expect( await rowMenuItem( page, '/pb-e2e-locked', name ), name ).toBeDisabled();
					await page.keyboard.press( 'Escape' );
				}
				// The writable group next to it stays editable.
				await expect( ruleRow( page, '/pb-e2e-fix-1' ).getByRole( 'switch' ) ).toBeEnabled();
				await expectRedirect( '/pb-e2e-locked', 301, '/pb-e2e-locked-new' );
			} finally {
				wpCli( 'wp option delete pb_e2e_redirects_readonly' );
			}
		} );

		test( 'the permalink monitor adds a rule when a watched post changes its slug', async ( {
			requestUtils,
		} ) => {
			writeSettings( { monitor: { postTypes: [ 'post' ] } } );
			const id = await createPost( requestUtils, 'posts', 'pb-e2e-moved-a' );
			posts.push( id );
			await requestUtils.rest( {
				method: 'POST',
				path: `/wp/v2/posts/${ id }`,
				data: { slug: 'pb-e2e-moved-b' },
			} );
			await expect.poll( () => groupExists( 'modified-posts' ) ).toBe( true );
			const text = readGroup( 'modified-posts' );
			expect( text ).toContain( '"from": "/pb-e2e-moved-a/"' );
			expect( text ).toContain( '"to": "/pb-e2e-moved-b/"' );
			// Proto-Blocks answers (not core's old-slug redirect).
			await expectRedirect( '/pb-e2e-moved-a/', 301, '/pb-e2e-moved-b/' );
		} );
	} );
} );
