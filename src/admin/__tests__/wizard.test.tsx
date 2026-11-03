import { mockApi, mockBootstrap } from './helpers';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from '@/admin/app';
import { navigate } from '@/admin/lib/navigate';

jest.mock('@/admin/lib/navigate', () => ({ navigate: jest.fn() }));

function payload(overrides: Record<string, unknown> = {}) {
	return {
		style: 'vanilla',
		categoryName: '',
		demos: { vanilla: ['Accordion', 'Card', 'Hero'], tailwind: ['tl-hero', 'tl-footer'] },
		installPath: 'wp-content/themes/cadco/proto-blocks/',
		tailwindEnabled: false,
		completed: false,
		...overrides,
	};
}

function renderScreen(overrides: Parameters<typeof mockBootstrap>[0] = {}) {
	const bootstrap = mockBootstrap({ screen: 'wizard', ...overrides });
	render(<AdminApp bootstrap={bootstrap} />);
	return bootstrap;
}

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
	jest.clearAllMocks();
});

function callsTo(api: jest.Mock, url: string) {
	return api.mock.calls.filter(([options]) => (options as { url: string }).url.endsWith(url));
}

async function goToStyleStep(user: ReturnType<typeof userEvent.setup>) {
	await screen.findByRole('heading', { name: 'Welcome to Proto Blocks' });
	await user.click(screen.getByRole('button', { name: /Get Started/ }));
	await screen.findByRole('heading', { name: 'Choose your style' });
}

test('starts on Welcome and Get Started moves to the style step', async () => {
	const user = userEvent.setup();
	mockApi({ 'admin/wizard': payload() });
	renderScreen();

	expect(await screen.findByRole('heading', { name: 'Welcome to Proto Blocks' })).toBeInTheDocument();
	expect(screen.getByRole('list', { name: 'Setup progress' })).toBeInTheDocument();

	await user.click(screen.getByRole('button', { name: /Get Started/ }));

	expect(await screen.findByRole('heading', { name: 'Choose your style' })).toBeInTheDocument();
});

test('choosing Tailwind saves the style and lists the tailwind demos', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/wizard': payload(),
		'POST admin/wizard/style': (body: unknown) =>
			payload({ style: (body as { style: string }).style, categoryName: (body as { categoryName: string }).categoryName }),
	});
	renderScreen();
	await goToStyleStep(user);

	await user.click(screen.getByRole('radio', { name: /Tailwind CSS/ }));
	await user.type(screen.getByLabelText(/Block category name/), 'Shop');
	await user.click(screen.getByRole('button', { name: /Continue/ }));

	expect(await screen.findByRole('heading', { name: 'Install example blocks?' })).toBeInTheDocument();
	const [options] = callsTo(api, 'admin/wizard/style')[0];
	expect(options).toMatchObject({
		url: 'https://example.test/wp-json/proto-blocks/v1/admin/wizard/style',
		method: 'POST',
		data: { style: 'tailwind', categoryName: 'Shop' },
	});
	expect(screen.getByText('tl-hero')).toBeInTheDocument();
	expect(screen.getByText('tl-footer')).toBeInTheDocument();
	expect(screen.queryByText('Accordion')).not.toBeInTheDocument();
});

test('Skip for now reaches Finish with the examples skipped', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/wizard': payload(),
		'POST admin/wizard/style': payload(),
	});
	renderScreen();
	await goToStyleStep(user);
	await user.click(screen.getByRole('button', { name: /Continue/ }));
	await user.click(await screen.findByRole('button', { name: 'Skip for now' }));

	expect(await screen.findByRole('heading', { name: 'Proto Blocks is ready' })).toBeInTheDocument();
	expect(screen.getByText('Styling Approach')).toBeInTheDocument();
	expect(screen.getByText('Vanilla CSS', { selector: 'dd' })).toBeInTheDocument();
	expect(screen.getByText('Category Name')).toBeInTheDocument();
	expect(screen.getByText('Proto Blocks', { selector: 'dd' })).toBeInTheDocument();
	expect(screen.getByText('Example Blocks Installed')).toBeInTheDocument();
	expect(screen.getByText('Skipped')).toBeInTheDocument();
	expect(screen.queryByText('Tailwind Integration')).not.toBeInTheDocument();
	expect(callsTo(api, 'admin/wizard/demos')).toHaveLength(0);
});

