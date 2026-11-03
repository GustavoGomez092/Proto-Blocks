import { mockApi, mockBootstrap } from './helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from '@/admin/app';
import type { AdminBootstrap } from '@/admin/bootstrap';
import { lastCompiledLabel } from '@/admin/screens/TailwindScreen';

type Status = Record<string, unknown>;

const TAILWIND = {
	ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
	actionPrefix: 'proto_blocks_tailwind_',
	nonce: 'tw-nonce',
};

function payload(status: Status = {}, config: Record<string, unknown> = {}) {
	return {
		status: {
			enabled: true,
			mode: 'cached',
			disable_global_styles: false,
			shell_available: true,
			engine: 'cli',
			engine_setting: 'auto',
			cli_installed: true,
			cli_trusted: true,
			cli_trust_state: 'trusted',
			cli_functional: true,
			cli_version: '4.1.3',
			cache_exists: true,
			cache_size: 39117,
			cache_url: 'https://example.test/wp-content/uploads/proto-blocks/tailwind/tailwind.css',
			last_compiled: null,
			needs_recompilation: false,
			...status,
		},
		settings: {
			enabled: (status.enabled as boolean | undefined) ?? true,
			mode: (status.mode as string | undefined) ?? 'cached',
			engine: (status.engine_setting as string | undefined) ?? 'auto',
			disableGlobalStyles: false,
			lastCompiled: null,
		},
		config: {
			path: 'cadco/tailwind-theme.css',
			exists: true,
			preview: '@theme {\n  --color-brand: #002a5c;\n}',
			dirWritable: true,
			...config,
		},
		cssSizeFormatted: '38.2 KB',
	};
}

function renderScreen(overrides: Partial<AdminBootstrap> = {}) {
	const bootstrap = mockBootstrap({ screen: 'tailwind', tailwind: TAILWIND, ...overrides });
	render(<AdminApp bootstrap={bootstrap} />);
	return bootstrap;
}

function getCalls(api: jest.Mock): number {
	return api.mock.calls.filter(
		([options]: [{ url?: string; method?: string }]) =>
			options.method === 'GET' && options.url === 'https://example.test/wp-json/proto-blocks/v1/admin/tailwind'
	).length;
}

afterEach(() => {
	delete window.protoBlocksAdmin;
	delete (window as unknown as { ProtoBlocksTailwind?: unknown }).ProtoBlocksTailwind;
	document.body.innerHTML = '';
	jest.clearAllMocks();
});

test.each([
	['browser engine', { engine: 'browser', cli_installed: false, cli_functional: false, cli_trust_state: 'missing' }, 'Browser compiler'],
	['functional', {}, 'Installed · v4.1.3'],
	['checksum mismatch', { cli_functional: false, cli_trusted: false, cli_trust_state: 'mismatch', cli_version: null }, 'Checksum mismatch — re-download'],
	['unverified', { cli_functional: false, cli_trusted: false, cli_trust_state: 'unverified', cli_version: null }, 'No recorded checksum — re-download'],
	['installed, not runnable', { cli_functional: false, cli_trust_state: 'trusted', cli_version: null }, 'Installed but not runnable'],
	['not installed', { cli_installed: false, cli_functional: false, cli_trusted: false, cli_trust_state: 'missing', cli_version: null }, 'Not installed'],
])('CLI status: %s', async (_name, status, text) => {
	mockApi({ 'admin/tailwind': payload(status) });
	renderScreen();

	expect(await screen.findByText(text)).toBeInTheDocument();
});

test('changing the engine posts the setting and refetches', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/tailwind': payload(),
		'POST admin/tailwind/settings': () => payload({ engine_setting: 'cli' }),
	});
	renderScreen();

	await user.click(await screen.findByRole('radio', { name: 'CLI binary (server)' }));

	await waitFor(() => expect(getCalls(api)).toBe(2));
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({
			url: 'https://example.test/wp-json/proto-blocks/v1/admin/tailwind/settings',
			method: 'POST',
			data: { engine: 'cli' },
		})
	);
	expect(await screen.findByText('Compile engine set to CLI binary.')).toBeInTheDocument();
});

test('a failed setting change rolls back and toasts the server message', async () => {
	const user = userEvent.setup();
	mockApi({
		'GET admin/tailwind': payload(),
		'POST admin/tailwind/settings': () => {
			throw { code: 'proto_blocks_invalid_setting', message: 'That Tailwind setting is not valid.', data: { status: 400 } };
		},
	});
	renderScreen();

	await user.click(await screen.findByRole('radio', { name: 'On reload' }));

	expect(await screen.findByText('That Tailwind setting is not valid.')).toBeInTheDocument();
	await waitFor(() => expect(screen.getByRole('radio', { name: 'Cached' })).toBeChecked());
});

test('compiles in the browser with the bootstrap AJAX settings when the engine is browser', async () => {
	const user = userEvent.setup();
	const runBrowserCompile = jest.fn().mockResolvedValue({ success: true, message: 'ok', cssSizeFormatted: '12 KB' });
	(window as unknown as { ProtoBlocksTailwind: unknown }).ProtoBlocksTailwind = { runBrowserCompile };
	const api = mockApi({ 'GET admin/tailwind': payload({ engine: 'browser', engine_setting: 'browser' }) });
	renderScreen();

	await user.click(await screen.findByRole('button', { name: 'Compile now' }));

	expect(runBrowserCompile).toHaveBeenCalledWith({
		ajaxUrl: TAILWIND.ajaxUrl,
		actionPrefix: 'proto_blocks_tailwind_',
		nonce: TAILWIND.nonce,
	});
	expect(await screen.findByText('ok')).toBeInTheDocument();
	expect(screen.getByText('12 KB')).toBeInTheDocument();
	await waitFor(() => expect(getCalls(api)).toBe(2));
	expect(api).not.toHaveBeenCalledWith(expect.objectContaining({ url: expect.stringContaining('admin/tailwind/compile') }));
});

