import { execSync } from 'child_process';
import path from 'path';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

const ROOT = path.join( __dirname, '../..' );

/** Where the fixture themes are mounted (see `mappings` in .wp-env.json). */
const FIXTURES = 'wp-content/pb-e2e-fixtures';

export type FixtureTheme = 'pb-e2e-block' | 'pb-e2e-classic';

/**
 * Runs a command in the wp-env tests CLI container (cwd /var/www/html) and
 * returns its stdout. Always `tests-cli`: the suite runs against :8889.
 * Throws when the command exits non-zero (the error carries `stdout`).
 * @param cmd Command to run, e.g. `wp option get siteurl`.
 */
export function wpCli( cmd: string ): string {
	return execSync( `npx wp-env run tests-cli ${ cmd }`, {
		cwd: ROOT,
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).toString();
}

function themeDir( name: string ): string {
	return `wp-content/themes/${ name }`;
}

/**
 * Runs a POSIX shell script in the container; the script is passed base64-encoded so nothing needs quoting.
 * @param script Shell script.
 */
function sh( script: string ): string {
	const encoded = Buffer.from( script, 'utf8' ).toString( 'base64' );
	return wpCli( `sh -c 'echo ${ encoded } | base64 -d | sh'` );
}

/**
 * Single-quotes a value for the container shell.
 * @param value Value to quote.
 */
function quote( value: string ): string {
	return `'${ value.replace( /'/g, `'\\''` ) }'`;
}

/**
 * Installs a fresh copy of a fixture theme. The admin UI writes into the
 * theme, so tests work on this copy inside the container, never on the
 * mapped repo folder.
 * @param name Theme folder name.
 */
export function installFixtureTheme( name: FixtureTheme ): void {
	const target = quote( themeDir( name ) );
	sh(
		[
			'set -e',
			`rm -rf ${ target }`,
			`cp -r ${ quote( `${ FIXTURES }/themes/${ name }` ) } ${ target }`,
			`chmod -R a+rwX ${ target }`,
		].join( '\n' )
	);
}

/**
 * The text of a file in the installed copy of a theme.
 * @param name Theme folder name.
 * @param rel  Path relative to the theme folder.
 */
export function themeFile( name: string, rel: string ): string {
	return sh( `cat ${ quote( `${ themeDir( name ) }/${ rel }` ) }` );
}

/**
 * Writes a file in the installed copy of a theme, creating folders as needed.
 * @param name Theme folder name.
 * @param rel  Path relative to the theme folder.
 * @param text File contents.
 */
export function writeThemeFile(
	name: string,
	rel: string,
	text: string
): void {
	const file = `${ themeDir( name ) }/${ rel }`;
	const encoded = Buffer.from( text, 'utf8' ).toString( 'base64' );
	sh(
		[
			'set -e',
			`mkdir -p ${ quote( path.posix.dirname( file ) ) }`,
			`echo ${ encoded } | base64 -d > ${ quote( file ) }`,
			`chmod -R a+rwX ${ quote( themeDir( name ) ) }`,
		].join( '\n' )
	);
}

/**
 * Deletes a file or folder in the installed copy of a theme.
 * @param name Theme folder name.
 * @param rel  Path relative to the theme folder.
 */
export function removeThemePath( name: string, rel: string ): void {
	sh( `rm -rf ${ quote( `${ themeDir( name ) }/${ rel }` ) }` );
}

/**
 * Whether a file or folder exists in the installed copy of a theme.
 * @param name Theme folder name.
 * @param rel  Path relative to the theme folder.
 */
export function themePathExists( name: string, rel: string ): boolean {
	return (
		sh(
			`test -e ${ quote(
				`${ themeDir( name ) }/${ rel }`
			) } && echo yes || echo no`
		).trim() === 'yes'
	);
}

/** The slug of the active theme, to restore after a spec. */
export function activeTheme(): string {
	return wpCli( 'wp theme list --status=active --field=name' ).trim();
}

/**
 * The shared `beforeAll` of the content model specs: pretty permalinks (this
 * flushes once, before the fixture theme exists), then a fresh copy of the
 * block fixture theme, activated. No manual flush follows, so working
 * `/book/` URLs prove the flush on declaration hash change.
 *
 * @param requestUtils The worker's RequestUtils.
 * @return The previously active theme.
 */
export async function prepareFixtureSite(
	requestUtils: RequestUtils
): Promise< string > {
	const previous = activeTheme();
	wpCli( "wp rewrite structure '/%postname%/'" );
	installFixtureTheme( 'pb-e2e-block' );
	await requestUtils.activateTheme( 'pb-e2e-block' );
	return previous;
}

/**
 * Permanently deletes every post of a REST-enabled type (any status).
 * @param requestUtils The worker's RequestUtils.
 * @param restBase     REST base of the type, e.g. `book`.
 */
export async function deleteAllOfType(
	requestUtils: RequestUtils,
	restBase: string
): Promise< void > {
	const posts = await requestUtils.rest< Array< { id: number } > >( {
		path: `/wp/v2/${ restBase }`,
		params: {
			status: 'publish,future,draft,pending,private',
			per_page: 100,
		},
	} );
	for ( const post of posts ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/${ restBase }/${ post.id }`,
			params: { force: true },
		} );
	}
}
