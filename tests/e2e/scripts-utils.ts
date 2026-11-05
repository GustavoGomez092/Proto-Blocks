import type { APIRequestContext } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { removeThemePath, wpCli, writeThemeFile } from './content-model-utils';

/**
 * Shared helpers of the Scripts specs (scripts.spec.ts, scripts-visual.spec.ts).
 * Every script lives in `proto-scripts/` of the installed copy of the block
 * fixture theme; `removeScripts()` deletes the whole folder again.
 */

export const THEME = 'pb-e2e-block';
export const SCREEN = '/wp-admin/admin.php?page=proto-blocks-scripts';
/** Option read by the pb-e2e-scripts mu-plugin: the slug it declares with proto_script(). */
export const PHP_OPTION = 'pb_e2e_scripts_php';

/**
 * Writes `proto-scripts/<slug>/script.jsonc` (as JSON with a comment line)
 * and any snippet files.
 * @param slug     Script slug (folder name).
 * @param data     The decoded script.jsonc.
 * @param snippets Files next to it, by basename (`head.html`, ...).
 */
export function writeScript(
	slug: string,
	data: Record< string, unknown >,
	snippets: Record< string, string > = {}
): void {
	writeThemeFile(
		THEME,
		`proto-scripts/${ slug }/script.jsonc`,
		`// E2E fixture script.\n${ JSON.stringify( data, null, 2 ) }\n`
	);
	for ( const [ file, code ] of Object.entries( snippets ) ) {
		writeThemeFile( THEME, `proto-scripts/${ slug }/${ file }`, code );
	}
}

/** Removes every script folder of the fixture theme. */
export function removeScripts(): void {
	removeThemePath( THEME, 'proto-scripts' );
}

/** Stops the mu-plugin's proto_script() declaration. */
export function clearPhpScript(): void {
	try {
		wpCli( `wp option delete ${ PHP_OPTION }` );
	} catch {
		// Not set.
	}
}

/**
 * Creates a published page and returns its id.
 * @param requestUtils The worker's RequestUtils.
 * @param slug         Page slug (also its title).
 */
export async function createPage(
	requestUtils: RequestUtils,
	slug: string
): Promise< number > {
	const page = await requestUtils.rest< { id: number } >( {
		method: 'POST',
		path: '/wp/v2/pages',
		data: { title: slug, slug, status: 'publish', content: 'E2E page' },
	} );
	return page.id;
}

/**
 * Permanently deletes pages by id.
 * @param requestUtils The worker's RequestUtils.
 * @param ids          Page ids.
 */
export async function deletePages(
	requestUtils: RequestUtils,
	ids: number[]
): Promise< void > {
	for ( const id of ids ) {
		await requestUtils
			.rest( {
				method: 'DELETE',
				path: `/wp/v2/pages/${ id }`,
				params: { force: true },
			} )
			.catch( () => undefined );
	}
}

/**
 * The HTML of a front-end URL.
 * @param request A request context (logged in or not).
 * @param url     Path, e.g. `/`.
 */
export async function html(
	request: APIRequestContext,
	url: string
): Promise< string > {
	const response = await request.get( url );
	if ( ! response.ok() ) {
		throw new Error( `${ url } answered ${ response.status() }` );
	}
	return response.text();
}

/**
 * The comment marker Output prints before each snippet.
 * @param slug Script slug.
 */
export function marker( slug: string ): string {
	return `<!-- proto-blocks: ${ slug } -->`;
}
