import { captureMany, captureOne, type CaptureDeps, type RowStatus } from '@/admin/lib/capture';

type FakeFrame = {
	src: string;
	srcSets: string[];
	onload: (() => void) | null;
	style: Record<string, string>;
	contentDocument: unknown;
};

/** A stand-in iframe: setting src fires onload on a microtask (unless `loads` is false). */
function fakeIframe({ loads = true }: { loads?: boolean } = {}): FakeFrame {
	const body = { scrollHeight: 420, offsetHeight: 400 };
	const frame: FakeFrame = {
		srcSets: [],
		onload: null,
		style: {},
		contentDocument: {
			body,
			documentElement: { scrollHeight: 400, offsetHeight: 400 },
			fonts: { ready: Promise.resolve() },
			images: [],
		},
		get src() {
			return this.srcSets[this.srcSets.length - 1] ?? '';
		},
		set src(value: string) {
			this.srcSets.push(value);
			if (loads) {
				queueMicrotask(() => this.onload?.());
			}
		},
	};
	return frame;
}

function canvas(): HTMLCanvasElement {
	return { toDataURL: () => 'data:image/png;base64,AAAA' } as unknown as HTMLCanvasElement;
}

function deps(overrides: Partial<CaptureDeps> = {}, frame = fakeIframe()): CaptureDeps {
	return {
		iframe: frame as unknown as HTMLIFrameElement,
		width: 1280,
		settleMs: 0,
		rasterize: jest.fn(async () => canvas()),
		upload: jest.fn(async (block: string) => ({ previewUrl: `https://example.test/${block}/preview.png`, mtime: 1 })),
		...overrides,
	};
}

const rows = [
	{ name: 'hero', renderUrl: 'https://example.test/render?block=hero' },
	{ name: 'cta', renderUrl: 'https://example.test/render?block=cta' },
];

afterEach(() => {
	jest.useRealTimers();
	jest.restoreAllMocks();
});

test('captureMany runs rows one after another', async () => {
	const events: string[] = [];
	const d = deps({
		rasterize: jest.fn(async () => {
			events.push('rasterize');
			return canvas();
		}),
		upload: jest.fn(async (block: string) => {
			events.push(`upload:${block}:start`);
			await new Promise((resolve) => setTimeout(resolve, 5));
			events.push(`upload:${block}:done`);
			return { previewUrl: '', mtime: 1 };
		}),
	});

	const result = await captureMany(rows, d, () => {}, () => {});

	expect(result).toEqual({ captured: 2, failed: 0 });
	expect(events).toEqual([
		'rasterize',
		'upload:hero:start',
		'upload:hero:done',
		'rasterize',
		'upload:cta:start',
		'upload:cta:done',
	]);
	expect(d.rasterize).toHaveBeenCalledWith(expect.anything(), { width: 1280, height: 420 });
	expect(d.upload).toHaveBeenNthCalledWith(1, 'hero', 'data:image/png;base64,AAAA');
});

test('a rejected upload fails that row with its message and the batch continues', async () => {
	const d = deps({
		upload: jest.fn(async (block: string) => {
			if (block === 'hero') {
				throw new Error('Upload rejected: too large');
			}
			return { previewUrl: '', mtime: 1 };
		}),
	});
	const onRow = jest.fn();
	const onProgress = jest.fn();

	const result = await captureMany(rows, d, onRow, onProgress);

	expect(result).toEqual({ captured: 1, failed: 1 });
	expect(onRow).toHaveBeenCalledWith('hero', 'failed', 'Upload rejected: too large');
	expect(onRow).toHaveBeenCalledWith('cta', 'captured', undefined);
	expect(onProgress).toHaveBeenLastCalledWith(2, 2);
});

test('an upload that fails without a message reports Upload failed.', async () => {
	const onStatus = jest.fn();
	await expect(
		captureOne(rows[0], deps({ upload: jest.fn(async () => Promise.reject(new Error(''))) }), onStatus)
	).rejects.toThrow('Upload failed.');
	expect(onStatus).toHaveBeenLastCalledWith('failed', 'Upload failed.');
});

test('a row without a render URL fails before touching the iframe', async () => {
	const frame = fakeIframe();
	const onStatus = jest.fn();

	await expect(captureOne({ name: 'hero', renderUrl: '' }, deps({}, frame), onStatus)).rejects.toThrow(
		'Missing render URL.'
	);

	expect(onStatus.mock.calls).toEqual([['failed', 'Missing render URL.']]);
	expect(frame.srcSets).toEqual([]);
});

test('an iframe that never loads fails after loadTimeoutMs', async () => {
	jest.useFakeTimers();
	const d = deps({ loadTimeoutMs: 10 }, fakeIframe({ loads: false }));
	const onStatus = jest.fn();

	const promise = captureOne(rows[0], d, onStatus);
	const assertion = expect(promise).rejects.toThrow('Iframe failed to load.');
	await jest.advanceTimersByTimeAsync(10);
	await assertion;

	expect(onStatus).toHaveBeenLastCalledWith('failed', 'Iframe failed to load.');
	expect(d.rasterize).not.toHaveBeenCalled();
});

test('a rasterizer error fails the row with Rasterize failed.', async () => {
	const onStatus = jest.fn();
	const d = deps({ rasterize: jest.fn(async () => Promise.reject(new Error('boom'))) });
	jest.spyOn(console, 'error').mockImplementation(() => {});

	await expect(captureOne(rows[0], d, onStatus)).rejects.toThrow('Rasterize failed.');
	expect(onStatus).toHaveBeenLastCalledWith('failed', 'Rasterize failed.');
	expect(d.upload).not.toHaveBeenCalled();
});

test('onStatus reports rendering, rasterizing, uploading, captured in order', async () => {
	const statuses: RowStatus[] = [];
	const result = await captureOne(rows[0], deps(), (s) => statuses.push(s));

	expect(statuses).toEqual(['rendering', 'rasterizing', 'uploading', 'captured']);
	expect(result).toEqual({ previewUrl: 'https://example.test/hero/preview.png', mtime: 1 });
});
