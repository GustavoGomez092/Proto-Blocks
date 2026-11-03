import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { EraserIcon, ImageIcon, LockIcon } from 'lucide-react';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { Panel, PanelHeader } from '@/admin/components/shell/Panel';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { CodeChip, Dot, Eyebrow } from '@/admin/components/shell/Signals';
import { StatCard } from '@/admin/components/shell/StatCard';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { interpolate } from '@/admin/lib/interpolate';

export interface DashboardBlock {
	name: string;
	title: string;
	description: string;
	category: string;
	/** Relative to the WordPress root when inside it. */
	location: string;
	usesTailwind: boolean;
	hasJson: boolean;
	hasCSS: boolean;
	hasJS: boolean;
	isExample: boolean;
}

/**
 * GET admin/dashboard (includes/API/Admin/DashboardRoutes.php). The payload
 * also carries `demos`, `installed` and `conflicts` for API stability; demo
 * installation is not exposed in the UI since 2.15, so they are not read.
 */
export interface DashboardData {
	stats: { blocks: number; cachedTemplates: number; cacheSize: string };
	exampleBlocks: { enabled: boolean; locked: boolean };
	blocks: DashboardBlock[];
}

const code = (text: ReactNode) => <CodeChip>{text}</CodeChip>;

/** Files shown per block: the definition, the template and whichever assets exist. */
function blockFiles(block: DashboardBlock): string[] {
	const files: string[] = [];
	if (block.hasJson) {
		files.push('block.json');
	}
	files.push('template.php');
	if (block.hasCSS) {
		files.push('style.css');
	}
	if (block.hasJS) {
		files.push('view.js');
	}
	return files;
}

function BlocksTable({ blocks }: { blocks: DashboardBlock[] }) {
	return (
		<div className="overflow-x-auto">
			<Table className="min-w-[720px]">
				<TableHeader>
					<TableRow>
						{[__('Block', 'proto-blocks'), __('Category', 'proto-blocks'), __('Location', 'proto-blocks'), __('Styling', 'proto-blocks'), __('Files', 'proto-blocks')].map(
							(heading) => (
								<TableHead key={heading} scope="col">
									{heading}
								</TableHead>
							)
						)}
					</TableRow>
				</TableHeader>
				<TableBody>
					{blocks.map((block) => (
						<TableRow key={block.name}>
							<TableCell className="whitespace-normal">
								<div className="flex flex-wrap items-center gap-2">
									<span className="font-medium">{block.title}</span>
									{block.isExample ? (
										<Badge variant="warning" className="px-[7px] py-px text-[11.5px]">
											{__('Example', 'proto-blocks')}
										</Badge>
									) : null}
								</div>
								<CodeChip muted className="whitespace-nowrap">proto-blocks/{block.name}</CodeChip>
							</TableCell>
							<TableCell>{block.category}</TableCell>
							<TableCell className="whitespace-normal">
								<CodeChip muted className="break-all">
									{block.location}
								</CodeChip>
							</TableCell>
							<TableCell>
								{block.usesTailwind ? (
									<Dot tone="brand">{__('Tailwind', 'proto-blocks')}</Dot>
								) : (
									<Dot tone="neutral">{__('Vanilla CSS', 'proto-blocks')}</Dot>
								)}
							</TableCell>
							<TableCell>
								<div className="flex gap-1.5">
									{blockFiles(block).map((file) => (
										<CodeChip key={file} className="text-[11.5px]">
											{file}
										</CodeChip>
									))}
								</div>
							</TableCell>
						</TableRow>
					))}
				</TableBody>
			</Table>
		</div>
	);
}

function EmptyBlocks({ examples }: { examples: ExampleToggle }) {
	const canTurnOn = !examples.enabled && !examples.locked;
	return (
		<Panel className="grid items-center gap-8 p-10 md:grid-cols-2">
			<div className="grid gap-3">
				<Eyebrow tone="brand">{__('No blocks yet', 'proto-blocks')}</Eyebrow>
				<h2 className="text-[20px] leading-[1.25] font-semibold tracking-[-0.01em] text-balance">
					{__('Your first block is a folder and two files.', 'proto-blocks')}
				</h2>
				<p className="text-label text-muted-foreground">
					{__('Drop it in your theme and it appears here and in the inserter — no build step.', 'proto-blocks')}
				</p>
				{canTurnOn ? (
					<div className="mt-1 flex gap-2">
						<Button disabled={examples.switching} onClick={() => examples.toggle(true)}>
							{__('Turn on examples', 'proto-blocks')}
						</Button>
					</div>
				) : null}
			</div>
			<pre className="m-0 overflow-x-auto rounded-lg bg-panel px-5 py-[18px] font-mono text-caption leading-[1.7] text-panel-foreground">
				<span className="text-faint">your-theme/</span>
				{'\n└─ proto-blocks/\n   └─ '}
				<span className="text-brand">my-card/</span>
				{'\n      ├─ block.json\n      ├─ template.php\n      └─ style.css  '}
				<span className="text-faint">{__('optional', 'proto-blocks')}</span>
			</pre>
		</Panel>
	);
}

