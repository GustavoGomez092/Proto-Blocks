import '../helpers';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readRules } from '@/admin/redirects/edits';
import { calls, choose, index, renderScreen, routes, setup, writes } from './fixtures';

setup();

const grouped = {
	rows: [
		{ url: '/missing', count: 3, lastSeen: '2026-11-04 09:00:00', lastReferrer: 'https://google.com/' },
		{ url: '/wp-login.bak', count: 40, lastSeen: '2026-11-04 08:00:00', lastReferrer: null },
	],
	total: 2,
};
// `?url=` is the server's exact filter: only /missing comes back.
const hits = {
	rows: [
		{ id: 11, created: '2026-11-04 09:00:00', url: '/missing', method: 'GET', ip: null, agent: 'Firefox', referrer: 'https://google.com/' },
		{ id: 12, created: '2026-11-03 09:00:00', url: '/missing', method: 'HEAD', ip: null, agent: 'curl', referrer: null },
	],
	total: 2,
	page: 1,
	perPage: 10,
	pages: 1,
	groupBy: null,
};

function notFoundRoutes(extra = {}) {
	return routes({
		'admin/redirects/404?group_by=url&page=1&per_page=50': { ...grouped, page: 1, perPage: 50, pages: 1, groupBy: 'url' },
		'admin/redirects/404?url=%2Fmissing&per_page=10': hits,
		'DELETE admin/redirects/404': { deleted: 2 },
		...extra,
	});
}

const fileWrites = () => writes().filter((c) => c.url.startsWith('admin/schema-files/'));

