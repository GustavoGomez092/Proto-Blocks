import { mockApi, mockBootstrap } from './helpers';
import apiFetch from '@wordpress/api-fetch';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';

const fetchMock = apiFetch as unknown as jest.Mock & Record<string, jest.Mock>;

beforeEach(() => {
	mockBootstrap({ restRoot: 'https://site.test/wp-json/proto-blocks/v1/', restNonce: 'n-123' });
});

test('adminGet installs root and nonce middleware once, then calls apiFetch', async () => {
	mockApi({ 'admin/system': { ok: true } });

	await expect(adminGet('admin/system')).resolves.toEqual({ ok: true });
	await adminGet('admin/system');

	expect(fetchMock.createRootURLMiddleware).toHaveBeenCalledWith('https://site.test/wp-json/proto-blocks/v1/');
	expect(fetchMock.createNonceMiddleware).toHaveBeenCalledWith('n-123');
	expect(fetchMock.use).toHaveBeenCalledWith({ middleware: 'root', root: 'https://site.test/wp-json/proto-blocks/v1/' });
	expect(fetchMock.use).toHaveBeenCalledWith({ middleware: 'nonce', nonce: 'n-123' });
	expect(fetchMock.use).toHaveBeenCalledTimes(2);
	expect(fetchMock).toHaveBeenCalledWith({ path: 'admin/system', method: 'GET' });
});

test('adminPost sends the body as data and adminDelete uses DELETE', async () => {
	const post = jest.fn((body: unknown) => ({ received: body }));
	mockApi({ 'POST admin/settings': post, 'DELETE admin/cache': { deleted: true } });

	await expect(adminPost('admin/settings', { a: 1 })).resolves.toEqual({ received: { a: 1 } });
	await expect(adminDelete('admin/cache')).resolves.toEqual({ deleted: true });
	expect(fetchMock).toHaveBeenCalledWith({ path: 'admin/settings', method: 'POST', data: { a: 1 } });
	expect(fetchMock).toHaveBeenCalledWith({ path: 'admin/cache', method: 'DELETE' });
});

test('a REST error becomes an ApiError with code, message and status', async () => {
	mockApi({
		'POST admin/demos': () => {
			throw { code: 'proto_blocks_file_mods', message: 'File modifications are disabled.', data: { status: 403 } };
		},
	});

	const error = await adminPost('admin/demos').catch((e: unknown) => e);

	expect(error).toBeInstanceOf(ApiError);
	expect(error).toMatchObject({ code: 'proto_blocks_file_mods', message: 'File modifications are disabled.', status: 403 });
});

test('a failure without REST data still becomes an ApiError', async () => {
	fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));

	const error = await adminGet('admin/system').catch((e: unknown) => e);

	expect(error).toBeInstanceOf(ApiError);
	expect(error).toMatchObject({ code: 'unknown_error', message: 'Failed to fetch', status: 0 });
});
