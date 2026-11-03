/**
 * Parity with tests/php/OptionsPages/OptionsKindTest.php: the browser-side
 * optionsSchema gives the same verdicts as SchemaCheck on the shared fixtures.
 */
import { readFileSync, readdirSync, statSync } from 'fs';
import { join } from 'path';
import { optionsSchema } from '@/admin/content-model/schema';
import { parseJsonc } from '@/admin/content-model/jsonc';

const FIXTURES = join(__dirname, '../../../tests/fixtures/options-pages');

const load = (relative: string) => parseJsonc(readFileSync(join(FIXTURES, relative), 'utf8'));

const pages = readdirSync(FIXTURES).filter(
	(entry) => entry !== 'invalid' && statSync(join(FIXTURES, entry)).isDirectory()
);
const invalid = readdirSync(join(FIXTURES, 'invalid')).filter((file) => file.endsWith('.jsonc'));

test('the fixture set is the one the PHP tests use', () => {
	expect(pages.sort()).toEqual(['cadco-display-settings', 'theme-settings']);
	expect(invalid.sort()).toEqual(['bad-jsonc.jsonc', 'missing-title.jsonc', 'unknown-mode.jsonc']);
});

test.each(pages)('accepts %s/page.jsonc', (slug) => {
	const { data, errors } = load(`${slug}/page.jsonc`);
	expect(errors).toEqual([]);
	const result = optionsSchema.safeParse(data);
	expect(result.success ? [] : result.error.issues).toEqual([]);
});

test('bad-jsonc fails to parse, before any schema check', () => {
	expect(load('invalid/bad-jsonc.jsonc').errors.length).toBeGreaterThan(0);
});

test.each(invalid.filter((file) => file !== 'bad-jsonc.jsonc'))('rejects invalid/%s', (file) => {
	const { data, errors } = load(`invalid/${file}`);
	expect(errors).toEqual([]);
	expect(optionsSchema.safeParse(data).success).toBe(false);
});

test('errors land at the same paths as SchemaCheck', () => {
	const paths = (data: unknown) => {
		const result = optionsSchema.safeParse(data);
		return result.success ? [] : result.error.issues.map((issue) => issue.path);
	};

	expect(paths(load('invalid/missing-title.jsonc').data)).toEqual([['title']]);
	expect(paths(load('invalid/unknown-mode.jsonc').data).every((path) => path[0] === 'storage')).toBe(true);
	expect(paths({ title: 'X', storage: { mode: 'array' } })).toEqual([['storage', 'option']]);
	expect(paths({ title: 'X', children: [{ slug: '../x', title: 'Y' }] })).toEqual([['children', 0, 'slug']]);
	expect(paths({ title: 'X', tabs: [{ fields: [] }] })).toEqual([['tabs', 0, 'title']]);
	expect(paths({ title: 'X', menu: 'Top Level' })).toEqual([['menu']]);
	expect(paths({ title: 'X', capability: 'Manage_Options' })).toEqual([['capability']]);
	expect(paths({ title: 'X', capability: '9_lives' })).toEqual([['capability']]);
	expect(paths({ title: 'X', capability: '_private' })).toEqual([['capability']]);
	expect(paths({ title: 'X', children: [{ slug: 'y', title: 'Y', capability: '9_lives' }] })).toEqual([
		['children', 0, 'capability'],
	]);
	expect(paths({ title: 'X', capability: 'manage_woocommerce' })).toEqual([]);
	expect(paths({ title: 'X', acf_extra: { anything: true } })).toEqual([]);
});
