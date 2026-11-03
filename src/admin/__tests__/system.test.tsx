import { mockApi, mockBootstrap } from './helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from '@/admin/app';

type Payload = {
	categoryName: string;
	config: { debug: boolean; cacheEnabled: boolean; exampleBlocks: boolean; exampleBlocksFromConfig: boolean };
	system: { pluginVersion: string; wpVersion: string; phpVersion: string; cacheDir: string; cacheDirWritable: boolean };
};

function payload(overrides: { categoryName?: string; config?: Partial<Payload['config']>; system?: Partial<Payload['system']> } = {}): Payload {
	return {
		categoryName: overrides.categoryName ?? 'Proto Blocks',
		config: {
			debug: false,
			cacheEnabled: true,
			exampleBlocks: false,
			exampleBlocksFromConfig: false,
			...overrides.config,
		},
		system: {
			pluginVersion: '2.14.5',
			wpVersion: '6.8.3',
			phpVersion: '8.2.27',
			cacheDir: 'wp-content/uploads/proto-blocks/cache',
			cacheDirWritable: false,
			...overrides.system,
		},
	};
}

function renderScreen() {
	const bootstrap = mockBootstrap({ screen: 'system' });
	render(<AdminApp bootstrap={bootstrap} />);
	return bootstrap;
}

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
	jest.clearAllMocks();
});

test('shows system information and a cache folder that is not writable', async () => {
	mockApi({ 'admin/system': payload() });
	renderScreen();

	expect(await screen.findByText('Plugin')).toBeInTheDocument();
	expect(screen.getByText('PHP')).toBeInTheDocument();
	expect(screen.getByText('8.2.27')).toBeInTheDocument();
	expect(screen.getByText('Not writable')).toBeInTheDocument();
	expect(screen.getByText('wp-content/uploads/proto-blocks/cache')).toBeInTheDocument();
	expect(screen.getByText('Allowed')).toBeInTheDocument();
	expect(screen.getByLabelText('Block category name')).toHaveValue('Proto Blocks');
});

test('saving the category name posts it and toasts', async () => {
	const user = userEvent.setup();
	const api = mockApi({
		'GET admin/system': payload(),
		'POST admin/system': (body: unknown) => payload({ categoryName: (body as { categoryName: string }).categoryName }),
	});
	renderScreen();

	const input = await screen.findByLabelText('Block category name');
	await user.clear(input);
	await user.type(input, 'Shop blocks');
	await user.click(screen.getByRole('button', { name: 'Save' }));

	expect(await screen.findByText('Category name saved.')).toBeInTheDocument();
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({
			url: 'https://example.test/wp-json/proto-blocks/v1/admin/system',
			method: 'POST',
			data: { categoryName: 'Shop blocks' },
		})
	);
});

test('a name over 100 characters shows an error and is not posted', async () => {
	const user = userEvent.setup();
	const api = mockApi({ 'GET admin/system': payload() });
	renderScreen();

	const input = await screen.findByLabelText('Block category name');
	await user.clear(input);
	await user.click(input);
	await user.paste('a'.repeat(101));
	await user.click(screen.getByRole('button', { name: 'Save' }));

	expect(await screen.findByText('Use 100 characters or fewer.')).toBeInTheDocument();
	expect(input).toHaveAttribute('aria-invalid', 'true');
	expect(api).not.toHaveBeenCalledWith(expect.objectContaining({ method: 'POST' }));
});

test('a failed save toasts the server message', async () => {
	const user = userEvent.setup();
	mockApi({
		'GET admin/system': payload(),
		'POST admin/system': () => {
			throw { code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: { status: 403 } };
		},
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: 'Save' }));

	expect(await screen.findByText('Sorry, you are not allowed to do that.')).toBeInTheDocument();
});

test('links the Example Blocks setting to the Blocks page when it is not set in wp-config.php', async () => {
	mockApi({ 'admin/system': payload({ config: { exampleBlocksFromConfig: false } }) });
	const bootstrap = renderScreen();

	const link = await screen.findByRole('link', { name: 'Managed from the Blocks page' });
	expect(link).toHaveAttribute('href', `${bootstrap.adminUrl}?page=proto-blocks`);
});

test('shows the wp-config.php values of the constants', async () => {
	mockApi({ 'admin/system': payload({ config: { debug: true, exampleBlocks: true, exampleBlocksFromConfig: true } }) });
	renderScreen();

	const row = (await screen.findByText('PROTO_BLOCKS_EXAMPLE_BLOCKS')).closest('[data-constant]') as HTMLElement;
	expect(within(row).getByText('true')).toBeInTheDocument();
	expect(screen.queryByRole('link', { name: 'Managed from the Blocks page' })).not.toBeInTheDocument();
	expect(within(screen.getByText('PROTO_BLOCKS_DEBUG').closest('[data-constant]') as HTMLElement).getByText('true')).toBeInTheDocument();
});

test('shows ScreenError when the request fails', async () => {
	mockApi({
		'admin/system': () => {
			throw { code: 'internal', message: 'Internal Server Error', data: { status: 500 } };
		},
	});
	renderScreen();

	expect(await screen.findByText("Couldn't load System Status")).toBeInTheDocument();
	await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('500'));
});

test('shows the read-only banner without disabling the form', async () => {
	mockApi({ 'admin/system': payload() });
	mockBootstrap({ screen: 'system', fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });
	render(<AdminApp bootstrap={window.protoBlocksAdmin!} />);

	expect(await screen.findByText('File modifications are disabled on this site')).toBeInTheDocument();
	expect(await screen.findByRole('button', { name: 'Save' })).toBeEnabled();
	expect(screen.getByText('Disallowed')).toBeInTheDocument();
});
