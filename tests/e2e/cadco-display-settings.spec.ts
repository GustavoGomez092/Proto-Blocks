import path from 'path';
import {
	test,
	expect,
	RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { wpCli } from './content-model-utils';

/**
 * Done criterion: the CADCO theme's Products > Display Settings page,
 * re-declared as tests/e2e/fixtures/proto-options/cadco-display-settings.
 *
 * Fixture vs the live theme (inc/cadco-display-settings.php): same slug,
 * parent menu (edit.php?post_type=product), capability (manage_woocommerce),
 * single array option `cadco_display_settings` and the same three keys, so
 * the live data keeps working. Migration notes: the theme's sanitiser stores
 * `hide_children` as a boolean and always a non-empty heading; Proto-Blocks
 * keeps the stored scalar format ('1' / '') and writes the heading only while
 * it is visible (the theme reads with !empty(), so both formats work). Labels
 * and the screen title are shorter in the fixture. Option names
 * `cadco-display-settings_<field>` never occur because the page uses
 * `storage.mode = array`.
 */

const OPTION = 'cadco_display_settings';
const SEED =
	'{"hide_children":"1","children_placement":"related","legacy_sort":"menu_order"}';
const SHOP_MANAGER = { username: 'shopmanager', password: 'password' };
const SHOP_MANAGER_STATE = path.join( __dirname, '.auth/shopmanager.json' );
const PAGE_URL = '/wp-admin/admin.php?page=cadco-display-settings';

function getOption(): string | null {
	try {
		return wpCli( `wp option get ${ OPTION } --format=json` ).trim();
	} catch {
		return null;
	}
}

async function shopManagerContext(
	browser: Browser
): Promise< BrowserContext > {
	const utils = await RequestUtils.setup( {
		baseURL: process.env.WP_BASE_URL,
		user: SHOP_MANAGER,
		storageStatePath: SHOP_MANAGER_STATE,
	} );
	await utils.setupRest();
	await utils.request.dispose();
	return browser.newContext( {
		baseURL: process.env.WP_BASE_URL,
		storageState: SHOP_MANAGER_STATE,
	} );
}

async function open( page: Page ) {
	await page.goto( PAGE_URL );
	await expect(
		page.getByRole( 'heading', { name: 'Display Settings', level: 1 } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Save settings' } )
	).toBeEnabled();
}

test.describe( 'CADCO display settings', () => {
	let original: string | null = null;

	test.beforeAll( () => {
		original = getOption();
		wpCli( `wp option update ${ OPTION } '${ SEED }' --format=json` );
	} );

	test.afterAll( () => {
		if ( original === null ) {
			wpCli( `wp option delete ${ OPTION }` );
		} else {
			wpCli(
				`wp option update ${ OPTION } '${ original }' --format=json`
			);
		}
	} );

	test( 'the shop manager edits the page and the legacy keys survive', async ( {
		browser,
	} ) => {
		const context = await shopManagerContext( browser );
		const page = await context.newPage();
		try {
			await page.goto( '/wp-admin/edit.php?post_type=product' );
			await expect(
				page
					.locator( '#menu-posts-product .wp-submenu a' )
					.filter( { hasText: 'Display Settings' } )
			).toBeVisible();

			await open( page );
			await expect(
				page.getByLabel( 'Hide child products' )
			).toBeChecked();
			await expect( page.getByLabel( 'Section heading' ) ).toBeHidden();

			await page.getByLabel( 'Own section' ).check();
			await page
				.getByLabel( 'Section heading' )
				.fill( 'Featured range' );
			await page.getByRole( 'button', { name: 'Save settings' } ).click();
			await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

			await page.reload();
			await expect( page.getByLabel( 'Own section' ) ).toBeChecked();
			await expect( page.getByLabel( 'Section heading' ) ).toHaveValue(
				'Featured range'
			);

			const stored = JSON.parse( getOption() ?? '{}' );
			expect( stored.legacy_sort ).toBe( 'menu_order' );
			expect( stored.hide_children ).toBe( '1' );
			expect( stored.children_placement ).toBe( 'section' );
			expect( stored.children_heading ).toBe( 'Featured range' );

			await expect(
				page.getByRole( 'link', { name: 'Edit declaration' } )
			).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );

	test( 'the administrator sees the edit link, which opens the editor', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=cadco-display-settings'
		);
		const link = page.getByRole( 'link', { name: 'Edit declaration' } );
		await expect( link ).toBeVisible();
		await link.click();
		await expect( page ).toHaveURL( /proto-blocks-options-pages/ );
		await expect( page ).toHaveURL( /cadco-display-settings/ );
	} );
} );
