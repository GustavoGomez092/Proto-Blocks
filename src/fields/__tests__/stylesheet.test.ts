/**
 * @jest-environment node
 */
import { readFileSync } from 'fs';
import { join } from 'path';
import postcss from 'postcss';

// eslint-disable-next-line @typescript-eslint/no-require-imports
const scopeUtilities = require( '../../../scripts/postcss/scope-utilities' );

const ROOT = join( __dirname, '../../..' );
const SCOPE = ':where(.proto-blocks-fields, #proto-blocks-fields-portal)';

describe( 'built assets/css/fields-box.css', () => {
	const css = readFileSync( join( ROOT, 'assets/css/fields-box.css' ), 'utf8' );

	test( 'has no unscoped utility that could hit other markup on the edit screen', () => {
		// A rule starting right after `{` or `}` (or at the start) is unscoped.
		for ( const cls of [ 'hidden', 'fixed', 'block', 'flex', 'grid', 'border', 'table', 'container', 'visible' ] ) {
			expect( css ).not.toMatch( new RegExp( `(^|[{},])\\.${ cls }[{:,\\[ ]` ) );
		}
		expect( css ).toContain( `${ SCOPE } .hidden{` );
	} );

	test( 'keeps the theme variables off :root and its keyframes prefixed', () => {
		expect( css ).not.toMatch( /:root|:host/ );
		expect( css ).not.toMatch( /@keyframes (spin|pulse|ping|bounce)\b/ );
		expect( css ).toMatch( /@keyframes pb-pulse/ );
	} );
} );

describe( 'scope-utilities PostCSS plugin', () => {
	const run = ( css: string, from: string ) =>
		postcss( [ scopeUtilities( { files: { 'fields/box/styles.css': [ '.r', '#p' ], 'admin.css': [ '#a' ] } } ) ] ).process(
			css,
			{ from }
		).css;
	const input =
		'@layer theme{:root,:host{--animate-spin:spin 1s linear infinite}}' +
		'@layer utilities{.hidden{display:none}@media (x){.fixed,.a:hover{position:fixed}}}' +
		'.base{color:red}@keyframes spin{to{transform:rotate(1turn)}}';

	test( 'prefixes utilities, moves theme variables, renames keyframes', () => {
		expect( run( input, '/x/src/fields/box/styles.css' ) ).toBe(
			'@layer theme{.r,#p{--animate-spin:pb-spin 1s linear infinite}}' +
				'@layer utilities{:where(.r, #p) .hidden{display:none}@media (x){:where(.r, #p) .fixed,:where(.r, #p) .a:hover{position:fixed}}}' +
				'.base{color:red}@keyframes pb-spin{to{transform:rotate(1turn)}}'
		);
	} );

	test( 'matches by basename and leaves other files alone', () => {
		expect( run( input, '/x/src/admin/styles/admin.css' ) ).toContain( ':where(#a) .hidden' );
		expect( run( input, '/x/src/other.css' ) ).toBe( input );
	} );
} );