interface ExampleToggle {
	enabled: boolean;
	locked: boolean;
	switching: boolean;
	toggle: (enabled: boolean) => void;
}

function useExampleToggle(data: DashboardData | null, onData: (data: DashboardData) => void): ExampleToggle {
	const [switching, setSwitching] = useState(false);

	const toggle = async (enabled: boolean) => {
		setSwitching(true);
		try {
			const next = await adminPost<DashboardData>('admin/example-blocks', { enabled });
			onData(next);
			toast.success(
				enabled
					? __('Example blocks enabled. Reload the editor to see them.', 'proto-blocks')
					: __('Example blocks disabled.', 'proto-blocks')
			);
		} catch (error) {
			toast.error(__("Couldn't change example blocks.", 'proto-blocks'), {
				description: error instanceof Error ? error.message : undefined,
			});
		} finally {
			setSwitching(false);
		}
	};

	return {
		enabled: data?.exampleBlocks.enabled ?? false,
		locked: data?.exampleBlocks.locked ?? false,
		switching,
		toggle: (enabled) => void toggle(enabled),
	};
}

function ExampleBlocks({ examples }: { examples: ExampleToggle }) {
	return (
		<Panel aria-labelledby="pb-examples-title" id="pb-examples" className="grid gap-3 px-6 py-4">
			<div className="flex items-center justify-between gap-6">
				<div className="grid gap-0.5">
					<Label htmlFor="pb-example-blocks" id="pb-examples-title" className="text-body font-medium">
						{__('Example blocks', 'proto-blocks')}
					</Label>
					<p className="text-label text-muted-foreground">
						{interpolate(
							/* translators: %s: the "Example" badge. */
							__("Load the bundled examples (hero, card, stats, testimonial…) into the inserter to learn from. They're marked %s above.", 'proto-blocks'),
							<Badge variant="warning" className="px-1.5 py-0 align-baseline text-[11.5px]">
								{__('Example', 'proto-blocks')}
							</Badge>
						)}
					</p>
				</div>
				<div className="flex flex-none items-center gap-3">
					{examples.locked ? (
						<Badge variant="outline">{__('Managed from configuration', 'proto-blocks')}</Badge>
					) : null}
					<Switch
						id="pb-example-blocks"
						checked={examples.enabled}
						disabled={examples.locked || examples.switching}
						aria-describedby={examples.locked ? 'pb-example-blocks-lock' : undefined}
						onCheckedChange={examples.toggle}
					/>
				</div>
			</div>
			{examples.locked ? (
				<p id="pb-example-blocks-lock" className="flex items-center gap-2 text-label text-muted-foreground">
					<LockIcon aria-hidden="true" className="size-4 flex-none" />
					<span>
						{interpolate(
							/* translators: %s: PROTO_BLOCKS_EXAMPLE_BLOCKS */
							__('Set by %s in wp-config.php. Remove the constant to manage it here.', 'proto-blocks'),
							code('PROTO_BLOCKS_EXAMPLE_BLOCKS')
						)}
					</span>
				</p>
			) : null}
		</Panel>
	);
}

function ClearCache({ cachedTemplates, onData }: { cachedTemplates: number; onData: (data: DashboardData) => void }) {
	const [open, setOpen] = useState(false);
	const [clearing, setClearing] = useState(false);

	const clear = async () => {
		setClearing(true);
		try {
			const result = await adminDelete<{ cleared: number; data: DashboardData }>('admin/cache');
			onData(result.data);
			toast.success(
				sprintf(
					/* translators: %d: number of cached templates */
					_n('%d cached template cleared.', '%d cached templates cleared.', result.cleared, 'proto-blocks'),
					result.cleared
				)
			);
		} catch (error) {
			toast.error(__("Couldn't clear the cache.", 'proto-blocks'), {
				description: error instanceof Error ? error.message : undefined,
			});
		} finally {
			setClearing(false);
			setOpen(false);
		}
	};

	return (
		<>
			<div className="grid content-center justify-items-start gap-1.5 px-6 py-[18px] sm:col-span-3 sm:border-t lg:col-span-1 lg:justify-items-end lg:border-t-0">
				<Button
					variant="outline"
					className="h-[34px] px-3 text-label"
					disabled={clearing || cachedTemplates === 0}
					onClick={() => setOpen(true)}
				>
					<EraserIcon aria-hidden="true" className="size-[15px]" />
					{__('Clear cache', 'proto-blocks')}
				</Button>
				<span className="text-micro text-muted-foreground">{__('Forces every block to recompile', 'proto-blocks')}</span>
			</div>
			<ConfirmDialog
				open={open}
				title={
					cachedTemplates === 0
						? __('No cached templates to clear.', 'proto-blocks')
						: sprintf(
								/* translators: %d: number of cached templates */
								_n('Clear %d cached template?', 'Clear %d cached templates?', cachedTemplates, 'proto-blocks'),
								cachedTemplates
							)
				}
				confirmDisabled={cachedTemplates === 0}
				description={
					cachedTemplates === 0
						? undefined
						: __('Templates are compiled again the next time each block renders.', 'proto-blocks')
				}
				confirmLabel={clearing ? __('Clearing…', 'proto-blocks') : __('Clear cache', 'proto-blocks')}
				onConfirm={clear}
				onCancel={() => {
					if (!clearing) {
						setOpen(false);
					}
				}}
			/>
		</>
	);
}