test('compiles on the server when the engine is CLI', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/tailwind': payload(),
		'POST admin/tailwind/compile': payload(),
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: 'Compile now' }));

	expect(await screen.findByText('Tailwind CSS compiled.')).toBeInTheDocument();
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({ url: 'https://example.test/wp-json/proto-blocks/v1/admin/tailwind/compile', method: 'POST' })
	);
	await waitFor(() => expect(getCalls(api)).toBe(2));
});

test('Compile now is disabled when the CLI engine has no working CLI', async () => {
	mockApi({ 'admin/tailwind': payload({ cli_installed: false, cli_functional: false, cli_trust_state: 'missing', cli_version: null }) });
	renderScreen();

	expect(await screen.findByRole('button', { name: 'Compile now' })).toBeDisabled();
	expect(screen.getByText('Download a working CLI first.')).toBeInTheDocument();
});

test('without shell access the CLI download is hidden and a warning shows', async () => {
	mockApi({ 'admin/tailwind': payload({ shell_available: false, engine: 'browser', cli_installed: false, cli_functional: false }) });
	renderScreen();

	expect(await screen.findByText("Shell access isn't available on this server")).toBeInTheDocument();
	expect(screen.queryByRole('button', { name: /CLI$/ })).not.toBeInTheDocument();
	expect(screen.getByRole('radio', { name: 'CLI binary (server)' })).toBeDisabled();
});

test('read-only sites disable Download CLI and Create starter file', async () => {
	mockApi({
		'admin/tailwind': payload(
			{ cli_installed: false, cli_functional: false, cli_trust_state: 'missing', cli_version: null },
			{ exists: false, preview: '' }
		),
	});
	renderScreen({ fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });

	expect(await screen.findByText('File modifications are disabled on this site')).toBeInTheDocument();
	expect(screen.getByRole('button', { name: 'Download CLI' })).toBeDisabled();
	expect(screen.getByRole('button', { name: 'Create starter file' })).toBeDisabled();
	expect(screen.getByRole('button', { name: 'Clear cache' })).toBeEnabled();
});

test('Clear cache asks first, then deletes the cache', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/tailwind': payload(),
		'DELETE admin/tailwind/cache': payload(),
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: 'Clear cache' }));
	const dialog = await screen.findByRole('alertdialog');
	expect(within(dialog).getByText('Clear the Tailwind cache?')).toBeInTheDocument();
	expect(api).not.toHaveBeenCalledWith(expect.objectContaining({ method: 'DELETE' }));

	await user.click(within(dialog).getByRole('button', { name: 'Clear cache' }));

	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({ url: 'https://example.test/wp-json/proto-blocks/v1/admin/tailwind/cache', method: 'DELETE' })
	);
	expect(await screen.findByText('Cache cleared.')).toBeInTheDocument();
});

test('with Tailwind off only the switch and a note show', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/tailwind': payload({ enabled: false }),
		'POST admin/tailwind/settings': () => payload(),
	});
	renderScreen();

	expect(
		await screen.findByText("Turn on Tailwind to choose a compile mode and engine. Your saved settings are kept while it's off.")
	).toBeInTheDocument();
	expect(screen.queryByRole('button', { name: 'Compile now' })).not.toBeInTheDocument();

	await user.click(screen.getByRole('switch', { name: 'Enable Tailwind CSS' }));

	expect(api).toHaveBeenCalledWith(expect.objectContaining({ method: 'POST', data: { enabled: true } }));
	expect(await screen.findByText('Tailwind CSS enabled.')).toBeInTheDocument();
});

test('the theme file contents are behind a disclosure button', async () => {
	const user = userEvent.setup();
	mockApi({ 'admin/tailwind': payload() });
	renderScreen();

	const toggle = await screen.findByRole('button', { name: 'Show file contents' });
	expect(toggle).toHaveAttribute('aria-expanded', 'false');
	expect(screen.getByText('cadco/tailwind-theme.css')).toBeInTheDocument();

	await user.click(toggle);

	expect(screen.getByRole('button', { name: 'Hide file contents' })).toHaveAttribute('aria-expanded', 'true');
	expect(screen.getByText(/--color-brand: #002a5c;/)).toBeInTheDocument();
});

test('shows ScreenError when the request fails', async () => {
	mockApi({
		'admin/tailwind': () => {
			throw { code: 'internal', message: 'Internal Server Error', data: { status: 500 } };
		},
	});
	renderScreen();

	expect(await screen.findByText("Couldn't load Tailwind Settings")).toBeInTheDocument();
});

describe('lastCompiledLabel', () => {
	const now = 1_800_000_000_000;
	const ago = (seconds: number) => now / 1000 - seconds;

	test.each([
		['never', null, 'Never'],
		['5 seconds', ago(5), 'just now'],
		['59 seconds', ago(59), 'just now'],
		['3 minutes', ago(180), '3 minutes ago'],
		['2 hours', ago(7200), '2 hours ago'],
	])('%s', (_label, timestamp, expected) => {
		expect(lastCompiledLabel(timestamp, now)).toBe(expected);
	});
});
