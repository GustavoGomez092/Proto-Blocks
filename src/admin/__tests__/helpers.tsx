/**
 * Test helpers for the React admin.
 *
 * Importing this module replaces `@wordpress/api-fetch` for the importing test
 * file (jest.mock below is hoisted to the top of this module). Import it
 * BEFORE anything that imports `@/admin/api`, so the API module binds to the
 * mock rather than the real package.
 */
import apiFetch from '@wordpress/api-fetch';
import type { AdminBootstrap } from '@/admin/bootstrap';

jest.mock('@wordpress/api-fetch', () => {
	const fn = jest.fn() as jest.Mock & Record<string, jest.Mock>;
	fn.use = jest.fn();
	fn.createRootURLMiddleware = jest.fn((root: string) => ({ middleware: 'root', root }));
	fn.createNonceMiddleware = jest.fn((nonce: string) => ({ middleware: 'nonce', nonce }));
	return { __esModule: true, default: fn };
});

/** Sets window.protoBlocksAdmin and returns it. */
export function mockBootstrap(overrides: Partial<AdminBootstrap> = {}): AdminBootstrap {
	const bootstrap: AdminBootstrap = {
		screen: 'system',
		restRoot: 'https://example.test/wp-json/proto-blocks/v1/',
		restNonce: 'nonce-wp_rest',
		adminUrl: 'https://example.test/wp-admin/admin.php',
		version: '9.9.9',
		fileModsAllowed: true,
		fileModsConstant: null,
		assetBase: 'https://example.test/wp-content/plugins/proto-blocks/assets/js/',
		tailwind: null,
		...overrides,
	};
	window.protoBlocksAdmin = bootstrap;
	return bootstrap;
}

type Route = unknown | ((body: unknown) => unknown);

/**
 * Routes apiFetch calls to canned responses. Keys are either a path relative
 * to restRoot ('admin/system') or 'METHOD path' ('POST admin/settings'). A
 * function value receives the request body. A value or function that throws
 * an object like { code, message, data: { status } } rejects the request, the
 * way apiFetch does for a REST error. Unknown routes reject with rest_no_route.
 */
export function mockApi(routes: Record<string, Route>): jest.Mock {
	const mock = apiFetch as unknown as jest.Mock;
	mock.mockImplementation(async (options: { path?: string; method?: string; data?: unknown }) => {
		const path = options.path ?? '';
		const method = (options.method ?? 'GET').toUpperCase();
		const key = `${method} ${path}` in routes ? `${method} ${path}` : path;
		if (!(key in routes)) {
			throw { code: 'rest_no_route', message: `No route for ${method} ${path}`, data: { status: 404 } };
		}
		const route = routes[key];
		return typeof route === 'function' ? (route as (body: unknown) => unknown)(options.data) : route;
	});
	return mock;
}
