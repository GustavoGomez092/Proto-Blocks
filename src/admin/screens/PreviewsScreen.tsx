import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	CameraIcon,
	CheckIcon,
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
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
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

const code = (text: ReactNode) => <code className="font-mono text-[12.5px]">{text}</code>;

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

function Thumbnail({ block, busy }: { block: PreviewBlock; busy: boolean }) {
	const frame = 'aspect-[16/10] w-[128px] rounded border';
	if (busy) {
		return <Skeleton className={`${frame} border-dashed border-input`} />;
	}
	if (block.previewUrl) {
		return (
			<img
				src={`${block.previewUrl}?v=${block.previewMtime ?? 0}`}
				/* translators: %s: block title. */
				alt={sprintf(__('%s preview', 'proto-blocks'), block.title)}
				className={`${frame} border-border bg-muted object-cover object-top`}
			/>
		);
	}
	return (
		<div
			className={`${frame} grid place-items-center border-dashed border-input bg-muted text-[11px] text-muted-foreground`}
		>
			<span className="flex flex-col items-center gap-1">
				<ImageOffIcon aria-hidden="true" className="size-4" />
				{block.hasPreview ? __('No public URL', 'proto-blocks') : __('No preview', 'proto-blocks')}
			</span>
		</div>
	);
}

function LiveLine({ live }: { live?: Live }) {
	if (!live) {
		return null;
	}
	const text = liveText(live);
	if (live.status === 'failed') {
		return (
			<span className="flex items-center gap-1 text-[12px] text-destructive">
				<CircleXIcon aria-hidden="true" className="size-3" />
				{text}
			</span>
		);
	}
	if (live.status === 'captured') {
		return <span className="text-[12px] text-success tabular-nums">{text}</span>;
	}
	return (
		<span className="flex items-center gap-1 text-[12px] text-muted-foreground">
			{BUSY.includes(live.status) ? (
				<LoaderCircleIcon aria-hidden="true" className="size-3 animate-spin motion-reduce:animate-none" />
			) : null}
			{text}
		</span>
	);
}

function RunStatus({ run }: { run: RunState }) {
	let content: ReactNode = null;
	if (run.phase === 'running') {
		const current = Math.min(run.done + 1, run.total);
		content = (
			<>
				<LoaderCircleIcon
					aria-hidden="true"
					className="size-4 animate-spin text-muted-foreground motion-reduce:animate-none"
				/>
				<span className="text-[13px] text-muted-foreground tabular-nums">
					{/* translators: 1: number of the block being captured, 2: blocks in this run. */}
					{sprintf(__('Capturing %1$d of %2$d…', 'proto-blocks'), current, run.total)}
				</span>
				<span aria-hidden="true" className="h-1.5 w-28 overflow-hidden rounded-full bg-muted">
					<span
						className="block h-full bg-brand transition-[width]"
						style={{ width: `${run.total ? Math.round((run.done / run.total) * 100) : 0}%` }}
					/>
				</span>
			</>
		);
	} else if (run.phase === 'finished') {
		content = (
			<span className="flex items-center gap-2 text-[13px] font-medium text-success">
				<CircleCheckIcon aria-hidden="true" className="size-4" />
				{/* translators: 1: blocks captured, 2: blocks that failed. */}
				{sprintf(__('Done. %1$d captured, %2$d failed.', 'proto-blocks'), run.captured, run.failed)}
			</span>
		);
	} else if (run.phase === 'empty') {
		content = <span className="text-[13px] text-muted-foreground">{__('Nothing to capture.', 'proto-blocks')}</span>;
	}

	return (
		<div role="status" aria-live="polite" className="ml-auto flex min-w-0 items-center gap-2">
			{content}
		</div>
	);
}

function EmptyState({ adminUrl }: { adminUrl: string }) {
	return (
		<Card className="flex flex-col items-center gap-3 rounded-lg px-6 py-12 text-center shadow-xs">
			<div
				aria-hidden="true"
				className="grid size-11 place-items-center rounded-lg bg-muted text-muted-foreground [&_svg]:size-[18px]"
			>
				<ImageIcon />
			</div>
			<h2 className="text-[15px] font-semibold text-balance">{__('No blocks to capture', 'proto-blocks')}</h2>
			<p className="max-w-[48ch] text-[13px] text-muted-foreground">
				{__('Previews appear here once your theme registers a Proto-Block.', 'proto-blocks')}
			</p>
			<Button variant="outline" size="sm" asChild>
				<a href={`${adminUrl}?page=proto-blocks`}>{__('Go to Blocks', 'proto-blocks')}</a>
			</Button>
		</Card>
	);
}

function LoadingState() {
	return (
		<Card aria-busy="true" className="gap-0 rounded-lg py-0 shadow-xs">
			<div className="flex gap-2 border-b p-4">
				<Skeleton className="h-8 w-36" />
				<Skeleton className="h-8 w-36" />
				<Skeleton className="h-8 w-28" />
			</div>
			<div className="grid gap-4 p-4">
				<Skeleton className="h-20" />
				<Skeleton className="h-20" />
				<Skeleton className="h-20" />
			</div>
		</Card>
	);
}

