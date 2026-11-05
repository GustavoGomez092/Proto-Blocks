import { mockApi, mockBootstrap } from '../helpers';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from '@/admin/app';
import { calls, detail, index, posts, row } from './fixtures';

beforeAll(() => {
	if (!('ResizeObserver' in window)) {
		(window as unknown as { ResizeObserver: unknown }).ResizeObserver = class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};
	}
});

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

const saved = { hash: 'h2', path: 'wp-content/themes/demo/proto-scripts/gtm/script.jsonc', created: false, warnings: [] };

async function openGtm(extra: Record<string, unknown> = {}) {
	mockApi({ 'admin/scripts': index(), 'GET admin/scripts/gtm': detail(), 'POST admin/scripts/gtm': saved, ...extra });
	renderScreen('&slug=gtm');
	return screen.findByRole('textbox', { name: 'Title' });
}

describe('script editor', () => {
	test('loads title, enabled, priority, the three snippets and the placement', async () => {
		const title = await openGtm();
		expect((title as HTMLInputElement).value).toBe('Google Tag Manager');
		expect(screen.getByRole('switch', { name: 'Enabled' }).getAttribute('aria-checked')).toBe('true');
		expect((screen.getByRole('spinbutton', { name: 'Priority' }) as HTMLInputElement).value).toBe('10');
		expect((screen.getByRole('textbox', { name: 'In <head>' }) as HTMLTextAreaElement).value).toBe('<script>gtm()</script>');
		expect((screen.getByRole('textbox', { name: 'Right after <body>' }) as HTMLTextAreaElement).value).toBe('<noscript>gtm</noscript>');
		expect((screen.getByRole('textbox', { name: 'Before </body>' }) as HTMLTextAreaElement).value).toBe('');
		expect(screen.getByRole('list', { name: 'Exclude' }).textContent).toContain('page:privacy-policy');
		expect(screen.getByText('Proto-Blocks file')).toBeTruthy();
	});

	test('the code editors are monospace with a line-number gutter', async () => {
		await openGtm();
		const head = screen.getByRole('textbox', { name: 'In <head>' });
		expect(head.className).toContain('font-mono');
		await userEvent.type(head, '{enter}<meta>{enter}');
		const gutter = screen.getAllByTestId('line-gutter')[0];
		expect(gutter.getAttribute('aria-hidden')).toBe('true');
		expect(gutter.textContent).toBe('123');
	});

	test('save payload: script.jsonc edited in place, every snippet, the loaded hash', async () => {
		await openGtm();
		await userEvent.type(screen.getByRole('textbox', { name: 'Before </body>' }), '<script>bye()</script>');
		await userEvent.clear(screen.getByRole('textbox', { name: 'Right after <body>' }));
		await userEvent.click(screen.getByRole('radio', { name: 'Logged out only' }));
		await userEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]);

		await waitFor(() => expect(posts()).toHaveLength(1));
		const post = posts()[0];
		expect(post.url).toBe('admin/scripts/gtm');
		expect(post.data).toMatchSnapshot();
		const text = (post.data as { text: string }).text;
		expect(text).toContain('// GTM container for the marketing site');
		expect(text).toContain('// legal pages stay clean');
		expect(text).toContain('"bodyClose": {');
		expect(text).not.toContain('body-open.html');
		expect(text).toContain('"loggedIn": false');
		expect((await screen.findAllByText('Script saved.')).length).toBeGreaterThan(0);
		// Reloaded after the save.
		expect(calls().filter((c) => c.url === 'admin/scripts/gtm' && c.method === 'GET')).toHaveLength(2);
	});

	test('validation: empty title and PHP in a snippet block the save', async () => {
		await openGtm();
		await userEvent.clear(screen.getByRole('textbox', { name: 'Title' }));
		await userEvent.type(screen.getByRole('textbox', { name: 'Before </body>' }), '<?php echo 1; ?>');
		await userEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]);

		expect(await screen.findByText('A title is required.')).toBeTruthy();
		expect(screen.getByText(/a PHP opening tag/)).toBeTruthy();
		expect(screen.getByRole('textbox', { name: 'Before </body>' }).getAttribute('aria-invalid')).toBe('true');
		expect(posts()).toHaveLength(0);
	});

	test('validation: markup in the title and an out-of-range priority', async () => {
		await openGtm();
		const title = screen.getByRole('textbox', { name: 'Title' });
		await userEvent.clear(title);
		await userEvent.type(title, 'GTM <b>');
		const priority = screen.getByRole('spinbutton', { name: 'Priority' });
		await userEvent.clear(priority);
		await userEvent.type(priority, '200000');
		await userEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]);
		expect(await screen.findByText('The title cannot contain "<" or ">".')).toBeTruthy();
		expect(screen.getByText(/between -100000 and 100000/)).toBeTruthy();
		expect(posts()).toHaveLength(0);
	});

	test('a conflict says the file changed on disk', async () => {
		await openGtm({
			'POST admin/scripts/gtm': () => {
				throw { code: 'proto_blocks_conflict', message: 'changed', data: { status: 409, hash: 'h9' } };
			},
		});
		await userEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]);
		expect(await screen.findByText(/The file changed on disk since it was loaded/)).toBeTruthy();
	});

	test('read-only without unfiltered_html: reason banner, fields locked, no Save or Delete', async () => {
		mockApi({
			'admin/scripts': index({ canWrite: false, writeReason: 'unfiltered_html' }),
			'GET admin/scripts/gtm': detail({ canWrite: false, writeReason: 'unfiltered_html', item: row({ editable: false, readOnlyReason: 'unfiltered_html' }) }),
		});
		renderScreen('&slug=gtm');
		expect(await screen.findByText('Scripts are read-only for you')).toBeTruthy();
		const save = screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement;
		expect(save.disabled).toBe(true);
		expect(save.getAttribute('aria-describedby')).toBe('proto-blocks-read-only');
		expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
		expect((screen.getByRole('textbox', { name: 'In <head>' }) as HTMLTextAreaElement).readOnly).toBe(true);
		expect(screen.queryByRole('button', { name: /Add to include/ })).toBeNull();
		expect(screen.queryByRole('button', { name: 'Remove page:privacy-policy' })).toBeNull();
	});

	test('a PHP-only script is shown read-only with its source', async () => {
		mockApi({
			'admin/scripts': index(),
			'GET admin/scripts/verify': detail({
				exists: false,
				hash: null,
				text: '{}',
				data: {},
				path: 'wp-content/themes/demo/proto-scripts/verify/script.jsonc',
				snippets: { head: '<meta name="v">', bodyOpen: '', bodyClose: '' },
				item: index().items[1],
			}),
		});
		renderScreen('&slug=verify');
		expect(await screen.findByText(/Declared in PHP at wp-content\/themes\/demo\/functions.php:12/)).toBeTruthy();
		expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);
	});

	test('delete goes through schema-files with the typed confirm', async () => {
		await openGtm({ 'DELETE admin/schema-files/script/gtm?confirm=gtm': { deleted: 'x', kept: [] } });
		await userEvent.click(screen.getByRole('button', { name: 'Delete' }));
		await userEvent.click(await screen.findByRole('button', { name: 'Delete script' }));
		await waitFor(() => expect(calls().some((c) => c.method === 'DELETE' && c.url === 'admin/schema-files/script/gtm?confirm=gtm')).toBe(true));
		expect(await screen.findByText('Google Tag Manager')).toBeTruthy();
	});

	test('create: slug from the title, a new script.jsonc with hash null', async () => {
		mockApi({
			'admin/scripts': index(),
			'POST admin/scripts/chat-widget': { ...saved, created: true },
			'GET admin/scripts/chat-widget': detail({ item: row({ slug: 'chat-widget', title: 'Chat widget' }) }),
		});
		renderScreen('&new=1');
		await userEvent.type(await screen.findByRole('textbox', { name: 'Title' }), 'Chat widget');
		expect((screen.getByRole('textbox', { name: 'Slug (folder name)' }) as HTMLInputElement).value).toBe('chat-widget');
		await userEvent.type(screen.getByRole('textbox', { name: 'Before </body>' }), '<script src="https://chat.example/w.js"></script>');
		await userEvent.click(screen.getByRole('button', { name: 'Save' }));

		await waitFor(() => expect(posts()).toHaveLength(1));
		const body = posts()[0].data as { text: string; hash: null; snippets: Record<string, string> };
		expect(posts()[0].url).toBe('admin/scripts/chat-widget');
		expect(body.hash).toBeNull();
		expect(body.text).toContain('"title": "Chat widget"');
		expect(body.text).toContain('"bodyClose": {');
		expect(body.snippets.bodyClose).toBe('<script src="https://chat.example/w.js"></script>');
		await waitFor(() => expect(window.location.search).toContain('slug=chat-widget'));
	});

	test('create: an invalid slug is refused before any request', async () => {
		mockApi({ 'admin/scripts': index() });
		renderScreen('&new=1');
		await userEvent.type(await screen.findByRole('textbox', { name: 'Title' }), 'X');
		const slug = screen.getByRole('textbox', { name: 'Slug (folder name)' });
		await userEvent.clear(slug);
		await userEvent.type(slug, 'Bad Slug');
		await userEvent.click(screen.getByRole('button', { name: 'Save' }));
		expect(await screen.findByText(/Start with a lowercase letter/)).toBeTruthy();
		expect(posts()).toHaveLength(0);
	});
});
