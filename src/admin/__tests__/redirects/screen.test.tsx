import '../helpers';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { calls, index, rowButton, legacyGroup, phpGroup, promoGroup, renderScreen, routes, rowOf, rulesTable, setup, writes } from './fixtures';

setup();

const logRows = {
	rows: [{ id: 1, created: '2026-11-04 10:00:00', url: '/about-us', target: '/about', code: 301, group: 'legacy-site', ruleId: 'r_00000001', redirectBy: 'proto-blocks', method: 'GET', ip: null, agent: 'curl/8', referrer: null }],
	total: 1,
};
const notFoundRows = { rows: [{ url: '/missing', count: 4, lastSeen: '2026-11-04 09:00:00', lastReferrer: 'https://google.com/' }], total: 1 };

function allTabs() {
	return routes({
		'admin/redirects/log': logRows,
		'admin/redirects/404': notFoundRows,
		'admin/redirects/import/plugins': { importers: [{ id: 'redirection', label: 'Redirection', cleanupHint: null, plugin: 'redirection/redirection.php' }] },
		'admin/schema-files/redirect-settings/settings': { exists: true, path: 'wp-content/themes/demo/proto-redirects/settings.jsonc', text: '{}\n', hash: 'h-settings', data: {}, writable: true, reason: null },
	});
}

