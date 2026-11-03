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

	expect(await screen.findByText('Cache Size')).toBeInTheDocument();
	expect(screen.getByText('12')).toBeInTheDocument();
	expect(screen.getByText('4')).toBeInTheDocument();
	expect(screen.getByText('1 MB')).toBeInTheDocument();
	expect(screen.getByText('Hero Banner')).toBeInTheDocument();
	expect(screen.getByText('proto-blocks/hero-banner')).toBeInTheDocument();
});

test('a locked example blocks switch is disabled and says why', async () => {
	mockApi({ 'admin/dashboard': payload({ exampleBlocks: { enabled: true, locked: true } }) });
	renderScreen();

	expect(await screen.findByRole('switch', { name: 'Example Blocks' })).toBeDisabled();
	expect(screen.getByText('Managed from configuration')).toBeInTheDocument();
});

test('toggling the example blocks switch posts the new value', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/dashboard': payload(),
		'POST admin/example-blocks': () => payload({ exampleBlocks: { enabled: false, locked: false } }),
	});
	renderScreen();

	await user.click(await screen.findByRole('switch', { name: 'Example Blocks' }));

	expect(await screen.findByText('Example blocks disabled.')).toBeInTheDocument();
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({
			url: 'https://example.test/wp-json/proto-blocks/v1/admin/example-blocks',
			method: 'POST',
			data: { enabled: false },
		})
	);
	expect(screen.getByRole('switch', { name: 'Example Blocks' })).not.toBeChecked();
});

test('Tailwind demos are disabled with a link to settings when Tailwind is off', async () => {
	const base = payload();
	mockApi({ 'admin/dashboard': payload({ demos: { ...base.demos, tailwindEnabled: false } }) });
	const boot = renderScreen();

	expect(await screen.findByRole('button', { name: /Install Tailwind blocks/ })).toBeDisabled();
	expect(screen.getByText('Requires Tailwind')).toBeInTheDocument();
	expect(screen.getByRole('link', { name: 'Turn on Tailwind' })).toHaveAttribute(
		'href',
		`${boot.adminUrl}?page=proto-blocks-settings`
	);
	expect(screen.getByRole('button', { name: /Install Vanilla CSS blocks/ })).toBeEnabled();
});

test('read-only mode shows the banner and disables install and remove', async () => {
	mockApi({ 'admin/dashboard': payload() });
	renderScreen({ fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });

	expect(await screen.findByText('File modifications are disabled on this site')).toBeInTheDocument();
	expect(await screen.findByRole('button', { name: /Install Vanilla CSS blocks/ })).toBeDisabled();
	expect(screen.getByRole('button', { name: /Install Tailwind blocks/ })).toBeDisabled();
	expect(screen.getByRole('button', { name: /Remove demo blocks/ })).toBeDisabled();
	expect(screen.getByRole('switch', { name: 'Example Blocks' })).toBeEnabled();
});

test('installing the vanilla demos posts the type and toasts the count', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/dashboard': payload(),
		'POST admin/demos/install': () => ({ installed: 2, skipped: [], data: payload() }),
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: /Install Vanilla CSS blocks/ }));

	expect(await screen.findByText('2 Vanilla CSS demo blocks installed.')).toBeInTheDocument();
	expect(api).toHaveBeenCalledWith(expect.objectContaining({ method: 'POST', data: { type: 'vanilla' } }));
});

test('removing demo blocks confirms with the names, then posts and toasts', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/dashboard': payload({ conflicts: ['card'] }),
		'POST admin/demos/remove': () => ({
			removed: 1,
			skipped: [],
			data: payload({ installed: [], conflicts: [] }),
		}),
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: /Remove demo blocks/ }));

	const dialog = await screen.findByRole('alertdialog');
	expect(within(dialog).getByText('hero-banner')).toBeInTheDocument();
	expect(within(dialog).getByText('Kept (modified)')).toBeInTheDocument();
	expect(within(dialog).getByText('card')).toBeInTheDocument();

	await user.click(within(dialog).getByRole('button', { name: 'Remove 1 block' }));

	expect(await screen.findByText('1 demo block removed.')).toBeInTheDocument();
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({
			url: 'https://example.test/wp-json/proto-blocks/v1/admin/demos/remove',
			method: 'POST',
		})
	);
	expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
});

test('an empty blocks array shows the empty state', async () => {
	mockApi({
		'admin/dashboard': payload({ stats: { blocks: 0, cachedTemplates: 0, cacheSize: '0 B' }, blocks: [] }),
	});
	renderScreen();

	expect(await screen.findByText('No blocks found')).toBeInTheDocument();
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
