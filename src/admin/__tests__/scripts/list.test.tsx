import { mockApi, mockBootstrap } from '../helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from '@/admin/app';
import { detail, index, posts, row } from './fixtures';

function renderScreen(query = '') {
	window.history.replaceState(null, '', `/wp-admin/admin.php?page=proto-blocks-scripts${query}`);
	render(<AdminApp bootstrap={mockBootstrap({ screen: 'scripts' })} />);
}

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
	window.history.replaceState(null, '', '/');
	jest.clearAllMocks();
});

describe('scripts list', () => {
	test('shows title, H/B/F badges, where summary, priority and origin', async () => {
		mockApi({ 'admin/scripts': index() });
		renderScreen();
		const gtm = (await screen.findByText('Google Tag Manager')).closest('tr') as HTMLElement;
		expect(within(gtm).getByText('Proto-Blocks file')).toBeTruthy();
		expect(within(gtm).getByText('All pages; except page:privacy-policy; not roles: administrator')).toBeTruthy();
		expect(within(gtm).getByLabelText('In <head>')).toBeTruthy();
		expect(within(gtm).getByLabelText('After <body>')).toBeTruthy();
		expect(within(gtm).getByLabelText('Nothing before </body>')).toBeTruthy();
		expect(within(gtm).getByRole('switch', { name: 'Enable Google Tag Manager' })).toBeTruthy();
		expect(within(gtm).getByRole('button', { name: 'Edit Google Tag Manager' })).toBeTruthy();

		const php = screen.getByText('Search Console').closest('tr') as HTMLElement;
		expect(within(php).getByText('Proto-Blocks PHP')).toBeTruthy();
		expect(within(php).getByRole('button', { name: 'View Search Console' })).toBeTruthy();
		expect((within(php).getByRole('switch') as HTMLButtonElement).disabled).toBe(true);
	});

	test('declared twice and issues are flagged', async () => {
		mockApi({
			'admin/scripts': index({
				items: [
					row({
						double: { file: 'wp-content/themes/demo/proto-scripts/gtm/script.jsonc', php: 'wp-content/themes/demo/functions.php:9' },
						issues: [{ level: 'warning', code: 'snippet_file_missing', message: 'head.html is referenced but does not exist.', source: 'x' }],
					}),
				],
				issues: [{ level: 'error', code: 'invalid_jsonc', message: 'proto-scripts/broken/script.jsonc is not valid JSONC', source: 'proto-scripts/broken/script.jsonc' }],
			}),
		});
		renderScreen();
		expect(await screen.findByText('Declared twice')).toBeTruthy();
		expect(screen.getByText('Check')).toBeTruthy();
		expect(screen.getByText('proto-scripts/broken/script.jsonc is not valid JSONC')).toBeTruthy();
	});

	test('empty state and module off', async () => {
		mockApi({ 'admin/scripts': index({ items: [] }) });
		renderScreen();
		expect(await screen.findByText('No scripts yet')).toBeTruthy();

		document.body.innerHTML = '';
		mockApi({ 'admin/scripts': index({ moduleEnabled: false, items: [] }) });
		renderScreen();
		expect(await screen.findByText('The Scripts module is disabled by the proto_blocks_modules filter.')).toBeTruthy();
		expect(screen.queryByRole('button', { name: 'New script' })).toBeNull();
	});

	test('the enabled switch rewrites only "enabled" with the loaded hash', async () => {
		const routes = mockApi({
			'admin/scripts': index(),
			'GET admin/scripts/gtm': detail(),
			'POST admin/scripts/gtm': { hash: 'h2', path: 'x', created: false, warnings: [] },
		});
		renderScreen();
		await userEvent.click(await screen.findByRole('switch', { name: 'Enable Google Tag Manager' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		const body = posts()[0].data as { text: string; hash: string; snippets?: unknown };
		expect(body.hash).toBe('h1');
		expect(body.snippets).toBeUndefined();
		expect(body.text).toContain('"enabled": false');
		expect(body.text).toContain('// legal pages stay clean');
		expect(body.text).toContain('"priority": 10, // keep first');
		expect(routes).toHaveBeenCalled();
	});

	test('read-only without unfiltered_html: banner names the reason, New script disabled', async () => {
		mockApi({
			'admin/scripts': index({
				canWrite: false,
				writeReason: 'unfiltered_html',
				items: [row({ editable: false, readOnlyReason: 'unfiltered_html' })],
			}),
		});
		renderScreen();
		expect(await screen.findByText('Scripts are read-only for you')).toBeTruthy();
		expect(screen.getByText(/Only users who may post unfiltered HTML can edit scripts\./)).toBeTruthy();
		const create = screen.getByRole('button', { name: 'New script' }) as HTMLButtonElement;
		expect(create.disabled).toBe(true);
		expect(create.getAttribute('aria-describedby')).toBe('proto-blocks-read-only');
		expect((screen.getByRole('switch', { name: 'Enable Google Tag Manager' }) as HTMLButtonElement).disabled).toBe(true);
		expect(screen.getByRole('button', { name: 'View Google Tag Manager' })).toBeTruthy();
	});

	test('file mods off uses the shared banner', async () => {
		mockApi({ 'admin/scripts': index({ canWrite: false, writeReason: 'file_mods' }) });
		renderScreen();
		expect(await screen.findByText('File modifications are disabled on this site')).toBeTruthy();
	});
});
