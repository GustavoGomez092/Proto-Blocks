#!/usr/bin/env node
/**
 * Regenerates src/admin/content-model/dashicons.ts from a dashicons.css.
 * Usage: node scripts/generate-dashicons.mjs <path-to-dashicons.css>
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const input = process.argv[2];
if (!input) {
	console.error('Usage: node scripts/generate-dashicons.mjs <path-to-dashicons.css>');
	process.exit(1);
}

const css = readFileSync(input, 'utf8');
const names = new Set();
for (const match of css.matchAll(/\.(dashicons-[a-z0-9-]+):before/g)) {
	if (match[1] !== 'dashicons-before') {
		names.add(match[1]);
	}
}
const sorted = [...names].sort();
if (sorted.length === 0) {
	console.error('No dashicons found in ' + input);
	process.exit(1);
}

const out = resolve(dirname(fileURLToPath(import.meta.url)), '../src/admin/content-model/dashicons.ts');
const body = `/**
 * Generated file, do not edit. Regenerate with:
 *   node scripts/generate-dashicons.mjs <path-to-wp-includes/css/dashicons.css>
 */
export const DASHICONS: readonly string[] = [
${sorted.map((n) => `\t'${n}',`).join('\n')}
];
`;
writeFileSync(out, body);
console.log(`Wrote ${sorted.length} icons to ${out}`);
