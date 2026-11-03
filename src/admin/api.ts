/**
 * REST client for the admin. Paths are relative to the bootstrap restRoot
 * (e.g. 'admin/system'); the root and nonce middleware are installed on
 * first use.
 */
import apiFetch from '@wordpress/api-fetch';
import { getBootstrap } from './bootstrap';

export class ApiError extends Error {
	code: string;
	status: number;

	constructor(code: string, message: string, status: number) {
		super(message);
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
	}
}

let installed = false;

function ensureMiddleware(): void {
	if (installed) {
		return;
	}
	const { restRoot, restNonce } = getBootstrap();
	apiFetch.use(apiFetch.createRootURLMiddleware(restRoot));
	apiFetch.use(apiFetch.createNonceMiddleware(restNonce));
	installed = true;
}

function toApiError(error: unknown): ApiError {
	if (error instanceof ApiError) {
		return error;
	}
	const e = (error ?? {}) as { code?: unknown; message?: unknown; data?: { status?: unknown } | null };
	const code = typeof e.code === 'string' && e.code !== '' ? e.code : 'unknown_error';
	const message = typeof e.message === 'string' ? e.message : String(error);
	const status = typeof e.data?.status === 'number' ? e.data.status : 0;
	return new ApiError(code, message, status);
}

async function request<T>(options: { path: string; method: string; data?: unknown }): Promise<T> {
	ensureMiddleware();
	try {
		return await apiFetch<T>(options);
	} catch (error) {
		throw toApiError(error);
	}
}

export function adminGet<T>(path: string): Promise<T> {
	return request<T>({ path, method: 'GET' });
}

export function adminPost<T>(path: string, body?: unknown): Promise<T> {
	return request<T>(body === undefined ? { path, method: 'POST' } : { path, method: 'POST', data: body });
}

export function adminDelete<T>(path: string): Promise<T> {
	return request<T>({ path, method: 'DELETE' });
}
