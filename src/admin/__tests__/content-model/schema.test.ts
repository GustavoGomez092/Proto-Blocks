import { taxonomySchema, toZod, typeSchema } from '@/admin/content-model/schema';
import type { z } from 'zod';

const paths = (schema: z.ZodTypeAny, data: unknown) => {
	const result = schema.safeParse(data);
	return result.success ? [] : result.error.issues.map((issue) => issue.path);
};

test('a wrong type fails at its path', () => {
	expect(paths(typeSchema, { name: 'X', public: 'yes' })).toEqual([['public']]);
});

test('has_archive takes a slug and rewrite takes false', () => {
	expect(typeSchema.safeParse({ name: 'X', has_archive: 'resources' }).success).toBe(true);
	expect(typeSchema.safeParse({ name: 'X', rewrite: false }).success).toBe(true);
});

test('an inline taxonomy is checked in place', () => {
	expect(paths(typeSchema, { name: 'X', taxonomies: [{ name: 'Kind', hierarchical: 'no' }] })).toEqual([
		['taxonomies', 0, 'hierarchical'],
	]);
});

test('unknown keys pass and are kept', () => {
	const result = typeSchema.safeParse({ name: 'X', delete_with_user: true });
	expect(result.success).toBe(true);
	expect(result.data).toEqual({ name: 'X', delete_with_user: true });
});

test('the taxonomy schema allows a 32-character slug', () => {
	expect(taxonomySchema.safeParse({ name: 'X', slug: 'a'.repeat(32) }).success).toBe(true);
	expect(typeSchema.safeParse({ name: 'X', slug: 'a'.repeat(32) }).success).toBe(false);
});

// Same inputs and verdicts as tests/php/ContentModel/SchemaCheckTest.php.
test.each([
	[{ name: 'Product' }, true],
	[{ name: 'X', public: 'yes', menu_position: '22' }, false],
	[{ name: 'X', has_archive: true }, true],
	[{ name: 'X', has_archive: 'Bad Slug' }, false],
	[{ name: 'X', rewrite: { slug: 'resources', with_front: false } }, true],
	[{ name: 'X', rewrite: 'resources' }, false],
	[{ name: 'X', taxonomies: ['category', { name: 'Kind', hierarchical: 'no' }] }, false],
	[{ name: 'X', delete_with_user: true }, true],
	[{ name: 'X', slug: 'abc\n' }, false],
	[{ name: 'X', capability_type: ['a'] }, false],
	[{ name: 'X', capability_type: ['a', 'b'] }, true],
	[{ name: 'X', capability_type: ['a', 'b', 'c'] }, false],
	[{ name: 'X', capability_type: 'product' }, true],
	[{ name: 'X', slug: 'Product' }, false],
	[{ name: 'X', slug: 'a'.repeat(21) }, false],
	[{ name: 'X', slug: 'a'.repeat(20) }, true],
	[{ template: [['core/paragraph']] }, true],
	[{ taxonomies: [{ hierarchical: true }] }, false],
	[{ name: 'X', taxonomies: ['Bad Slug'] }, false],
])('parity with SchemaCheck: %j is valid: %s', (data, valid) => {
	expect(typeSchema.safeParse(data).success).toBe(valid);
});

test('parity paths for several errors and for the fitting anyOf branch', () => {
	expect(paths(typeSchema, { name: 'X', public: 'yes', menu_position: '22' }).sort()).toEqual([['menu_position'], ['public']]);
	expect(paths(typeSchema, { name: 'X', taxonomies: ['category', { name: 'Kind', hierarchical: 'no' }] })).toEqual([
		['taxonomies', 1, 'hierarchical'],
	]);
	expect(paths(typeSchema, { name: 'X', rewrite: 'resources' })).toEqual([['rewrite']]);
	expect(paths(typeSchema, {})).toEqual([]);
	expect(paths(typeSchema, { taxonomies: [{ hierarchical: true }] })).toEqual([['taxonomies', 0, 'name']]);
	expect(paths(typeSchema, { name: 'X', taxonomies: ['Bad Slug'] })).toEqual([['taxonomies', 0]]);
	expect(paths(taxonomySchema, { labels: { menu_name: 'Topics' } })).toEqual([]);
});

test('allOf reports the errors of every branch', () => {
	const schema = toZod({
		allOf: [{ type: 'object', properties: { a: { type: 'integer' } } }, { required: ['b'] }],
	});
	expect(paths(schema, { a: 1, b: 2 })).toEqual([]);
	expect(paths(schema, { a: 1 })).toEqual([['b']]);
	expect(paths(schema, { a: 'x' })).toEqual([['a'], ['b']]);
});

test('inline schema keywords match SchemaCheck', () => {
	const schema = toZod({
		type: 'object',
		required: ['a'],
		additionalProperties: false,
		properties: {
			a: { type: 'integer', minimum: 1, maximum: 5 },
			e: { enum: ['x', 'y'] },
		},
	});
	expect(paths(schema, { a: 3, e: 'x' })).toEqual([]);
	expect(paths(schema, {})).toEqual([['a']]);
	expect(paths(schema, { a: 3, z: 1 })).toEqual([['z']]);
	expect(paths(schema, { a: 3, e: 'q' })).toEqual([['e']]);
	expect(paths(schema, { a: 0 })).toEqual([['a']]);
	expect(paths(schema, { a: 6 })).toEqual([['a']]);
	expect(paths(toZod({ type: 'array', uniqueItems: true }), ['a', 'a'])).toEqual([[1]]);
	expect(paths(toZod({ type: 'string', minLength: 2 }), '🙂🙂')).toEqual([]);
});
