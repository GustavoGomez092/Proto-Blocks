import '../helpers';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { readRules } from '@/admin/redirects/edits';
import { filenameFrom } from '@/admin/redirects/api';
import { detectFormat, mergeRules, sameSource } from '@/admin/redirects/import';
import { calls, choose, renderScreen, routes, setup, writes } from './fixtures';

setup();

const fileWrites = () => writes().filter((c) => c.url.startsWith('admin/schema-files/'));

const parsed = {
	rules: [
		{ rule: { from: '/spring', to: '/summer', code: 302 }, status: 'duplicate', reason: 'duplicate', line: 2, message: 'Source exists in promo.' },
		{ rule: { from: '/new', to: '/n' }, status: 'new', reason: null, line: 3, message: null },
		{ rule: { from: 'bad', to: '/x' }, status: 'invalid', reason: 'invalid_source', line: 4, message: 'Source must start with /.' },
	],
	format: 'csv',
};

async function upload(user: ReturnType<typeof userEvent.setup>, name = 'redirects.csv') {
	const input = (await screen.findByLabelText('Redirect file')) as HTMLInputElement;
	await user.upload(input, new File(['source,target\n/spring,/summer\n'], name, { type: 'text/csv' }));
	await user.click(screen.getAllByRole('button', { name: 'Preview' })[0]);
}

describe('file import', () => {
	test('preview shows each row’s status; replace mode merges into the chosen group', async () => {
		let body: unknown = null;
		routes({
			'admin/redirects/import/plugins': { importers: [] },
			'POST admin/redirects/import/parse': (data: unknown) => {
				body = data;
				return parsed;
			},
		});
		const user = userEvent.setup();
		renderScreen('&tab=import');
		await upload(user);
		const table = await screen.findByRole('table', { name: 'Import preview' });
		expect(body).toEqual({ format: 'csv', text: 'source,target\n/spring,/summer\n', filename: 'redirects.csv' });
		const rows = within(table).getAllByRole('row').slice(1);
		expect(within(rows[0]).getByText('Duplicate')).toBeTruthy();
		expect(within(rows[0]).getByText('Source exists in promo.')).toBeTruthy();
		expect(within(rows[1]).getByText('New')).toBeTruthy();
		expect(within(rows[2]).getByText('Invalid')).toBeTruthy();
		expect(within(rows[2]).getByText('Source must start with /.')).toBeTruthy();
		// The readable message is shown, not the reason code.
		expect(within(table).queryByText('invalid_source')).toBeNull();
		expect(screen.getByText('1 new, 1 duplicate, 1 invalid.')).toBeTruthy();

		await choose(user, 'Import into group', 'Promo');
		await user.click(screen.getByRole('radio', { name: 'Replace the existing rule' }));
		await user.click(screen.getByRole('button', { name: 'Import 2 rules' }));
		await waitFor(() => expect(fileWrites()).toHaveLength(1));
		const [post] = fileWrites();
		expect(post).toMatchObject({ url: 'admin/schema-files/redirects/promo', data: { hash: 'h-promo' } });
		expect(readRules(post.data?.text ?? '')).toEqual([
			{ id: 'r_0000000p', from: '/spring', to: '/summer', code: 302 },
			{ id: expect.stringMatching(/^r_[a-f0-9]{8}$/), from: '/new', to: '/n' },
		]);
		expect(post.data?.text).toContain('// Spring sale.');
		expect(await screen.findByText('Imported: 1 added, 1 replaced, 0 skipped.')).toBeTruthy();
	});

	test('skip is the default; a new group is created with hash null', async () => {
		routes({
			'admin/redirects/import/plugins': { importers: [] },
			'POST admin/redirects/import/parse': parsed,
			'admin/schema-files/redirects/imported': { exists: false, path: 'p', text: '', hash: null, data: {}, writable: true, reason: null },
			'POST admin/schema-files/redirects/imported': { path: 'p', hash: 'h', created: true, warnings: [] },
		});
		const user = userEvent.setup();
		renderScreen('&tab=import');
		await upload(user, '_redirects');
		await screen.findByRole('table', { name: 'Import preview' });
		expect((screen.getByRole('radio', { name: 'Skip rules whose source already exists' }) as HTMLButtonElement).getAttribute('aria-checked')).toBe('true');
		await choose(user, 'Import into group', 'New group…');
		expect((screen.getByRole('button', { name: 'Import 2 rules' }) as HTMLButtonElement).disabled).toBe(true);
		await user.type(screen.getByRole('textbox', { name: 'New group name' }), 'Imported');
		await user.click(screen.getByRole('button', { name: 'Import 2 rules' }));
		await waitFor(() => expect(fileWrites()).toHaveLength(1));
		const [post] = fileWrites();
		expect(post.data?.hash).toBeNull();
		expect(parseJsonc(post.data?.text ?? '').data).toMatchObject({ title: 'Imported', enabled: true });
		expect(readRules(post.data?.text ?? '').map((r) => r.from)).toEqual(['/spring', '/new']);
		const parse = calls().find((c) => c.url === 'admin/redirects/import/parse');
		expect(parse?.data).toMatchObject({ format: 'redirects', filename: '_redirects' });
	});

	test('read-only: Import is disabled', async () => {
		routes({ 'admin/redirects/import/plugins': { importers: [] }, 'POST admin/redirects/import/parse': parsed });
		const user = userEvent.setup();
		renderScreen('&tab=import', { fileModsAllowed: false });
		await upload(user);
		await screen.findByRole('table', { name: 'Import preview' });
		const button = screen.getByRole('button', { name: 'Import 2 rules' }) as HTMLButtonElement;
		expect(button.disabled).toBe(true);
		expect(button.getAttribute('aria-describedby')).toBe('proto-blocks-read-only');
	});
});

