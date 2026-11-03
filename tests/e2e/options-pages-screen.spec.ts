import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import { activeTheme, wpCli } from './content-model-utils';

/**
 * The Options Pages admin screen end to end, plus its visual baselines.
 * Declarations come from tests/e2e/fixtures/proto-options (read-only here,
 * they live in the pb-e2e filter root); the page the spec creates is written
 * to the active theme and removed again in `afterAll`.
 */

const SCREEN = '/wp-admin/admin.php?page=proto-blocks-options-pages';
const NEW_SLUG = 'e2e-notices';
const CADCO_OPTION = 'cadco_display_settings';
const CADCO_SEED =
	'{"hide_children":"1","children_placement":"related","legacy_sort":"menu_order"}';
const RESTORED = [ 'theme-settings_head_scripts', 'theme-settings_phone' ];
const NOTICE_OPTION = 'e2e-notices_notice';

function getOption( name: string ): string | null {
	try {
		return wpCli( `wp option get ${ name }` ).replace( /\n$/, '' );
	} catch {
		return null;
	}
}

function pageFile(): string {
	return wpCli(
		`cat wp-content/themes/${ activeTheme() }/proto-options/${ NEW_SLUG }/page.jsonc`
	);
}

function shot( page: Page ) {
	return {
		maxDiffPixelRatio: 0.002,
		animations: 'disabled' as const,
		caret: 'hide' as const,
		mask: [ page.locator( '#wpfooter' ) ],
		// The other-settings list depends on the active theme and plugins.
		style: 'section[aria-labelledby="pb-op-external"] { display: none !important; }',
	};
}

async function openScreen( page: Page, query = '' ) {
	await page.goto( `${ SCREEN }${ query }` );
	await expect( page.locator( '[aria-busy="true"]' ) ).toHaveCount( 0 );
}

