/**
 * Fails when the field box bundle (assets/js/fields-box.js, built) is
 * missing or larger than its gzip budget. Run after `npm run build`; it is
 * not a *.test.mjs because `test:scripts` runs before the build in CI.
 *
 *   node scripts/check-fields-bundle-size.mjs [path]
 */
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

export const BUDGET = 150 * 1024;

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const file = resolve( process.argv[ 2 ] ?? resolve( root, 'assets/js/fields-box.js' ) );

if ( ! existsSync( file ) ) {
	console.error( `fields-box bundle: ${ file } is missing (measured 0 bytes); run npm run build first.` );
	process.exit( 1 );
}

const size = gzipSync( readFileSync( file ), { level: 9 } ).length;
const kb = ( size / 1024 ).toFixed( 1 );
if ( size > BUDGET ) {
	console.error( `fields-box bundle: ${ size } bytes gzipped (${ kb } KB) exceeds the ${ BUDGET } byte (150 KB) budget.` );
	process.exit( 1 );
}
console.log( `fields-box bundle: ${ size } bytes gzipped (${ kb } KB), budget ${ BUDGET } bytes.` );