describe('404s', () => {
	test('Add redirect: Source prefilled, Target empty; after save the URL’s 404 rows are deleted', async () => {
		notFoundRoutes();
		const user = userEvent.setup();
		renderScreen('&tab=404s');
		await user.click(await screen.findByRole('button', { name: 'Add redirect for /missing' }));
		const dialog = await screen.findByRole('dialog', { name: 'Add redirect for this 404' });
		expect((within(dialog).getByRole('textbox', { name: 'Source' }) as HTMLInputElement).value).toBe('/missing');
		expect((within(dialog).getByRole('textbox', { name: 'Target' }) as HTMLInputElement).value).toBe('');
		expect(within(dialog).getByRole('combobox', { name: 'Group' }).textContent).toBe('Legacy site');
		await user.type(within(dialog).getByRole('textbox', { name: 'Target' }), '/found');
		await user.click(within(dialog).getByRole('button', { name: 'Save redirect' }));

		await waitFor(() => expect(calls().some((c) => c.method === 'DELETE')).toBe(true));
		const order = writes().map((c) => `${c.method} ${c.url}`);
		expect(order).toEqual(['POST admin/schema-files/redirects/legacy-site', 'DELETE admin/redirects/404?url=%2Fmissing']);
		expect(await screen.findByText('Deleted 2 404 entries.')).toBeTruthy();
		expect(readRules(fileWrites()[0].data?.text ?? '').slice(-1)[0]).toMatchObject({ from: '/missing', to: '/found' });
		await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
	});

	test('a failed save deletes nothing', async () => {
		notFoundRoutes({
			'POST admin/schema-files/redirects/legacy-site': () => {
				throw { code: 'proto_blocks_schema_invalid', message: 'Bad rule', data: { status: 400 } };
			},
		});
		const user = userEvent.setup();
		renderScreen('&tab=404s');
		await user.click(await screen.findByRole('button', { name: 'Add redirect for /missing' }));
		const dialog = await screen.findByRole('dialog', { name: 'Add redirect for this 404' });
		await user.type(within(dialog).getByRole('textbox', { name: 'Target' }), '/found');
		await user.click(within(dialog).getByRole('button', { name: 'Save redirect' }));
		expect(await screen.findByText('Bad rule')).toBeTruthy();
		expect(calls().some((c) => c.method === 'DELETE')).toBe(false);
	});

	test('Ignore adds an action: nothing rule; with no writable group it creates one', async () => {
		notFoundRoutes();
		const user = userEvent.setup();
		renderScreen('&tab=404s');
		await user.click(await screen.findByRole('button', { name: 'Ignore /wp-login.bak' }));
		await waitFor(() => expect(fileWrites()).toHaveLength(1));
		expect(readRules(fileWrites()[0].data?.text ?? '').slice(-1)[0]).toEqual({ id: expect.stringMatching(/^r_[a-f0-9]{8}$/), from: '/wp-login.bak', action: 'nothing' });

		document.body.innerHTML = '';
		jestClear();
		notFoundRoutes({
			'admin/redirects': index({ groups: [] }),
			'admin/schema-files/redirects/redirects': { exists: false, path: 'p', text: '', hash: null, data: {}, writable: true, reason: null },
			'POST admin/schema-files/redirects/redirects': { path: 'p', hash: 'h', created: true, warnings: [] },
		});
		renderScreen('&tab=404s');
		await user.click(await screen.findByRole('button', { name: 'Ignore /missing' }));
		await waitFor(() => expect(fileWrites()).toHaveLength(1));
		expect(fileWrites()[0]).toMatchObject({ url: 'admin/schema-files/redirects/redirects', data: { hash: null } });
	});

	test('expand shows the recent hits; delete group and clear all', async () => {
		notFoundRoutes({ 'DELETE admin/redirects/404?all=1': { deleted: 43 } });
		const user = userEvent.setup();
		renderScreen('&tab=404s');
		await user.click(await screen.findByRole('button', { name: 'Show hits of /missing' }));
		const recent = await screen.findByRole('table', { name: 'Recent hits of /missing' });
		expect(within(recent).getByText('Firefox')).toBeTruthy();
		expect(within(recent).queryByText('GET', { selector: 'td' })).toBeTruthy();
		expect(within(recent).getAllByRole('row')).toHaveLength(3);

		await user.click(screen.getByRole('button', { name: 'Delete 404s of /missing' }));
		await user.click(await screen.findByRole('button', { name: 'Delete' }));
		await waitFor(() => expect(calls().some((c) => c.method === 'DELETE' && c.url === 'admin/redirects/404?url=%2Fmissing')).toBe(true));
		// Never the "contains" search: /missing-page keeps its rows.
		expect(calls().some((c) => c.url.includes('search=%2Fmissing'))).toBe(false);

		await user.click(screen.getByRole('button', { name: 'Clear all' }));
		await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Clear all' }));
		await waitFor(() => expect(calls().some((c) => c.method === 'DELETE' && c.url === 'admin/redirects/404?all=1')).toBe(true));
	});

	test('export CSV downloads with the nonce', async () => {
		notFoundRoutes();
		const fetchMock = jest.fn(async () => ({
			ok: true,
			status: 200,
			blob: async () => new Blob(['url,count\n']),
			headers: { get: (name: string) => (name === 'Content-Disposition' ? 'attachment; filename="404s-2026-11-05.csv"' : null) },
		}));
		const original = { fetch: window.fetch, create: URL.createObjectURL, revoke: URL.revokeObjectURL };
		window.fetch = fetchMock as unknown as typeof fetch;
		URL.createObjectURL = jest.fn(() => 'blob:x');
		URL.revokeObjectURL = jest.fn();
		const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
		try {
			const user = userEvent.setup();
			renderScreen('&tab=404s');
			await screen.findByRole('table', { name: '404s by URL' });
			await user.click(screen.getByRole('button', { name: 'Export CSV' }));
			await waitFor(() => expect(click).toHaveBeenCalled());
			expect(fetchMock).toHaveBeenCalledWith('https://example.test/wp-json/proto-blocks/v1/admin/redirects/404/export?format=csv', { headers: { 'X-WP-Nonce': 'nonce-wp_rest' }, credentials: 'same-origin' });
			const link = click.mock.contexts[0] as HTMLAnchorElement;
			expect(link.download).toBe('404s-2026-11-05.csv');
		} finally {
			click.mockRestore();
			window.fetch = original.fetch;
			URL.createObjectURL = original.create;
			URL.revokeObjectURL = original.revoke;
		}
	});
});

