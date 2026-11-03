import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	CameraIcon,
	CircleCheckIcon,
	CircleXIcon,
	ImageIcon,
	ImageOffIcon,
	ListChecksIcon,
	LoaderCircleIcon,
	RefreshCwIcon,
	RotateCwIcon,
} from 'lucide-react';
import { adminGet, adminPost, ApiError } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { READ_ONLY_BANNER_ID, ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { Panel } from '@/admin/components/shell/Panel';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { cn } from '@/admin/lib/utils';
import { interpolate } from '@/admin/lib/interpolate';
import { captureMany, type CaptureDeps, type CaptureRow, type RowStatus } from '@/admin/lib/capture';

/** One block in GET admin/previews (includes/API/Admin/PreviewsRoutes.php). */
export interface PreviewBlock {
	name: string;
	title: string;
	hasPreview: boolean;
	/** '' or null when the block folder has no public URL. */
	previewUrl: string | null;
	previewMtime: number | null;
	renderUrl: string;
}

export interface PreviewsData {
	blocks: PreviewBlock[];
	width: number;
	settleMs: number;
}

/** A row's live line: the sequencer's status, or queued while waiting its turn. */
type Live = { status: RowStatus | 'queued'; message?: string };

type RunState =
	| { phase: 'idle' }
	| { phase: 'running'; done: number; total: number }
	| { phase: 'finished'; captured: number; failed: number }
	| { phase: 'empty' };

type LoadState = { status: 'loading' } | { status: 'error'; error: unknown } | { status: 'ready'; data: PreviewsData };

const code = (text: ReactNode) => <code className="font-mono text-caption">{text}</code>;

type Html2Canvas = typeof import('html2canvas-pro').default;
let html2canvasModule: Promise<Html2Canvas> | null = null;

/** html2canvas-pro is a lazy chunk, fetched on the first capture only. */
function loadHtml2Canvas(): Promise<Html2Canvas> {
	html2canvasModule ??= import(/* webpackChunkName: "html2canvas" */ 'html2canvas-pro').then(
		(module) => module.default
	);
	return html2canvasModule;
}

async function rasterize(body: HTMLElement, { width, height }: { width: number; height: number }) {
	const html2canvas = await loadHtml2Canvas();
	return html2canvas(body, {
		backgroundColor: '#ffffff',
		useCORS: true,
		allowTaint: true,
		logging: false,
		width,
		height,
		windowWidth: width,
		windowHeight: height,
		scale: 1,
	});
}

/** POST to the existing preview-capture route; REST errors keep their message. */
async function upload(block: string, image: string): Promise<{ previewUrl: string; mtime: number }> {
	try {
		const result = await adminPost<{ previewUrl?: unknown; mtime?: unknown }>('preview-capture', { block, image });
		return {
			previewUrl: typeof result?.previewUrl === 'string' ? result.previewUrl : '',
			mtime: Number(result?.mtime) || Date.now(),
		};
	} catch (error) {
		if (error instanceof ApiError && error.status > 0) {
			throw error;
		}
		throw new Error(__('Upload failed.', 'proto-blocks'));
	}
}

function liveText(live: Live): string {
	switch (live.status) {
		case 'queued':
			return __('Queued', 'proto-blocks');
		case 'rendering':
			return __('Rendering…', 'proto-blocks');
		case 'rasterizing':
			return __('Rasterizing…', 'proto-blocks');
		case 'uploading':
			return __('Uploading…', 'proto-blocks');
		case 'captured':
			return __('Captured just now', 'proto-blocks');
		case 'failed':
			return live.message ?? __('Capture failed.', 'proto-blocks');
	}
}

const BUSY: Live['status'][] = ['rendering', 'rasterizing', 'uploading'];

/** Card media: the preview, a rasterizing overlay, or the empty "no preview" state. 16:10. */
function Thumbnail({ block, busy }: { block: PreviewBlock; busy: boolean }) {
	if (busy) {
		return (
			<div className="absolute inset-0 grid place-items-center bg-code">
				<span className="grid justify-items-center gap-2 text-primary">
					<LoaderCircleIcon aria-hidden="true" className="size-5 animate-spin motion-reduce:animate-none" />
					<span className="text-micro font-medium">{__('Rasterizing…', 'proto-blocks')}</span>
				</span>
			</div>
		);
	}
	if (block.previewUrl) {
		return (
			<img
				src={`${block.previewUrl}?v=${block.previewMtime ?? 0}`}
				/* translators: %s: block title. */
				alt={sprintf(__('%s preview', 'proto-blocks'), block.title)}
				className="absolute inset-0 size-full object-cover object-top"
			/>
		);
	}
	return (
		<span className="grid justify-items-center gap-1.5 text-muted-foreground">
			<ImageOffIcon aria-hidden="true" className="size-5" strokeWidth={1.75} />
			<span className="text-micro">
				{block.hasPreview ? __('No public URL', 'proto-blocks') : __('No preview yet', 'proto-blocks')}
			</span>
		</span>
	);
}

function LiveLine({ live }: { live?: Live }) {
	if (!live) {
		return null;
	}
	const text = liveText(live);
	if (live.status === 'failed') {
		return (
			<span className="flex items-center gap-1 text-micro text-destructive">
				<CircleXIcon aria-hidden="true" className="size-3" />
				{text}
			</span>
		);
	}
	if (live.status === 'captured') {
		return <span className="text-micro text-success tabular-nums">{text}</span>;
	}
	return (
		<span className="flex items-center gap-1 text-micro text-muted-foreground">
			{BUSY.includes(live.status) ? (
				<LoaderCircleIcon aria-hidden="true" className="size-3 animate-spin motion-reduce:animate-none" />
			) : null}
			{text}
		</span>
	);
}

/** Run progress strip above the grid; also the polite live region for the run. */
function RunStatus({ run }: { run: RunState }) {
	let content: ReactNode = null;
	if (run.phase === 'running') {
		const current = Math.min(run.done + 1, run.total);
		content = (
			<>
				<LoaderCircleIcon aria-hidden="true" className="size-4 flex-none animate-spin text-primary motion-reduce:animate-none" />
				<span className="text-label font-medium tabular-nums">
					{/* translators: 1: number of the block being captured, 2: blocks in this run. */}
					{sprintf(__('Capturing %1$d of %2$d…', 'proto-blocks'), current, run.total)}
				</span>
				<span aria-hidden="true" className="h-1.5 flex-1 overflow-hidden rounded-full bg-divider">
					<span
						className="block h-full bg-brand transition-[width]"
						style={{ width: `${run.total ? Math.round((run.done / run.total) * 100) : 0}%` }}
					/>
				</span>
			</>
		);
	} else if (run.phase === 'finished') {
		content = (
			<span className="flex items-center gap-2 text-label font-medium text-success">
				<CircleCheckIcon aria-hidden="true" className="size-4" />
				{/* translators: 1: blocks captured, 2: blocks that failed. */}
				{sprintf(__('Done. %1$d captured, %2$d failed.', 'proto-blocks'), run.captured, run.failed)}
			</span>
		);
	} else if (run.phase === 'empty') {
		content = <span className="text-label text-muted-foreground">{__('Nothing to capture.', 'proto-blocks')}</span>;
	}

	return (
		<div
			role="status"
			aria-live="polite"
			className={cn(
				'flex min-w-0 items-center gap-3.5 rounded-xl border bg-card px-4 py-3',
				content ? 'mb-4' : 'sr-only'
			)}
		>
			{content}
		</div>
	);
}

function EmptyState({ adminUrl }: { adminUrl: string }) {
	return (
		<Panel className="flex flex-col items-center gap-3 px-6 py-12 text-center">
			<div
				aria-hidden="true"
				className="grid size-11 place-items-center rounded-lg bg-muted text-muted-foreground [&_svg]:size-[18px]"
			>
				<ImageIcon />
			</div>
			<h2 className="text-section font-semibold text-balance">{__('No blocks to capture', 'proto-blocks')}</h2>
			<p className="max-w-[48ch] text-label text-muted-foreground">
				{__('Previews appear here once your theme registers a Proto-Block.', 'proto-blocks')}
			</p>
			<Button variant="outline" size="sm" asChild>
				<a href={`${adminUrl}?page=proto-blocks`}>{__('Go to Blocks', 'proto-blocks')}</a>
			</Button>
		</Panel>
	);
}

function LoadingState() {
	return (
		<div aria-busy="true" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
			<Skeleton className="h-60 rounded-xl" />
			<Skeleton className="h-60 rounded-xl" />
			<Skeleton className="h-60 rounded-xl" />
			<Skeleton className="h-60 rounded-xl" />
		</div>
	);
}

function CaptureGrid({
	data,
	fileModsAllowed,
	header,
}: {
	data: PreviewsData;
	fileModsAllowed: boolean;
	/** Renders the page header (and read-only banner) with this grid's actions. */
	header: (actions: ReactNode) => ReactNode;
}) {
	const [blocks, setBlocks] = useState<PreviewBlock[]>(data.blocks);
	const [selected, setSelected] = useState<Set<string>>(() => new Set());
	const [live, setLive] = useState<Record<string, Live>>({});
	const [run, setRun] = useState<RunState>({ phase: 'idle' });
	const iframeRef = useRef<HTMLIFrameElement>(null);

	const running = run.phase === 'running';
	const locked = !fileModsAllowed || running;
	const describedBy = fileModsAllowed ? undefined : READ_ONLY_BANNER_ID;
	const missing = useMemo(() => blocks.filter((b) => !b.hasPreview), [blocks]);

	const start = useCallback(
		async (targets: PreviewBlock[]) => {
			const iframe = iframeRef.current;
			if (!iframe || running || !fileModsAllowed) {
				return;
			}
			if (targets.length === 0) {
				setRun({ phase: 'empty' });
				return;
			}

			setLive((prev) => {
				const next = { ...prev };
				for (const t of targets) {
					next[t.name] = { status: 'queued' };
				}
				return next;
			});
			setRun({ phase: 'running', done: 0, total: targets.length });

			const rows: CaptureRow[] = targets.map((t) => ({ name: t.name, renderUrl: t.renderUrl }));
			const results = new Map<string, { previewUrl: string; mtime: number }>();
			const deps: CaptureDeps = {
				iframe,
				width: data.width,
				settleMs: data.settleMs,
				rasterize,
				upload: async (block, image) => {
					const result = await upload(block, image);
					results.set(block, result);
					return result;
				},
			};

			const outcome = await captureMany(
				rows,
				deps,
				(name, status, message) => {
					setLive((prev) => ({ ...prev, [name]: { status, message } }));
					if (status === 'captured') {
						const result = results.get(name);
						setBlocks((prev) =>
							prev.map((b) =>
								b.name === name
									? {
											...b,
											hasPreview: true,
											previewUrl: result?.previewUrl || b.previewUrl,
											previewMtime: result?.mtime ?? b.previewMtime,
										}
									: b
							)
						);
					}
				},
				(done, total) => setRun({ phase: 'running', done, total })
			);

			setRun({ phase: 'finished', ...outcome });
		},
		[data.settleMs, data.width, fileModsAllowed, running]
	);

	const allChecked = blocks.length > 0 && selected.size === blocks.length;
	const someChecked = selected.size > 0 && !allChecked;

	const toggleAll = (checked: boolean) => setSelected(checked ? new Set(blocks.map((b) => b.name)) : new Set());
	const toggle = (name: string, checked: boolean) =>
		setSelected((prev) => {
			const next = new Set(prev);
			if (checked) {
				next.add(name);
			} else {
				next.delete(name);
			}
			return next;
		});

	const withPreviews = blocks.length - missing.length;

	return (
		<>
			{header(
				<>
					<Button variant="outline" disabled={locked} aria-describedby={describedBy} onClick={() => start(blocks)}>
						<RefreshCwIcon aria-hidden="true" />
						{__('Recapture all', 'proto-blocks')}
					</Button>
					<Button
						disabled={locked || missing.length === 0}
						aria-describedby={describedBy}
						onClick={() => start(missing)}
					>
						<CameraIcon aria-hidden="true" />
						{sprintf(
							/* translators: %d: number of blocks without a preview. */
							_n('Capture %d missing', 'Capture %d missing', missing.length, 'proto-blocks'),
							missing.length
						)}
					</Button>
				</>
			)}
			<RunStatus run={run} />
			<section aria-labelledby="pb-previews-heading">
				<h2 id="pb-previews-heading" className="sr-only">
					{__('Block previews', 'proto-blocks')}
				</h2>
				<div className="mb-3 flex flex-wrap items-center gap-3 text-label text-muted-foreground">
					<label className="inline-flex cursor-pointer items-center gap-2">
						<Checkbox
							aria-label={__('Select all', 'proto-blocks')}
							checked={allChecked ? true : someChecked ? 'indeterminate' : false}
							onCheckedChange={(checked) => toggleAll(checked === true)}
						/>
						<span aria-hidden="true">{__('Select all', 'proto-blocks')}</span>
					</label>
					<span aria-hidden="true">·</span>
					<span>
						{sprintf(
							/* translators: 1: number of blocks, 2: number of blocks with a preview. */
							_n('%1$d block · %2$d with previews', '%1$d blocks · %2$d with previews', blocks.length, 'proto-blocks'),
							blocks.length,
							withPreviews
						)}
					</span>
					<Button
						size="sm"
						variant="outline"
						className="ml-auto"
						disabled={locked || selected.size === 0}
						aria-describedby={describedBy}
						onClick={() => start(blocks.filter((b) => selected.has(b.name)))}
					>
						<ListChecksIcon aria-hidden="true" />
						{__('Capture selected', 'proto-blocks')}
						<span className="text-muted-foreground tabular-nums">({selected.size})</span>
					</Button>
				</div>
				<ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
					{blocks.map((block) => {
						const rowLive = live[block.name];
						const busy = rowLive ? BUSY.includes(rowLive.status) : false;
						const failed = rowLive?.status === 'failed';
						let actionLabel: string = block.hasPreview ? __('Recapture', 'proto-blocks') : __('Capture', 'proto-blocks');
						let ActionIcon = CameraIcon;
						if (failed) {
							actionLabel = __('Retry', 'proto-blocks');
							ActionIcon = RotateCwIcon;
						}
						const titleId = `pb-preview-${block.name}`;

						return (
							<li key={block.name} className="min-w-0">
								<article
									aria-labelledby={titleId}
									className="grid h-full overflow-hidden rounded-xl border bg-card"
								>
									<div className="relative grid aspect-[16/10] place-items-center border-b border-divider bg-muted">
										<Thumbnail block={block} busy={busy} />
										<span className="absolute top-2.5 left-2.5 grid rounded-sm bg-background">
											<Checkbox
												/* translators: %s: block title. */
												aria-label={sprintf(__('Select %s', 'proto-blocks'), block.title)}
												checked={selected.has(block.name)}
												onCheckedChange={(checked) => toggle(block.name, checked === true)}
											/>
										</span>
									</div>
									<div className="grid content-start gap-2.5 px-3.5 py-3">
										<div className="grid min-w-0 gap-px">
											<span id={titleId} className="truncate font-medium">
												{block.title}
											</span>
											<code className="truncate font-mono text-micro text-muted-foreground">{block.name}</code>
										</div>
										<div className="flex items-start justify-between gap-2">
											<div className="grid min-w-0 gap-0.5">
												{block.hasPreview ? (
													<span className="text-caption text-success">{__('Present', 'proto-blocks')}</span>
												) : (
													<span className="text-caption text-muted-foreground">{__('Missing', 'proto-blocks')}</span>
												)}
												<LiveLine live={rowLive} />
											</div>
											<Button
												size="sm"
												variant="outline"
												className="flex-none"
												disabled={locked}
												aria-describedby={describedBy}
												onClick={() => start([block])}
											>
												<ActionIcon aria-hidden="true" />
												{actionLabel}
											</Button>
										</div>
									</div>
								</article>
							</li>
						);
					})}
				</ul>
			</section>
			{/*
			 * Capture surface. Off-screen rather than display:none, because the
			 * rasterizer needs layout. sandbox: allow-same-origin lets this page
			 * read the frame's document; allow-scripts keeps block view scripts
			 * (reveals, sliders, counters) running so the shot shows their final
			 * state. Navigation, forms and popups stay blocked. No referrer, so
			 * the render URL and its nonce never reach an asset host.
			 */}
			<iframe
				ref={iframeRef}
				title={__('Preview capture frame', 'proto-blocks')}
				aria-hidden="true"
				tabIndex={-1}
				sandbox="allow-same-origin allow-scripts"
				referrerPolicy="no-referrer"
				width={data.width}
				style={{ position: 'fixed', left: -10000, top: 0, width: data.width, height: 800, border: 0 }}
			/>
		</>
	);
}

export default function PreviewsScreen() {
	const { adminUrl, fileModsAllowed } = getBootstrap();
	const [state, setState] = useState<LoadState>({ status: 'loading' });

	const load = useCallback(() => {
		setState({ status: 'loading' });
		adminGet<PreviewsData>('admin/previews').then(
			(data) => {
				if (data) {
					setState({ status: 'ready', data });
				}
			},
			(error: unknown) => setState({ status: 'error', error })
		);
	}, []);

	useEffect(load, [load]);

	const header = (actions?: ReactNode) => (
		<>
			<PageHeader
				eyebrow={__('Blocks', 'proto-blocks')}
				title={__('Preview capture', 'proto-blocks')}
				description={__("Inserter thumbnails rendered from each block's live front end at 1280px.", 'proto-blocks')}
				actions={actions}
			/>
			{fileModsAllowed ? null : (
				<div className="pb-6">
					<ReadOnlyBanner
						reason="file_mods"
						description={interpolate(
							/* translators: %s: preview.png */
							__('Previews are saved as files in your theme, so capturing is off. Add %s to each block folder in your repository instead.', 'proto-blocks'),
							code('preview.png')
						)}
					/>
				</div>
			)}
		</>
	);

	if (state.status === 'ready' && state.data.blocks.length > 0) {
		return <CaptureGrid data={state.data} fileModsAllowed={fileModsAllowed} header={header} />;
	}

	let body: ReactNode;
	if (state.status === 'loading') {
		body = <LoadingState />;
	} else if (state.status === 'error') {
		body = (
			<ScreenError
				screen={__('Preview Capture', 'proto-blocks')}
				error={state.error instanceof ApiError ? state.error : undefined}
				onRetry={load}
			/>
		);
	} else {
		body = <EmptyState adminUrl={adminUrl} />;
	}

	return (
		<>
			{header()}
			{body}
		</>
	);
}
