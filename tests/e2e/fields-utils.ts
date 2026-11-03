import path from 'path';
import {
	Admin,
	Editor,
	PageUtils,
	RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';
import { expect } from '@playwright/test';
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { activeTheme, wpCli } from './content-model-utils';

/** The fixture theme the Fields specs run on (mounted by `themes` in .wp-env.json). */
export const FIELDS_THEME = 'pb-e2e-fields';

export const PIXEL_PNG = path.join( __dirname, 'fixtures/assets/pixel.png' );

/**
 * Pretty permalinks, then the Fields fixture theme. Returns the theme to
 * restore in `afterAll`.
 * @param requestUtils The worker's RequestUtils.
 */
export async function activateFieldsTheme(
	requestUtils: RequestUtils
): Promise< string > {
	const previous = activeTheme();
	wpCli( "wp rewrite structure '/%postname%/'" );
	await requestUtils.activateTheme( FIELDS_THEME );
	return previous;
}

/**
 * Restores the previous theme and the block editor.
 * @param requestUtils The worker's RequestUtils.
 * @param previous     The theme returned by activateFieldsTheme().
 */
export async function restoreTheme(
	requestUtils: RequestUtils,
	previous: string
): Promise< void > {
	setClassicEditor( false );
	await requestUtils.activateTheme( previous );
}

/**
 * Turns the block editor off (the proto-classic-editor mu-plugin) or on.
 * @param on True for the classic editor.
 */
export function setClassicEditor( on: boolean ): void {
	wpCli( `wp option update proto_test_classic_editor ${ on ? 1 : 0 }` );
}

/**
 * Creates a post of a type through REST (as the admin).
 * @param requestUtils The worker's RequestUtils.
 * @param restBase     REST base, e.g. `event`.
 * @param data         Request body.
 * @return The new post's ID.
 */
export async function createPost(
	requestUtils: RequestUtils,
	restBase: string,
	data: Record< string, unknown >
): Promise< number > {
	const post = await requestUtils.rest< { id: number } >( {
		method: 'POST',
		path: `/wp/v2/${ restBase }`,
		data: { status: 'publish', ...data },
	} );
	return post.id;
}

/**
 * Permanently deletes a post.
 * @param requestUtils The worker's RequestUtils.
 * @param restBase     REST base.
 * @param id           Post ID.
 */
export async function deletePost(
	requestUtils: RequestUtils,
	restBase: string,
	id: number
): Promise< void > {
	await requestUtils
		.rest( {
			method: 'DELETE',
			path: `/wp/v2/${ restBase }/${ id }`,
			params: { force: true },
		} )
		.catch( () => undefined );
}

/**
 * Meta of a post as JSON text from WP-CLI, or null when the key is not set.
 * @param id  Post ID.
 * @param key Meta key.
 */
export function postMeta( id: number, key: string ): string | null {
	try {
		return wpCli( `wp post meta get ${ id } ${ key } --format=json` ).trim();
	} catch {
		return null;
	}
}

/**
 * Writes post meta as the admin (the trust callbacks look at the current
 * user, and WP-CLI's default user 0 cannot write markup).
 * @param id    Post ID.
 * @param key   Meta key.
 * @param value Value (string or JSON-able).
 */
export function setPostMeta( id: number, key: string, value: unknown ): void {
	const encoded = Buffer.from( JSON.stringify( value ), 'utf8' ).toString(
		'base64'
	);
	wpCli(
		`wp eval --user=admin 'update_post_meta(${ id }, "${ key }", json_decode(base64_decode("${ encoded }"), true));'`
	);
}

const STATE_DIR = path.join( __dirname, '.auth' );

/**
 * Creates the user (if needed) and returns a logged-in browser context plus
 * a RequestUtils for it. Login goes through RequestUtils, not the form.
 * @param browser  Playwright browser.
 * @param username User login.
 * @param role     WordPress role.
 */
export async function userSession(
	browser: Browser,
	username: string,
	role: string
): Promise< {
	context: BrowserContext;
	page: Page;
	utils: RequestUtils;
	admin: Admin;
	editor: Editor;
	id: number;
} > {
	let id = 0;
	try {
		id = Number(
			wpCli( `wp user get ${ username } --field=ID` ).trim()
		);
	} catch {
		id = Number(
			wpCli(
				`wp user create ${ username } ${ username }@example.test --role=${ role } --user_pass=password --porcelain`
			).trim()
		);
	}
	const storageStatePath = path.join( STATE_DIR, `${ username }.json` );
	const utils = await RequestUtils.setup( {
		baseURL: process.env.WP_BASE_URL,
		user: { username, password: 'password' },
		storageStatePath,
	} );
	await utils.setupRest();
	const context = await browser.newContext( {
		baseURL: process.env.WP_BASE_URL,
		storageState: storageStatePath,
	} );
	const page = await context.newPage();
	const pageUtils = new PageUtils( { page } );
	const editor = new Editor( { page } );
	const admin = new Admin( { page, pageUtils, editor } );
	return { context, page, utils, admin, editor, id };
}

/**
 * Deletes a user by login (reassigning nothing; its posts are removed).
 * @param username User login.
 */
export function deleteUser( username: string ): void {
	try {
		wpCli( `wp user delete ${ username } --yes` );
	} catch {
		// Already gone.
	}
}

const POSTBOX: Record< string, string > = {
	'Event details': 'group_6543a1b2c3d4e',
	Developer: 'group_pb_e2e_developer',
};

/**
 * The field box of a group on the current screen: a meta box by title, or
 * (term and user forms, one box per screen) the first box.
 * @param page  Editor page.
 * @param title Group title.
 */
export function box( page: Page, title?: string ) {
	const key = title ? POSTBOX[ title ] : undefined;
	return key
		? page.locator( `#proto-fields-${ key } [data-proto-fields-box]` )
		: page.locator( '[data-proto-fields-box]' ).first();
}

/**
 * Waits until the field box has mounted its form.
 * @param page  Editor page.
 * @param title Optional group title.
 */
export async function waitForBox( page: Page, title?: string ) {
	const b = box( page, title );
	// The block editor collapses its meta boxes panel by default.
	const toggle = page.getByRole( 'button', { name: 'Meta Boxes' } );
	if ( ( await toggle.count() ) > 0 ) {
		await expect( toggle ).toBeVisible();
		if ( ( await toggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
			// The resize handle overlays the toggle, so click it programmatically.
			await toggle.dispatchEvent( 'click' );
			await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		}
	}
	await b.locator( '[data-field]' ).first().waitFor();
	return b;
}

/**
 * Clicks Update/Save in the block editor and waits for the post and its
 * meta boxes to be saved.
 * @param page Block editor page.
 */
export async function saveBlockEditor( page: Page ): Promise< void > {
	await page
		.getByRole( 'button', { name: /^(Update|Save|Publish)$/ } )
		.first()
		.click();
	await page.waitForFunction( () => {
		const w = window as unknown as {
			wp: {
				data: {
					select: ( s: string ) => Record< string, () => boolean >;
				};
			};
		};
		const editor = w.wp.data.select( 'core/editor' );
		const post = w.wp.data.select( 'core/edit-post' );
		return (
			editor.didPostSaveRequestSucceed() &&
			! editor.isSavingPost() &&
			! post.isSavingMetaBoxes()
		);
	} );
}

/**
 * Clicks Update in the classic editor and waits for the reloaded screen.
 * @param page Classic editor page.
 */
export async function saveClassicEditor( page: Page ): Promise< void > {
	await Promise.all( [
		page.waitForURL( /message=/ ),
		page.locator( '#publish' ).click(),
	] );
	await waitForBox( page );
}

/**
 * Makes a user the post's author (the fixture type does not declare `author`
 * support, so REST ignores the field).
 * @param id     Post ID.
 * @param userId User ID.
 */
export function setAuthor( id: number, userId: number ): void {
	wpCli( `wp post update ${ id } --post_author=${ userId }` );
}
