import '../helpers';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { readRules } from '@/admin/redirects/edits';
import { detectFormat, mergeRules, sameSource } from '@/admin/redirects/import';
import { calls, choose, renderScreen, routes, setup, writes } from './fixtures';

setup();

const fileWrites = () => writes().filter((c) => c.url.startsWith('admin/schema-files/'));

const parsed = {
	rules: [
		{ rule: { from: '/spring', to: '/summer', code: 302 }, status: 'duplicate', reason: 'Source exists in promo.', line: 2 },
		{ rule: { from: '/new', to: '/n' }, status: 'new', reason: null, line: 3 },
		{ rule: { from: 'bad', to: '/x' }, status: 'invalid', reason: 'Source must start with /.', line: 4 },
	],
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
			'admin/redirects/import/plugins': [],
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
			'admin/redirects/import/plugins': [],
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
		routes({ 'admin/redirects/import/plugins': [], 'POST admin/redirects/import/parse': parsed });
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
			'admin/redirects/import/plugins': { importers: [{ id: 'redirection', label: 'Redirection', cleanupHint: 'Delete the Redirection tables afterwards.', plugin: 'redirection/redirection.php' }] },
			'POST admin/redirects/import/plugins/redirection/preview': {
				groups: {
					'redirections': { title: 'Redirections', enabled: false, position: 3, rules: [{ from: '/r1', to: '/t1' }] },
					'legacy-site': { title: 'Legacy site', rules: [{ from: '/about-us', to: '/elsewhere' }, { from: '/r2', to: '/t2', code: 307 }] },
				},
			},
			'admin/schema-files/redirects/redirections': { exists: false, path: 'p', text: '', hash: null, data: {}, writable: true, reason: null },
			'POST admin/schema-files/redirects/redirections': { path: 'p', hash: 'h', created: true, warnings: [] },
			'POST admin/redirects/import/deactivate': { deactivated: true },
		});
		const user = userEvent.setup();
		renderScreen('&tab=import');
		await user.click(await screen.findByRole('button', { name: 'Preview Redirection' }));
		expect(await screen.findByText('Redirection: 3 rules in 2 groups')).toBeTruthy();
		expect(screen.getByText('Delete the Redirection tables afterwards.')).toBeTruthy();
		expect(screen.getByText('merges into existing group')).toBeTruthy();
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
	});

	test('nothing to import', async () => {
		routes({ 'admin/redirects/import/plugins': [] });
		renderScreen('&tab=import');
		expect(await screen.findByText('No other redirect plugin data found.')).toBeTruthy();
	});
});

describe('export', () => {
	test('downloads the chosen group and format with the nonce', async () => {
		routes({ 'admin/redirects/import/plugins': [] });
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
		routes({ 'admin/redirects/import/plugins': [] });
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
