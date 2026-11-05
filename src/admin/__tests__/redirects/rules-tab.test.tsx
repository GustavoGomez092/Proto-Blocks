import '../helpers';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { readRules } from '@/admin/redirects/edits';
import { calls, choose, file, index, legacyGroup, legacyText, promoGroup, phpGroup, promoText, renderScreen, routes, rowOf, rule, setup, writes } from './fixtures';

setup();

const posts = () => writes().filter((c) => c.method === 'POST' && c.url.startsWith('admin/schema-files/'));

async function openAdd(user: ReturnType<typeof userEvent.setup>) {
	await rowOf('/about-us');
	await user.click(screen.getByRole('button', { name: 'Add redirect' }));
	return screen.findByRole('dialog', { name: 'Add redirect' });
}

async function submit(user: ReturnType<typeof userEvent.setup>, dialog: HTMLElement) {
	await user.click(within(dialog).getByRole('button', { name: 'Save redirect' }));
}

describe('add/edit dialog validation mirrors the schema codes', () => {
	test('invalid regex', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		const dialog = await openAdd(user);
		await user.type(within(dialog).getByRole('textbox', { name: 'Source' }), '^/(a');
		await user.click(within(dialog).getByRole('checkbox', { name: 'Regex' }));
		await user.type(within(dialog).getByRole('textbox', { name: 'Target' }), '/b');
		await submit(user, dialog);
		expect(await within(dialog).findByText(/The regular expression does not compile/)).toBeTruthy();
		expect(within(dialog).getByRole('textbox', { name: 'Source' }).getAttribute('aria-invalid')).toBe('true');
		expect(posts()).toEqual([]);
	});

	test('unanchored regex warns but does not block', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		const dialog = await openAdd(user);
		await user.click(within(dialog).getByRole('checkbox', { name: 'Regex' }));
		await user.type(within(dialog).getByRole('textbox', { name: 'Source' }), 'blog');
		expect(within(dialog).getByText(/neither \^ nor \$/)).toBeTruthy();
	});

	test('missing target and missing source', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		const dialog = await openAdd(user);
		await submit(user, dialog);
		expect(await within(dialog).findByText('Enter the source path.')).toBeTruthy();
		expect(within(dialog).getByText('Enter the target.')).toBeTruthy();
		expect(posts()).toEqual([]);
	});

	test('bad code (a file rule with 399)', async () => {
		routes({ 'admin/redirects': index({ groups: [legacyGroup({ rules: [rule({ code: 399 })] })] }) });
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/about-us')).getByRole('button', { name: 'Edit /about-us' }));
		const dialog = await screen.findByRole('dialog', { name: 'Edit redirect' });
		expect(within(dialog).getByRole('combobox', { name: 'Code' }).textContent).toContain('399 Not a valid code');
		await submit(user, dialog);
		expect(await within(dialog).findByText('Choose a code this action supports.')).toBeTruthy();
		expect(posts()).toEqual([]);
	});

	test('fallback target without a condition', async () => {
		routes({ 'admin/redirects': index({ groups: [legacyGroup({ rules: [rule({ else: '/elsewhere' })] })] }) });
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/about-us')).getByRole('button', { name: 'Edit /about-us' }));
		const dialog = await screen.findByRole('dialog', { name: 'Edit redirect' });
		await submit(user, dialog);
		expect(await within(dialog).findByText('A fallback target needs a match condition.')).toBeTruthy();
	});

	test('pass refuses an absolute target', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		const dialog = await openAdd(user);
		await user.type(within(dialog).getByRole('textbox', { name: 'Source' }), '/old-api');
		await choose(user, 'Action', 'Pass through (internal rewrite)');
		// pass has no code select.
		expect(within(dialog).queryByRole('combobox', { name: 'Code' })).toBeNull();
		await user.type(within(dialog).getByRole('textbox', { name: 'Target' }), 'https://other.test/api');
		await submit(user, dialog);
		expect(await within(dialog).findByText('Use a path starting with / or a full http:// or https:// URL.')).toBeTruthy();
		expect(posts()).toEqual([]);
	});

	test('an absolute target passes and the rule is written as one line with a fresh id', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		const dialog = await openAdd(user);
		await user.type(within(dialog).getByRole('textbox', { name: 'Source' }), '/shop');
		await user.type(within(dialog).getByRole('textbox', { name: 'Target' }), 'https://shop.example.test/');
		await choose(user, 'Code', /^302 /);
		await submit(user, dialog);
		await waitFor(() => expect(posts()).toHaveLength(1));
		const [post] = posts();
		expect(post.url).toBe('admin/schema-files/redirects/legacy-site');
		expect(post.data?.hash).toBe('h-legacy');
		const added = readRules(post.data?.text ?? '').slice(-1)[0];
		expect(added).toEqual({ id: expect.stringMatching(/^r_[a-f0-9]{8}$/), from: '/shop', to: 'https://shop.example.test/', code: 302 });
		expect(post.data?.text).toContain('// End of rules.');
		await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
	});

	test('the condition select reveals its fields', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		const dialog = await openAdd(user);
		expect(within(dialog).queryByRole('textbox', { name: 'Header name' })).toBeNull();
		await choose(user, 'Match condition', 'Request header');
		expect(within(dialog).getByRole('textbox', { name: 'Header name' })).toBeTruthy();
		expect(within(dialog).getByRole('textbox', { name: 'Header value' })).toBeTruthy();
		expect(within(dialog).getByRole('checkbox', { name: 'Value is a regular expression' })).toBeTruthy();
		expect(within(dialog).getByRole('textbox', { name: 'Fallback target' })).toBeTruthy();
		await user.type(within(dialog).getByRole('textbox', { name: 'Source' }), '/a');
		await user.type(within(dialog).getByRole('textbox', { name: 'Target' }), '/b');
		await submit(user, dialog);
		expect(await within(dialog).findByText('Enter the header or cookie name.')).toBeTruthy();
		expect(within(dialog).getByText('Enter a value for this condition.')).toBeTruthy();

		await choose(user, 'Match condition', 'IP address');
		expect(within(dialog).queryByRole('textbox', { name: 'Header name' })).toBeNull();
		await user.type(within(dialog).getByRole('textbox', { name: 'IP addresses or ranges' }), '10.0.0.0/8, 999.1.1.1');
		await submit(user, dialog);
		expect(await within(dialog).findByText('"999.1.1.1" is not an IP address or CIDR range.')).toBeTruthy();

		await choose(user, 'Match condition', 'Logged in');
		expect(within(dialog).queryByRole('textbox', { name: 'IP addresses or ranges' })).toBeNull();
	});
});