describe('plugin import', () => {
	test('lists available importers, previews, writes one file per group, then deactivates the plugin', async () => {
		routes({
			'admin/redirects/import/plugins': {
				importers: [{ id: 'redirection', label: 'Redirection', cleanupHint: 'Delete the Redirection tables afterwards.', plugin: 'redirection/redirection.php', plugins: ['redirection/redirection.php'], active: ['redirection/redirection.php'], canDeactivate: true }],
			},
			'POST admin/redirects/import/plugins/redirection/preview': {
				id: 'redirection',
				label: 'Redirection',
				cleanupHint: 'Delete the Redirection tables afterwards.',
				skipped: [{ rule: { from: '/x', to: '/y' }, status: 'invalid', reason: 'unsupported_action', line: 7, message: 'The action has no Proto-Blocks equivalent.' }],
				groups: {
					'redirections': { title: 'Redirections', enabled: false, position: 3, rules: [{ from: '/r1', to: '/t1' }] },
					'legacy-site': { title: 'Legacy site', rules: [{ from: '/about-us', to: '/elsewhere' }, { from: '/r2', to: '/t2', code: 307 }] },
				},
			},
			'admin/schema-files/redirects/redirections': { exists: false, path: 'p', text: '', hash: null, data: {}, writable: true, reason: null },
			'POST admin/schema-files/redirects/redirections': { path: 'p', hash: 'h', created: true, warnings: [] },
			'POST admin/redirects/import/deactivate': { deactivated: ['redirection/redirection.php'], skipped: [] },
		});
		const user = userEvent.setup();
		renderScreen('&tab=import');
		await user.click(await screen.findByRole('button', { name: 'Preview Redirection' }));
		expect(await screen.findByText('Redirection: 3 rules in 2 groups')).toBeTruthy();
		expect(screen.getByText('Delete the Redirection tables afterwards.')).toBeTruthy();
		expect(screen.getByText('merges into existing group')).toBeTruthy();
		const skipped = screen.getByRole('table', { name: 'Rows of Redirection that are skipped' });
		expect(within(skipped).getByText('The action has no Proto-Blocks equivalent.')).toBeTruthy();
		expect(within(skipped).getByText('Invalid')).toBeTruthy();
		expect(screen.getByText('1 row cannot be imported:')).toBeTruthy();
		await user.click(screen.getByRole('checkbox', { name: 'Deactivate Redirection after importing' }));
		await user.click(screen.getByRole('button', { name: 'Import 3 rules' }));
		await waitFor(() => expect(calls().some((c) => c.url === 'admin/redirects/import/deactivate')).toBe(true));
		const order = writes().map((c) => `${c.method} ${c.url}`);
		expect(order).toEqual(['POST admin/redirects/import/plugins/redirection/preview', 'POST admin/schema-files/redirects/redirections', 'POST admin/schema-files/redirects/legacy-site', 'POST admin/redirects/import/deactivate']);
		const [created, merged] = fileWrites();
		expect(created.data?.hash).toBeNull();
		expect(parseJsonc(created.data?.text ?? '').data).toMatchObject({ title: 'Redirections', enabled: false, position: 3 });
		// Skip mode: /about-us exists in legacy-site, so only /r2 is added.
		expect(readRules(merged.data?.text ?? '').map((r) => r.from).slice(-2)).toEqual(['/gone-forever', '/r2']);
		expect(calls().find((c) => c.url === 'admin/redirects/import/deactivate')?.data).toEqual({ id: 'redirection' });
		expect(await screen.findByText('Redirection deactivated.')).toBeTruthy();
	});

	test('no deactivate option without activate_plugins or when the plugin is already inactive', async () => {
		const importer = { id: 'redirection', label: 'Redirection', cleanupHint: null, plugin: 'redirection/redirection.php', plugins: ['redirection/redirection.php'] };
		for (const extra of [{ active: ['redirection/redirection.php'], canDeactivate: false }, { active: [], canDeactivate: true }]) {
			document.body.innerHTML = '';
			routes({
				'admin/redirects/import/plugins': { importers: [{ ...importer, ...extra }] },
				'POST admin/redirects/import/plugins/redirection/preview': { groups: { r: { title: 'R', rules: [{ from: '/a', to: '/b' }] } }, skipped: [] },
			});
			const user = userEvent.setup();
			renderScreen('&tab=import');
			await user.click(await screen.findByRole('button', { name: 'Preview Redirection' }));
			expect(await screen.findByText('Redirection: 1 rules in 1 groups')).toBeTruthy();
			expect(screen.queryByRole('checkbox', { name: 'Deactivate Redirection after importing' })).toBeNull();
			expect(screen.queryByText(/cannot be imported/)).toBeNull();
		}
	});

	test('nothing to import', async () => {
		routes({ 'admin/redirects/import/plugins': { importers: [] } });
		renderScreen('&tab=import');
		expect(await screen.findByText('No other redirect plugin data found.')).toBeTruthy();
	});
});

