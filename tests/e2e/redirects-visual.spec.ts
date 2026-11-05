import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext, Page } from '@playwright/test';
import { prepareFixtureSite, wpCli } from './content-model-utils';
import {
	BASE_URL,
	PHP_OPTION,
	SCREEN,
	clearPhpRedirect,
	clearRedirectTables,
	hop,
	removeRedirects,
	writeGroup,
} from './redirects-utils';

/**
 * Visual baselines of the Redirects screen, one per tab. Baselines are macOS
 * (`-chromium-darwin`, see README.md). The rules are fixture group files in a
 * fresh copy of pb-e2e-block plus one proto_redirect() declaration from the
 * pb-e2e-redirects mu-plugin; the 404s and the log come from real visitor
 * requests. Times (last hit, last seen, log time) are masked. Everything is
 * removed in `afterAll`.
 */

function shot( page: Page ) {
	return {
		maxDiffPixelRatio: 0.002,
		animations: 'disabled' as const,
		caret: 'hide' as const,
		mask: [
			page.locator( '#wpfooter' ),
			page.locator( '[data-pb-mask]' ),
		],
	};
}

/** Marks the time cells of a table (by column header) for masking. */
async function maskColumns( page: Page, table: string, headers: string[] ) {
	await page.evaluate(
		( { name, wanted } ) => {
			const el = document.querySelector( `table[aria-label="${ name }"]` );
			if ( ! el ) {
				return;
			}
			const heads = [ ...el.querySelectorAll( 'thead th' ) ].map( ( th ) => th.textContent?.trim() ?? '' );
			for ( const row of el.querySelectorAll( 'tbody tr' ) ) {
				wanted.forEach( ( header ) => {
					const cell = row.children[ heads.indexOf( header ) ];
					cell?.setAttribute( 'data-pb-mask', '' );
				} );
			}
		},
		{ name: table, wanted: headers }
	);
}

async function openTab( page: Page, tab: string ) {
	await page.goto( `${ SCREEN }${ tab === 'redirects' ? '' : `&tab=${ tab }` }` );
	await expect( page.locator( '[aria-busy="true"]' ) ).toHaveCount( 0 );
}

test.describe( 'Redirects screen (visual)', () => {
	let previousTheme = '';
	let anonymous: APIRequestContext;

	test.beforeAll( async ( { requestUtils, playwright } ) => {
		previousTheme = await prepareFixtureSite( requestUtils );
		removeRedirects();
		clearRedirectTables();
		writeGroup( 'legacy-site', {
			title: 'Legacy site',
			position: 10,
			rules: [
				{ id: 'r_1e9ac001', from: '/about-us', to: '/about' },
				{ id: 'r_1e9ac002', title: 'Old blog', from: '^/blog/(\\d+)/(.*)$', regex: true, to: '/news/$2' },
				{ id: 'r_1e9ac003', from: '/members', when: { type: 'login' }, to: '/dashboard', else: '/login' },
				{ id: 'r_1e9ac004', from: '/gone-forever', action: 'error', code: 410 },
			],
		} );
		writeGroup( 'promo', {
			title: 'Promotions',
			position: 20,
			rules: [
				{ id: 'r_9a0b0001', from: '/promo', to: '/sale', code: 302, query: 'pass' },
				{ id: 'r_9a0b0002', from: '/spring', to: '/sale', enabled: false },
			],
		} );
		wpCli( `wp option update ${ PHP_OPTION } /php-old` );
		anonymous = await playwright.request.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		// Two logged 404s and two redirect log rows.
		await hop( anonymous, '/pb-e2e-missing-page', { headers: { Referer: 'https://example.test/links' } } );
		// A second apart: the 404s tab orders ties on count by last seen.
		await new Promise( ( resolve ) => setTimeout( resolve, 1100 ) );
		await hop( anonymous, '/old-catalogue/item-7' );
		// Redirect log rows.
		await hop( anonymous, '/about-us' );
		await new Promise( ( resolve ) => setTimeout( resolve, 1100 ) );
		await hop( anonymous, '/promo?utm=mail' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		removeRedirects();
		clearPhpRedirect();
		clearRedirectTables();
		await anonymous?.dispose();
		if ( previousTheme ) {
			await requestUtils.activateTheme( previousTheme );
		}
	} );

	test( 'redirects tab', async ( { page } ) => {
		await openTab( page, 'redirects' );
		await expect( page.getByRole( 'switch', { name: 'Enable /php-old', exact: true } ) ).toBeVisible();
		await maskColumns( page, 'Redirect rules', [ 'Last hit' ] );
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot( 'redirects-rules.png', shot( page ) );
	} );

	test( '404s tab', async ( { page } ) => {
		await openTab( page, '404s' );
		await expect( page.getByText( '/old-catalogue/item-7', { exact: true } ) ).toBeVisible();
		await maskColumns( page, '404s by URL', [ 'Last seen' ] );
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot( 'redirects-404s.png', shot( page ) );
	} );

	test( 'log tab', async ( { page } ) => {
		await openTab( page, 'log' );
		await expect( page.getByRole( 'table', { name: 'Redirect log' } ).getByText( '/sale?utm=mail' ) ).toBeVisible();
		await maskColumns( page, 'Redirect log', [ 'Time', 'User agent' ] );
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot( 'redirects-log.png', shot( page ) );
	} );

	test( 'import / export tab', async ( { page } ) => {
		await openTab( page, 'import' );
		await expect( page.getByRole( 'heading', { name: 'Import a file' } ) ).toBeVisible();
		await expect( page.getByText( 'Looking for redirect plugins…' ) ).toHaveCount( 0 );
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot( 'redirects-import.png', shot( page ) );
	} );

	test( 'settings tab', async ( { page } ) => {
		await openTab( page, 'settings' );
		await expect( page.getByRole( 'button', { name: 'Save settings' } ) ).toBeVisible();
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot( 'redirects-settings.png', shot( page ) );
	} );
} );
