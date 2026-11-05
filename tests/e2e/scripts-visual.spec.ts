import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import { prepareFixtureSite, wpCli } from './content-model-utils';
import {
	PHP_OPTION,
	SCREEN,
	clearPhpScript,
	removeScripts,
	writeScript,
} from './scripts-utils';

/**
 * Visual baselines of the Scripts screen (list and editor). Baselines are
 * macOS (`-chromium-darwin`, see README.md). The scripts are fixture files in
 * a fresh copy of pb-e2e-block plus one proto_script() declaration from the
 * pb-e2e-scripts mu-plugin; all removed in `afterAll`.
 */

function shot( page: Page ) {
	return {
		maxDiffPixelRatio: 0.002,
		animations: 'disabled' as const,
		caret: 'hide' as const,
		mask: [ page.locator( '#wpfooter' ) ],
	};
}

async function openScreen( page: Page, query = '' ) {
	await page.goto( `${ SCREEN }${ query }` );
	await expect( page.locator( '[aria-busy="true"]' ) ).toHaveCount( 0 );
}

test.describe( 'Scripts screen (visual)', () => {
	let previousTheme = '';

	test.beforeAll( async ( { requestUtils } ) => {
		previousTheme = await prepareFixtureSite( requestUtils );
		writeScript(
			'gtm',
			{
				title: 'Google Tag Manager',
				enabled: true,
				priority: 1,
				head: { file: 'head.html' },
				bodyOpen: { file: 'body-open.html' },
				where: {
					include: [ 'all' ],
					exclude: [ 'page:privacy-policy', 'url:/landing/*' ],
					excludeRoles: [ 'administrator' ],
				},
			},
			{
				'head.html':
					"<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime()});})(window,document,'script','dataLayer','GTM-E2E');</script>",
				'body-open.html':
					'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-E2E" height="0" width="0" style="display:none"></iframe></noscript>',
			}
		);
		writeScript( 'chat-widget', {
			title: 'Chat widget',
			enabled: false,
			bodyClose: '<script src="https://chat.example.test/widget.js" async></script>',
			where: { include: [ 'front_page', 'singular:page' ], loggedIn: false },
		} );
		wpCli( `wp option update ${ PHP_OPTION } e2e-php` );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		removeScripts();
		clearPhpScript();
		if ( previousTheme ) {
			await requestUtils.activateTheme( previousTheme );
		}
	} );

	test( 'scripts list', async ( { page } ) => {
		await openScreen( page );
		await expect(
			page.getByRole( 'row' ).filter( { hasText: 'Google Tag Manager' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'row' ).filter( { hasText: 'E2E PHP Script' } )
		).toContainText( 'Proto-Blocks PHP' );
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot(
			'scripts-list.png',
			shot( page )
		);
	} );

	test( 'script editor', async ( { page } ) => {
		await openScreen( page, '&slug=gtm' );
		await expect( page.getByLabel( 'Title', { exact: true } ) ).toHaveValue(
			'Google Tag Manager'
		);
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot(
			'scripts-editor.png',
			shot( page )
		);
	} );
} );
