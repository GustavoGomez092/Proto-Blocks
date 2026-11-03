/**
 * Preview capture sequencer: loads each block's render document
 * (PreviewCapture::handleRender) into a hidden same-origin iframe, waits for
 * fonts, images and load-time animation to settle, rasterizes the body and
 * uploads the PNG to the `preview-capture` REST route.
 *
 * One block at a time: parallel iframe loads burn memory and clash on the
 * font cache. The rasterizer and the upload are injected so the screen can
 * lazy-load html2canvas-pro and tests can fake both.
 */
import { __ } from '@wordpress/i18n';

export interface CaptureRow {
	name: string;
	renderUrl: string;
}

export type RowStatus = 'rendering' | 'rasterizing' | 'uploading' | 'captured' | 'failed';

export interface CaptureDeps {
	iframe: HTMLIFrameElement;
	width: number;
	settleMs: number;
	rasterize: (body: HTMLElement, opts: { width: number; height: number }) => Promise<HTMLCanvasElement>;
	upload: (block: string, image: string) => Promise<{ previewUrl: string; mtime: number }>;
	/** Longest wait for the render document's load event (default 15000). */
	loadTimeoutMs?: number;
}

/** Upper bound on the iframe load. A block that never loads fails rather than stalling the queue. */
const LOAD_TIMEOUT_MS = 15000;
/** Upper bound on fonts.ready and on each image. A capture slightly early beats one that never returns. */
const ASSET_TIMEOUT_MS = 5000;

class CaptureError extends Error {}

function delay(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Resolve when `attach` calls its callback, or after `ms`, whichever comes first. */
function raceTimeout(attach: (done: () => void) => void, ms: number): Promise<void> {
	return new Promise((resolve) => {
		let settled = false;
		const finish = () => {
			if (!settled) {
				settled = true;
				clearTimeout(timer);
				resolve();
			}
		};
		const timer = setTimeout(finish, ms);
		attach(finish);
	});
}

/** Point the iframe at `url`; true once it loads, false after `timeoutMs`. */
function loadFrame(iframe: HTMLIFrameElement, url: string, timeoutMs: number): Promise<boolean> {
	return new Promise((resolve) => {
		const timer = setTimeout(() => {
			iframe.onload = null;
			resolve(false);
		}, timeoutMs);
		iframe.onload = () => {
			iframe.onload = null;
			clearTimeout(timer);
			resolve(true);
		};
		iframe.src = url;
	});
}

/** Wait for fonts and images inside the render document, then for `settleMs`. */
async function waitForReady(doc: Document, settleMs: number): Promise<void> {
	try {
		if (doc.fonts?.ready) {
			await Promise.race([doc.fonts.ready, delay(ASSET_TIMEOUT_MS)]);
		}
	} catch {
		// The font loading API can throw; a capture without settled fonts is still useful.
	}

	const images = Array.from(doc.images ?? []);
	await Promise.all(
		images.map((img) => {
			if (img.complete && img.naturalWidth > 0) {
				return null;
			}
			// `<img src="">` (an image field nobody filled in) fires neither
			// load nor error; waiting on it would hang the whole queue.
			if ((img.getAttribute('src') ?? '').trim() === '') {
				return null;
			}
			return raceTimeout((done) => {
				img.addEventListener('load', done, { once: true });
				img.addEventListener('error', done, { once: true });
			}, ASSET_TIMEOUT_MS);
		})
	);

	// Load-time animation (reveals, counters, a hero zoom) must reach its
	// final frame. Length from the proto_blocks_capture_settle_ms filter.
	await delay(Number.isFinite(settleMs) && settleMs > 0 ? settleMs : 0);
}

/**
 * Capture one block. Reports each step through `onStatus`; on failure it
 * reports `failed` with the message and rejects with the same message.
 */
export async function captureOne(
	row: CaptureRow,
	deps: CaptureDeps,
	onStatus: (s: RowStatus, message?: string) => void
): Promise<{ previewUrl: string; mtime: number }> {
	const fail = (message: string): never => {
		onStatus('failed', message);
		throw new CaptureError(message);
	};

	if (!row.renderUrl) {
		return fail(__('Missing render URL.', 'proto-blocks'));
	}

	const { iframe, width } = deps;
	onStatus('rendering');

	// Start every block from a short frame: the previous block's height
	// would otherwise become this document's viewport, and a theme's
	// `min-height: 100vh` would leave a band of empty page under the block.
	iframe.style.height = '10px';
	const loaded = await loadFrame(iframe, row.renderUrl, deps.loadTimeoutMs ?? LOAD_TIMEOUT_MS);
	const doc = loaded ? iframe.contentDocument : null;
	if (!doc || !doc.body) {
		return fail(__('Iframe failed to load.', 'proto-blocks'));
	}

	await waitForReady(doc, deps.settleMs);

	// Size the frame to the block's natural height so nothing is clipped.
	const height = Math.max(
		doc.body.scrollHeight || 0,
		doc.documentElement?.scrollHeight || 0,
		doc.body.offsetHeight || 0,
		doc.documentElement?.offsetHeight || 0
	);
	iframe.style.height = `${height}px`;

	onStatus('rasterizing');
	let image: string;
	try {
		const canvas = await deps.rasterize(doc.body, { width, height });
		image = canvas.toDataURL('image/png');
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error(`Rasterize failed for ${row.name}`, error);
		return fail(__('Rasterize failed.', 'proto-blocks'));
	}

	onStatus('uploading');
	let result: { previewUrl: string; mtime: number };
	try {
		result = await deps.upload(row.name, image);
	} catch (error) {
		const message = error instanceof Error && error.message !== '' ? error.message : '';
		return fail(message || __('Upload failed.', 'proto-blocks'));
	}

	onStatus('captured');
	return result;
}

/**
 * Capture `rows` in order. A failed row is reported through `onRow` and
 * the batch moves on. `onProgress(done, total)` runs before each row and
 * once at the end.
 */
export async function captureMany(
	rows: CaptureRow[],
	deps: CaptureDeps,
	onRow: (name: string, s: RowStatus, message?: string) => void,
	onProgress: (done: number, total: number) => void
): Promise<{ captured: number; failed: number }> {
	let captured = 0;
	let failed = 0;

	for (let i = 0; i < rows.length; i++) {
		onProgress(i, rows.length);
		const row = rows[i];
		try {
			await captureOne(row, deps, (s, message) => onRow(row.name, s, message));
			captured++;
		} catch (error) {
			if (!(error instanceof CaptureError)) {
				onRow(row.name, 'failed', error instanceof Error ? error.message : String(error));
			}
			failed++;
		}
	}

	onProgress(rows.length, rows.length);
	return { captured, failed };
}
