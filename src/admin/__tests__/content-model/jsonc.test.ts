import { applyDeclarationEdits, parseJsonc } from '@/admin/content-model/jsonc';

const SOURCE = '// header\n{\n  // the name\n  "name": "Product",\n  /* archive */ "has_archive": true,\n}';

test('a changed value keeps comments and key order', () => {
	const before = parseJsonc(SOURCE).data;
	const out = applyDeclarationEdits(SOURCE, before, { ...before, has_archive: 'shop' });

	expect(out).toContain('// header');
	expect(out).toContain('// the name');
	expect(out).toContain('/* archive */');
	expect(out).toContain('"has_archive": "shop"');
	expect(out.indexOf('"name"')).toBeLessThan(out.indexOf('"has_archive"'));
	expect(parseJsonc(out).data).toEqual({ name: 'Product', has_archive: 'shop' });
});

test('a missing key is added at the end', () => {
	const text = '{\n  // keep\n  "name": "Product"\n}';
	const before = parseJsonc(text).data;
	const out = applyDeclarationEdits(text, before, { ...before, menu_position: 30 });

	expect(out).toContain('// keep');
	expect(out.indexOf('"name"')).toBeLessThan(out.indexOf('"menu_position": 30'));
	expect(parseJsonc(out)).toEqual({ data: { name: 'Product', menu_position: 30 }, errors: [] });
});

test('a missing key is added to a file with a trailing comma', () => {
	const before = parseJsonc(SOURCE).data;
	const out = applyDeclarationEdits(SOURCE, before, { ...before, public: false });

	expect(out).toContain('/* archive */');
	expect(parseJsonc(out)).toEqual({ data: { name: 'Product', has_archive: true, public: false }, errors: [] });
});

test('a removed key goes without touching neighbouring comments', () => {
	const text = '{\n  // the name\n  "name": "Product",\n  "public": false,\n  // the archive\n  "has_archive": true\n}';
	const before = parseJsonc(text).data;
	const { public: _removed, ...after } = before;
	const out = applyDeclarationEdits(text, before, after);

	expect(out).toContain('// the name');
	expect(out).toContain('// the archive');
	expect(out).not.toContain('"public"');
	expect(parseJsonc(out).data).toEqual({ name: 'Product', has_archive: true });
});

test('a labels change edits only that key', () => {
	const text =
		'{\n  "name": "Product",\n  "labels": {\n    // shown in the menu\n    "menu_name": "Shop",\n    "add_new": "Add product" // keep\n  }\n}';
	const before = parseJsonc(text).data;
	const after = { ...before, labels: { ...(before.labels as Record<string, unknown>), menu_name: 'Store' } };
	const out = applyDeclarationEdits(text, before, after);

	expect(out).toContain('// shown in the menu');
	expect(out).toContain('"add_new": "Add product" // keep');
	expect(out).toContain('"menu_name": "Store"');
	expect(out).not.toContain('"Shop"');
});

test('a supports change replaces the whole array', () => {
	const text = '{\n  "name": "Product",\n  "supports": ["title", "editor"]\n}';
	const before = parseJsonc(text).data;
	const out = applyDeclarationEdits(text, before, { ...before, supports: ['title', 'thumbnail'] });

	expect(parseJsonc(out).data.supports).toEqual(['title', 'thumbnail']);
});

test('unchanged data returns the text untouched', () => {
	const before = parseJsonc(SOURCE).data;
	expect(applyDeclarationEdits(SOURCE, before, { ...before })).toBe(SOURCE);
});

test('parseJsonc accepts trailing commas and comments', () => {
	const result = parseJsonc(SOURCE);
	expect(result.errors).toEqual([]);
	expect(result.data).toEqual({ name: 'Product', has_archive: true });
});

test('parseJsonc reports syntax errors with an offset', () => {
	const result = parseJsonc('{ "name": }');
	expect(result.errors.length).toBeGreaterThan(0);
	expect(typeof result.errors[0].offset).toBe('number');
	expect(result.data).toEqual({});
});

test('parseJsonc refuses a file that is not an object', () => {
	const result = parseJsonc('["a"]');
	expect(result.errors).toHaveLength(1);
	expect(result.data).toEqual({});
});

test('parseJsonc refuses a __proto__ key', () => {
	const result = parseJsonc('{"labels": {"__proto__": {"polluted": true}}}');

	expect(result.errors).toHaveLength(1);
	expect(result.errors[0].message).toContain('__proto__');
	expect(result.data).toEqual({});
	expect(({} as Record<string, unknown>).polluted).toBeUndefined();
});
