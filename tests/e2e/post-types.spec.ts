import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import {
	deleteAllOfType,
	installFixtureTheme,
	prepareFixtureSite,
	removeThemePath,
	themeFile,
	themePathExists,
	wpCli,
	writeThemeFile,
} from './content-model-utils';

const THEME = 'pb-e2e-block';
const SCREEN = 'page=proto-blocks-post-types';

interface ParityReport {
	[ slug: string ]: { equal: boolean; diff: Record< string, unknown > };
}

/**
 * The Post Types table row whose Name cell is exactly `name`.
 * @param page The current page.
 * @param name Exact text of the Name cell (the plural label).
 */
function row( page: Page, name: string ) {
	return page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'cell', { name, exact: true } ),
	} );
}

async function openType( page: Page, slug: string ) {
	await page.goto( `/wp-admin/admin.php?${ SCREEN }&type=${ slug }` );
	await expect(
		page.getByRole( 'button', { name: 'All post types' } )
	).toBeVisible();
}

test.describe( 'Post types', () => {
	let previousTheme = '';
	let bookId = 0;

	test.beforeAll( async ( { requestUtils } ) => {
		previousTheme = await prepareFixtureSite( requestUtils );
		await deleteAllOfType( requestUtils, 'book' );
		const book = await requestUtils.rest< { id: number } >( {
			path: '/wp/v2/book',
			method: 'POST',
			data: { title: 'Dune', status: 'publish' },
		} );
		bookId = book.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( THEME );
		await deleteAllOfType( requestUtils, 'book' );
		if ( previousTheme ) {
			await requestUtils.activateTheme( previousTheme );
		}
	} );

	test( 'a declared type works on the front end without a manual flush', async ( {
		page,
	} ) => {
		const single = await page.goto( '/book/dune/' );
		expect( single?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).toContainText( 'Dune' );

		const archive = await page.goto( '/book/' );
		expect( archive?.status() ).toBe( 200 );
		await expect(
			page.getByRole( 'link', { name: 'Dune' } )
		).toBeVisible();
	} );

	test( 'an override file pre-fills the core post template', async ( {
		admin,
		page,
	} ) => {
		const file = themeFile( THEME, 'proto-types/post/type.jsonc' );
		const first = /\["(core\/[a-z-]+)"/.exec( file )?.[ 1 ];
		expect( first ).toBe( 'core/heading' );

		await admin.createNewPost();
		await expect
			.poll( () =>
				page.evaluate(
					() =>
						(
							window as unknown as {
								wp: {
									data: {
										select: ( store: string ) => {
											getBlocks: () => Array< {
												name: string;
											} >;
										};
									};
								};
							}
						 ).wp.data
							.select( 'core/block-editor' )
							.getBlocks()[ 0 ]?.name
				)
			)
			.toBe( first );
	} );

	test( 'an SVG menu icon from the theme is inlined as a data URI', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );
		const image = page.locator( '#menu-posts-event .wp-menu-image' );
		await expect( image ).toHaveCount( 1 );
		const background = await image.evaluate(
			( el ) => getComputedStyle( el ).backgroundImage
		);
		expect(
			background.startsWith( 'url("data:image/svg+xml;base64,' )
		).toBe( true );
	} );

	test( 'the list shows each type with its origin', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', SCREEN );
		// The Name column shows each type's plural label.
		await expect( row( page, 'Books' ) ).toContainText(
			'Proto-Blocks file'
		);
		await expect( row( page, 'Events' ) ).toContainText(
			'Proto-Blocks PHP'
		);
		await expect( row( page, 'Posts' ) ).toContainText( 'Core' );
	} );

	test( 'a file and a PHP call for the same type are flagged', async ( {
		admin,
		page,
	} ) => {
		writeThemeFile(
			THEME,
			'proto-types/event/type.jsonc',
			'{ "name": "Event" }'
		);
		try {
			await admin.visitAdminPage( 'admin.php', SCREEN );
			await expect( row( page, 'Events' ) ).toContainText(
				'Declared twice'
			);
			const notice = page
				.locator( '.notice-warning' )
				.filter( { hasText: 'declared twice' } );
			await expect( notice ).toContainText(
				'proto-types/event/type.jsonc'
			);
			await expect( notice ).toContainText( 'functions.php' );

			expect( wpCli( 'wp proto-blocks validate' ) ).toContain(
				'double_declaration'
			);
		} finally {
			removeThemePath( THEME, 'proto-types/event' );
		}
	} );

	test( 'saving from the form keeps the file comments', async ( {
		page,
	} ) => {
		const original = themeFile( THEME, 'proto-types/book/type.jsonc' );
		try {
			await openType( page, 'book' );
			await page.getByLabel( "No archive — I'll use a page" ).check();
			await page.getByRole( 'button', { name: 'Save to file' } ).click();
			await expect( page.getByText( /^Saved to / ) ).toBeVisible();

			const text = themeFile( THEME, 'proto-types/book/type.jsonc' );
			expect( text ).toContain( '// fixture comment' );
			expect( text ).toContain( '"has_archive": false' );

			const archive = await page.goto( '/book/' );
			expect( archive?.status() ).toBe( 404 );
		} finally {
			// Later tests (scaffold) expect the archive back.
			writeThemeFile( THEME, 'proto-types/book/type.jsonc', original );
		}
	} );

	test( 'a type created from the screen can be deleted again', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', SCREEN );
		await page.getByRole( 'button', { name: 'Add post type' } ).click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByLabel( 'Singular name' ).fill( 'Case Study' );
		await expect( dialog.getByLabel( 'Slug' ) ).toHaveValue( 'case-study' );
		await dialog
			.getByRole( 'button', { name: 'Create post type' } )
			.click();
		await expect(
			page.getByRole( 'button', { name: 'All post types' } )
		).toBeVisible();

		expect(
			themePathExists( THEME, 'proto-types/case-study/type.jsonc' )
		).toBe( true );
		await page.reload();
		await expect( page.locator( '#adminmenu' ) ).toContainText(
			'Case Studies'
		);

		await page.getByRole( 'button', { name: 'Delete…' } ).click();
		await page
			.getByRole( 'alertdialog' )
			.getByRole( 'button', { name: 'Delete declaration' } )
			.click();
		await expect( page.getByText( /^Deleted / ) ).toBeVisible();
		await expect
			.poll( () => themePathExists( THEME, 'proto-types/case-study' ) )
			.toBe( false );
	} );

	test( 'removing a feature that content uses asks for confirmation', async ( {
		page,
	} ) => {
		expect( bookId ).toBeGreaterThan( 0 );
		await openType( page, 'book' );
		await page
			.getByRole( 'checkbox', { name: 'Editor', exact: true } )
			.uncheck();
		await page.getByRole( 'button', { name: 'Save to file' } ).click();

		const dialog = page.getByRole( 'alertdialog' );
		await expect( dialog ).toContainText(
			'Save changes that affect existing content?'
		);
		await expect( dialog ).toContainText( /\b1\b/ );
		await dialog.getByRole( 'button', { name: 'Go back' } ).click();
		expect(
			themeFile( THEME, 'proto-types/book/type.jsonc' )
		).not.toContain( '"supports"' );
	} );

	test( 'templates are scaffolded once, and never on a classic theme', async ( {
		page,
		requestUtils,
	} ) => {
		await openType( page, 'book' );
		const scaffold = page.getByRole( 'button', {
			name: 'Scaffold templates',
		} );
		await scaffold.click();
		await expect(
			page.getByText( 'Wrote 2 templates, skipped 0.' )
		).toBeVisible();
		const templates = wpCli( `ls wp-content/themes/${ THEME }/templates` );
		expect( templates ).toContain( 'single-book.html' );
		expect( templates ).toContain( 'archive-book.html' );

		await scaffold.click();
		await expect(
			page.getByText( 'Wrote 0 templates, skipped 2.' )
		).toBeVisible();

		installFixtureTheme( 'pb-e2e-classic' );
		await requestUtils.activateTheme( 'pb-e2e-classic' );
		try {
			await openType( page, 'post' );
			await expect(
				page.getByRole( 'button', { name: 'Scaffold templates' } )
			).toBeDisabled();
			await expect(
				page
					.getByText( 'Classic theme: build templates in your theme' )
					.first()
			).toBeVisible();
		} finally {
			await requestUtils.activateTheme( THEME );
		}
	} );

	test( 'with DISALLOW_FILE_MODS the screens are read-only', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		wpCli( 'wp config set DISALLOW_FILE_MODS true --raw' );
		try {
			// PHP's opcache rechecks wp-config.php every 2 seconds.
			await expect( async () => {
				await admin.visitAdminPage( 'admin.php', SCREEN );
				await expect(
					page.getByText(
						'File modifications are disabled on this site'
					)
				).toBeVisible( { timeout: 1000 } );
			} ).toPass( { timeout: 15000 } );

			await openType( page, 'book' );
			await expect(
				page.getByRole( 'button', { name: 'Save to file' } )
			).toBeDisabled();

			await expect(
				requestUtils.rest( {
					path: '/proto-blocks/v1/schema-files/type/book',
					method: 'POST',
					data: {
						text: '{ "name": "Book" }\n',
						hash: null,
						acknowledge: [],
					},
				} )
			).rejects.toMatchObject( { data: { status: 403 } } );
		} finally {
			wpCli( 'wp config delete DISALLOW_FILE_MODS' );
		}
	} );

	test( 'one-line declarations register the same objects as the live sites', () => {
		let output: string;
		try {
			output = wpCli(
				'wp eval-file wp-content/pb-e2e-fixtures/parity.php'
			);
		} catch ( error ) {
			// Exit 1 on a difference: show the report instead of a bare exit code.
			output = String( ( error as { stdout?: Buffer } ).stdout ?? error );
		}
		const report = JSON.parse( output ) as ParityReport;
		expect(
			report.resource,
			JSON.stringify( report, null, 2 )
		).toMatchObject( { equal: true } );
		expect(
			report.oit_resource,
			JSON.stringify( report, null, 2 )
		).toMatchObject( { equal: true } );
	} );
} );
