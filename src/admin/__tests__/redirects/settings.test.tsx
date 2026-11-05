import '../helpers';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { formToSettings, settingsToForm } from '@/admin/redirects/SettingsTab';
import { calls, choose, index, renderScreen, routes, SETTINGS, setup, writes } from './fixtures';

setup();

const settingsText = '// proto-redirects/settings.jsonc\n{\n  "log": {\n    "redirects": 7 // a week\n  },\n  "trackHits": true\n}\n';
const settingsFile = (overrides = {}) => ({
	exists: true,
	path: 'wp-content/themes/demo/proto-redirects/settings.jsonc',
	text: settingsText,
	hash: 'h-settings',
	data: {},
	writable: true,
	reason: null,
	...overrides,
});

const settingsPosts = () => writes().filter((c) => c.url === 'admin/schema-files/redirect-settings/settings');

describe('settings', () => {
	test('save writes only the changed keys into settings.jsonc, comments kept, with the hash', async () => {
		routes({
			'admin/schema-files/redirect-settings/settings': settingsFile(),
			'POST admin/schema-files/redirect-settings/settings': { path: 'p', hash: 'h2', created: false, warnings: [] },
		});
		const user = userEvent.setup();
		renderScreen('&tab=settings');
		await screen.findByRole('button', { name: 'Save settings' });
		await choose(user, 'Redirect log', '30 days');
		await choose(user, 'IP addresses', 'Masked (last part zeroed)');
		await user.type(screen.getByRole('textbox', { name: 'Every front-end response' }), 'X-Frame-Options: DENY');
		await user.type(screen.getByRole('textbox', { name: 'Alias hosts' }), 'Old.example.test, www.old.test');
		await user.click(screen.getByRole('checkbox', { name: 'Run even while Redirection is active' }));
		await user.click(screen.getByRole('checkbox', { name: 'X-Forwarded-For' }));
		await user.click(screen.getByRole('button', { name: 'Save settings' }));

		await waitFor(() => expect(settingsPosts()).toHaveLength(1));
		const [post] = settingsPosts();
		expect(post.data?.hash).toBe('h-settings');
		expect(post.data?.text).toContain('// a week');
		expect(post.data?.text).toContain('// proto-redirects/settings.jsonc');
		expect(parseJsonc(post.data?.text ?? '').data).toEqual({
			log: { redirects: 30, ip: 'masked', ipHeaders: ['X-Forwarded-For'] },
			trackHits: true,
			headers: { site: { 'X-Frame-Options': 'DENY' } },
			canonical: { aliases: ['old.example.test', 'www.old.test'] },
			coexist: true,
		});
		// The screen reloads (paused may change) and the file is read again.
		await waitFor(() => expect(calls().filter((c) => c.url === 'admin/redirects')).toHaveLength(2));
	});

	test('a settings file that does not exist yet is created with hash null', async () => {
		routes({
			'admin/redirects': index({ settingsFile: { exists: false, hash: null, writable: true } }),
			'admin/schema-files/redirect-settings/settings': settingsFile({ exists: false, text: '{\n}\n', hash: null }),
			'POST admin/schema-files/redirect-settings/settings': { path: 'p', hash: 'h', created: true, warnings: [] },
		});
		const user = userEvent.setup();
		renderScreen('&tab=settings');
		await user.click(await screen.findByRole('checkbox', { name: 'Redirect http to https' }));
		await user.click(screen.getByRole('button', { name: 'Save settings' }));
		await waitFor(() => expect(settingsPosts()).toHaveLength(1));
		expect(settingsPosts()[0].data?.hash).toBeNull();
		expect(parseJsonc(settingsPosts()[0].data?.text ?? '').data).toEqual({ canonical: { https: true } });
	});

	test('validation: bad hosts, bad header lines and unsafe headers block the save', async () => {
		routes({ 'admin/schema-files/redirect-settings/settings': settingsFile() });
		const user = userEvent.setup();
		renderScreen('&tab=settings');
		await screen.findByRole('button', { name: 'Save settings' });
		await user.type(screen.getByRole('textbox', { name: 'Alias hosts' }), 'https://old.test/');
		await user.type(screen.getByRole('textbox', { name: 'Redirects only' }), 'no colon here');
		await user.type(screen.getByRole('textbox', { name: 'Every front-end response' }), 'Set-Cookie: a=1');
		await user.type(screen.getByRole('textbox', { name: 'Trusted proxies' }), '10.0.0.1, nope');
		await user.click(screen.getByRole('button', { name: 'Save settings' }));
		expect(await screen.findByText('Enter host names only, without http:// or a path.')).toBeTruthy();
		expect(screen.getByText('Write one header per line as Name: value.')).toBeTruthy();
		expect(screen.getByText('The header "Set-Cookie" is not allowed or its value has line breaks.')).toBeTruthy();
		expect(screen.getByText('Enter IP addresses or CIDR ranges.')).toBeTruthy();
		expect(screen.getByRole('textbox', { name: 'Alias hosts' }).getAttribute('aria-invalid')).toBe('true');
		expect(settingsPosts()).toEqual([]);
	});

	test('an unchanged form writes nothing', async () => {
		routes({ 'admin/schema-files/redirect-settings/settings': settingsFile() });
		const user = userEvent.setup();
		renderScreen('&tab=settings');
		await user.click(await screen.findByRole('button', { name: 'Save settings' }));
		expect(await screen.findByText('Nothing changed.')).toBeTruthy();
		expect(settingsPosts()).toEqual([]);
	});

	test('database buttons: create tables when missing, prune orphans', async () => {
		routes({
			'admin/redirects': index({ db: { tables: false, orphans: 3 } }),
			'admin/schema-files/redirect-settings/settings': settingsFile(),
			'POST admin/redirects/db/install': { tables: true },
			'POST admin/redirects/hits/prune': { deleted: 3 },
		});
		const user = userEvent.setup();
		renderScreen('&tab=settings', { fileModsAllowed: false });
		await user.click(await screen.findByRole('button', { name: 'Create tables' }));
		await waitFor(() => expect(calls().some((c) => c.url === 'admin/redirects/db/install')).toBe(true));
		await user.click(screen.getByRole('button', { name: 'Prune orphan hit rows (3)' }));
		expect(await screen.findByText('3 rows removed.')).toBeTruthy();
	});

	test('form mapping round trips the defaults', () => {
		const form = settingsToForm(SETTINGS);
		expect(form.canonical.www).toBe('none');
		const back = formToSettings(form);
		expect(back).toEqual(SETTINGS);
	});
});
