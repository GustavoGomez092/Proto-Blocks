import path from 'path';
import {
	test,
	expect,
	RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { wpCli } from './content-model-utils';

/**
 * Settings pages end to end. The pages come from tests/e2e/fixtures/proto-options
 * and the `options-probe` block from tests/e2e/fixtures/blocks, both added by
 * the pb-e2e-options mu-plugin (mapped in .wp-env.json), which also creates
 * the `shopmanager` user (role pb_shop_manager: manage_woocommerce, no
 * unfiltered_html) inside the wp-env test site.
 */

const OPTIONS = [
	'theme-settings_phone',
	'theme-settings_head_scripts',
	'theme-settings-footer_copyright',
	'shop-notices_banner',
	'shop-notices_tracking_snippet',
];
const PHONE = '<b>555</b> & co';
const SNIPPET = '<script>window.pbOk=1</script>';
const HIDDEN =
	'Hidden; only users with unfiltered HTML may view and edit this field.';
const SHOP_MANAGER = { username: 'shopmanager', password: 'password' };
const SHOP_MANAGER_STATE = path.join( __dirname, '.auth/shopmanager.json' );
/**
 * Plain-permalink safe REST URL.
 * @param route
 */
const restUrl = ( route: string ) => `/?rest_route=/proto-blocks/v1/${ route }`;

/**
 * The stored value of an option, or null when it does not exist.
 * @param name Option name.
 */
function getOption( name: string ): string | null {
	try {
		return wpCli( `wp option get ${ name }` ).replace( /\n$/, '' );
	} catch {
		return null;
	}
}

/**
 * Writes an option as the admin: the Fields module's sanitize_option trust
 * callback strips markup when the current user lacks unfiltered_html, which
 * is the case for WP-CLI's default user 0. The value travels base64-encoded
 * so nothing needs quoting.
 * @param name  Option name.
 * @param value Value.
 */
function setOption( name: string, value: string ): void {
	const encoded = Buffer.from( value, 'utf8' ).toString( 'base64' );
	wpCli(
		`wp eval --user=admin 'update_option("${ name }", base64_decode("${ encoded }"), false);'`
	);
	expect( getOption( name ) ).toBe( value );
}

function deleteOption( name: string ): void {
	wpCli( `wp option delete ${ name }` );
}

function saveButton( page: Page ) {
	return page.getByRole( 'button', { name: 'Save settings' } );
}

/**
 * Saves the form and waits for the success toast.
 * @param page The settings page.
 */
async function save( page: Page ) {
	await saveButton( page ).click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
}

/**
 * Opens a settings page and waits for the React screen.
 * @param page  The current page.
 * @param slug  Page slug.
 * @param title Page title (the h1).
 */
async function openSettings( page: Page, slug: string, title: string ) {
	await page.goto( `/wp-admin/admin.php?page=${ slug }` );
	await expect(
		page.getByRole( 'heading', { name: title, level: 1 } )
	).toBeVisible();
	await expect( saveButton( page ) ).toBeEnabled();
}

/**
 * A browser context logged in as the shop manager. The session comes from
 * RequestUtils (REST login against the local test site), not the login form.
 * @param browser The worker's browser.
 */
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

test.describe( 'Settings pages', () => {
	const saved = new Map< string, string | null >();

	test.beforeAll( () => {
		for ( const name of OPTIONS ) {
			saved.set( name, getOption( name ) );
			if ( saved.get( name ) !== null ) {
				deleteOption( name );
			}
		}
	} );

	test.afterAll( () => {
		for ( const [ name, value ] of saved ) {
			if ( value === null ) {
				if ( getOption( name ) !== null ) {
					deleteOption( name );
				}
			} else {
				setOption( name, value );
			}
		}
	} );

	test( 'the CLI lists the fixture pages', () => {
		const rows = JSON.parse(
			wpCli( 'wp proto-blocks options list --format=json' )
		) as Array< { slug: string; menu: string; capability: string } >;
		expect( rows ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					slug: 'theme-settings',
					menu: 'top',
					capability: 'manage_options',
				} ),
				expect.objectContaining( {
					slug: 'theme-settings-footer',
					menu: 'theme-settings',
				} ),
				expect.objectContaining( {
					slug: 'shop-notices',
					capability: 'manage_woocommerce',
				} ),
			] )
		);
	} );

	test( 'the page has its menu, child page and tabs', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=theme-settings' );
		await expect(
			page.getByRole( 'heading', { name: 'Theme Settings', level: 1 } )
		).toBeVisible();

		const menu = page.locator( '#toplevel_page_theme-settings' );
		await expect(
			menu.locator( 'a.menu-top', { hasText: 'Theme Settings' } )
		).toBeVisible();
		await expect(
			menu.locator( '.wp-submenu a', { hasText: 'Footer' } )
		).toHaveAttribute( 'href', /page=theme-settings-footer$/ );

		await expect(
			page.getByRole( 'tab', { name: 'General' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'tab', { name: 'Tracking' } )
		).toBeVisible();

		const form = page
			.locator( 'form' )
			.filter( { has: saveButton( page ) } );
		await expect( saveButton( page ) ).toBeEnabled();
		await expect( form ).toHaveScreenshot( 'theme-settings-form.png', {
			maxDiffPixelRatio: 0.002,
			animations: 'disabled',
			caret: 'hide',
		} );
	} );

	test( 'a value saves, survives a reload and is stored as one option', async ( {
		page,
	} ) => {
		await openSettings( page, 'theme-settings', 'Theme Settings' );
		await page.getByLabel( 'Phone', { exact: true } ).fill( PHONE );
		await save( page );

		await page.reload();
		await expect( page.getByLabel( 'Phone', { exact: true } ) ).toHaveValue(
			PHONE
		);
		expect( getOption( 'theme-settings_phone' ) ).toBe( PHONE );
	} );

	test( 'a block template reads the value through proto_field, escaped', async ( {
		page,
		requestUtils,
	} ) => {
		setOption( 'theme-settings_phone', PHONE );
		const post = await requestUtils.createPost( {
			title: 'Options probe',
			content: '<!-- wp:proto-blocks/options-probe /-->',
			status: 'publish',
		} );
		try {
			await page.goto( post.link );
			const probe = page.locator( '.pb-probe-phone' );
			await expect( probe ).toHaveText( PHONE );
			await expect( probe.locator( 'b' ) ).toHaveCount( 0 );
			expect( await probe.innerHTML() ).toBe(
				'&lt;b&gt;555&lt;/b&gt; &amp; co'
			);
		} finally {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ post.id }`,
				params: { force: true },
			} );
		}
	} );

	test( 'the child page saves and the front end shows it', async ( {
		page,
		requestUtils,
	} ) => {
		const copyright = '© 2026 Proto Blocks';
		await openSettings( page, 'theme-settings-footer', 'Footer' );
		await page.locator( 'input[name$="[copyright]"]' ).fill( copyright );
		await save( page );
		expect( getOption( 'theme-settings-footer_copyright' ) ).toBe(
			copyright
		);

		const post = await requestUtils.createPost( {
			title: 'Options probe footer',
			content: '<!-- wp:proto-blocks/options-probe /-->',
			status: 'publish',
		} );
		try {
			await page.goto( post.link );
			await expect( page.locator( '.pb-probe-copyright' ) ).toHaveText(
				copyright
			);
		} finally {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ post.id }`,
				params: { force: true },
			} );
		}
	} );

	test( 'a shop manager cannot open a manage_options page', async ( {
		browser,
	} ) => {
		const context = await shopManagerContext( browser );
		try {
			const page = await context.newPage();
			await page.goto( '/wp-admin/admin.php?page=theme-settings' );
			await expect( page.locator( 'body' ) ).toContainText(
				'Sorry, you are not allowed to access this page.'
			);
			await expect( saveButton( page ) ).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );

	test( 'a shop manager without unfiltered_html saves text but never sees or writes code', async ( {
		browser,
	} ) => {
		setOption( 'shop-notices_tracking_snippet', SNIPPET );
		const context = await shopManagerContext( browser );
		try {
			const page = await context.newPage();
			await openSettings( page, 'shop-notices', 'Shop Notices' );

			// The code value is hidden, not rendered read-only or run.
			await expect(
				page.getByRole( 'note' ).filter( { hasText: HIDDEN } )
			).toBeVisible();
			await expect(
				page.locator( '[name*="tracking_snippet"]' )
			).toHaveCount( 0 );
			expect( await page.content() ).not.toContain( 'window.pbOk' );
			expect(
				await page.evaluate(
					() => ( window as unknown as { pbOk?: number } ).pbOk
				)
			).toBeUndefined();

			// The other field saves, sanitised for an untrusted writer.
			await page
				.getByLabel( 'Banner', { exact: true } )
				.fill( '<script>alert(1)</script>Sale' );
			await save( page );
			await expect
				.poll( () => getOption( 'shop-notices_banner' ) )
				.toContain( 'Sale' );
			expect( getOption( 'shop-notices_banner' ) ).not.toContain(
				'<script'
			);

			// A direct REST write of the code field (nonce from the page) is
			// refused with a notice and the stored value is kept.
			const response = await page.evaluate( () =>
				(
					window as unknown as {
						wp: {
							apiFetch: ( o: object ) => Promise< {
								values: Record< string, unknown >;
								notices: Array< {
									field: string | null;
									message: string;
								} >;
							} >;
						};
					}
				 ).wp.apiFetch( {
					path: '/proto-blocks/v1/options/shop-notices',
					method: 'POST',
					data: {
						fields: {
							group_shop_notices_1: {
								tracking_snippet: '<img onerror=x>',
							},
						},
						present: {
							group_shop_notices_1: [ 'tracking_snippet' ],
						},
					},
				} )
			);
			expect( response.values.tracking_snippet ).toBeNull();
			expect( response.notices ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( { field: 'tracking_snippet' } ),
				] )
			);
			expect( getOption( 'shop-notices_tracking_snippet' ) ).toBe(
				SNIPPET
			);
		} finally {
			await context.close();
		}
	} );

	test( 'REST writes need a nonce and a session', async ( {
		playwright,
	} ) => {
		setOption( 'theme-settings_phone', PHONE );
		const body = {
			fields: { group_theme_settings_1: { phone: '1' } },
			present: { group_theme_settings_1: [ 'phone' ] },
		};

		// The admin's cookies without X-WP-Nonce: treated as logged out.
		const noNonce = await playwright.request.newContext( {
			baseURL: process.env.WP_BASE_URL,
			storageState: process.env.STORAGE_STATE_PATH,
		} );
		const anonymous = await playwright.request.newContext( {
			baseURL: process.env.WP_BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		try {
			const cookieOnly = await noNonce.post(
				restUrl( 'options/theme-settings' ),
				{ data: body }
			);
			expect( cookieOnly.status() ).toBe( 401 );

			const loggedOut = await anonymous.post(
				restUrl( 'options/theme-settings' ),
				{ data: body }
			);
			expect( loggedOut.status() ).toBe( 401 );
			const read = await anonymous.get(
				restUrl( 'options/theme-settings' )
			);
			expect( read.status() ).toBe( 401 );

			expect( getOption( 'theme-settings_phone' ) ).toBe( PHONE );
		} finally {
			await noNonce.dispose();
			await anonymous.dispose();
		}
	} );
} );
