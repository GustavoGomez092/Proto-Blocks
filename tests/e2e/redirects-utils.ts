import type { APIRequestContext, APIResponse } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import {
	removeThemePath,
	themeFile,
	themePathExists,
	wpCli,
	writeThemeFile,
} from './content-model-utils';

/**
 * Shared helpers of the Redirects specs (redirects.spec.ts,
 * redirects-visual.spec.ts). Every group file lives in `proto-redirects/` of
 * the installed copy of the block fixture theme; `removeRedirects()` deletes
 * the whole folder, `clearRedirectTables()` empties the hits and log tables.
 */

export const THEME = 'pb-e2e-block';
export const SCREEN = '/wp-admin/admin.php?page=proto-blocks-redirects';
export const BASE_URL = process.env.WP_BASE_URL ?? 'http://localhost:8889';
/** Option read by the pb-e2e-redirects mu-plugin: the source it declares with proto_redirect(). */
export const PHP_OPTION = 'pb_e2e_redirects_php';

/** Path of a group file inside the theme. */
export function groupPath( slug: string ): string {
	return `proto-redirects/${ slug }/redirects.jsonc`;
}

/**
 * Writes `proto-redirects/<slug>/redirects.jsonc` as JSON with a leading
 * hand-written comment line.
 * @param slug  Group slug (folder name).
 * @param group The decoded group file.
 */
export function writeGroup( slug: string, group: Record< string, unknown > ): void {
	writeThemeFile(
		THEME,
		groupPath( slug ),
		`// E2E fixture group.\n${ JSON.stringify( group, null, 2 ) }\n`
	);
}

/** Writes `proto-redirects/<slug>/redirects.jsonc` verbatim. */
export function writeGroupText( slug: string, text: string ): void {
	writeThemeFile( THEME, groupPath( slug ), text );
}

/** The text of a group file. */
export function readGroup( slug: string ): string {
	return themeFile( THEME, groupPath( slug ) );
}

/** Whether a group file exists. */
export function groupExists( slug: string ): boolean {
	return themePathExists( THEME, groupPath( slug ) );
}

/**
 * Writes `proto-redirects/settings.jsonc`.
 * @param settings The decoded settings file.
 */
export function writeSettings( settings: Record< string, unknown > ): void {
	writeThemeFile(
		THEME,
		'proto-redirects/settings.jsonc',
		`// E2E settings.\n${ JSON.stringify( settings, null, 2 ) }\n`
	);
}

/** Removes every group file and the settings file of the fixture theme. */
export function removeRedirects(): void {
	removeThemePath( THEME, 'proto-redirects' );
}

/**
 * Runs PHP in WordPress (wp eval) from a base64 payload, so nothing needs
 * quoting for the container shell.
 * @param php PHP code without the opening tag.
 */
export function wpEval( php: string ): string {
	const encoded = Buffer.from( php, 'utf8' ).toString( 'base64' );
	return wpCli( `wp eval 'eval(base64_decode("${ encoded }"));'` );
}

/** Empties the hits, redirect log and 404 log tables. */
export function clearRedirectTables(): void {
	wpEval(
		'global $wpdb; foreach (["proto_redirect_hits", "proto_redirect_log", "proto_404_log"] as $t) { $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}"); }'
	);
}

/**
 * Rows of a redirects table (`proto_redirect_hits`, `proto_redirect_log`,
 * `proto_404_log`), optionally filtered by `url`.
 */
export function tableRows(
	table: 'proto_redirect_hits' | 'proto_redirect_log' | 'proto_404_log',
	url?: string
): Array< Record< string, string | null > > {
	const where =
		url === undefined
			? ''
			: ` WHERE url = '" . esc_sql(base64_decode("${ Buffer.from(
					url,
					'utf8'
			  ).toString( 'base64' ) }")) . "'`;
	const out = wpEval(
		`global $wpdb; echo wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}${ table }${ where } ORDER BY 1", ARRAY_A));`
	);
	return JSON.parse( out.trim() || '[]' );
}

/** Stops the mu-plugin's proto_redirect() declaration. */
export function clearPhpRedirect(): void {
	try {
		wpCli( `wp option delete ${ PHP_OPTION }` );
	} catch {
		// Not set.
	}
}

/**
 * One request without following redirects.
 * @param request A request context (logged in or not).
 * @param url     Path and query.
 * @param options Method, headers, body.
 */
export function raw(
	request: APIRequestContext,
	url: string,
	options: {
		method?: 'GET' | 'POST' | 'HEAD';
		headers?: Record< string, string >;
		data?: string | Record< string, string >;
	} = {}
): Promise< APIResponse > {
	// Absolute, so a path starting with `//` is not read as a host.
	return request.fetch( url.startsWith( '/' ) ? `${ BASE_URL }${ url }` : url, {
		method: options.method ?? 'GET',
		headers: options.headers,
		data: options.data,
		maxRedirects: 0,
	} );
}

/**
 * Status and Location of one request (Location reduced to path and query
 * when it points at this site).
 */
export async function hop(
	request: APIRequestContext,
	url: string,
	options: Parameters< typeof raw >[ 2 ] = {}
): Promise< { status: number; location: string | null; by: string | null } > {
	const response = await raw( request, url, options );
	const location = response.headers().location ?? null;
	return {
		status: response.status(),
		by: response.headers()[ 'x-redirect-by' ] ?? null,
		location:
			location !== null && location.startsWith( BASE_URL )
				? location.slice( BASE_URL.length )
				: location,
	};
}

/**
 * Creates a published post (or page) and returns its id.
 * @param requestUtils The worker's RequestUtils.
 * @param type         `posts` or `pages`.
 * @param slug         Slug (also the title).
 * @param content      Body text.
 */
export async function createPost(
	requestUtils: RequestUtils,
	type: 'posts' | 'pages',
	slug: string,
	content = 'E2E redirect page'
): Promise< number > {
	const post = await requestUtils.rest< { id: number } >( {
		method: 'POST',
		path: `/wp/v2/${ type }`,
		data: { title: slug, slug, status: 'publish', content },
	} );
	return post.id;
}

/** Permanently deletes posts or pages by id. */
export async function deletePosts(
	requestUtils: RequestUtils,
	type: 'posts' | 'pages',
	ids: number[]
): Promise< void > {
	for ( const id of ids ) {
		await requestUtils
			.rest( {
				method: 'DELETE',
				path: `/wp/v2/${ type }/${ id }`,
				params: { force: true },
			} )
			.catch( () => undefined );
	}
}