describe('tabs', () => {
	test('the Redirects tab lists every rule across groups with code, condition, hits and origin', async () => {
		allTabs();
		const user = userEvent.setup();
		renderScreen();
		const about = await rowOf('/about-us');
		expect(within(about).getByText('/about')).toBeTruthy();
		expect(within(about).getByText('301')).toBeTruthy();
		expect(within(about).getByText('12')).toBeTruthy();
		expect(within(about).getByText('File')).toBeTruthy();
		expect(within(await rowOf('/members')).getByText('Logged in')).toBeTruthy();
		expect(within(await rowOf('/gone-forever')).getByText('Error page')).toBeTruthy();
		const php = await rowOf('/php-old');
		expect(within(php).getByText('PHP')).toBeTruthy();
		// A PHP rule is not in a file: toggle, move and delete are off.
		expect((within(php).getByRole('switch') as HTMLButtonElement).disabled).toBe(true);
		expect((await rowButton(user, '/php-old', 'Delete /php-old')).disabled).toBe(true);
		expect((within(php).getByRole('button', { name: 'Edit /php-old' }) as HTMLButtonElement).disabled).toBe(false);
		// Evaluation order: legacy (10), promo (20), php (100).
		const sources = within(await rulesTable()).getAllByRole('row').slice(1).map((row) => row.querySelector('code')?.textContent);
		expect(sources).toEqual(['/about-us', '^/blog/(\\d+)/(.*)$', '/promo', '/members', '/gone-forever', '/spring', '/php-old']);
	});

	test('each other tab renders from its route and the tab is kept in ?tab=', async () => {
		allTabs();
		const user = userEvent.setup();
		renderScreen();
		await rulesTable();

		await user.click(screen.getByRole('tab', { name: '404s' }));
		const notFound = await screen.findByRole('table', { name: '404s by URL' });
		expect(within(notFound).getByText('/missing')).toBeTruthy();
		expect(within(notFound).getByText('4')).toBeTruthy();
		expect(window.location.search).toBe('?page=proto-blocks-redirects&tab=404s');

		await user.click(screen.getByRole('tab', { name: 'Log' }));
		const log = await screen.findByRole('table', { name: 'Redirect log' });
		expect(within(log).getByText('curl/8')).toBeTruthy();
		expect(window.location.search).toContain('tab=log');

		await user.click(screen.getByRole('tab', { name: 'Import / Export' }));
		expect(await screen.findByRole('heading', { name: 'Import a file' })).toBeTruthy();
		expect(await screen.findByText('Redirection')).toBeTruthy();
		expect(screen.getByRole('heading', { name: 'Export' })).toBeTruthy();

		await user.click(screen.getByRole('tab', { name: 'Settings' }));
		expect(await screen.findByRole('button', { name: 'Save settings' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Prune orphan hit rows (2)' })).toBeTruthy();

		await user.click(screen.getByRole('tab', { name: /^Rules/ }));
		await rulesTable();
		expect(window.location.search).toBe('?page=proto-blocks-redirects');
	});

	test('?tab= opens a tab directly, ?slug= filters the rules to a group', async () => {
		allTabs();
		renderScreen('&tab=log');
		expect(await screen.findByRole('table', { name: 'Redirect log' })).toBeTruthy();

		document.body.innerHTML = '';
		renderScreen('&slug=promo');
		const table = await rulesTable();
		expect(within(table).getByText('/spring')).toBeTruthy();
		expect(within(table).queryByText('/about-us')).toBeNull();
	});

	test('?tab=log on load renders the log tab, selected, without the rules table', async () => {
		allTabs();
		renderScreen('&tab=log');
		expect(await screen.findByRole('table', { name: 'Redirect log' })).toBeTruthy();
		expect(screen.getByRole('tab', { name: 'Log' }).getAttribute('aria-selected')).toBe('true');
		expect(screen.queryByRole('table', { name: 'Redirect rules' })).toBeNull();
		expect(calls().some((c) => c.url.startsWith('admin/redirects/log?'))).toBe(true);
		// An unknown tab falls back to the rules.
		document.body.innerHTML = '';
		renderScreen('&tab=nope');
		expect(await rulesTable()).toBeTruthy();
	});

	test('log rule link opens the rule in the edit dialog', async () => {
		allTabs();
		const user = userEvent.setup();
		renderScreen('&tab=log');
		await user.click(await screen.findByRole('button', { name: 'Edit rule r_00000001' }));
		const dialog = await screen.findByRole('dialog', { name: 'Edit redirect' });
		expect((within(dialog).getByRole('textbox', { name: 'When a visitor requests' }) as HTMLInputElement).value).toBe('/about-us');
	});
});

describe('banners', () => {
	test('read-only: banner, every file write disabled and pointed at the banner; logs still usable', async () => {
		allTabs();
		const user = userEvent.setup();
		renderScreen('', { fileModsAllowed: false });
		expect(await screen.findByText('File modifications are disabled on this site')).toBeTruthy();
		const add = screen.getByRole('button', { name: 'Add redirect' }) as HTMLButtonElement;
		expect(add.disabled).toBe(true);
		expect(add.getAttribute('aria-describedby')).toBe('proto-blocks-read-only');
		const about = await rowOf('/about-us');
		const toggle = within(about).getByRole('switch') as HTMLButtonElement;
		expect(toggle.disabled).toBe(true);
		expect(toggle.getAttribute('aria-describedby')).toBe('proto-blocks-read-only');
		for (const name of ['Edit /about-us', 'Delete /about-us', 'Duplicate /about-us', 'Move /about-us down']) {
			expect((await rowButton(user, '/about-us', name)).disabled).toBe(true);
		}

		await user.click(screen.getByRole('tab', { name: '404s' }));
		await screen.findByRole('table', { name: '404s by URL' });
		expect((screen.getByRole('button', { name: 'Add redirect for /missing' }) as HTMLButtonElement).disabled).toBe(true);
		expect((screen.getByRole('button', { name: 'Ignore /missing' }) as HTMLButtonElement).disabled).toBe(true);
		expect((screen.getByRole('button', { name: 'Delete 404s of /missing' }) as HTMLButtonElement).disabled).toBe(false);
		expect((screen.getByRole('button', { name: 'Clear all' }) as HTMLButtonElement).disabled).toBe(false);

		await user.click(screen.getByRole('tab', { name: 'Settings' }));
		const save = (await screen.findByRole('button', { name: 'Save settings' })) as HTMLButtonElement;
		expect(save.disabled).toBe(true);
		expect(save.getAttribute('aria-describedby')).toBe('proto-blocks-read-only');
		expect(writes()).toEqual([]);
	});

	test('an unwritable theme folder is read-only too', async () => {
		routes({ 'admin/redirects': index({ createTarget: { path: 'wp-content/themes/demo/proto-redirects', writable: false, reason: 'not_writable' } }) });
		renderScreen();
		expect(await screen.findByText('The theme folder is not writable')).toBeTruthy();
		expect((screen.getByRole('button', { name: 'Add redirect' }) as HTMLButtonElement).disabled).toBe(true);
	});

	test('a file group the server cannot write (parent theme, filter root) keeps its rules read-only', async () => {
		routes({ 'admin/redirects': index({ groups: [legacyGroup({ writable: false })] }) });
		const user = userEvent.setup();
		renderScreen();
		const about = await rowOf('/about-us');
		for (const name of ['Edit /about-us', 'Delete /about-us', 'Duplicate /about-us']) {
			expect((await rowButton(user, '/about-us', name)).disabled).toBe(true);
		}
		expect((within(about).getByRole('switch') as HTMLButtonElement).disabled).toBe(true);
		// Adding is still possible: the dialog offers a new group.
		expect((screen.getByRole('button', { name: 'Add redirect' }) as HTMLButtonElement).disabled).toBe(false);
	});

	test('coexistence: paused banner with an Import button that opens the import tab', async () => {
		routes({ 'admin/redirects': index({ paused: true }), 'admin/redirects/import/plugins': { importers: [] } });
		const user = userEvent.setup();
		renderScreen();
		expect(await screen.findByText('Redirection is active, Proto-Blocks redirects are paused.')).toBeTruthy();
		await user.click(screen.getByRole('button', { name: 'Import' }));
		expect(await screen.findByRole('heading', { name: 'Import a file' })).toBeTruthy();
		expect(window.location.search).toContain('tab=import');
		// Still shown on every tab.
		expect(screen.getByText('Redirection is active, Proto-Blocks redirects are paused.')).toBeTruthy();
	});

	test('no banner when not paused; issues and declared-twice badges are shown', async () => {
		routes({
			'admin/redirects': index({
				issues: [{ level: 'warning', code: 'redirect_chain', message: '/a redirects to /b, which redirects again.', source: 'proto-redirects/legacy-site/redirects.jsonc' }],
				groups: [legacyGroup({ double: { file: 'proto-redirects/legacy-site/redirects.jsonc', php: 'functions.php:30' } })],
			}),
		});
		renderScreen();
		expect(await screen.findByText('/a redirects to /b, which redirects again.')).toBeTruthy();
		expect(screen.queryByText('Redirection is active, Proto-Blocks redirects are paused.')).toBeNull();
		expect(within(await rowOf('/about-us')).getByText('Declared twice')).toBeTruthy();
	});

	test('tables missing: Create tables installs them, then the screen reloads', async () => {
		let tables = false;
		let installs = 0;
		routes({
			'admin/redirects': () => index({ db: { tables, orphans: 0 } }),
			'POST admin/redirects/db/install': () => {
				installs++;
				if (installs === 1) {
					throw { code: 'proto_blocks_db_install_failed', message: "Check the database user's CREATE privilege.", data: { status: 500 } };
				}
				tables = true;
				return { tables: true };
			},
		});
		const user = userEvent.setup();
		renderScreen();
		expect(await screen.findByText('The redirect tables are missing')).toBeTruthy();
		// Database only: a read-only theme does not disable it.
		await user.click(screen.getByRole('button', { name: 'Create tables' }));
		expect((await screen.findAllByText("Check the database user's CREATE privilege.")).length).toBeGreaterThan(0);
		expect(screen.getByText('The redirect tables are missing')).toBeTruthy();

		await user.click(screen.getByRole('button', { name: 'Create tables' }));
		await waitFor(() => expect(screen.queryByText('The redirect tables are missing')).toBeNull());
		expect(calls().filter((c) => c.url === 'admin/redirects')).toHaveLength(2);
		expect(writes().map((c) => `${c.method} ${c.url}`)).toEqual(['POST admin/redirects/db/install', 'POST admin/redirects/db/install']);
	});

	test('tables missing shows on a read-only site too, and on every tab', async () => {
		allTabs();
		routes({ 'admin/redirects': index({ db: { tables: false, orphans: 0 } }), 'admin/redirects/log': { rows: [], total: 0 } });
		renderScreen('&tab=log', { fileModsAllowed: false });
		expect(await screen.findByText('The redirect tables are missing')).toBeTruthy();
		expect((screen.getByRole('button', { name: 'Create tables' }) as HTMLButtonElement).disabled).toBe(false);
	});

	test('readOnlyReason: a locked file group says why and its rules are locked; a PHP group edits by copying', async () => {
		routes({
			'admin/redirects': index({
				groups: [
					legacyGroup({ writable: false, readOnlyReason: 'read_only' }),
					// Defensive: a reason wins over a stale writable flag.
					promoGroup({ readOnlyReason: 'file_mods' }),
					{ ...phpGroup(), readOnlyReason: 'php' },
				],
			}),
		});
		const user = userEvent.setup();
		renderScreen();
		const about = await rowOf('/about-us');
		expect(within(about).getByText(/the file is outside the theme the screen writes to/)).toBeTruthy();
		for (const name of ['Edit /about-us', 'Delete /about-us', 'Duplicate /about-us']) {
			expect((await rowButton(user, '/about-us', name)).disabled).toBe(true);
		}
		const spring = await rowOf('/spring');
		expect(within(spring).getByText('Read-only: file modifications are disabled')).toBeTruthy();
		expect((within(spring).getByRole('button', { name: 'Edit /spring' }) as HTMLButtonElement).disabled).toBe(true);
		const php = await rowOf('/php-old');
		expect((within(php).getByRole('button', { name: 'Edit /php-old' }) as HTMLButtonElement).disabled).toBe(false);

		await user.click(screen.getByRole('button', { name: 'Groups' }));
		const dialog = await screen.findByRole('dialog', { name: 'Groups' });
		expect(within(dialog).getByText(/the file is outside the theme the screen writes to/)).toBeTruthy();
		expect((within(dialog).getByRole('switch', { name: 'Enable group Legacy site' }) as HTMLButtonElement).disabled).toBe(true);
		expect((within(dialog).getByRole('switch', { name: 'Enable group Promo' }) as HTMLButtonElement).disabled).toBe(true);
	});

	test('monitor issues get their own alert; other issues stay in the declarations list', async () => {
		routes({
			'admin/redirects': index({
				issues: [
					{ level: 'warning', code: 'monitor_unwritable', message: 'The monitor could not write proto-redirects/modified-posts/redirects.jsonc.', source: 'proto-redirects/modified-posts/redirects.jsonc' },
					{ level: 'warning', code: 'redirect_chain', message: '/a redirects to /b, which redirects again.', source: '' },
				],
			}),
		});
		renderScreen();
		const monitor = await screen.findByRole('list', { name: 'Post monitor issues' });
		expect(screen.getByText('The post monitor needs attention')).toBeTruthy();
		expect(within(monitor).getByText('The monitor could not write proto-redirects/modified-posts/redirects.jsonc.')).toBeTruthy();
		expect(within(monitor).queryByText('/a redirects to /b, which redirects again.')).toBeNull();
		expect(screen.getByText('/a redirects to /b, which redirects again.')).toBeTruthy();
		expect(screen.getByText('Check these declarations')).toBeTruthy();

		document.body.innerHTML = '';
		routes({ 'admin/redirects': index({ issues: [{ level: 'warning', code: 'monitor_rule_disabled', message: 'A monitor rule was disabled.', source: '' }] }) });
		renderScreen();
		expect(await screen.findByText('A monitor rule was disabled.')).toBeTruthy();
		expect(screen.queryByText('Check these declarations')).toBeNull();
	});

	test('module disabled and load error', async () => {
		routes({ 'admin/redirects': index({ moduleEnabled: false }) });
		renderScreen();
		expect(await screen.findByText('The Redirects module is disabled by the proto_blocks_modules filter.')).toBeTruthy();

		document.body.innerHTML = '';
		routes({
			'admin/redirects': () => {
				throw { code: 'rest_forbidden', message: 'Nope', data: { status: 403 } };
			},
		});
		renderScreen();
		await waitFor(() => expect(screen.getByText(/Nope/)).toBeTruthy());
		expect(calls()[0].url).toBe('admin/redirects');
	});
});

describe('redesign states', () => {
	test('rules list: tab count, filter pills, code ink, origin line and the footer', async () => {
		allTabs();
		const user = userEvent.setup();
		renderScreen();
		await rulesTable();
		expect(screen.getByRole('tab', { name: /^Rules/ }).textContent).toBe('Rules7');
		const code = screen.getByRole('combobox', { name: 'Code' });
		expect(code.getAttribute('data-active')).toBe('false');
		expect(code.textContent).toBe('Code');
		await user.click(code);
		await user.click(await screen.findByRole('option', { name: '410' }));
		expect(code.getAttribute('data-active')).toBe('true');
		expect(code.textContent).toBe('Code 410');
		const gone = await rowOf('/gone-forever');
		expect(within(gone).getByText('410').parentElement?.className).toContain('text-warning');
		expect(within(gone).getByText('Error page').className).toContain('text-muted-foreground');
		expect(screen.getByText(/^1 rule in 1 group/)).toBeTruthy();
		await user.click(code);
		await user.click(await screen.findByRole('option', { name: 'All codes' }));
		const promo = await rowOf('/promo');
		expect(within(promo).getByText('temp')).toBeTruthy();
		expect(within(await rowOf('/about-us')).getByText('File')).toBeTruthy();
	});

	test('the row menu holds reorder, duplicate and delete; Edit stays in the row', async () => {
		allTabs();
		const user = userEvent.setup();
		renderScreen();
		const about = await rowOf('/about-us');
		expect(within(about).getByRole('button', { name: 'Edit /about-us' }).textContent).toBe('Edit');
		expect(within(about).queryByRole('button', { name: 'Delete /about-us' })).toBeNull();
		await user.click(within(about).getByRole('button', { name: 'More actions for /about-us' }));
		const menu = await screen.findByRole('dialog');
		expect(within(menu).getAllByRole('button').map((b) => b.textContent)).toEqual(['Move up', 'Move down', 'Duplicate', 'Delete']);
	});

	test('404s: period chips set the From date; the aside counts and links to Settings', async () => {
		allTabs();
		const user = userEvent.setup();
		renderScreen('&tab=404s');
		await screen.findByRole('table', { name: '404s by URL' });
		expect(screen.getByRole('radio', { name: 'All time' }).getAttribute('aria-checked')).toBe('true');
		await user.click(screen.getByRole('radio', { name: 'Last 7 days' }));
		const from = screen.getByLabelText('From') as HTMLInputElement;
		expect(from.value).toMatch(/^\d{4}-\d{2}-\d{2}$/);
		await waitFor(() => expect(calls().some((c) => c.url.startsWith('admin/redirects/404?') && c.url.includes(`from=${from.value}`))).toBe(true));
		expect(screen.getByRole('radio', { name: 'Last 7 days' }).getAttribute('aria-checked')).toBe('true');
		await user.click(screen.getByRole('radio', { name: 'All time' }));
		expect(from.value).toBe('');

		const summary = screen.getByRole('complementary', { name: '404 summary' });
		expect(within(summary).getByText('Distinct paths')).toBeTruthy();
		expect(within(summary).getByText('Logs are pruned after 7 days.')).toBeTruthy();
		await user.click(within(summary).getByRole('button', { name: 'Change in Settings' }));
		expect(screen.getByRole('tab', { name: 'Settings' }).getAttribute('aria-selected')).toBe('true');
	});
});