function CaptureTable({ data, fileModsAllowed }: { data: PreviewsData; fileModsAllowed: boolean }) {
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

	return (
		<Card role="region" aria-labelledby="pb-previews-heading" className="gap-0 rounded-lg py-0 shadow-xs">
			<h2 id="pb-previews-heading" className="sr-only">
				{__('Block previews', 'proto-blocks')}
			</h2>
			<div className="sticky top-8 z-10 flex flex-wrap items-center gap-2 rounded-t-lg border-b bg-card p-4">
				<Button
					size="sm"
					disabled={locked || missing.length === 0}
					aria-describedby={describedBy}
					onClick={() => start(missing)}
				>
					<CameraIcon aria-hidden="true" />
					{__('Capture missing', 'proto-blocks')}
					<Badge variant="secondary" className="rounded-sm py-0 tabular-nums">
						{missing.length}
					</Badge>
				</Button>
				<Button
					size="sm"
					variant="outline"
					disabled={locked || selected.size === 0}
					aria-describedby={describedBy}
					onClick={() => start(blocks.filter((b) => selected.has(b.name)))}
				>
					<ListChecksIcon aria-hidden="true" />
					{__('Capture selected', 'proto-blocks')}
					<span className="text-muted-foreground tabular-nums">({selected.size})</span>
				</Button>
				<Button
					size="sm"
					variant="ghost"
					disabled={locked}
					aria-describedby={describedBy}
					onClick={() => start(blocks)}
				>
					<RefreshCwIcon aria-hidden="true" />
					{__('Recapture all', 'proto-blocks')}
				</Button>
				<RunStatus run={run} />
			</div>
			<Table className="min-w-[720px] table-fixed">
				<TableHeader>
					<TableRow>
						<TableHead className="w-10 px-4">
							<Checkbox
								aria-label={__('Select all', 'proto-blocks')}
								checked={allChecked ? true : someChecked ? 'indeterminate' : false}
								onCheckedChange={(checked) => toggleAll(checked === true)}
							/>
						</TableHead>
						<TableHead scope="col" className="w-[30%] text-[13px] text-muted-foreground">
							{__('Block', 'proto-blocks')}
						</TableHead>
						<TableHead scope="col" className="w-[168px] text-[13px] text-muted-foreground">
							{__('Preview', 'proto-blocks')}
						</TableHead>
						<TableHead scope="col" className="text-[13px] text-muted-foreground">
							{__('Status', 'proto-blocks')}
						</TableHead>
						<TableHead scope="col" className="w-[130px] px-4 text-right text-[13px] text-muted-foreground">
							{__('Action', 'proto-blocks')}
						</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
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

						return (
							<TableRow key={block.name}>
								<TableCell className="px-4">
									<Checkbox
										/* translators: %s: block title. */
										aria-label={sprintf(__('Select %s', 'proto-blocks'), block.title)}
										checked={selected.has(block.name)}
										onCheckedChange={(checked) => toggle(block.name, checked === true)}
									/>
								</TableCell>
								<TableCell className="whitespace-normal">
									<div className="font-medium">{block.title}</div>
									<div className="font-mono text-[12px] text-muted-foreground">{block.name}</div>
								</TableCell>
								<TableCell>
									<Thumbnail block={block} busy={busy} />
								</TableCell>
								<TableCell className="whitespace-normal">
									<div className="flex flex-col items-start gap-1">
										{block.hasPreview ? (
											<Badge
												variant="outline"
												className="rounded-sm border-transparent bg-success-soft text-success"
											>
												<CheckIcon aria-hidden="true" />
												{__('Present', 'proto-blocks')}
											</Badge>
										) : (
											<Badge variant="outline" className="rounded-sm text-muted-foreground">
												{__('Missing', 'proto-blocks')}
											</Badge>
										)}
										<LiveLine live={rowLive} />
									</div>
								</TableCell>
								<TableCell className="px-4 text-right">
									<Button
										size="sm"
										variant="ghost"
										disabled={locked}
										aria-describedby={describedBy}
										onClick={() => start([block])}
									>
										<ActionIcon aria-hidden="true" />
										{actionLabel}
									</Button>
								</TableCell>
							</TableRow>
						);
					})}
				</TableBody>
			</Table>
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
		</Card>
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
	} else if (state.data.blocks.length === 0) {
		body = <EmptyState adminUrl={adminUrl} />;
	} else {
		body = <CaptureTable data={state.data} fileModsAllowed={fileModsAllowed} />;
	}

	return (
		<>
			<PageHeader
				title={__('Preview Capture', 'proto-blocks')}
				description={__("Generate inserter thumbnails from each block's live front-end render at 1280px.", 'proto-blocks')}
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
			{body}
		</>
	);
}
