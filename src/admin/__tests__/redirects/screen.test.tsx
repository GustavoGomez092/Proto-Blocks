import '../helpers';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { calls, index, legacyGroup, renderScreen, routes, rowOf, rulesTable, setup, writes } from './fixtures';

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
		expect((within(php).getByRole('button', { name: 'Delete /php-old' }) as HTMLButtonElement).disabled).toBe(true);
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

		await user.click(screen.getByRole('tab', { name: 'Redirects' }));
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

	test('log rule link opens the rule in the edit dialog', async () => {
		allTabs();
		const user = userEvent.setup();
		renderScreen('&tab=log');
		await user.click(await screen.findByRole('button', { name: 'Edit rule r_00000001' }));
		const dialog = await screen.findByRole('dialog', { name: 'Edit redirect' });
		expect((within(dialog).getByRole('textbox', { name: 'Source' }) as HTMLInputElement).value).toBe('/about-us');
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
			expect((within(about).getByRole('button', { name }) as HTMLButtonElement).disabled).toBe(true);
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

	test('coexistence: paused banner with an Import button that opens the import tab', async () => {
		routes({ 'admin/redirects': index({ paused: true }), 'admin/redirects/import/plugins': [] });
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