describe('export', () => {
	test('downloads the chosen group and format with the nonce', async () => {
		routes({ 'admin/redirects/import/plugins': { importers: [] } });
		const fetchMock = jest.fn(async () => ({ ok: true, status: 200, blob: async () => new Blob(['{}']), headers: { get: () => null } }));
		const original = { fetch: window.fetch, create: URL.createObjectURL, revoke: URL.revokeObjectURL };
		window.fetch = fetchMock as unknown as typeof fetch;
		URL.createObjectURL = jest.fn(() => 'blob:x');
		URL.revokeObjectURL = jest.fn();
		const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
		try {
			const user = userEvent.setup();
			renderScreen('&tab=import');
			await screen.findByRole('heading', { name: 'Export' });
			await choose(user, 'Group to export', 'Promo');
			await choose(user, 'Format', 'JSON');
			await user.click(screen.getByRole('button', { name: 'Download' }));
			await waitFor(() => expect(click).toHaveBeenCalled());
			expect(fetchMock).toHaveBeenCalledWith('https://example.test/wp-json/proto-blocks/v1/admin/redirects/export?format=json&group=promo', { headers: { 'X-WP-Nonce': 'nonce-wp_rest' }, credentials: 'same-origin' });
			expect((click.mock.contexts[0] as HTMLAnchorElement).download).toBe('redirects.json');
			expect(await screen.findByText('Downloaded redirects.json.')).toBeTruthy();
		} finally {
			click.mockRestore();
			window.fetch = original.fetch;
			URL.createObjectURL = original.create;
			URL.revokeObjectURL = original.revoke;
		}
	});

	test('a failed export shows the server message', async () => {
		routes({ 'admin/redirects/import/plugins': { importers: [] } });
		const original = window.fetch;
		window.fetch = jest.fn(async () => ({ ok: false, status: 403, json: async () => ({ code: 'rest_forbidden', message: 'Not allowed.' }) })) as unknown as typeof fetch;
		try {
			const user = userEvent.setup();
			renderScreen('&tab=import');
			await user.click(await screen.findByRole('button', { name: 'Download' }));
			expect(await screen.findByText('Not allowed.')).toBeTruthy();
		} finally {
			window.fetch = original;
		}
	});
});

/** Replaces fetch and the Blob link for one test; returns the fetch mock and the clicked links. */
function stubDownload(response: unknown) {
	const fetchMock = jest.fn(async () => (response instanceof Error ? Promise.reject(response) : response));
	const original = { fetch: window.fetch, create: URL.createObjectURL, revoke: URL.revokeObjectURL };
	window.fetch = fetchMock as unknown as typeof fetch;
	URL.createObjectURL = jest.fn(() => 'blob:x');
	URL.revokeObjectURL = jest.fn();
	const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
	return {
		fetchMock,
		names: () => click.mock.contexts.map((link) => (link as HTMLAnchorElement).download),
		restore: () => {
			click.mockRestore();
			window.fetch = original.fetch;
			URL.createObjectURL = original.create;
			URL.revokeObjectURL = original.revoke;
		},
	};
}