test('Install Example Blocks posts, then shows the count and skipped names', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/wizard': payload(),
		'POST admin/wizard/style': payload({ style: 'tailwind', categoryName: 'Shop', tailwindEnabled: true }),
		'POST admin/wizard/demos': { installed: 2, skipped: ['tl-hero'] },
	});
	renderScreen();
	await goToStyleStep(user);
	await user.click(screen.getByRole('radio', { name: /Tailwind CSS/ }));
	await user.click(screen.getByRole('button', { name: /Continue/ }));
	await user.click(await screen.findByRole('button', { name: /Install Example Blocks/ }));

	expect(await screen.findByRole('heading', { name: 'Proto Blocks is ready' })).toBeInTheDocument();
	expect(callsTo(api, 'admin/wizard/demos')[0][0]).toMatchObject({ method: 'POST' });
	expect(screen.getByText('2', { selector: 'dd span' })).toBeInTheDocument();
	expect(screen.getByText('Shop', { selector: 'dd' })).toBeInTheDocument();
	expect(screen.getByText('Tailwind Integration')).toBeInTheDocument();
	expect(screen.getByText('Enabled')).toBeInTheDocument();
	const skipped = screen.getByText('tl-hero').closest('[data-slot="alert"]');
	expect(skipped).toHaveTextContent('1 block skipped');
});

test('a failed install lands on Finish with the error', async () => {
	const user = userEvent.setup();
	mockApi({
		'GET admin/wizard': payload(),
		'POST admin/wizard/style': payload(),
		'POST admin/wizard/demos': () => {
			throw { code: 'proto_blocks_copy_failed', message: 'Directory is not writable', data: { status: 500 } };
		},
	});
	renderScreen();
	await goToStyleStep(user);
	await user.click(screen.getByRole('button', { name: /Continue/ }));
	await user.click(await screen.findByRole('button', { name: /Install Example Blocks/ }));

	expect(await screen.findByRole('heading', { name: 'Proto Blocks is ready' })).toBeInTheDocument();
	expect(screen.getByText("Some blocks couldn't be installed")).toBeInTheDocument();
	expect(screen.getByText('Directory is not writable')).toBeInTheDocument();
	expect(screen.getByText('Skipped')).toBeInTheDocument();
});

test('with file modifications disabled the banner shows and Install is disabled', async () => {
	const user = userEvent.setup();
	mockApi({ 'GET admin/wizard': payload(), 'POST admin/wizard/style': payload() });
	renderScreen({ fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });
	await goToStyleStep(user);
	await user.click(screen.getByRole('button', { name: /Continue/ }));

	expect(await screen.findByText('File modifications are disabled on this site')).toBeInTheDocument();
	expect(screen.getByRole('button', { name: /Install Example Blocks/ })).toBeDisabled();
	expect(screen.getByRole('button', { name: 'Skip for now' })).toBeEnabled();
});

test('Back returns to the previous step', async () => {
	const user = userEvent.setup();
	mockApi({ 'GET admin/wizard': payload() });
	renderScreen();
	await goToStyleStep(user);

	await user.click(screen.getByRole('button', { name: /Back/ }));

	expect(await screen.findByRole('heading', { name: 'Welcome to Proto Blocks' })).toBeInTheDocument();
});

test('Go to Dashboard completes the wizard and follows the redirect', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/wizard': payload(),
		'POST admin/wizard/style': payload(),
		'POST admin/wizard/complete': { redirect: 'https://example.test/wp-admin/admin.php?page=proto-blocks' },
	});
	renderScreen();
	await goToStyleStep(user);
	await user.click(screen.getByRole('button', { name: /Continue/ }));
	await user.click(await screen.findByRole('button', { name: 'Skip for now' }));
	await user.click(await screen.findByRole('button', { name: /Go to Dashboard/ }));

	await waitFor(() => expect(navigate).toHaveBeenCalledWith('https://example.test/wp-admin/admin.php?page=proto-blocks'));
	expect(callsTo(api, 'admin/wizard/complete')).toHaveLength(1);
});

test('a load failure shows the screen error', async () => {
	mockApi({});
	renderScreen();

	expect(await screen.findByText("Couldn't load Setup Wizard")).toBeInTheDocument();
});