describe('writes', () => {
	test('enable toggle writes the expected JSONC text, comments kept', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/promo')).getByRole('switch', { name: 'Enable /promo' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		const [post] = posts();
		expect(post).toMatchObject({ url: 'admin/schema-files/redirects/legacy-site', data: { hash: 'h-legacy', acknowledge: [] } });
		expect(post.data?.text).toMatchSnapshot();
		expect(post.data?.text).toContain('// Keep the old blog URLs alive.');
		expect(post.data?.text?.split('\n').filter((line, i) => line !== legacyText.split('\n')[i])).toEqual([
			'    { "id": "r_00000003", "enabled": false, "from": "/promo", "to": "/sale", "code": 302, "query": "pass" },',
		]);
		// The list reloads after the write.
		await waitFor(() => expect(calls().filter((c) => c.url === 'admin/redirects')).toHaveLength(2));
	});

	test('a rule written without an id is toggled by its index and gets its id', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/gone-forever')).getByRole('switch'));
		await waitFor(() => expect(posts()).toHaveLength(1));
		expect(readRules(posts()[0].data?.text ?? '')[4]).toEqual({ id: 'r_1a2b3c4d', enabled: false, from: '/gone-forever', action: 'error', code: 410 });
	});

	test('moving a rule to another group deletes it from its file, then inserts it in the other, each with its hash', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/about-us')).getByRole('button', { name: 'Edit /about-us' }));
		const dialog = await screen.findByRole('dialog', { name: 'Edit redirect' });
		await choose(user, 'Group', 'Promo');
		await submit(user, dialog);
		await waitFor(() => expect(posts()).toHaveLength(2));
		const sequence = calls()
			.filter((c) => c.url.startsWith('admin/schema-files/'))
			.map((c) => `${c.method} ${c.url}`);
		expect(sequence).toEqual([
			'GET admin/schema-files/redirects/legacy-site',
			'POST admin/schema-files/redirects/legacy-site',
			'GET admin/schema-files/redirects/promo',
			'POST admin/schema-files/redirects/promo',
		]);
		const [removal, insert] = posts();
		expect(removal.data?.hash).toBe('h-legacy');
		expect(readRules(removal.data?.text ?? '').map((r) => r.id)).not.toContain('r_00000001');
		expect(removal.data?.text).toContain('// About page moved in 2024.');
		expect(insert.data?.hash).toBe('h-promo');
		expect(readRules(insert.data?.text ?? '')).toEqual([
			{ id: 'r_0000000p', from: '/spring', to: '/sale' },
			{ id: 'r_00000001', from: '/about-us', to: '/about' },
		]);
		expect(insert.data?.text).toContain('// Spring sale.');
	});

	test('a failed delete stops before the insert', async () => {
		routes({
			'POST admin/schema-files/redirects/legacy-site': () => {
				throw { code: 'proto_blocks_write_failed', message: 'Disk full', data: { status: 500 } };
			},
		});
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/about-us')).getByRole('button', { name: 'Edit /about-us' }));
		const dialog = await screen.findByRole('dialog', { name: 'Edit redirect' });
		await choose(user, 'Group', 'Promo');
		await submit(user, dialog);
		expect(await screen.findByText('Disk full')).toBeTruthy();
		expect(calls().some((c) => c.method === 'POST' && c.url.endsWith('/promo'))).toBe(false);
		expect(screen.getByRole('dialog', { name: 'Edit redirect' })).toBeTruthy();
	});

	test('a file changed since the list loaded is a conflict: nothing is written', async () => {
		routes({ 'admin/schema-files/redirects/legacy-site': file('legacy-site', legacyText, 'h-changed') });
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/promo')).getByRole('switch'));
		expect(await screen.findByText('The file changed on disk.')).toBeTruthy();
		expect(posts()).toEqual([]);
		await user.click(screen.getByRole('button', { name: 'Reload file' }));
		await waitFor(() => expect(calls().filter((c) => c.url === 'admin/redirects')).toHaveLength(2));
	});

	test('a server conflict and a guardrail are handled like the other screens', async () => {
		let attempt = 0;
		routes({
			'POST admin/schema-files/redirects/legacy-site': (body: { acknowledge: string[] }) => {
				attempt++;
				if (body.acknowledge.length === 0) {
					throw { code: 'proto_blocks_needs_confirmation', message: 'Confirm', data: { status: 409, guardrails: [{ code: 'rule_removed', message: 'Removes 1 rule with hits.', count: 1, detail: [] }] } };
				}
				return { path: 'x', hash: 'h2', created: false, warnings: [] };
			},
		});
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/promo')).getByRole('switch'));
		expect(await screen.findByText('Removes 1 rule with hits.')).toBeTruthy();
		await user.click(screen.getByRole('button', { name: 'Save anyway' }));
		await waitFor(() => expect(attempt).toBe(2));
		expect(posts()[1].data?.acknowledge).toEqual(['rule_removed']);
	});

	test('editing a PHP rule writes it into the chosen file group', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/php-old')).getByRole('button', { name: 'Edit /php-old' }));
		const dialog = await screen.findByRole('dialog', { name: 'Edit redirect' });
		expect(within(dialog).getByText(/declared in PHP/)).toBeTruthy();
		await submit(user, dialog);
		await waitFor(() => expect(posts()).toHaveLength(1));
		expect(posts()[0].url).toBe('admin/schema-files/redirects/legacy-site');
		expect(readRules(posts()[0].data?.text ?? '').slice(-1)[0]).toEqual({ id: 'r_0000beef', from: '/php-old', to: '/php-new' });
	});

	test('delete, move down and duplicate', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/about-us')).getByRole('button', { name: 'Delete /about-us' }));
		await user.click(await screen.findByRole('button', { name: 'Delete 1 redirect' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		expect(readRules(posts()[0].data?.text ?? '').map((r) => r.from)).toEqual(['^/blog/(\\d+)/(.*)$', '/promo', '/members', '/gone-forever']);

		await user.click(within(await rowOf('/about-us')).getByRole('button', { name: 'Move /about-us down' }));
		await waitFor(() => expect(posts()).toHaveLength(2));
		expect(readRules(posts()[1].data?.text ?? '').map((r) => r.id).slice(0, 2)).toEqual(['r_00000002', 'r_00000001']);
		expect((within(await rowOf('/about-us')).getByRole('button', { name: 'Move /about-us up' }) as HTMLButtonElement).disabled).toBe(true);

		await user.click(within(await rowOf('/about-us')).getByRole('button', { name: 'Duplicate /about-us' }));
		await waitFor(() => expect(posts()).toHaveLength(3));
		const dup = readRules(posts()[2].data?.text ?? '');
		expect(dup[1]).toEqual({ id: expect.stringMatching(/^r_[a-f0-9]{8}$/), from: '/about-us', to: '/about' });
		expect(dup[1].id).not.toBe('r_00000001');
	});

	test('bulk disable writes each group once; bulk move deletes then inserts; reset hits', async () => {
		routes({ 'POST admin/redirects/hits/reset': { reset: true } });
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/about-us')).getByRole('checkbox', { name: 'Select /about-us' }));
		await user.click(within(await rowOf('/promo')).getByRole('checkbox', { name: 'Select /promo' }));
		const bulk = screen.getByRole('region', { name: 'Bulk actions' });
		expect(within(bulk).getByText('2 selected')).toBeTruthy();
		await user.click(within(bulk).getByRole('button', { name: 'Disable' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		const disabled = readRules(posts()[0].data?.text ?? '');
		expect(disabled.filter((r) => r.enabled === false).map((r) => r.id)).toEqual(['r_00000001', 'r_00000003']);

		await user.click(within(bulk).getByRole('button', { name: 'Reset hits' }));
		await waitFor(() => expect(calls().filter((c) => c.url === 'admin/redirects/hits/reset')).toHaveLength(2));
		expect(calls().find((c) => c.url === 'admin/redirects/hits/reset')?.data).toEqual({ group: 'legacy-site', ruleId: 'r_00000001' });

		await choose(user, 'Move to group', 'Promo');
		await user.click(within(bulk).getByRole('button', { name: 'Move' }));
		await waitFor(() => expect(posts()).toHaveLength(3));
		const [, removal, insert] = posts();
		expect(removal.url).toBe('admin/schema-files/redirects/legacy-site');
		expect(readRules(removal.data?.text ?? '').map((r) => r.id)).toEqual(['r_00000002', 'r_00000004', undefined]);
		expect(insert.url).toBe('admin/schema-files/redirects/promo');
		expect(readRules(insert.data?.text ?? '').map((r) => r.id)).toEqual(['r_0000000p', 'r_00000001', 'r_00000003']);
	});

	test('search and filters', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		await rowOf('/about-us');
		await user.type(screen.getByRole('searchbox', { name: 'Search redirects' }), 'blog');
		expect(screen.queryByText('/about-us')).toBeNull();
		expect(screen.getByText('Old blog')).toBeTruthy();
		await user.clear(screen.getByRole('searchbox', { name: 'Search redirects' }));
		await choose(user, 'Code', '410');
		expect(screen.getByText('/gone-forever')).toBeTruthy();
		expect(screen.queryByText('/about-us')).toBeNull();
		await choose(user, 'Code', 'All codes');
		await choose(user, 'Group', 'Promo');
		expect(window.location.search).toContain('slug=promo');
		expect(screen.getByText('/spring')).toBeTruthy();
	});
});

describe('groups', () => {
	test('create, rename and disable a group', async () => {
		routes({
			'admin/schema-files/redirects/legacy-pages': { exists: false, path: 'wp-content/themes/demo/proto-redirects/legacy-pages/redirects.jsonc', text: '', hash: null, data: {}, writable: true, reason: null },
			'POST admin/schema-files/redirects/legacy-pages': { path: 'x', hash: 'h', created: true, warnings: [] },
		});
		const user = userEvent.setup();
		renderScreen();
		await rowOf('/about-us');
		await user.click(screen.getByRole('button', { name: 'Groups' }));
		const dialog = await screen.findByRole('dialog', { name: 'Groups' });
		await user.type(within(dialog).getByRole('textbox', { name: 'New group' }), 'Legacy pages');
		expect(within(dialog).getByText('proto-redirects/legacy-pages/redirects.jsonc')).toBeTruthy();
		await user.click(within(dialog).getByRole('button', { name: 'Create group' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		expect(posts()[0]).toMatchObject({ url: 'admin/schema-files/redirects/legacy-pages', data: { hash: null } });
		expect(parseJsonc(posts()[0].data?.text ?? '').data).toEqual({ title: 'Legacy pages', enabled: true, position: 10, rules: [] });

		await user.click(within(dialog).getByRole('switch', { name: 'Enable group Promo' }));
		await waitFor(() => expect(posts()).toHaveLength(2));
		expect(posts()[1].url).toBe('admin/schema-files/redirects/promo');
		expect(parseJsonc(posts()[1].data?.text ?? '').data.enabled).toBe(false);
		expect(posts()[1].data?.text).toContain('// Spring sale.');

		const rows = within(dialog).getAllByRole('listitem');
		const legacy = rows.find((li) => within(li).queryByText('legacy-site')) as HTMLElement;
		const title = within(legacy).getByRole('textbox', { name: 'Title' });
		await user.clear(title);
		await user.type(title, 'Old site');
		await user.clear(within(legacy).getByRole('spinbutton', { name: 'Position' }));
		await user.type(within(legacy).getByRole('spinbutton', { name: 'Position' }), '5');
		await user.click(within(legacy).getByRole('button', { name: 'Save' }));
		await waitFor(() => expect(posts()).toHaveLength(3));
		expect(posts()[2].data?.text).toContain('"title": "Old site", // shown in the UI');
		expect(parseJsonc(posts()[2].data?.text ?? '').data.position).toBe(5);
		// The PHP group cannot be edited.
		const php = rows.find((li) => within(li).queryByText('php')) as HTMLElement;
		expect((within(php).getByRole('switch') as HTMLButtonElement).disabled).toBe(true);
	});

	test('reserved and taken slugs are refused; delete asks first', async () => {
		routes({ 'DELETE admin/schema-files/redirects/promo': { deleted: true } });
		const user = userEvent.setup();
		renderScreen();
		await rowOf('/about-us');
		await user.click(screen.getByRole('button', { name: 'Groups' }));
		const dialog = await screen.findByRole('dialog', { name: 'Groups' });
		await user.type(within(dialog).getByRole('textbox', { name: 'New group' }), 'Settings');
		expect(within(dialog).getByText('This name is reserved. Choose another.')).toBeTruthy();
		expect((within(dialog).getByRole('button', { name: 'Create group' }) as HTMLButtonElement).disabled).toBe(true);
		await user.clear(within(dialog).getByRole('textbox', { name: 'New group' }));
		await user.type(within(dialog).getByRole('textbox', { name: 'New group' }), 'Promo');
		expect(within(dialog).getByText('A group with this slug already exists.')).toBeTruthy();

		await user.click(within(dialog).getByRole('button', { name: 'Delete group Promo' }));
		await user.click(await screen.findByRole('button', { name: 'Delete group' }));
		await waitFor(() => expect(calls().some((c) => c.method === 'DELETE')).toBe(true));
		expect(calls().find((c) => c.method === 'DELETE')?.url).toBe('admin/schema-files/redirects/promo?confirm=promo');
	});
});

describe('test box', () => {
	test('renders the trace', async () => {
		routes({
			'admin/redirects/test': (_body: unknown, path: string) => {
				expect(path).toBe('admin/redirects/test?url=%2Fblog%2F2020%2Fhello');
				return {
					matched: true,
					group: 'legacy-site',
					ruleId: 'r_00000002',
					action: 'url',
					code: 301,
					target: '/news/hello',
					trace: [
						{ group: 'legacy-site', ruleId: 'r_00000001', result: 'skipped', reason: 'Source does not match.' },
						{ group: 'legacy-site', ruleId: 'r_00000002', result: 'matched', reason: 'Regex matched.' },
					],
					note: 'Login conditions use your session.',
				};
			},
		});
		const user = userEvent.setup();
		renderScreen();
		await rowOf('/about-us');
		await user.type(screen.getByRole('textbox', { name: 'URL to test' }), '/blog/2020/hello');
		await user.click(screen.getByRole('button', { name: 'Test' }));
		expect(await screen.findByText('Rule r_00000002 in legacy-site fires: Redirect to URL 301 → /news/hello')).toBeTruthy();
		const trace = screen.getByRole('table', { name: 'Evaluation trace' });
		expect(within(trace).getByText('Source does not match.')).toBeTruthy();
		expect(within(trace).getByText('Matched')).toBeTruthy();
		expect(within(trace).getByText('Skipped')).toBeTruthy();
		expect(screen.getByText('Login conditions use your session.')).toBeTruthy();
	});

	test('no match and errors', async () => {
		let fail = false;
		routes({
			'admin/redirects/test': () => {
				if (fail) {
					throw { code: 'proto_blocks_invalid_url', message: 'Enter a URL on this site.', data: { status: 400 } };
				}
				return { matched: false, group: null, ruleId: null, action: null, code: null, target: null, trace: [] };
			},
		});
		const user = userEvent.setup();
		renderScreen();
		await rowOf('/about-us');
		await user.type(screen.getByRole('textbox', { name: 'URL to test' }), '/nothing');
		await user.click(screen.getByRole('button', { name: 'Test' }));
		expect(await screen.findByText('No rule fires for this URL.')).toBeTruthy();
		fail = true;
		await user.click(screen.getByRole('button', { name: 'Test' }));
		expect(await screen.findByText('Enter a URL on this site.')).toBeTruthy();
	});
});

// Keep fixture builders referenced for readers jumping here from fixtures.tsx.
void promoGroup;
void phpGroup;
void promoText;
