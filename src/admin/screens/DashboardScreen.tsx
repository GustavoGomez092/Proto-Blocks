import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { BlocksIcon, DatabaseZapIcon, EraserIcon, HardDriveIcon, ImageIcon, LockIcon, PackageOpenIcon } from 'lucide-react';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { StatCard } from '@/admin/components/shell/StatCard';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { cn } from '@/admin/lib/utils';
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

const code = (text: ReactNode) => <code className="font-mono text-[12.5px]">{text}</code>;

function SectionCard({ className, ...props }: React.ComponentProps<typeof Card> & { 'aria-labelledby': string }) {
	return <Card role="region" className={cn('gap-0 rounded-lg py-0 shadow-xs', className)} {...props} />;
}

function CardHead({ id, title, description, aside }: { id: string; title: string; description?: ReactNode; aside?: ReactNode }) {
	return (
		<div className="flex flex-wrap items-end justify-between gap-3 p-5 pb-4">
			<div className="grid min-w-0 gap-1">
				<h2 id={id} className="text-[15px] leading-[1.3] font-semibold text-balance">
					{title}
				</h2>
				{description ? <p className="text-[13px] text-muted-foreground">{description}</p> : null}
			</div>
			{aside}
		</div>
	);
}

function BlocksTable({ blocks }: { blocks: DashboardBlock[] }) {
	return (
		<div className="overflow-x-auto border-t">
			<Table className="min-w-[720px]">
				<TableHeader>
					<TableRow>
						{[__('Block', 'proto-blocks'), __('Category', 'proto-blocks'), __('Location', 'proto-blocks'), __('Type', 'proto-blocks'), __('Assets', 'proto-blocks')].map(
							(heading) => (
								<TableHead key={heading} scope="col" className="px-5 text-[13px] text-muted-foreground">
									{heading}
								</TableHead>
							)
						)}
					</TableRow>
				</TableHeader>
				<TableBody>
					{blocks.map((block) => (
						<TableRow key={block.name}>
							<TableCell className="px-5 py-3 whitespace-normal">
								<div className="flex flex-wrap items-center gap-2">
									<span className="font-medium">{block.title}</span>
									{block.isExample ? (
										<Badge
											variant="outline"
											className="rounded-sm border-transparent bg-warning-soft text-warning"
										>
											{__('Example', 'proto-blocks')}
										</Badge>
									) : null}
								</div>
								<div className="font-mono text-[12px] text-muted-foreground">proto-blocks/{block.name}</div>
							</TableCell>
							<TableCell className="px-5 py-3">{block.category}</TableCell>
							<TableCell className="px-5 py-3 whitespace-normal">
								<span className="font-mono text-[12px] break-all text-muted-foreground">{block.location}</span>
							</TableCell>
							<TableCell className="px-5 py-3">
								{block.usesTailwind ? (
									<Badge variant="outline" className="rounded-sm border-transparent bg-brand-soft text-[#0b5f5a]">
										{__('Tailwind', 'proto-blocks')}
									</Badge>
								) : (
									<Badge variant="secondary" className="rounded-sm">
										{__('Vanilla', 'proto-blocks')}
									</Badge>
								)}
							</TableCell>
							<TableCell className="px-5 py-3">
								<div className="flex gap-1">
									{block.hasJson ? (
										<Badge variant="outline" className="rounded-sm">
											JSON
										</Badge>
									) : null}
									{block.hasCSS ? (
										<Badge variant="outline" className="rounded-sm">
											CSS
										</Badge>
									) : null}
									{block.hasJS ? (
										<Badge variant="outline" className="rounded-sm">
											JS
										</Badge>
									) : null}
								</div>
							</TableCell>
						</TableRow>
					))}
				</TableBody>
			</Table>
		</div>
	);
}

function EmptyBlocks() {
	return (
		<div className="flex flex-col items-center gap-3 border-t px-6 py-12 text-center">
			<div
				aria-hidden="true"
				className="grid size-11 place-items-center rounded-lg bg-secondary text-primary [&_svg]:size-[18px]"
			>
				<PackageOpenIcon />
			</div>
			<h3 className="text-[15px] font-semibold">{__('No blocks found', 'proto-blocks')}</h3>
			<p className="max-w-[52ch] text-[13px] text-muted-foreground">
				{__('Create a block folder in your theme, or turn on Example Blocks below to see how one is built.', 'proto-blocks')}
			</p>
			<pre className="rounded-md bg-muted p-3 text-left font-mono text-[12px] leading-relaxed">
				{'your-theme/proto-blocks/my-block/\n  block.json\n  template.php\n  style.css   (optional)'}
			</pre>
		</div>
	);
}

