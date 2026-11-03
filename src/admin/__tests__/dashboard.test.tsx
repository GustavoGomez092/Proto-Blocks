import { mockApi, mockBootstrap } from './helpers';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from '@/admin/app';

function block(name: string, overrides: Record<string, unknown> = {}) {
	return {
		name,
		title: name === 'hero-banner' ? 'Hero Banner' : name,
		description: '',
		category: 'proto-blocks',
		location: `wp-content/themes/cadco/proto-blocks/${name}`,
		usesTailwind: false,
		hasJson: true,
		hasCSS: true,
		hasJS: false,
		isExample: false,
		...overrides,
	};
}

function payload(overrides: Record<string, unknown> = {}) {
	return {
		stats: { blocks: 12, cachedTemplates: 4, cacheSize: '1 MB' },
		exampleBlocks: { enabled: true, locked: false },
		demos: {
			vanilla: ['accordion', 'hero-banner'],
			tailwind: ['tl-header'],
			tailwindEnabled: true,
			installPath: 'wp-content/themes/cadco/proto-blocks',
		},
		installed: ['hero-banner'],
		conflicts: [],
		skipped: [],
		blocks: [block('hero-banner'), block('card', { isExample: true, usesTailwind: true })],
		...overrides,
	};
}

function renderScreen(bootstrap = {}) {
	const boot = mockBootstrap({ screen: 'dashboard', ...bootstrap });
	render(<AdminApp bootstrap={boot} />);
	return boot;
}

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
	jest.clearAllMocks();
});

test('renders the three stats', async () => {
	mockApi({ 'admin/dashboard': payload() });
	renderScreen();

	expect(await screen.findByText('Cache on disk')).toBeInTheDocument();
	expect(screen.getByText('12')).toBeInTheDocument();
	expect(screen.getByText('4')).toBeInTheDocument();
	expect(screen.getByText('1 MB')).toBeInTheDocument();
	expect(screen.getByText('Hero Banner')).toBeInTheDocument();
	expect(screen.getByText('proto-blocks/hero-banner')).toBeInTheDocument();
});

test('a locked example blocks switch is disabled and says why', async () => {
	mockApi({ 'admin/dashboard': payload({ exampleBlocks: { enabled: true, locked: true } }) });
	renderScreen();

	expect(await screen.findByRole('switch', { name: 'Example blocks' })).toBeDisabled();
	expect(screen.getByText('Managed from configuration')).toBeInTheDocument();
});

test('toggling the example blocks switch posts the new value', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/dashboard': payload(),
		'POST admin/example-blocks': () => payload({ exampleBlocks: { enabled: false, locked: false } }),
	});
	renderScreen();

	await user.click(await screen.findByRole('switch', { name: 'Example blocks' }));

	expect(await screen.findByText('Example blocks disabled.')).toBeInTheDocument();
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({
			url: 'https://example.test/wp-json/proto-blocks/v1/admin/example-blocks',
			method: 'POST',
			data: { enabled: false },
		})
	);
	expect(screen.getByRole('switch', { name: 'Example blocks' })).not.toBeChecked();
});

test('read-only mode shows the banner and keeps the screen working', async () => {
	mockApi({ 'admin/dashboard': payload() });
	renderScreen({ fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });

	expect(await screen.findByText('File modifications are disabled on this site')).toBeInTheDocument();
	expect(await screen.findByRole('switch', { name: 'Example blocks' })).toBeEnabled();
	expect(screen.getByRole('button', { name: 'Clear cache' })).toBeEnabled();
});

test('demo block installation is not offered in the UI', async () => {
	mockApi({ 'admin/dashboard': payload() });
	renderScreen();

	expect(await screen.findByRole('switch', { name: 'Example blocks' })).toBeInTheDocument();
	expect(screen.queryByRole('button', { name: /Install/ })).not.toBeInTheDocument();
	expect(screen.queryByRole('button', { name: /Remove demo blocks/ })).not.toBeInTheDocument();
	expect(screen.queryByText('Demo Blocks')).not.toBeInTheDocument();
});

test('an empty blocks array shows the empty state', async () => {
	mockApi({
		'admin/dashboard': payload({ stats: { blocks: 0, cachedTemplates: 0, cacheSize: '0 B' }, blocks: [] }),
	});
	renderScreen();

	expect(await screen.findByText('Your first block is a folder and two files.')).toBeInTheDocument();
	expect(screen.queryByRole('link', { name: /Install demo blocks/ })).not.toBeInTheDocument();
});

test('shows ScreenError when the request fails', async () => {
	mockApi({
		'admin/dashboard': () => {
			throw { code: 'internal', message: 'Internal Server Error', data: { status: 500 } };
		},
	});
	renderScreen();

	expect(await screen.findByText("Couldn't load Blocks")).toBeInTheDocument();
});

test('clearing the cache confirms, calls DELETE admin/cache and toasts', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/dashboard': payload(),
		'DELETE admin/cache': () => ({
			cleared: 4,
			data: payload({ stats: { blocks: 12, cachedTemplates: 0, cacheSize: '0 B' } }),
		}),
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: 'Clear cache' }));

	const dialog = await screen.findByRole('alertdialog');
	expect(within(dialog).getByText('Clear 4 cached templates?')).toBeInTheDocument();
	await user.click(within(dialog).getByRole('button', { name: 'Clear cache' }));

	expect(await screen.findByText('4 cached templates cleared.')).toBeInTheDocument();
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({
			url: 'https://example.test/wp-json/proto-blocks/v1/admin/cache',
			method: 'DELETE',
		})
	);
	expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
});

test('with no cached templates the Clear cache button is disabled', async () => {
	mockApi({ 'GET admin/dashboard': payload({ stats: { blocks: 12, cachedTemplates: 0, cacheSize: '0 B' } }) });
	renderScreen();

	expect(await screen.findByRole('button', { name: 'Clear cache' })).toBeDisabled();
});

test('the Category column shows the label the server sends, not the slug', async () => {
	mockApi({ 'GET admin/dashboard': payload({ blocks: [block('card', { category: 'QA Shop blocks' })] }) });
	renderScreen();

	expect(await screen.findByText('QA Shop blocks')).toBeInTheDocument();
});

test('the empty state turns on the example blocks', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/dashboard': payload({ exampleBlocks: { enabled: false, locked: false }, blocks: [] }),
		'POST admin/example-blocks': () => payload({ exampleBlocks: { enabled: true, locked: false } }),
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: 'Turn on examples' }));

	expect(await screen.findByText('Example blocks enabled. Reload the editor to see them.')).toBeInTheDocument();
	expect(api).toHaveBeenCalledWith(expect.objectContaining({ method: 'POST', data: { enabled: true } }));
});
