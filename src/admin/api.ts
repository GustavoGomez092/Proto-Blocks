/**
 * REST client for the admin. Paths are relative to the bootstrap restRoot
 * (e.g. 'admin/system').
 *
 * Every request carries a full `url` and its own nonce header rather than a
 * `path` plus global middleware: WordPress core registers a root-URL
 * middleware on wp.apiFetch that rewrites any `path` against /wp-json/, and
 * it runs after middleware added here, so a `path` would lose the
 * proto-blocks/v1 namespace.
 */
import apiFetch from '@wordpress/api-fetch';
import { getBootstrap } from './bootstrap';

export class ApiError extends Error {
	code: string;
	status: number;
	/** The WP_Error data object without `status`; {} when the error had none. */
	data: Record<string, unknown>;

	constructor(code: string, message: string, status: number, data: Record<string, unknown> = {}) {
		super(message);
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.data = data;
	}
}

/**
 * `path` under `root` (default: the bootstrap restRoot). Handles the
 * plain-permalink root (`?rest_route=/proto-blocks/v1/`), where the path's
 * own query string must continue with `&`.
 */
export function restUrl(path: string, root: string = getBootstrap().restRoot): string {
	let relative = path.replace(/^\//, '');
	if (root.includes('?')) {
		relative = relative.replace('?', '&');
	}
	return root + relative;
}

function toApiError(error: unknown): ApiError {
	if (error instanceof ApiError) {
		return error;
	}
	const e = (error ?? {}) as { code?: unknown; message?: unknown; data?: { status?: unknown } | null };
	const code = typeof e.code === 'string' && e.code !== '' ? e.code : 'unknown_error';
	const message = typeof e.message === 'string' ? e.message : String(error);
	const status = typeof e.data?.status === 'number' ? e.data.status : 0;
	const { status: _status, ...data } = e.data !== null && typeof e.data === 'object' && !Array.isArray(e.data) ? e.data : {};
	return new ApiError(code, message, status, data);
}

async function request<T>({ path, ...options }: { path: string; method: string; data?: unknown }): Promise<T> {
	const { restRoot, restNonce } = getBootstrap();
	try {
		return await apiFetch<T>({
			url: restUrl(path, restRoot),
			...options,
			headers: { 'X-WP-Nonce': restNonce },
		});
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
