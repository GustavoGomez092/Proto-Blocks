import { mockApi, mockBootstrap } from '../helpers';
import { act, renderHook, waitFor } from '@testing-library/react';
import { toast } from 'sonner';
import { useDeclarationFile } from '@/admin/content-model/useDeclarationFile';
import type { FileResult } from '@/admin/content-model/types';

const TEXT = '// keep me\n{\n  "name": "Product",\n  "has_archive": true\n}';

function fileResult(overrides: Partial<FileResult> = {}): FileResult {
	return {
		exists: true,
		path: 'wp-content/themes/x/proto-types/product/type.jsonc',
		text: TEXT,
		hash: 'h1',
		data: { name: 'Product', has_archive: true },
		writable: true,
		reason: null,
		...overrides,
	};
}

beforeEach(() => {
	mockBootstrap();
});

afterEach(() => {
	delete window.protoBlocksAdmin;
	jest.restoreAllMocks();
	jest.clearAllMocks();
});

async function loaded(kind: 'type' | 'taxonomy' = 'type', slug = 'product', name?: string) {
	const hook = renderHook(() => useDeclarationFile(kind, slug, name));
	await waitFor(() => expect(hook.result.current.file).not.toBeNull());
	return hook;
}

test('loads the file, passing the name for a new declaration', async () => {
	const api = mockApi({ 'admin/schema-files/type/case-study?name=Case%20Study': fileResult({ exists: false, hash: null }) });
	const { result } = await loaded('type', 'case-study', 'Case Study');

	expect(result.current.loading).toBe(false);
	expect(result.current.error).toBeNull();
	expect(result.current.file?.exists).toBe(false);
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({ url: 'https://example.test/wp-json/proto-blocks/v1/admin/schema-files/type/case-study?name=Case%20Study' })
	);
});

test('a load error is exposed', async () => {
	mockApi({});
	const { result } = renderHook(() => useDeclarationFile('type', 'product'));
	await waitFor(() => expect(result.current.error).not.toBeNull());
	expect(result.current.error?.code).toBe('rest_no_route');
	expect(result.current.loading).toBe(false);
});

test('save posts the comment-preserving text with the hash and toasts', async () => {
	const success = jest.spyOn(toast, 'success');
	const post = jest.fn(() => ({ path: 'wp-content/themes/x/proto-types/product/type.jsonc', hash: 'h2', created: false, warnings: [] }));
	mockApi({ 'GET admin/schema-files/type/product': fileResult(), 'POST admin/schema-files/type/product': post });
	const { result } = await loaded();

	let outcome: unknown;
	await act(async () => {
		outcome = await result.current.save({ ...result.current.file!.data, has_archive: false });
	});

	expect(outcome).toEqual({ status: 'saved' });
	const body = post.mock.calls[0][0] as { text: string; hash: string; acknowledge: string[] };
	expect(body.hash).toBe('h1');
	expect(body.acknowledge).toEqual([]);
	expect(body.text).toContain('// keep me');
	expect(body.text).toContain('"has_archive": false');
	expect(success).toHaveBeenCalledWith('Saved to wp-content/themes/x/proto-types/product/type.jsonc.');
	expect(result.current.file?.hash).toBe('h2');
	expect(result.current.file?.data).toEqual({ name: 'Product', has_archive: false });
});

test('save diffs against the text, not the data it was given', async () => {
	const post = jest.fn(() => ({ path: 'p', hash: 'h2', created: false, warnings: [] }));
	// data out of step with the text: has_archive is true in the file.
	mockApi({
		'GET admin/schema-files/type/product': fileResult({ data: { name: 'Product', has_archive: false } }),
		'POST admin/schema-files/type/product': post,
	});
	const { result } = await loaded();

	await act(async () => {
		await result.current.save({ ...result.current.file!.data });
	});
	const text = (post.mock.calls[0][0] as { text: string }).text;
	expect(text).toContain('// keep me');
	expect(text).toContain('"has_archive": false');
});

test('save passes acknowledged guardrail codes', async () => {
	const post = jest.fn(() => ({ path: 'p', hash: 'h2', created: false, warnings: [] }));
	mockApi({ 'GET admin/schema-files/type/product': fileResult(), 'POST admin/schema-files/type/product': post });
	const { result } = await loaded();

	await act(async () => {
		await result.current.save(result.current.file!.data, ['supports_removed']);
	});
	expect((post.mock.calls[0][0] as { acknowledge: string[] }).acknowledge).toEqual(['supports_removed']);
});

test('a conflict resolves conflict', async () => {
	mockApi({
		'GET admin/schema-files/type/product': fileResult(),
		'POST admin/schema-files/type/product': () => {
			throw { code: 'proto_blocks_conflict', message: 'Changed on disk.', data: { status: 409 } };
		},
	});
	const { result } = await loaded();

	await expect(result.current.save({ name: 'Product' })).resolves.toEqual({ status: 'conflict' });
});

test('a guardrail resolves confirm with the guardrails', async () => {
	const guardrails = [{ code: 'supports_removed', message: 'm', count: 2, detail: ['thumbnail'] }];
	mockApi({
		'GET admin/schema-files/type/product': fileResult(),
		'POST admin/schema-files/type/product': () => {
			throw { code: 'proto_blocks_needs_confirmation', message: 'Confirm.', data: { status: 409, guardrails } };
		},
	});
	const { result } = await loaded();

	await expect(result.current.save({ name: 'Product', supports: ['title'] })).resolves.toEqual({ status: 'confirm', guardrails });
});

test('other save errors reject', async () => {
	mockApi({
		'GET admin/schema-files/type/product': fileResult(),
		'POST admin/schema-files/type/product': () => {
			throw { code: 'proto_blocks_schema_invalid', message: 'Bad.', data: { status: 400 } };
		},
	});
	const { result } = await loaded();

	await expect(result.current.save({ name: 'Product' })).rejects.toMatchObject({ code: 'proto_blocks_schema_invalid' });
});

test('remove deletes, passing confirm, and maps confirm_required', async () => {
	const content = { count: 4, taxonomies: { genre: 2 } };
	mockApi({
		'GET admin/schema-files/type/product': fileResult(),
		'DELETE admin/schema-files/type/product': () => {
			throw { code: 'proto_blocks_confirm_required', message: 'Type the slug.', data: { status: 409, content } };
		},
		'DELETE admin/schema-files/type/product?confirm=product': { deleted: true, kept: ['wp-content/themes/x/proto-types/product/fields.php'] },
	});
	const { result } = await loaded();

	await expect(result.current.remove()).resolves.toEqual({ status: 'confirm', content });
	await expect(result.current.remove('product')).resolves.toEqual({
		status: 'deleted',
		kept: ['wp-content/themes/x/proto-types/product/fields.php'],
	});
});

test('reload fetches the file again', async () => {
	let hash = 'h1';
	mockApi({ 'GET admin/schema-files/taxonomy/genre': () => fileResult({ hash }) });
	const { result } = await loaded('taxonomy', 'genre');
	hash = 'h9';

	await act(async () => {
		await result.current.reload();
	});
	expect(result.current.file?.hash).toBe('h9');
});
