import { mockApi, mockBootstrap } from './helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
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

test('the progress indicator has three steps: Welcome, Style, Finish', async () => {
	mockApi({ 'admin/wizard': payload() });
	renderScreen();

	const progress = await screen.findByRole('list', { name: 'Setup progress' });
	const steps = within(progress).getAllByRole('listitem');
	expect(steps.map((step) => step.textContent)).toEqual(['1Welcome', '2Style', '3Finish']);
	expect(steps[0]).toHaveAttribute('aria-current', 'step');
});

test('choosing Tailwind saves the style and goes straight to Finish', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/wizard': payload(),
		'POST admin/wizard/style': (body: unknown) =>
			payload({
				style: (body as { style: string }).style,
				categoryName: (body as { categoryName: string }).categoryName,
				tailwindEnabled: true,
			}),
	});
	renderScreen();
	await goToStyleStep(user);

	await user.click(screen.getByRole('radio', { name: /Tailwind CSS/ }));
	await user.type(screen.getByLabelText(/Block category name/), 'Shop');
	await user.click(screen.getByRole('button', { name: /Continue/ }));

	expect(await screen.findByRole('heading', { name: 'Proto Blocks is ready' })).toBeInTheDocument();
	const [options] = callsTo(api, 'admin/wizard/style')[0];
	expect(options).toMatchObject({
		url: 'https://example.test/wp-json/proto-blocks/v1/admin/wizard/style',
		method: 'POST',
		data: { style: 'tailwind', categoryName: 'Shop' },
	});
	expect(screen.getByText('Tailwind CSS', { selector: 'dd' })).toBeInTheDocument();
	expect(screen.getByText('Shop', { selector: 'dd' })).toBeInTheDocument();
	expect(screen.getByText('Tailwind Integration')).toBeInTheDocument();
	expect(screen.getByText('Enabled')).toBeInTheDocument();
});

test('Finish summarises the choices without an example-blocks row or install controls', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/wizard': payload(),
		'POST admin/wizard/style': payload(),
	});
	renderScreen();
	await goToStyleStep(user);
	await user.click(screen.getByRole('button', { name: /Continue/ }));

	expect(await screen.findByRole('heading', { name: 'Proto Blocks is ready' })).toBeInTheDocument();
	expect(screen.getByText('Styling Approach')).toBeInTheDocument();
	expect(screen.getByText('Vanilla CSS', { selector: 'dd' })).toBeInTheDocument();
	expect(screen.getByText('Category Name')).toBeInTheDocument();
	expect(screen.getByText('Proto Blocks', { selector: 'dd' })).toBeInTheDocument();
	expect(screen.queryByText('Example Blocks Installed')).not.toBeInTheDocument();
	expect(screen.queryByText('Tailwind Integration')).not.toBeInTheDocument();
	expect(screen.queryByRole('button', { name: 'Skip for now' })).not.toBeInTheDocument();
	expect(screen.queryByRole('button', { name: /Install Example Blocks/ })).not.toBeInTheDocument();
	expect(callsTo(api, 'admin/wizard/demos')).toHaveLength(0);
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
	await user.click(await screen.findByRole('button', { name: /Go to Dashboard/ }));

	await waitFor(() => expect(navigate).toHaveBeenCalledWith('https://example.test/wp-admin/admin.php?page=proto-blocks'));
	expect(callsTo(api, 'admin/wizard/complete')).toHaveLength(1);
});

test('a load failure shows the screen error', async () => {
	mockApi({});
	renderScreen();

	expect(await screen.findByText("Couldn't load Setup Wizard")).toBeInTheDocument();
});