async function openSettings( page: Page, slug: string, title: string ) {
	await page.goto( `/wp-admin/admin.php?page=${ slug }` );
	await expect(
		page.getByRole( 'heading', { name: title, level: 1 } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Save settings' } )
	).toBeEnabled();
}

test.describe( 'Options Pages screen', () => {
	const saved = new Map< string, string | null >();
	let cadcoOriginal: string | null = null;

	test.beforeAll( () => {
		for ( const name of [ ...RESTORED, NOTICE_OPTION ] ) {
			saved.set( name, getOption( name ) );
			if ( saved.get( name ) !== null ) {
				wpCli( `wp option delete ${ name }` );
			}
		}
		try {
			cadcoOriginal = wpCli(
				`wp option get ${ CADCO_OPTION } --format=json`
			).trim();
		} catch {
			cadcoOriginal = null;
		}
		wpCli(
			`wp option update ${ CADCO_OPTION } '${ CADCO_SEED }' --format=json`
		);
	} );

	test.afterAll( async ( { requestUtils } ) => {
		try {
			wpCli( 'wp config delete DISALLOW_FILE_MODS' );
		} catch {
			// Not set.
		}
		await requestUtils
			.rest( {
				method: 'DELETE',
				path: `/proto-blocks/v1/schema-files/options/${ NEW_SLUG }`,
			} )
			.catch( () => undefined );
		if ( getOption( NOTICE_OPTION ) !== null ) {
			wpCli( `wp option delete ${ NOTICE_OPTION }` );
		}
		for ( const [ name, value ] of saved ) {
			if ( name !== NOTICE_OPTION && value !== null ) {
				wpCli( `wp option update ${ name } '${ value }'` );
			}
		}
		if ( cadcoOriginal === null ) {
			wpCli( `wp option delete ${ CADCO_OPTION }` );
		} else {
			wpCli(
				`wp option update ${ CADCO_OPTION } '${ cadcoOriginal }' --format=json`
			);
		}
	} );

	test( 'the list shows file pages, their origin and other settings pages', async ( {
		page,
	} ) => {
		await openScreen( page );
		const table = page.locator( 'table' ).first();

		const cadco = table
			.getByRole( 'row' )
			.filter( { hasText: 'Display Settings' } );
		await expect( cadco ).toContainText( 'Under Products' );
		await expect( cadco ).toContainText( 'manage_woocommerce' );
		await expect( cadco ).toContainText( 'Array: cadco_display_settings' );
		await expect( cadco ).toContainText( 'Proto-Blocks file' );
		await expect(
			cadco.getByRole( 'button', { name: /^View / } )
		).toBeVisible();

		const theme = table
			.getByRole( 'row' )
			.filter( { hasText: 'Theme Settings' } )
			.first();
		await expect( theme ).toContainText( 'Proto-Blocks file' );

		const external = page.getByRole( 'region', {
			name: 'Other settings pages',
		} );
		await external.getByRole( 'heading', { name: 'Other settings pages' } ).click();
		const general = external
			.getByRole( 'row' )
			.filter( { hasText: 'General' } )
			.first();
		await expect( general ).toContainText( 'WordPress' );

		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot(
			'options-pages-list.png',
			shot( page )
		);
	} );

	test( 'the declaration view of the CADCO page', async ( { page } ) => {
		await openScreen( page, '&slug=cadco-display-settings' );
		await expect(
			page.getByRole( 'textbox', { name: 'Title', exact: true } )
		).toBeVisible();
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot(
			'options-pages-edit-cadco.png',
			shot( page )
		);
	} );

	test( 'the CADCO page renders in both placements', async ( { page } ) => {
		await openSettings( page, 'cadco-display-settings', 'Display Settings' );
		await expect( page.getByLabel( 'Section heading' ) ).toBeHidden();
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot(
			'options-page-cadco-related.png',
			shot( page )
		);

		await page.getByLabel( 'Own section' ).check();
		await page.getByLabel( 'Section heading' ).fill( 'Featured range' );
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot(
			'options-page-cadco-section.png',
			shot( page )
		);
	} );

	test( 'a tabbed page, on its second tab', async ( { page } ) => {
		await openSettings( page, 'theme-settings', 'Theme Settings' );
		await page.getByRole( 'tab', { name: 'Tracking' } ).click();
		await expect(
			page.getByRole( 'tab', { name: 'Tracking', selected: true } )
		).toBeVisible();
		await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot(
			'options-page-theme-settings-tracking-tab.png',
			shot( page )
		);
	} );

	test( 'create, edit, use and delete a page', async ( { page } ) => {
		await openScreen( page, '&new=1' );
		await page.getByRole( 'textbox', { name: 'Page title', exact: true } ).fill( 'E2E Notices' );
		await expect( page.getByRole( 'textbox', { name: 'Slug' } ) ).toHaveValue(
			NEW_SLUG
		);
		await page.getByRole( 'button', { name: 'Continue' } ).click();

		await page.getByRole( 'radio', { name: 'Under an existing menu' } ).click();
		await page
			.getByRole( 'combobox', { name: 'Parent menu' } )
			.selectOption( 'options-general.php' );
		await page
			.getByRole( 'combobox', { name: 'Who can use this page' } )
			.fill( 'manage_options' );

		await page.getByRole( 'button', { name: 'Add tab' } ).click();
		await page.getByRole( 'textbox', { name: 'Tab title' } ).fill( 'Main' );
		await page.getByRole( 'button', { name: 'Edit fields of Main' } ).click();
		await page.getByRole( 'button', { name: 'Add field', exact: true } ).first().click();
		await page
			.getByRole( 'dialog', { name: 'Add field' } )
			.getByRole( 'button', { name: /^Text/ } )
			.first()
			.click();
		const label = page.getByRole( 'textbox', { name: 'Label', exact: true } );
		await expect( label ).toBeVisible();
		await label.fill( 'Notice' );
		await label.blur();

		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await expect( page.getByText( /^Saved to / ) ).toBeVisible();

		await page.goto( '/wp-admin/index.php' );
		await expect(
			page.locator( `#menu-settings .wp-submenu a[href$="page=${ NEW_SLUG }"]` )
		).toHaveText( 'E2E Notices' );

		const written = pageFile();
		expect( written ).toContain( '"capability": "manage_options"' );
		expect( written ).toContain( '// Where the page appears' );

		// Edit the capability: the template comments survive.
		await openScreen( page, `&slug=${ NEW_SLUG }` );
		const capability = page.getByRole( 'combobox', {
			name: 'Who can use this page',
		} );
		await capability.fill( 'edit_posts' );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await expect( page.getByText( /^Saved to / ) ).toBeVisible();
		const edited = pageFile();
		expect( edited ).toContain( '"capability": "edit_posts"' );
		expect( edited ).toContain( '// Where the page appears' );

		// Use the page, then delete it: the saved value stays.
		await openSettings( page, NEW_SLUG, 'E2E Notices' );
		await page.getByLabel( 'Notice', { exact: true } ).fill( 'Closed Monday' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		expect( getOption( NOTICE_OPTION ) ).toBe( 'Closed Monday' );

		await openScreen( page, `&slug=${ NEW_SLUG }` );
		await page.getByRole( 'button', { name: 'Delete', exact: true } ).click();
		await page
			.getByRole( 'alertdialog' )
			.getByRole( 'button', { name: 'Delete', exact: true } )
			.click();
		await expect( page.getByText( 'Options page deleted.' ) ).toBeVisible();

		await page.goto( '/wp-admin/index.php' );
		await expect(
			page.locator( `#menu-settings .wp-submenu a[href$="page=${ NEW_SLUG }"]` )
		).toHaveCount( 0 );
		expect( getOption( NOTICE_OPTION ) ).toBe( 'Closed Monday' );
	} );

	test( 'file modifications disabled makes the screen read-only', async ( {
		page,
	} ) => {
		wpCli( 'wp config set DISALLOW_FILE_MODS true --raw' );
		try {
			// PHP's opcache rechecks wp-config.php every 2 seconds.
			await expect( async () => {
				await openScreen( page );
				await expect(
					page.getByText(
						'File modifications are disabled on this site'
					)
				).toBeVisible( { timeout: 1000 } );
			} ).toPass( { timeout: 15000 } );
			await expect(
				page.getByRole( 'button', { name: 'New options page' } )
			).toBeDisabled();
			await expect( page.locator( '#wpbody-content' ) ).toHaveScreenshot(
				'options-pages-readonly.png',
				shot( page )
			);

			await openScreen( page, '&slug=cadco-display-settings' );
			await expect(
				page.getByText( 'File modifications are disabled on this site' )
			).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: 'Save', exact: true } )
			).toBeDisabled();
			await expect(
				page.getByRole( 'button', { name: 'Delete', exact: true } )
			).toHaveCount( 0 );
		} finally {
			wpCli( 'wp config delete DISALLOW_FILE_MODS' );
		}
	} );
} );