function LoadingState() {
	return (
		<div aria-busy="true" className="grid gap-6">
			<Skeleton className="h-[94px] rounded-xl" />
			<Skeleton className="h-64 rounded-xl" />
			<Skeleton className="h-[74px] rounded-xl" />
		</div>
	);
}

type LoadState =
	| { status: 'loading' }
	| { status: 'error'; error: unknown }
	| { status: 'ready'; data: DashboardData };

export default function DashboardScreen() {
	const { adminUrl, fileModsAllowed, version } = getBootstrap();
	const [state, setState] = useState<LoadState>({ status: 'loading' });

	const load = useCallback(() => {
		setState({ status: 'loading' });
		adminGet<DashboardData>('admin/dashboard').then(
			(data) => {
				if (data) {
					setState({ status: 'ready', data });
				}
			},
			(error: unknown) => setState({ status: 'error', error })
		);
	}, []);

	useEffect(load, [load]);

	const onData = useCallback((data: DashboardData) => setState({ status: 'ready', data }), []);
	const examples = useExampleToggle(state.status === 'ready' ? state.data : null, onData);

	let body: ReactNode;
	if (state.status === 'loading') {
		body = <LoadingState />;
	} else if (state.status === 'error') {
		body = (
			<ScreenError
				screen={__('Blocks', 'proto-blocks')}
				error={state.error instanceof ApiError ? state.error : undefined}
				onRetry={load}
			/>
		);
	} else {
		const { data } = state;
		const fromExamples = data.blocks.filter((block) => block.isExample).length;
		const stat = 'border-b sm:border-r sm:border-b-0';
		body = (
			<div className="grid gap-6">
				<Panel>
					<section
						aria-label={__('Overview', 'proto-blocks')}
						className="grid items-stretch sm:grid-cols-3 lg:grid-cols-[1fr_1fr_1fr_auto]"
					>
						<StatCard
							className={stat}
							label={__('Registered blocks', 'proto-blocks')}
							value={data.stats.blocks}
							hint={sprintf(
								/* translators: %d: number of example blocks */
								__('%d from examples', 'proto-blocks'),
								fromExamples
							)}
						/>
						<StatCard
							className={stat}
							label={__('Cached templates', 'proto-blocks')}
							value={data.stats.cachedTemplates}
							hint={__('compiled PHP', 'proto-blocks')}
						/>
						<StatCard
							className="border-b sm:border-b-0 lg:border-r"
							label={__('Cache on disk', 'proto-blocks')}
							value={data.stats.cacheSize}
							hint={__('wp-content/uploads', 'proto-blocks')}
						/>
						<ClearCache cachedTemplates={data.stats.cachedTemplates} onData={onData} />
					</section>
				</Panel>

				{data.blocks.length > 0 ? (
					<Panel aria-labelledby="pb-blocks-title" className="overflow-hidden">
						<PanelHeader
							id="pb-blocks-title"
							title={__('Registered blocks', 'proto-blocks')}
							count={data.blocks.length}
							aside={
								<p className="text-label text-muted-foreground">
									{interpolate(
										/* translators: %s: folder name */
										__('Found in %s and the bundled examples', 'proto-blocks'),
										code('your-theme/proto-blocks/')
									)}
								</p>
							}
						/>
						<BlocksTable blocks={data.blocks} />
					</Panel>
				) : (
					<EmptyBlocks examples={examples} />
				)}

				<ExampleBlocks examples={examples} />

				<footer className="flex flex-wrap items-center gap-4 text-caption text-muted-foreground">
					<a href={`${adminUrl}?page=proto-blocks-system`}>{__('System status', 'proto-blocks')}</a>
					<a href={`${adminUrl}?page=proto-blocks-settings`}>{__('Tailwind settings', 'proto-blocks')}</a>
				</footer>
			</div>
		);
	}

	return (
		<>
			<PageHeader
				eyebrow={`v${version}`}
				title={__('Blocks', 'proto-blocks')}
				description={__('Every Proto-Block registered on this site.', 'proto-blocks')}
				actions={
					<Button asChild variant="outline">
						<a href={`${adminUrl}?page=proto-blocks-previews`}>
							<ImageIcon aria-hidden="true" />
							{__('Preview capture', 'proto-blocks')}
						</a>
					</Button>
				}
			/>
			{fileModsAllowed ? null : (
				<div className="pb-6">
					<ReadOnlyBanner
						reason="file_mods"
						description={__(
							'Nothing on this screen writes theme files, so all of it keeps working.',
							'proto-blocks'
						)}
					/>
				</div>
			)}
			{body}
		</>
	);
}
