import { mockBootstrap } from '../helpers';
import apiFetch from '@wordpress/api-fetch';
import { adminPost, ApiError } from '@/admin/api';

const fetchMock = apiFetch as unknown as jest.Mock;

beforeEach(() => {
	mockBootstrap();
});

test('a REST error keeps its data, without status', async () => {
	fetchMock.mockRejectedValueOnce({
		code: 'proto_blocks_needs_confirmation',
		message: 'm',
		data: { status: 409, guardrails: [{ code: 'supports_removed' }] },
	});

	const error = await adminPost('admin/schema-files/type/product', {}).catch((e) => e);

	expect(error).toBeInstanceOf(ApiError);
	expect(error.status).toBe(409);
	expect(error.code).toBe('proto_blocks_needs_confirmation');
	expect(error.data.guardrails).toHaveLength(1);
	expect(error.data).not.toHaveProperty('status');
});

test('a REST error without data has empty data', async () => {
	fetchMock.mockRejectedValueOnce({ code: 'x', message: 'm' });

	const error = await adminPost('admin/schema-files/type/product', {}).catch((e) => e);

	expect(error).toBeInstanceOf(ApiError);
	expect(error.data).toEqual({});
});
