import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import {
	BlocksIcon,
	DatabaseZapIcon,
	DownloadIcon,
	HardDriveIcon,
	ImageIcon,
	LoaderCircleIcon,
	LockIcon,
	PackageOpenIcon,
	PaletteIcon,
	ShieldCheckIcon,
	Trash2Icon,
	WindIcon,
} from 'lucide-react';
import { adminGet, adminPost, ApiError } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { READ_ONLY_BANNER_ID, ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { StatCard } from '@/admin/components/shell/StatCard';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Label } from '@/admin/components/ui/label';
import { Separator } from '@/admin/components/ui/separator';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/admin/components/ui/tooltip';
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

/** GET admin/dashboard (includes/API/Admin/DashboardRoutes.php). */
export interface DashboardData {
	stats: { blocks: number; cachedTemplates: number; cacheSize: string };
	exampleBlocks: { enabled: boolean; locked: boolean };
	demos: { vanilla: string[]; tailwind: string[]; tailwindEnabled: boolean; installPath: string };
	/** Demo blocks in the theme that are unmodified copies, so removable. */
	installed: string[];
	/** Same-named theme folders that are custom or edited, so kept. */
	conflicts: string[];
	skipped: string[];
	blocks: DashboardBlock[];
}

/** POST admin/demos/install and admin/demos/remove: a count plus the refreshed screen data. */
interface DemoResult {
	installed?: number;
	removed?: number;
	skipped: string[];
	data: DashboardData;
}

type DemoType = 'vanilla' | 'tailwind';

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

function keptDescription(names: string[]): string | undefined {
	if (names.length === 0) {
		return undefined;
	}
	return sprintf(
		/* translators: 1: number of blocks, 2: comma-separated block names */
		__('Kept %1$d modified: %2$s', 'proto-blocks'),
		names.length,
		names.join(', ')
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
				{__('Create a block folder in your theme, or install the demo blocks below to see how one is built.', 'proto-blocks')}
			</p>
			<pre className="rounded-md bg-muted p-3 text-left font-mono text-[12px] leading-relaxed">
				{'your-theme/proto-blocks/my-block/\n  block.json\n  template.php\n  style.css   (optional)'}
			</pre>
			<Button asChild size="sm">
				<a href="#pb-demos">
					<DownloadIcon aria-hidden="true" />
					{__('Install demo blocks', 'proto-blocks')}
				</a>
			</Button>
		</div>
	);
}

function DemoCard({
	icon,
	title,
	names,
	description,
	badge,
	muted,
	children,
}: {
	icon: ReactNode;
	title: string;
	names: string[];
	description: ReactNode;
	badge?: ReactNode;
	muted?: boolean;
	children: ReactNode;
}) {
	return (
		<div className={cn('flex min-w-0 flex-col gap-3 rounded-lg border p-4', muted && 'bg-muted')}>
			<div className="flex items-center gap-3">
				<div
					aria-hidden="true"
					className="grid size-9 flex-none place-items-center rounded-lg bg-secondary text-primary [&_svg]:size-[18px]"
				>
					{icon}
				</div>
				<div className="min-w-0">
					<div className="flex flex-wrap items-center gap-2">
						<p className="font-medium">{title}</p>
						{badge}
					</div>
					<p className="text-[13px] break-words text-muted-foreground">
						{sprintf(
							/* translators: 1: number of blocks, 2: comma-separated block names */
							_n('%1$d block: %2$s', '%1$d blocks: %2$s', names.length, 'proto-blocks'),
							names.length,
							names.join(', ')
						)}
					</p>
				</div>
			</div>
			<p className="text-[13px] text-muted-foreground">{description}</p>
			<div>{children}</div>
		</div>
	);
}

function Spinner() {
	return <LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" />;
}