describe('export downloads', () => {
	test('Netlify: the file is named _redirects, from Content-Disposition', async () => {
		routes({ 'admin/redirects/import/plugins': { importers: [] } });
		const stub = stubDownload({ ok: true, status: 200, blob: async () => new Blob(['/a /b 301\n']), headers: { get: (name: string) => (name === 'Content-Disposition' ? 'attachment; filename="_redirects"' : null) } });
		try {
			const user = userEvent.setup();
			renderScreen('&tab=import');
			await screen.findByRole('heading', { name: 'Export' });
			await choose(user, 'Format', 'Netlify (_redirects)');
			await user.click(screen.getByRole('button', { name: 'Download' }));
			await waitFor(() => expect(stub.names()).toEqual(['_redirects']));
			expect(stub.fetchMock).toHaveBeenCalledWith('https://example.test/wp-json/proto-blocks/v1/admin/redirects/export?format=redirects', { headers: { 'X-WP-Nonce': 'nonce-wp_rest' }, credentials: 'same-origin' });
			expect((await screen.findAllByText('Downloaded _redirects.')).length).toBeGreaterThan(0);
		} finally {
			stub.restore();
		}
	});

	test('the server name wins over the fallback', async () => {
		routes({ 'admin/redirects/import/plugins': { importers: [] } });
		const stub = stubDownload({ ok: true, status: 200, blob: async () => new Blob(['x']), headers: { get: () => 'attachment; filename="proto-redirects-all-2026-11-05.csv"' } });
		try {
			const user = userEvent.setup();
			renderScreen('&tab=import');
			await user.click(await screen.findByRole('button', { name: 'Download' }));
			await waitFor(() => expect(stub.names()).toEqual(['proto-redirects-all-2026-11-05.csv']));
		} finally {
			stub.restore();
		}
	});

	test('a non-JSON error page and a network failure both toast, nothing is downloaded', async () => {
		routes({ 'admin/redirects/import/plugins': { importers: [] } });
		let stub = stubDownload({ ok: false, status: 502, json: async () => { throw new SyntaxError('Unexpected token <'); } });
		try {
			const user = userEvent.setup();
			renderScreen('&tab=import');
			await user.click(await screen.findByRole('button', { name: 'Download' }));
			expect((await screen.findAllByText('HTTP 502')).length).toBeGreaterThan(0);
			expect(screen.getAllByText('The export failed.').length).toBeGreaterThan(0);
			expect(stub.names()).toEqual([]);
		} finally {
			stub.restore();
		}

		document.body.innerHTML = '';
		stub = stubDownload(new TypeError('Failed to fetch'));
		try {
			const user = userEvent.setup();
			renderScreen('&tab=import');
			await user.click(await screen.findByRole('button', { name: 'Download' }));
			expect((await screen.findAllByText('Failed to fetch')).length).toBeGreaterThan(0);
			expect(stub.names()).toEqual([]);
		} finally {
			stub.restore();
		}
	});

	test('filenameFrom', () => {
		expect(filenameFrom('attachment; filename="_redirects"')).toBe('_redirects');
		expect(filenameFrom('attachment; filename=log.csv')).toBe('log.csv');
		expect(filenameFrom("attachment; filename*=UTF-8''r%C3%A9sum%C3%A9.csv; filename=\"x.csv\"")).toBe('résumé.csv');
		// Folders and control characters never reach the download attribute.
		expect(filenameFrom('attachment; filename="../../evil.sh"')).toBe('evil.sh');
		expect(filenameFrom("attachment; filename*=UTF-8''..%2F..%2Fa%0A.txt")).toBe('a.txt');
		expect(filenameFrom('attachment; filename=".."')).toBeNull();
		expect(filenameFrom("attachment; filename*=UTF-8''%E0%A4%A; filename=\"ok.csv\"")).toBe('ok.csv');
		expect(filenameFrom('inline')).toBeNull();
		expect(filenameFrom(null)).toBeNull();
	});
});

describe('merge helpers', () => {
	test('detectFormat', () => {
		expect(detectFormat('a.CSV')).toBe('csv');
		expect(detectFormat('export.json')).toBe('json');
		expect(detectFormat('.htaccess')).toBe('apache');
		expect(detectFormat('rules.txt')).toBe('apache');
		expect(detectFormat('site/_redirects')).toBe('redirects');
		expect(detectFormat('x.xml')).toBeNull();
	});

	test('sameSource compares from, regex and condition', () => {
		expect(sameSource({ from: '/a' }, { from: '/a', to: '/b' })).toBe(true);
		expect(sameSource({ from: '/a' }, { from: '/a', regex: true })).toBe(false);
		expect(sameSource({ from: '/a', when: { type: 'login' } }, { from: '/a' })).toBe(false);
	});

	test('add mode always appends and never reuses a taken id', () => {
		const text = '{\n  "rules": [\n    { "id": "r_00000001", "from": "/a", "to": "/b" }\n  ]\n}';
		const result = mergeRules(text, [{ id: 'r_00000001', from: '/a', to: '/c' }], 'add');
		expect(result).toMatchObject({ added: 1, replaced: 0, skipped: 0 });
		const ids = readRules(result.text).map((r) => r.id);
		expect(ids[0]).toBe('r_00000001');
		expect(ids[1]).not.toBe('r_00000001');
		expect(mergeRules(text, [{ from: '/a', to: '/c' }], 'skip')).toMatchObject({ text, skipped: 1 });
	});
});
