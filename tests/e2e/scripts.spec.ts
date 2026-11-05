import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext, Page } from '@playwright/test';
import {
	prepareFixtureSite,
	themeFile,
	themePathExists,
	wpCli,
} from './content-model-utils';
import {
	PHP_OPTION,
	SCREEN,
	THEME,
	clearPhpScript,
	createPage,
	deletePages,
	html,
	marker,
	removeScripts,
	writeScript,
} from './scripts-utils';

/**
 * The Scripts module end to end (spec docs/superpowers/specs/2026-11-05-scripts-design.md §8):
 * front-end output of file scripts, the admin screen (create, edit, toggle,
 * delete), PHP and declared-twice origins, the unfiltered_html read-only mode
 * and the php_in_snippet refusal. Everything is written into a fresh copy of
 * the pb-e2e-block theme; `proto-scripts/` is removed after every test.
 */

const EXCLUDED = 'pb-e2e-excluded';
const GLOB = 'pb-e2e-glob-target';
const HEAD = '<meta name="pb-e2e-head" content="head">';
const BODY_OPEN = '<noscript id="pb-e2e-body-open"></noscript>';
const BODY_CLOSE = '<script id="pb-e2e-body-close">window.pbE2e = 1;</script>';
const VIEWER = {
	username: 'pb-e2e-script-viewer',
	email: 'pb-e2e-script-viewer@example.test',
	password: 'pb-e2e-script-viewer-password',
};

const BASE_URL = process.env.WP_BASE_URL ?? 'http://localhost:8889';

/**
 * Shows one snippet location of the editor (only the active tab's editor is rendered).
 * @param page The current page.
 * @param name The tab's caption, e.g. /^after <body>/.
 */
async function snippetTab( page: Page, name: RegExp ) {
	await page.getByRole( 'tab', { name } ).click();
}

async function openScreen( page: Page, query = '' ) {
	await page.goto( `${ SCREEN }${ query }` );
	await expect( page.locator( '[aria-busy="true"]' ) ).toHaveCount( 0 );
}

/**
 * The list row of a script, by its title.
 * @param page  The current page.
 * @param title Script title.
 */
function row( page: Page, title: string ) {
	return page
		.getByRole( 'row' )
		.filter( { has: page.getByText( title, { exact: true } ) } );
}

/** Position of the end of the `<body …>` open tag. */
function bodyOpenEnd( text: string ): number {
	const match = /<body[^>]*>/i.exec( text );
	expect( match, 'the page has a <body> tag' ).not.toBeNull();
	return ( match?.index ?? 0 ) + ( match?.[ 0 ].length ?? 0 );
}