function ExampleAndDemoBlocks({
	data,
	fileModsAllowed,
	adminUrl,
	onData,
}: {
	data: DashboardData;
	fileModsAllowed: boolean;
	adminUrl: string;
	onData: (data: DashboardData) => void;
}) {
	const [switching, setSwitching] = useState(false);
	const [installing, setInstalling] = useState<DemoType | null>(null);
	const [confirmOpen, setConfirmOpen] = useState(false);
	const [removing, setRemoving] = useState(false);

	const { exampleBlocks, demos, installed, conflicts } = data;
	const busy = installing !== null || removing;
	const readOnlyProps = fileModsAllowed ? {} : { 'aria-describedby': READ_ONLY_BANNER_ID };

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

	const install = async (type: DemoType) => {
		setInstalling(type);
		try {
			const result = await adminPost<DemoResult>('admin/demos/install', { type });
			onData(result.data);
			const count = result.installed ?? 0;
			const message =
				type === 'vanilla'
					? sprintf(
							/* translators: %d: number of blocks */
							_n('%d Vanilla CSS demo block installed.', '%d Vanilla CSS demo blocks installed.', count, 'proto-blocks'),
							count
						)
					: sprintf(
							/* translators: %d: number of blocks */
							_n('%d Tailwind demo block installed.', '%d Tailwind demo blocks installed.', count, 'proto-blocks'),
							count
						);
			toast.success(message, { description: keptDescription(result.skipped) });
		} catch (error) {
			toast.error(__("Couldn't install demo blocks.", 'proto-blocks'), {
				description: error instanceof Error ? error.message : undefined,
			});
		} finally {
			setInstalling(null);
		}
	};

	const remove = async () => {
		setRemoving(true);
		try {
			const result = await adminPost<DemoResult>('admin/demos/remove');
			onData(result.data);
			const count = result.removed ?? 0;
			toast.success(
				sprintf(
					/* translators: %d: number of blocks */
					_n('%d demo block removed.', '%d demo blocks removed.', count, 'proto-blocks'),
					count
				),
				{ description: keptDescription(result.skipped) }
			);
			setConfirmOpen(false);
		} catch (error) {
			toast.error(__("Couldn't remove demo blocks.", 'proto-blocks'), {
				description: error instanceof Error ? error.message : undefined,
			});
			setConfirmOpen(false);
		} finally {
			setRemoving(false);
		}
	};

	const installLabel = (label: string, type: DemoType) =>
		installing === type ? (
			<>
				<Spinner />
				{__('Installing…', 'proto-blocks')}
			</>
		) : (
			<>
				<DownloadIcon aria-hidden="true" />
				{label}
			</>
		);

	const nothingToRemove = installed.length === 0;
	const removeButton = (
		<Button
			variant="outline"
			size="sm"
			className="border-destructive/40 text-destructive hover:bg-destructive-soft hover:text-destructive"
			disabled={!fileModsAllowed || nothingToRemove || busy}
			onClick={() => setConfirmOpen(true)}
			{...readOnlyProps}
		>
			<Trash2Icon aria-hidden="true" />
			{__('Remove demo blocks', 'proto-blocks')}
		</Button>
	);

	return (
		<SectionCard aria-labelledby="pb-demos-title" id="pb-demos">
			<CardHead
				id="pb-demos-title"
				title={__('Example and demo blocks', 'proto-blocks')}
				description={__(
					'Try Proto-Blocks with ready-made blocks. Examples load from the plugin; demos are copied into your theme so you can edit them.',
					'proto-blocks'
				)}
			/>
			<div className="grid gap-6 border-t p-5">
				<div className="grid gap-3">
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

				<Separator />

				<div className="grid gap-3">
					<div>
						<h3 className="text-sm font-medium">{__('Demo Blocks', 'proto-blocks')}</h3>
						<p className="text-[13px] text-muted-foreground">
							{interpolate(
								/* translators: %s: folder path */
								__('Blocks will be installed to %s', 'proto-blocks'),
								code(`${demos.installPath}/`)
							)}
						</p>
					</div>
					<div className="grid gap-4 md:grid-cols-2">
						<DemoCard
							icon={<PaletteIcon />}
							title={__('Vanilla CSS', 'proto-blocks')}
							names={demos.vanilla}
							description={__('Classic CSS-styled demo blocks with a stylesheet per block.', 'proto-blocks')}
						>
							<Button
								variant="outline"
								size="sm"
								disabled={!fileModsAllowed || busy}
								onClick={() => install('vanilla')}
								{...readOnlyProps}
							>
								{installLabel(__('Install Vanilla CSS blocks', 'proto-blocks'), 'vanilla')}
							</Button>
						</DemoCard>
						<DemoCard
							icon={<WindIcon />}
							title={__('Tailwind', 'proto-blocks')}
							names={demos.tailwind}
							muted={!demos.tailwindEnabled}
							badge={
								demos.tailwindEnabled ? null : (
									<Badge
										variant="outline"
										className="rounded-sm border-transparent bg-warning-soft text-warning"
									>
										{__('Requires Tailwind', 'proto-blocks')}
									</Badge>
								)
							}
							description={
								demos.tailwindEnabled ? (
									__('Utility-first demo blocks.', 'proto-blocks')
								) : (
									<>
										{__('Utility-first demo blocks.', 'proto-blocks')}{' '}
										<a
											className="font-medium text-primary underline underline-offset-2"
											href={`${adminUrl}?page=proto-blocks-settings`}
										>
											{__('Turn on Tailwind', 'proto-blocks')}
										</a>{' '}
										{__('to install them.', 'proto-blocks')}
									</>
								)
							}
						>
							<Button
								variant="outline"
								size="sm"
								disabled={!fileModsAllowed || !demos.tailwindEnabled || busy}
								onClick={() => install('tailwind')}
								{...readOnlyProps}
							>
								{installLabel(__('Install Tailwind blocks', 'proto-blocks'), 'tailwind')}
							</Button>
						</DemoCard>
					</div>
				</div>

				<Separator />

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="max-w-[60ch]">
						<h3 className="text-sm font-medium">{__('Remove demo blocks', 'proto-blocks')}</h3>
						<p className="text-[13px] text-muted-foreground">
							{__('Deletes unmodified demo copies from your theme. Demos you edited and your own blocks are kept.', 'proto-blocks')}
						</p>
					</div>
					{fileModsAllowed && nothingToRemove ? (
						<Tooltip>
							<TooltipTrigger asChild>
								<span tabIndex={0}>{removeButton}</span>
							</TooltipTrigger>
							<TooltipContent>{__('No unmodified demo blocks in your theme.', 'proto-blocks')}</TooltipContent>
						</Tooltip>
					) : (
						removeButton
					)}
				</div>
			</div>

			<ConfirmDialog
				open={confirmOpen}
				title={sprintf(
					/* translators: %d: number of blocks */
					_n('Remove %d demo block?', 'Remove %d demo blocks?', installed.length, 'proto-blocks'),
					installed.length
				)}
				description={interpolate(
					/* translators: %s: folder path */
					__('These folders are deleted from %s. This cannot be undone.', 'proto-blocks'),
					code(`${demos.installPath}/`)
				)}
				confirmLabel={
					removing
						? __('Removing…', 'proto-blocks')
						: sprintf(
								/* translators: %d: number of blocks */
								_n('Remove %d block', 'Remove %d blocks', installed.length, 'proto-blocks'),
								installed.length
							)
				}
				destructive
				onConfirm={remove}
				onCancel={() => {
					if (!removing) {
						setConfirmOpen(false);
					}
				}}
			>
				<ul className="divide-y rounded-md border font-mono text-[13px]">
					{installed.map((name) => (
						<li key={name} className="px-3 py-2">
							{name}
						</li>
					))}
				</ul>
				{conflicts.length > 0 ? (
					<Alert variant="warning" role="status">
						<ShieldCheckIcon aria-hidden="true" />
						<AlertTitle className="line-clamp-none">{__('Kept (modified)', 'proto-blocks')}</AlertTitle>
						<AlertDescription>
							<p>
								{__('You changed these, so they stay:', 'proto-blocks')}{' '}
								<span className="font-mono">{conflicts.join(', ')}</span>
							</p>
						</AlertDescription>
					</Alert>
				) : null}
			</ConfirmDialog>
		</SectionCard>
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

				<ExampleAndDemoBlocks data={data} fileModsAllowed={fileModsAllowed} adminUrl={adminUrl} onData={onData} />

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
				description={__('Every Proto-Block registered on this site, plus the demo blocks you can install.', 'proto-blocks')}
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
							"Blocks can't be installed or removed here. Copy demo blocks into your theme repository instead. Everything else on this screen still works.",
							'proto-blocks'
						)}
					/>
				</div>
			)}
			{body}
		</>
	);
}
