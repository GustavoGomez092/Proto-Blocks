import { mockApi, mockBootstrap } from './helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import html2canvas from 'html2canvas-pro';
import { AdminApp } from '@/admin/app';

jest.mock('html2canvas-pro', () => ({ __esModule: true, default: jest.fn() }));

const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';

function payload() {
	return {
		blocks: [
			{
				name: 'hero',
				title: 'Hero Banner',
				hasPreview: true,
				previewUrl: 'https://example.test/wp-content/themes/t/blocks/hero/preview.png',
				previewMtime: 100,
				renderUrl: 'https://example.test/wp-admin/admin-ajax.php?action=proto_blocks_capture_render&block=hero',
			},
			{
				name: 'cta',
				title: 'Call To Action',
				hasPreview: false,
				previewUrl: null,
				previewMtime: null,
				renderUrl: 'https://example.test/wp-admin/admin-ajax.php?action=proto_blocks_capture_render&block=cta',
			},
		],
		width: 1280,
		settleMs: 0,
	};
}

function routes() {
	return {
		'admin/previews': payload(),
		'POST preview-capture': (body: unknown) => {
			const { block } = body as { block: string };
			return {
				success: true,
				block,
				previewUrl: `https://example.test/wp-content/themes/t/blocks/${block}/preview.png`,
				mtime: 200,
			};
		},
	};
}

function renderScreen(overrides: Parameters<typeof mockBootstrap>[0] = {}) {
	const bootstrap = mockBootstrap({ screen: 'previews', ...overrides });
	render(<AdminApp bootstrap={bootstrap} />);
	return bootstrap;
}

function uploads(api: jest.Mock) {
	return api.mock.calls.map(([options]) => options).filter((options) => options.url === `${ROOT}preview-capture`);
}

// jsdom does not load frame URLs, so a src change never fires load. Record
// the URL and fire onload on a microtask; the frame keeps its about:blank
// document, whose body is what the (mocked) rasterizer receives.
const realSrc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src');
const frameUrls: string[] = [];

beforeAll(() => {
	Object.defineProperty(HTMLIFrameElement.prototype, 'src', {
		configurable: true,
		get() {
			return frameUrls[frameUrls.length - 1] ?? '';
		},
		set(this: HTMLIFrameElement, value: string) {
			frameUrls.push(value);
			queueMicrotask(() => this.onload?.(new Event('load')));
		},
	});
});

afterAll(() => {
	if (realSrc) {
		Object.defineProperty(HTMLIFrameElement.prototype, 'src', realSrc);
	}
});

beforeEach(() => {
	frameUrls.length = 0;
	(html2canvas as unknown as jest.Mock).mockResolvedValue({ toDataURL: () => 'data:image/png;base64,AAAA' });
});

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
	jest.clearAllMocks();
});

test('lists every block with a Present or Missing badge', async () => {
	mockApi(routes());
	renderScreen();

	const hero = await screen.findByRole('row', { name: /Hero Banner/ });
	const cta = screen.getByRole('row', { name: /Call To Action/ });

	expect(within(hero).getByText('Present')).toBeInTheDocument();
	expect(within(hero).getByText('hero')).toBeInTheDocument();
	expect(within(cta).getByText('Missing')).toBeInTheDocument();
	expect(within(cta).getByText('No preview')).toBeInTheDocument();
});

test('the capture frame is sandboxed and sends no referrer', async () => {
	mockApi(routes());
	renderScreen();
	await screen.findByRole('row', { name: /Hero Banner/ });

	const frame = document.querySelector('iframe');
	expect(frame).not.toBeNull();
	expect(frame).toHaveAttribute('sandbox', 'allow-same-origin allow-scripts');
	expect(frame).toHaveAttribute('referrerpolicy', 'no-referrer');
	expect(frame).toHaveAttribute('aria-hidden', 'true');
});