describe('log', () => {
	const logRows = {
		rows: [
			{ id: 1, created: '2026-11-04 10:00:00', url: '/about-us', target: '/about', code: 301, group: 'legacy-site', ruleId: 'r_00000001', redirectBy: 'proto-blocks', method: 'GET', ip: '10.0.0.0', agent: 'Firefox', referrer: null },
			{ id: 2, created: '2026-11-04 11:00:00', url: '/x', target: '/y', code: 302, group: null, ruleId: null, redirectBy: 'wordpress', method: 'GET', ip: null, agent: null, referrer: null },
		],
		total: 2,
	};

	test('filters go to the query; delete selected and clear all send the right DELETE', async () => {
		routes({ 'admin/redirects/log': logRows, 'DELETE admin/redirects/log': { deleted: 1 } });
		const user = userEvent.setup();
		renderScreen('&tab=log');
		const table = await screen.findByRole('table', { name: 'Redirect log' });
		expect(within(table).getByText('wordpress')).toBeTruthy();
		await user.type(screen.getByRole('textbox', { name: 'Code' }), '301');
		await waitFor(() => expect(calls().some((c) => c.url === 'admin/redirects/log?page=1&per_page=50&code=301')).toBe(true));

		await user.click(within(table).getAllByRole('checkbox')[1]);
		await user.click(screen.getByRole('button', { name: 'Delete selected' }));
		await user.click(await screen.findByRole('button', { name: 'Delete' }));
		await waitFor(() => expect(calls().some((c) => c.method === 'DELETE' && c.url === 'admin/redirects/log?ids[]=1')).toBe(true));

		await user.click(screen.getByRole('button', { name: 'Clear all' }));
		const confirm = await screen.findByRole('alertdialog');
		await user.click(within(confirm).getByRole('button', { name: 'Clear all' }));
		await waitFor(() => expect(calls().some((c) => c.method === 'DELETE' && c.url === 'admin/redirects/log?all=1&code=301')).toBe(true));
	});
});

describe('log export', () => {
	test('exports the current filters with the nonce; the server file name is used; a refusal toasts', async () => {
		routes({ 'admin/redirects/log': { rows: [], total: 0, page: 1, perPage: 50, pages: 0 } });
		const fetchMock = jest
			.fn()
			.mockResolvedValueOnce({ ok: true, status: 200, blob: async () => new Blob(['id\n']), headers: { get: (name: string) => (name === 'Content-Disposition' ? 'attachment; filename="proto-redirect-log-2026-11-05.csv"' : null) } })
			.mockResolvedValueOnce({ ok: false, status: 403, json: async () => ({ code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.' }) });
		const original = { fetch: window.fetch, create: URL.createObjectURL, revoke: URL.revokeObjectURL };
		window.fetch = fetchMock as unknown as typeof fetch;
		URL.createObjectURL = jest.fn(() => 'blob:x');
		URL.revokeObjectURL = jest.fn();
		const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
		try {
			const user = userEvent.setup();
			renderScreen('&tab=log');
			expect(await screen.findByText('No redirects logged.')).toBeTruthy();
			await user.type(screen.getByRole('textbox', { name: 'Code' }), '302');
			await user.click(screen.getByRole('button', { name: 'Export CSV' }));
			await waitFor(() => expect(click).toHaveBeenCalledTimes(1));
			expect(fetchMock).toHaveBeenNthCalledWith(1, 'https://example.test/wp-json/proto-blocks/v1/admin/redirects/log/export?format=csv&code=302', { headers: { 'X-WP-Nonce': 'nonce-wp_rest' }, credentials: 'same-origin' });
			expect((click.mock.contexts[0] as HTMLAnchorElement).download).toBe('proto-redirect-log-2026-11-05.csv');

			await user.click(screen.getByRole('button', { name: 'Export CSV' }));
			expect((await screen.findAllByText('Sorry, you are not allowed to do that.')).length).toBeGreaterThan(0);
			expect(click).toHaveBeenCalledTimes(1);
		} finally {
			click.mockRestore();
			window.fetch = original.fetch;
			URL.createObjectURL = original.create;
			URL.revokeObjectURL = original.revoke;
		}
	});

	test('a failed 404 export toasts the server message', async () => {
		routes({ 'admin/redirects/404': { rows: [{ url: '/missing', count: 1, lastSeen: '2026-11-04 09:00:00', lastReferrer: null }], total: 1 } });
		const original = window.fetch;
		window.fetch = jest.fn(async () => ({ ok: false, status: 400, json: async () => ({ code: 'proto_blocks_invalid_format', message: 'Logs export as CSV only.' }) })) as unknown as typeof fetch;
		try {
			const user = userEvent.setup();
			renderScreen('&tab=404s');
			await screen.findByRole('table', { name: '404s by URL' });
			await user.click(screen.getByRole('button', { name: 'Export CSV' }));
			expect((await screen.findAllByText('Logs export as CSV only.')).length).toBeGreaterThan(0);
		} finally {
			window.fetch = original;
		}
	});
});

function jestClear() {
	(jest.requireMock('@wordpress/api-fetch') as { default: jest.Mock }).default.mockClear();
}

void choose;
