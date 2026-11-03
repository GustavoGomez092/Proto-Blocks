import { mockApi, mockBootstrap } from '../helpers';
import apiFetch from '@wordpress/api-fetch';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { lineColumn, setAtPath } from '@/admin/screens/fields/jsonc-edit';
import { deleteSchemaFile, getSchemaFile, putSchemaFile } from '@/admin/screens/fields/schema-files';

const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';

afterEach(() => {
	delete window.protoBlocksAdmin;
	jest.clearAllMocks();
});

describe('setAtPath', () => {
	it('appends with -1 and keeps comments', () => {
		const text = setAtPath('{\n  // keep\n  "fields": [1]\n}', ['fields', -1], 2);
		expect(parseJsonc(text).data).toEqual({ fields: [1, 2] });
		expect(text).toContain('// keep');
	});

	it('removes an element with undefined', () => {
		const text = setAtPath('{\n  // keep\n  "fields": [{ "key": "a" }, { "key": "b" }]\n}', ['fields', 0], undefined);
		expect(parseJsonc(text).data).toEqual({ fields: [{ key: 'b' }] });
		expect(text).toContain('// keep');
	});

	it('sets a nested value with two-space indentation', () => {
		const text = setAtPath('{\n  "title": "A"\n}', ['location'], [[{ param: 'post_type', operator: '==', value: 'post' }]]);
		expect(parseJsonc(text).data).toEqual({ title: 'A', location: [[{ param: 'post_type', operator: '==', value: 'post' }]] });
		expect(text).toContain('\n  "location"');
	});
});

describe('lineColumn', () => {
	it('is 1-based', () => {
		const text = '{\n  "a": 1,\n  x\n}';
		expect(lineColumn(text, text.indexOf('x'))).toEqual({ line: 3, column: 3 });
		expect(lineColumn(text, 0)).toEqual({ line: 1, column: 1 });
	});
});

describe('schema-files', () => {
	it('calls the schema-files routes with exact URLs and bodies', async () => {
		mockBootstrap();
		const file = { exists: true, path: 'demo/proto-fields/events/group.jsonc', text: '{}', hash: 'h1', data: {}, writable: true, reason: null };
		const mock = mockApi({
			'admin/schema-files/field-group/events': file,
			'POST admin/schema-files/type/talk': { path: 'demo/proto-types/talk/type.jsonc', hash: 'h2', created: false, warnings: [] },
			'DELETE admin/schema-files/field-group/events?confirm=events': { deleted: 'demo/proto-fields/events/group.jsonc', kept: [] },
		});

		await expect(getSchemaFile('field-group', 'events')).resolves.toEqual(file);
		await expect(putSchemaFile('type', 'talk', '{}', 'h0')).resolves.toEqual({ path: 'demo/proto-types/talk/type.jsonc', hash: 'h2', created: false });
		await expect(deleteSchemaFile('field-group', 'events', 'events')).resolves.toEqual({ deleted: 'demo/proto-fields/events/group.jsonc', kept: [] });

		expect((apiFetch as unknown as jest.Mock).mock.calls.map(([options]) => [options.method, options.url, options.data])).toEqual([
			['GET', ROOT + 'admin/schema-files/field-group/events', undefined],
			['POST', ROOT + 'admin/schema-files/type/talk', { text: '{}', hash: 'h0', acknowledge: [] }],
			['DELETE', ROOT + 'admin/schema-files/field-group/events?confirm=events', undefined],
		]);
		expect(mock).toHaveBeenCalledTimes(3);
	});

	it('refuses a slug that is not a slug before any request', async () => {
		mockBootstrap();
		const mock = mockApi({});
		await expect(getSchemaFile('field-group', '../x')).rejects.toThrow();
		await expect(putSchemaFile('field-group', 'A', '{}', null)).rejects.toThrow();
		await expect(deleteSchemaFile('field-group', 'x\n')).rejects.toThrow();
		expect(mock).not.toHaveBeenCalled();
	});
});