test.describe( 'Scripts', () => {
	let previousTheme = '';
	const pages: number[] = [];
	let anonymous: APIRequestContext;

	test.beforeAll( async ( { requestUtils, playwright } ) => {
		previousTheme = await prepareFixtureSite( requestUtils );
		clearPhpScript();
		pages.push( await createPage( requestUtils, EXCLUDED ) );
		pages.push( await createPage( requestUtils, GLOB ) );
		anonymous = await playwright.request.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
	} );

	test.afterEach( () => {
		removeScripts();
		clearPhpScript();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		removeScripts();
		clearPhpScript();
		try {
			wpCli( `wp user delete ${ VIEWER.username } --yes` );
		} catch {
			// Not created (or already gone).
		}
		await deletePages( requestUtils, pages );
		await anonymous?.dispose();
		if ( previousTheme ) {
			await requestUtils.activateTheme( previousTheme );
		}
	} );

	test( 'a file script prints at its three locations, and not on an excluded page', async () => {
		writeScript(
			'e2e-fixture',
			{
				title: 'E2E Fixture',
				enabled: true,
				head: { file: 'head.html' },
				bodyOpen: { file: 'body-open.html' },
				bodyClose: BODY_CLOSE,
				where: { include: [ 'all' ], exclude: [ `page:${ EXCLUDED }` ] },
			},
			{ 'head.html': HEAD, 'body-open.html': BODY_OPEN }
		);

		const front = await html( anonymous, '/' );
		const tag = marker( 'e2e-fixture' );
		const head = front.indexOf( `${ tag }\n${ HEAD }` );
		const open = front.indexOf( `${ tag }\n${ BODY_OPEN }` );
		const close = front.indexOf( `${ tag }\n${ BODY_CLOSE }` );
		expect( head, 'head snippet with its marker' ).toBeGreaterThan( -1 );
		expect( open, 'body-open snippet with its marker' ).toBeGreaterThan( -1 );
		expect( close, 'body-close snippet with its marker' ).toBeGreaterThan( -1 );

		// <head> … head snippet … </head> <body> body-open … body-close </body>
		expect( head ).toBeGreaterThan( front.search( /<head[\s>]/i ) );
		expect( head ).toBeLessThan( front.indexOf( '</head>' ) );
		const bodyStart = bodyOpenEnd( front );
		expect( open ).toBeGreaterThanOrEqual( bodyStart );
		// Right after <body>: before the theme's header template part.
		expect( open ).toBeLessThan( front.indexOf( '<header', bodyStart ) );
		expect( close ).toBeGreaterThan( open );
		expect( close ).toBeLessThan( front.lastIndexOf( '</body>' ) );
		// Each snippet exactly once.
		expect( front.split( HEAD ) ).toHaveLength( 2 );
		expect( front.split( BODY_OPEN ) ).toHaveLength( 2 );
		expect( front.split( BODY_CLOSE ) ).toHaveLength( 2 );

		const excluded = await html( anonymous, `/${ EXCLUDED }/` );
		expect( excluded ).toContain( '</body>' );
		expect( excluded ).not.toContain( tag );
		expect( excluded ).not.toContain( HEAD );
		expect( excluded ).not.toContain( BODY_CLOSE );
	} );

	test( 'where.loggedIn splits logged-in and logged-out visitors', async ( {
		page,
	} ) => {
		writeScript( 'e2e-in', {
			title: 'E2E Logged In',
			head: '<meta name="pb-e2e-logged-in" content="1">',
			where: { loggedIn: true },
		} );
		writeScript( 'e2e-out', {
			title: 'E2E Logged Out',
			head: '<meta name="pb-e2e-logged-out" content="1">',
			where: { loggedIn: false },
		} );

		const visitor = await html( anonymous, '/' );
		expect( visitor ).toContain( 'name="pb-e2e-logged-out"' );
		expect( visitor ).not.toContain( 'name="pb-e2e-logged-in"' );

		// page.request carries the admin session cookies.
		const admin = await html( page.request, '/' );
		expect( admin ).toContain( 'name="pb-e2e-logged-in"' );
		expect( admin ).not.toContain( 'name="pb-e2e-logged-out"' );
	} );

	test( 'a url: glob include only matches its paths', async () => {
		writeScript( 'e2e-url', {
			title: 'E2E Url',
			bodyClose: '<script id="pb-e2e-url">1</script>',
			where: { include: [ 'url:/pb-e2e-glob*' ] },
		} );
		expect( await html( anonymous, `/${ GLOB }/` ) ).toContain(
			'id="pb-e2e-url"'
		);
		expect( await html( anonymous, `/${ GLOB }/?utm=1` ) ).toContain(
			'id="pb-e2e-url"'
		);
		expect( await html( anonymous, '/' ) ).not.toContain( 'id="pb-e2e-url"' );
		expect( await html( anonymous, `/${ EXCLUDED }/` ) ).not.toContain(
			'id="pb-e2e-url"'
		);
	} );

	test( 'create a script in the UI, edit it and toggle it from the list', async ( {
		page,
	} ) => {
		await openScreen( page );
		// No scripts yet: the empty state's template tiles.
		await page.getByRole( 'button', { name: /^Blank script/ } ).click();

		await page.getByLabel( 'Script title', { exact: true } ).fill( 'E2E UI Script' );
		await expect( page.getByLabel( 'Slug (folder name)' ) ).toHaveValue(
			'e2e-ui-script'
		);
		await page.getByLabel( 'Priority', { exact: true } ).fill( '5' );
		await page.getByRole( 'textbox', { name: 'In <head>', exact: true } ).fill( '<meta name="pb-e2e-ui" content="head">' );
		await snippetTab( page, /^after <body>/ );
		await page.getByRole( 'textbox', { name: 'Right after <body>', exact: true } ).fill( '<div id="pb-e2e-ui-open"></div>' );
		await snippetTab( page, /^before <\/body>/ );
		await page.getByRole( 'textbox', { name: 'Before </body>', exact: true } ).fill( '<script>window.pbE2eUi = 1;</script>' );

		// Placement: exclude a page, logged-out visitors only.
		await page.getByLabel( 'Exclude target kind' ).selectOption( 'page:' );
		await page.getByLabel( 'Exclude target value' ).fill( EXCLUDED );
		await page.getByRole( 'button', { name: 'Add to exclude' } ).click();
		await expect(
			page.getByRole( 'list', { name: 'Exclude' } ).getByText( `Page · ${ EXCLUDED }`, { exact: true } )
		).toBeVisible();
		await page.getByRole( 'radio', { name: 'Logged out', exact: true } ).click();

		await page.getByRole( 'button', { name: 'Save to file', exact: true } ).click();
		await expect( page.getByText( 'Script saved.' ) ).toBeVisible();
		await expect( page ).toHaveURL( /[?&]slug=e2e-ui-script/ );

		const dir = 'proto-scripts/e2e-ui-script';
		const jsonc = themeFile( THEME, `${ dir }/script.jsonc` );
		expect( jsonc ).toContain( '// Proto-Blocks script' );
		expect( jsonc ).toContain( '"title": "E2E UI Script"' );
		expect( jsonc ).toContain( '"priority": 5' );
		expect( jsonc ).toMatch( /"head": \{\s*"file": "head\.html"\s*\}/ );
		expect( jsonc ).toMatch( /"bodyOpen": \{\s*"file": "body-open\.html"\s*\}/ );
		expect( jsonc ).toMatch( /"bodyClose": \{\s*"file": "body-close\.html"\s*\}/ );
		expect( jsonc ).toMatch( /"exclude": \[\s*"page:pb-e2e-excluded"\s*\]/ );
		expect( jsonc ).toContain( '"loggedIn": false' );
		expect( themeFile( THEME, `${ dir }/head.html` ) ).toBe(
			'<meta name="pb-e2e-ui" content="head">'
		);
		expect( themeFile( THEME, `${ dir }/body-open.html` ) ).toBe(
			'<div id="pb-e2e-ui-open"></div>'
		);
		expect( themeFile( THEME, `${ dir }/body-close.html` ) ).toBe(
			'<script>window.pbE2eUi = 1;</script>'
		);

		const front = await html( anonymous, '/' );
		expect( front ).toContain( `${ marker( 'e2e-ui-script' ) }\n<meta name="pb-e2e-ui"` );
		expect( await html( anonymous, `/${ EXCLUDED }/` ) ).not.toContain( 'pb-e2e-ui' );

		// Edit: the title changes, the head snippet is emptied (its file and key go away).
		await page.getByLabel( 'Script title', { exact: true } ).fill( 'E2E UI Script Edited' );
		await snippetTab( page, /^<head>/ );
		await page.getByRole( 'textbox', { name: 'In <head>', exact: true } ).fill( '' );
		await page.getByRole( 'button', { name: 'Save to file', exact: true } ).click();
		await expect( page.getByText( 'Script saved.' ).first() ).toBeVisible();
		await expect.poll( () => themeFile( THEME, `${ dir }/script.jsonc` ) ).toContain(
			'"title": "E2E UI Script Edited"'
		);
		const edited = themeFile( THEME, `${ dir }/script.jsonc` );
		expect( edited ).toContain( '// Proto-Blocks script' );
		expect( edited ).not.toContain( '"head"' );
		expect( themePathExists( THEME, `${ dir }/head.html` ) ).toBe( false );
		expect( themePathExists( THEME, `${ dir }/body-close.html` ) ).toBe( true );

		// Toggle from the list.
		await page.getByRole( 'button', { name: 'All scripts' } ).click();
		const item = row( page, 'E2E UI Script Edited' );
		await expect( item ).toContainText( 'Proto-Blocks file' );
		await expect( item ).toContainText( 'e2e-ui-script' );
		const toggle = item.getByRole( 'switch', { name: 'Enable E2E UI Script Edited' } );
		await expect( toggle ).toBeChecked();
		await toggle.click();
		await expect( toggle ).not.toBeChecked();
		await expect( toggle ).toBeEnabled();
		expect( themeFile( THEME, `${ dir }/script.jsonc` ) ).toContain( '"enabled": false' );
		expect( await html( anonymous, '/' ) ).not.toContain( marker( 'e2e-ui-script' ) );

		await toggle.click();
		await expect( toggle ).toBeChecked();
		await expect( toggle ).toBeEnabled();
		expect( themeFile( THEME, `${ dir }/script.jsonc` ) ).toContain( '"enabled": true' );
		expect( await html( anonymous, '/' ) ).toContain( 'id="pb-e2e-ui-open"' );
	} );

	test( 'delete removes the script folder', async ( { page } ) => {
		writeScript(
			'e2e-delete',
			{ title: 'E2E Delete', head: { file: 'head.html' } },
			{ 'head.html': HEAD }
		);
		await openScreen( page );
		await row( page, 'E2E Delete' )
			.getByRole( 'button', { name: 'Edit E2E Delete' } )
			.click();
		await expect( page.getByRole( 'textbox', { name: 'In <head>', exact: true } ) ).toHaveValue( HEAD );
		await page.getByRole( 'button', { name: 'Delete', exact: true } ).click();
		await page
			.getByRole( 'alertdialog' )
			.getByRole( 'button', { name: 'Delete script' } )
			.click();
		await expect( page.getByText( 'Script deleted.' ) ).toBeVisible();
		await expect( row( page, 'E2E Delete' ) ).toHaveCount( 0 );
		expect( themePathExists( THEME, 'proto-scripts/e2e-delete' ) ).toBe( false );
		expect( await html( anonymous, '/' ) ).not.toContain( HEAD );
	} );

	test( 'a PHP opening tag in a snippet is refused', async ( {
		page,
		requestUtils,
	} ) => {
		await openScreen( page, '&new=1' );
		await page.getByLabel( 'Script title', { exact: true } ).fill( 'E2E Php Snippet' );
		const head = page.getByRole( 'textbox', { name: 'In <head>', exact: true } );
		await head.fill( '<?php echo "x"; ?>' );
		await page.getByRole( 'button', { name: 'Save to file', exact: true } ).click();
		await expect( page.getByText( 'Fix the highlighted fields before saving.' ) ).toBeVisible();
		await expect( page.getByText( /a PHP opening tag .* is not allowed/ ) ).toBeVisible();
		await expect( head ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( themePathExists( THEME, 'proto-scripts/e2e-php-snippet' ) ).toBe( false );

		// The server refuses it too (the editor's check is only a convenience).
		const refused = await requestUtils
			.rest( {
				method: 'POST',
				path: '/proto-blocks/v1/admin/scripts/e2e-php-snippet',
				data: {
					text: '{ "title": "E2E Php Snippet" }',
					hash: null,
					snippets: { bodyClose: '<?= 1 ?>' },
				},
			} )
			.then(
				() => null,
				( error: { code?: string } ) => error
			);
		expect( refused?.code ).toBe( 'proto_blocks_php_in_snippet' );
		expect( themePathExists( THEME, 'proto-scripts/e2e-php-snippet' ) ).toBe( false );
	} );

	test( 'a PHP declaration shows its origin, and the same slug in a file is declared twice', async ( {
		page,
	} ) => {
		wpCli( `wp option update ${ PHP_OPTION } e2e-php` );
		await openScreen( page );
		const php = row( page, 'E2E PHP Script' );
		await expect( php ).toContainText( 'Proto-Blocks PHP' );
		await expect( php.getByRole( 'switch' ) ).toBeDisabled();
		await expect( php.getByRole( 'button', { name: 'View E2E PHP Script' } ) ).toBeVisible();
		expect( await html( anonymous, '/' ) ).toContain(
			`${ marker( 'e2e-php' ) }\n<meta name="pb-e2e-php" content="php">`
		);

		writeScript(
			'e2e-php',
			{ title: 'E2E PHP Script From File', head: '<meta name="pb-e2e-php" content="file">' }
		);
		await openScreen( page );
		const file = row( page, 'E2E PHP Script From File' );
		await expect( file ).toContainText( 'Proto-Blocks file' );
		await expect( file.getByText( 'Declared twice' ) ).toBeVisible();
		await expect( row( page, 'E2E PHP Script' ) ).toHaveCount( 0 );
		// The file wins whole.
		const front = await html( anonymous, '/' );
		expect( front ).toContain( 'content="file"' );
		expect( front ).not.toContain( 'content="php"' );
	} );

	test( 'read-only for a user without unfiltered_html', async ( {
		browser,
	} ) => {
		writeScript(
			'e2e-locked',
			{ title: 'E2E Locked', head: { file: 'head.html' } },
			{ 'head.html': HEAD }
		);
		try {
			wpCli( `wp user delete ${ VIEWER.username } --yes` );
		} catch {
			// Not there yet.
		}
		wpCli(
			`wp user create ${ VIEWER.username } ${ VIEWER.email } --role=editor --user_pass=${ VIEWER.password }`
		);
		// The screen needs manage_options; editors have unfiltered_html on a
		// single site, so it is denied on the user (as multisite or
		// DISALLOW_UNFILTERED_HTML would).
		wpCli(
			`wp eval '$u = get_user_by("login", "${ VIEWER.username }"); $u->add_cap("manage_options"); $u->add_cap("unfiltered_html", false);'`
		);
		expect(
			wpCli(
				`wp eval 'echo user_can(get_user_by("login", "${ VIEWER.username }"), "unfiltered_html") ? "yes" : "no";'`
			).trim()
		).toBe( 'no' );

		const context = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		try {
			const page = await context.newPage();
			await page.goto( '/wp-login.php' );
			await page.locator( '#user_login' ).fill( VIEWER.username );
			await page.locator( '#user_pass' ).fill( VIEWER.password );
			await page.locator( '#wp-submit' ).click();
			await page.waitForURL( /wp-admin/ );

			await openScreen( page );
			const banner = page.getByRole( 'status' ).filter( {
				hasText: 'Scripts are read-only for you',
			} );
			await expect( banner ).toContainText(
				'Only users who may post unfiltered HTML can edit scripts.'
			);
			await expect(
				page.getByRole( 'button', { name: 'New script' } )
			).toBeDisabled();
			const item = row( page, 'E2E Locked' );
			await expect( item.getByRole( 'switch', { name: 'Enable E2E Locked' } ) ).toBeDisabled();

			await item.getByRole( 'button', { name: 'View E2E Locked' } ).click();
			await expect( page.getByText( 'Scripts are read-only for you' ) ).toBeVisible();
			await expect( page.getByRole( 'button', { name: 'Save to file', exact: true } ) ).toBeDisabled();
			await expect( page.getByRole( 'button', { name: 'Delete', exact: true } ) ).toHaveCount( 0 );
			await expect( page.getByLabel( 'Script title', { exact: true } ) ).not.toBeEditable();
			await expect( page.getByRole( 'textbox', { name: 'In <head>', exact: true } ) ).toHaveValue( HEAD );
			await expect( page.getByRole( 'textbox', { name: 'In <head>', exact: true } ) ).not.toBeEditable();
			await expect( page.getByRole( 'switch', { name: 'Enabled' } ) ).toBeDisabled();
			await expect( page.getByRole( 'radio', { name: 'Anyone' } ) ).toBeDisabled();
			await expect( page.getByRole( 'button', { name: 'Add to include' } ) ).toHaveCount( 0 );

			// The REST write is refused for this user as well.
			const refused = await page.evaluate( async () => {
				const boot = (
					window as unknown as {
						protoBlocksAdmin: { restRoot: string; restNonce: string };
					}
				 ).protoBlocksAdmin;
				// Pretty permalinks (prepareFixtureSite): a plain path root.
				const url = `${ boot.restRoot }admin/scripts/e2e-locked`;
				const response = await fetch( url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': boot.restNonce,
					},
					body: JSON.stringify( {
						text: '{ "title": "Hacked" }',
						hash: null,
					} ),
				} );
				const body = ( await response.json() ) as { code?: string };
				return { status: response.status, code: body.code };
			} );
			expect( refused ).toEqual( {
				status: 403,
				code: 'proto_blocks_unfiltered_html',
			} );
			expect( themeFile( THEME, 'proto-scripts/e2e-locked/script.jsonc' ) ).toContain(
				'"title": "E2E Locked"'
			);
		} finally {
			await context.close();
			wpCli( `wp user delete ${ VIEWER.username } --yes` );
		}
	} );
} );