test('Capture missing captures only the missing row', async () => {
	const user = userEvent.setup();
	const api = mockApi(routes());
	renderScreen();

	await user.click(await screen.findByRole('button', { name: /Capture missing/ }));

	expect(await screen.findByText('Done. 1 captured, 0 failed.')).toBeInTheDocument();

	expect(frameUrls).toEqual([payload().blocks[1].renderUrl]);
	expect(html2canvas).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ width: 1280, scale: 1 }));

	const sent = uploads(api);
	expect(sent).toHaveLength(1);
	expect(sent[0]).toEqual(
		expect.objectContaining({
			method: 'POST',
			headers: { 'X-WP-Nonce': 'nonce-wp_rest' },
			data: { block: 'cta', image: 'data:image/png;base64,AAAA' },
		})
	);

	const cta = screen.getByRole('row', { name: /Call To Action/ });
	expect(within(cta).getByText('Present')).toBeInTheDocument();
	expect(within(cta).getByText('Captured just now')).toBeInTheDocument();
	expect(within(cta).getByRole('img')).toHaveAttribute(
		'src',
		'https://example.test/wp-content/themes/t/blocks/cta/preview.png?v=200'
	);
	expect(screen.getByRole('button', { name: /Capture missing/ })).toBeDisabled();
});

test('a rejected upload shows the server message on the row', async () => {
	const user = userEvent.setup();
	mockApi({
		...routes(),
		'POST preview-capture': () => {
			throw { code: 'proto_blocks_capture_too_large', message: 'Image too large.', data: { status: 413 } };
		},
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: /Capture missing/ }));

	expect(await screen.findByText('Done. 0 captured, 1 failed.')).toBeInTheDocument();
	const cta = screen.getByRole('row', { name: /Call To Action/ });
	expect(within(cta).getByText('Image too large.')).toBeInTheDocument();
	expect(within(cta).getByText('Missing')).toBeInTheDocument();
	expect(within(cta).getByRole('button', { name: 'Retry' })).toBeEnabled();
});

test('with file modifications off every capture button is disabled and the banner shows', async () => {
	mockApi(routes());
	renderScreen({ fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });

	await screen.findByRole('row', { name: /Hero Banner/ });

	expect(screen.getByText('File modifications are disabled on this site')).toBeInTheDocument();
	const buttons = screen.getAllByRole('button', { name: /Capture|Recapture/ });
	expect(buttons.length).toBeGreaterThanOrEqual(5);
	for (const button of buttons) {
		expect(button).toBeDisabled();
	}
});

test('select all checks every row and Capture selected captures them', async () => {
	const user = userEvent.setup();
	const api = mockApi(routes());
	renderScreen();

	const selected = await screen.findByRole('button', { name: /Capture selected/ });
	expect(selected).toBeDisabled();

	await user.click(screen.getByRole('checkbox', { name: 'Select all' }));

	expect(screen.getByRole('checkbox', { name: 'Select Hero Banner' })).toBeChecked();
	expect(screen.getByRole('checkbox', { name: 'Select Call To Action' })).toBeChecked();

	await user.click(screen.getByRole('button', { name: /Capture selected/ }));

	expect(await screen.findByText('Done. 2 captured, 0 failed.')).toBeInTheDocument();
	expect(uploads(api).map((options) => (options.data as { block: string }).block)).toEqual(['hero', 'cta']);
});

test('a load error shows ScreenError', async () => {
	mockApi({});
	renderScreen();

	expect(await screen.findByText("Couldn't load Preview Capture")).toBeInTheDocument();
});

test('no blocks shows the empty state', async () => {
	mockApi({ 'admin/previews': { blocks: [], width: 1280, settleMs: 0 } });
	renderScreen();

	expect(await screen.findByText('No blocks to capture')).toBeInTheDocument();
	expect(screen.getByRole('link', { name: 'Go to Blocks' })).toHaveAttribute(
		'href',
		'https://example.test/wp-admin/admin.php?page=proto-blocks'
	);
	await waitFor(() => expect(screen.queryByRole('row')).toBeNull());
});
