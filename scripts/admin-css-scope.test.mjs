import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const postcss = require('postcss');
const scopeUtilities = require('./postcss/scope-utilities.js');
const ADMIN_ROOTS = ['#proto-blocks-admin', '#proto-blocks-admin-portal'];
const scopePlugin = () => scopeUtilities({ files: { 'admin/styles/admin.css': ADMIN_ROOTS } });

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const SCOPE = `:where(${ADMIN_ROOTS.join(', ')})`;

function run(css, from = '/x/src/admin/styles/admin.css') {
	return postcss([scopePlugin()]).process(css, { from }).css;
}

test('plugin scopes top-level utility rules, including inside @media', () => {
	const out = run(
		'@layer utilities{.hidden{display:none!important}.a,.b:hover{color:red}@media (hover:hover){.c:hover{color:blue}}}'
	);
	assert.match(out, new RegExp(`${escape(SCOPE)} \\.hidden\\{`));
	assert.match(out, new RegExp(`${escape(SCOPE)} \\.a,\\s*${escape(SCOPE)} \\.b:hover`));
	assert.match(out, new RegExp(`@media \\(hover:hover\\)\\{${escape(SCOPE)} \\.c:hover`));
});

test('plugin leaves non-utility layers, keyframe steps and nested rules alone', () => {
	const out = run(
		'@layer theme{:root{--x:1}}#proto-blocks-admin{color:red}@layer utilities{.p{color:red;&:hover{color:blue}}}@keyframes spin{to{transform:rotate(1turn)}}'
	);
	assert.match(out, /@layer theme\{:root\{--x:1\}\}/);
	assert.match(out, /^@layer theme.*\}#proto-blocks-admin\{color:red\}/);
	assert.match(out, /&:hover\{color:blue\}/);
	assert.match(out, /@keyframes pb-spin\{to\{/);
});

test('plugin renames keyframes and their animation references', () => {
	const out = run(
		'@layer theme{:root{--animate-spin:spin 1s linear infinite;--animate-pulse:pulse 2s infinite}}@layer utilities{.x{animation:spin 1s}}@keyframes spin{to{opacity:1}}@keyframes pulse{50%{opacity:.5}}'
	);
	assert.match(out, /--animate-spin:pb-spin 1s/);
	assert.match(out, /--animate-pulse:pb-pulse 2s/);
	assert.match(out, /animation:pb-spin 1s/);
	assert.doesNotMatch(out, /@keyframes (spin|pulse)\b/);
});

test('plugin ignores stylesheets other than the admin entry', () => {
	const css = '@layer utilities{.hidden{display:none}}@keyframes spin{to{opacity:1}}';
	assert.equal(run(css, '/x/src/editor/styles/editor.css'), css);
});

test('built assets/css/admin.css has no unscoped utility rules', () => {
	const css = readFileSync(join(root, 'assets/css/admin.css'), 'utf8');
	const ast = postcss.parse(css);

	// No rule anywhere whose selector is a bare `.hidden` / `.fixed` etc.
	for (const bare of ['.hidden', '.fixed', '.table', '.border', '.container', '.block', '.flex']) {
		assert.ok(!css.includes(`}${bare}{`) && !css.startsWith(`${bare}{`), `${bare} is emitted unscoped`);
	}
	assert.ok(css.includes(`${SCOPE} .hidden{`), 'scoped .hidden rule missing (stale build?)');
	assert.ok(css.includes(`${SCOPE} .fixed{`), 'scoped .fixed rule missing (stale build?)');

	let utilityRules = 0;
	ast.walkAtRules('layer', (layer) => {
		if (layer.params.trim() !== 'utilities' || !layer.nodes) {
			return;
		}
		layer.walkRules((rule) => {
			if (rule.parent && rule.parent.type === 'rule') {
				return;
			}
			for (const selector of rule.selectors) {
				utilityRules++;
				assert.ok(selector.startsWith(`${SCOPE} `), `unscoped utility selector: ${selector}`);
			}
		});
	});
	assert.ok(utilityRules > 50, 'expected the utilities layer to be present');

	ast.walkAtRules(/keyframes$/i, (atRule) => {
		assert.ok(atRule.params.startsWith('pb-'), `global keyframes name: ${atRule.params}`);
	});
});

function escape(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
