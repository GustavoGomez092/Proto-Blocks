import { mockApi, mockBootstrap } from './helpers';
import apiFetch from '@wordpress/api-fetch';
import { adminDelete, adminGet, adminPost, ApiError, restUrl } from '@/admin/api';

const fetchMock = apiFetch as unknown as jest.Mock & Record<string, jest.Mock>;

beforeEach(() => {
	mockBootstrap({ restRoot: 'https://site.test/wp-json/proto-blocks/v1/', restNonce: 'n-123' });
});

test('adminGet requests the full URL under restRoot with the REST nonce, without global middleware', async () => {
	mockApi({ 'admin/system': { ok: true } });

	await expect(adminGet('admin/system')).resolves.toEqual({ ok: true });

	// WordPress core already registers a root middleware on wp.apiFetch that
	// rewrites any `path` against /wp-json/, so requests must carry a url.
	expect(fetchMock.use).not.toHaveBeenCalled();
	expect(fetchMock).toHaveBeenCalledWith({
		url: 'https://site.test/wp-json/proto-blocks/v1/admin/system',
		method: 'GET',
		headers: { 'X-WP-Nonce': 'n-123' },
	});
});

test('restUrl joins paths onto a plain-permalink root', () => {
	expect(restUrl('admin/x', 'https://site.test/?rest_route=/proto-blocks/v1/')).toBe(
		'https://site.test/?rest_route=/proto-blocks/v1/admin/x'
	);
	expect(restUrl('/admin/x?a=1', 'https://site.test/?rest_route=/proto-blocks/v1/')).toBe(
		'https://site.test/?rest_route=/proto-blocks/v1/admin/x&a=1'
	);
	expect(restUrl('admin/x?a=1', 'https://site.test/wp-json/proto-blocks/v1/')).toBe(
		'https://site.test/wp-json/proto-blocks/v1/admin/x?a=1'
	);
});

test('adminPost sends the body as data and adminDelete uses DELETE', async () => {
	const post = jest.fn((body: unknown) => ({ received: body }));
	mockApi({ 'POST admin/settings': post, 'DELETE admin/cache': { deleted: true } });

	await expect(adminPost('admin/settings', { a: 1 })).resolves.toEqual({ received: { a: 1 } });
	await expect(adminDelete('admin/cache')).resolves.toEqual({ deleted: true });
	expect(fetchMock).toHaveBeenCalledWith(
		expect.objectContaining({ url: 'https://site.test/wp-json/proto-blocks/v1/admin/settings', method: 'POST', data: { a: 1 } })
	);
	expect(fetchMock).toHaveBeenCalledWith(
		expect.objectContaining({ url: 'https://site.test/wp-json/proto-blocks/v1/admin/cache', method: 'DELETE' })
	);
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