function ExampleBlocks({ data, onData }: { data: DashboardData; onData: (data: DashboardData) => void }) {
	const [switching, setSwitching] = useState(false);
	const { exampleBlocks } = data;

	const toggleExamples = async (enabled: boolean) => {
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

	return (
		<SectionCard aria-labelledby="pb-examples-title" id="pb-examples">
			<CardHead
				id="pb-examples-title"
				title={__('Example blocks', 'proto-blocks')}
				description={__('Try Proto-Blocks with the ready-made blocks bundled with the plugin.', 'proto-blocks')}
			/>
			<div className="grid gap-3 border-t p-5">
				<div className="flex items-start justify-between gap-6">
					<div className="grid gap-1">
						<Label htmlFor="pb-example-blocks" className="text-sm font-medium">
							{__('Example Blocks', 'proto-blocks')}
						</Label>
						<p className="text-[13px] text-muted-foreground">
							{__('Make the bundled example blocks available in the block inserter for testing and learning.', 'proto-blocks')}
						</p>
					</div>
					<div className="flex flex-none items-center gap-3">
						{exampleBlocks.locked ? (
							<Badge variant="outline" className="rounded-sm">
								{__('Managed from configuration', 'proto-blocks')}
							</Badge>
						) : null}
						<Switch
							id="pb-example-blocks"
							checked={exampleBlocks.enabled}
							disabled={exampleBlocks.locked || switching}
							aria-describedby={exampleBlocks.locked ? 'pb-example-blocks-lock' : undefined}
							onCheckedChange={toggleExamples}
						/>
					</div>
				</div>
				{exampleBlocks.locked ? (
					<p id="pb-example-blocks-lock" className="flex items-center gap-2 text-[13px] text-muted-foreground">
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
			</div>
		</SectionCard>
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
			<div className="flex flex-wrap items-center justify-between gap-3 text-[13px] text-muted-foreground">
				<p>{__('Clear the template cache to force every block to recompile. Useful while developing.', 'proto-blocks')}</p>
				<Button variant="outline" size="sm" disabled={clearing} onClick={() => setOpen(true)}>
					<EraserIcon aria-hidden="true" />
					{__('Clear cache', 'proto-blocks')}
				</Button>
			</div>
			<ConfirmDialog
				open={open}
				title={sprintf(
					/* translators: %d: number of cached templates */
					_n('Clear %d cached template?', 'Clear %d cached templates?', cachedTemplates, 'proto-blocks'),
					cachedTemplates
				)}
				description={__('Templates are compiled again the next time each block renders.', 'proto-blocks')}
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
			<div className="grid gap-4 sm:grid-cols-3">
				<Skeleton className="h-28" />
				<Skeleton className="h-28" />
				<Skeleton className="h-28" />
			</div>
			<Skeleton className="h-64 rounded-lg" />
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
		body = (
			<div className="grid gap-6">
				<section aria-label={__('Overview', 'proto-blocks')} className="grid gap-4 sm:grid-cols-3">
					<StatCard
						label={__('Registered Blocks', 'proto-blocks')}
						value={data.stats.blocks}
						hint={sprintf(
							/* translators: %d: number of example blocks */
							__('%d from examples', 'proto-blocks'),
							fromExamples
						)}
						icon={<BlocksIcon />}
					/>
					<StatCard
						label={__('Cached Templates', 'proto-blocks')}
						value={data.stats.cachedTemplates}
						hint={__('Compiled PHP templates', 'proto-blocks')}
						icon={<DatabaseZapIcon />}
					/>
					<StatCard
						label={__('Cache Size', 'proto-blocks')}
						value={data.stats.cacheSize}
						hint={__('Template cache on disk', 'proto-blocks')}
						icon={<HardDriveIcon />}
					/>
				</section>

				<ClearCache cachedTemplates={data.stats.cachedTemplates} onData={onData} />

				<SectionCard aria-labelledby="pb-blocks-title">
					<CardHead
						id="pb-blocks-title"
						title={__('Registered Blocks', 'proto-blocks')}
						description={interpolate(
							/* translators: %s: folder name */
							__("Found in your theme's %s folder and the bundled examples.", 'proto-blocks'),
							code('proto-blocks/')
						)}
						aside={
							data.blocks.length > 0 ? (
								<Badge variant="secondary" className="rounded-sm tabular-nums">
									{sprintf(
										/* translators: %d: number of blocks */
										_n('%d block', '%d blocks', data.blocks.length, 'proto-blocks'),
										data.blocks.length
									)}
								</Badge>
							) : null
						}
					/>
					{data.blocks.length > 0 ? <BlocksTable blocks={data.blocks} /> : <EmptyBlocks />}
				</SectionCard>

				<ExampleBlocks data={data} onData={onData} />

				<footer className="flex items-center gap-2 text-[13px] text-muted-foreground">
					{sprintf(
						/* translators: %s: plugin version */
						__('Proto Blocks v%s', 'proto-blocks'),
						version
					)}
					<span aria-hidden="true">·</span>
					<a className="underline underline-offset-2" href={`${adminUrl}?page=proto-blocks-system`}>
						{__('System Status', 'proto-blocks')}
					</a>
				</footer>
			</div>
		);
	}

	return (
		<>
			<PageHeader
				title={__('Blocks', 'proto-blocks')}
				description={__('Every Proto-Block registered on this site.', 'proto-blocks')}
				actions={
					<Button asChild variant="outline" size="sm">
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
