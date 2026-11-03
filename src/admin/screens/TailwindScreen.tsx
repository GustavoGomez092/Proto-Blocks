import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import {
	ChevronDownIcon,
	ChevronRightIcon,
	CircleCheckIcon,
	CircleXIcon,
	DownloadIcon,
	EraserIcon,
	FilePlusIcon,
	FileXIcon,
	CheckIcon,
	LoaderCircleIcon,
	MonitorCheckIcon,
	RefreshCwIcon,
	ShieldAlertIcon,
	ShieldXIcon,
	TerminalIcon,
	TriangleAlertIcon,
} from 'lucide-react';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { READ_ONLY_BANNER_ID, ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { cn } from '@/admin/lib/utils';
import { interpolate } from '@/admin/lib/interpolate';

type Mode = 'cached' | 'on_reload';
type Engine = 'auto' | 'cli' | 'browser';

/** Tailwind\Manager::getStatus(). */
export interface TailwindStatus {
	enabled: boolean;
	mode: Mode;
	disable_global_styles: boolean;
	shell_available: boolean;
	/** Resolved engine. */
	engine: 'cli' | 'browser';
	/** Saved preference. */
	engine_setting: Engine;
	cli_installed: boolean;
	cli_trusted: boolean;
	cli_trust_state: 'missing' | 'trusted' | 'unverified' | 'mismatch';
	cli_functional: boolean;
	cli_version: string | null;
	cache_exists: boolean;
	cache_size: number;
	last_compiled: number | null;
	needs_recompilation: boolean;
}

/** GET admin/tailwind (includes/API/Admin/TailwindRoutes.php). */
export interface TailwindData {
	status: TailwindStatus;
	settings: {
		enabled: boolean;
		mode: Mode;
		engine: Engine;
		disableGlobalStyles: boolean;
		lastCompiled: number | null;
	};
	config: {
		/** Relative to wp-content/themes when inside it. */
		path: string;
		exists: boolean;
		preview: string;
		dirWritable?: boolean;
	};
	cssSizeFormatted: string;
}

type SettingsPatch = Partial<Pick<TailwindData['settings'], 'enabled' | 'mode' | 'engine' | 'disableGlobalStyles'>>;

interface BrowserCompileResult {
	success: boolean;
	message: string;
	cssSizeFormatted?: string;
}

/** Exposed by assets/js/tailwind-compiler.js (src/tailwind/compiler.ts). */
type BrowserCompiler = {
	runBrowserCompile: (deps: { ajaxUrl: string; actionPrefix: string; nonce: string }) => Promise<BrowserCompileResult>;
};

const code = (text: ReactNode) => <code className="font-mono text-[12.5px]">{text}</code>;

function errorMessage(error: unknown): string | undefined {
	return error instanceof Error && error.message ? error.message : undefined;
}

function modeLabel(mode: Mode): string {
	return mode === 'cached' ? __('Cached', 'proto-blocks') : __('On reload', 'proto-blocks');
}

function engineLabel(engine: Engine): string {
	switch (engine) {
		case 'cli':
			return __('CLI binary', 'proto-blocks');
		case 'browser':
			return __('Browser', 'proto-blocks');
		default:
			return __('Automatic', 'proto-blocks');
	}
}

/** "4 minutes ago" in the browser's locale, or "Never". */
function lastCompiledLabel(timestamp: number | null): string {
	if (!timestamp) {
		return __('Never', 'proto-blocks');
	}
	const seconds = Math.round(timestamp - Date.now() / 1000);
	const units: [Intl.RelativeTimeFormatUnit, number][] = [
		['day', 86400],
		['hour', 3600],
		['minute', 60],
	];
	const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
	for (const [unit, size] of units) {
		if (Math.abs(seconds) >= size) {
			return format.format(Math.round(seconds / size), unit);
		}
	}
	return format.format(0, 'minute');
}

type CliState = 'browser' | 'functional' | 'mismatch' | 'unverified' | 'not_runnable' | 'missing';

/** The CLI state, checked in this order. */
export function cliState(status: TailwindStatus): CliState {
	if (status.engine === 'browser') {
		return 'browser';
	}
	if (status.cli_functional) {
		return 'functional';
	}
	if (status.cli_installed && status.cli_trust_state === 'mismatch') {
		return 'mismatch';
	}
	if (status.cli_installed && status.cli_trust_state === 'unverified') {
		return 'unverified';
	}
	if (status.cli_installed) {
		return 'not_runnable';
	}
	return 'missing';
}

function CliStatus({ status }: { status: TailwindStatus }) {
	const state = cliState(status);
	const view: Record<CliState, { icon: ReactNode; tone: string; text: string }> = {
		browser: {
			icon: <MonitorCheckIcon />,
			tone: 'text-success',
			text: __('Browser compiler', 'proto-blocks'),
		},
		functional: {
			icon: <CircleCheckIcon />,
			tone: 'text-success',
			/* translators: %s: Tailwind CLI version, e.g. 4.1.3. */
			text: sprintf(__('Installed · v%s', 'proto-blocks'), status.cli_version ?? ''),
		},
		mismatch: {
			icon: <ShieldXIcon />,
			tone: 'text-destructive',
			text: __('Checksum mismatch — re-download', 'proto-blocks'),
		},
		unverified: {
			icon: <ShieldAlertIcon />,
			tone: 'text-warning',
			text: __('No recorded checksum — re-download', 'proto-blocks'),
		},
		not_runnable: {
			icon: <TriangleAlertIcon />,
			tone: 'text-warning',
			text: __('Installed but not runnable', 'proto-blocks'),
		},
		missing: {
			icon: <CircleXIcon />,
			tone: 'text-destructive',
			text: __('Not installed', 'proto-blocks'),
		},
	};
	const { icon, tone, text } = view[state];

	return (
		<dd className={cn('mt-1 flex items-center gap-2 font-medium [&_svg]:size-4 [&_svg]:flex-none', tone)}>
			<span aria-hidden="true" className="contents">
				{icon}
			</span>
			<span>{text}</span>
		</dd>
	);
}

function SectionCard({ className, ...props }: React.ComponentProps<typeof Card> & { 'aria-labelledby': string }) {
	return <Card role="region" className={cn('gap-0 rounded-lg py-0 shadow-xs', className)} {...props} />;
}

function CardHead({ id, title, description }: { id: string; title: string; description?: ReactNode }) {
	return (
		<div className="grid gap-1 p-5 pb-1">
			<h2 id={id} className="text-[15px] leading-[1.3] font-semibold text-balance">
				{title}
			</h2>
			{description ? <p className="text-[13px] text-muted-foreground">{description}</p> : null}
		</div>
	);
}

/** One radio card: the whole card is the label, the dot stays visible. */
function RadioCard({
	value,
	title,
	help,
	badge,
	disabled,
	checked,
}: {
	value: string;
	title: string;
	help: string;
	badge?: ReactNode;
	disabled?: boolean;
	checked: boolean;
}) {
	const id = useId();
	return (
		<label
			htmlFor={`${id}-item`}
			className={cn(
				'flex cursor-pointer items-start gap-3 rounded-md border bg-background p-3',
				checked && 'border-primary ring-1 ring-primary ring-inset',
				disabled && 'cursor-not-allowed opacity-60'
			)}
		>
			<RadioGroupItem
				id={`${id}-item`}
				value={value}
				disabled={disabled}
				aria-labelledby={`${id}-title`}
				aria-describedby={`${id}-help`}
				className="mt-0.5"
			/>
			<span className="grid gap-0.5">
				<span className="flex flex-wrap items-center gap-2">
					<span id={`${id}-title`} className="font-medium">
						{title}
					</span>
					{badge}
				</span>
				<span id={`${id}-help`} className="text-[13px] text-muted-foreground">
					{help}
				</span>
			</span>
		</label>
	);
}

function SettingsCard({
	data,
	settings,
	onChange,
}: {
	data: TailwindData;
	settings: TailwindData['settings'];
	onChange: (patch: SettingsPatch) => void;
}) {
	const { status } = data;
	// CLI stays selectable when it is the saved value, so the choice is visible.
	const cliUnavailable = !status.shell_available && settings.engine !== 'cli';

	return (
		<SectionCard aria-labelledby="pb-tw-settings">
			<CardHead
				id="pb-tw-settings"
				title={__('Settings', 'proto-blocks')}
				description={__('Changes save as you make them.', 'proto-blocks')}
			/>
			<div className="divide-y px-5">
				<div className="flex items-start justify-between gap-6 py-5">
					<div className="grid gap-1">
						<Label htmlFor="pb-tw-enabled" className="text-sm font-medium">
							{__('Enable Tailwind CSS', 'proto-blocks')}
						</Label>
						<p className="text-[13px] text-muted-foreground">
							{__('Compile and load Tailwind utilities for every Proto-Block.', 'proto-blocks')}
						</p>
					</div>
					<Switch
						id="pb-tw-enabled"
						checked={settings.enabled}
						onCheckedChange={(enabled) => onChange({ enabled })}
					/>
				</div>

				{settings.enabled ? (
					<>
						<div
							role="group"
							aria-labelledby="pb-tw-mode-label"
							className="grid gap-3 py-5 md:grid-cols-[200px_minmax(0,1fr)] md:gap-6"
						>
							<div className="grid content-start gap-1">
								<p id="pb-tw-mode-label" className="text-sm font-medium">
									{__('Compilation Mode', 'proto-blocks')}
								</p>
								<p className="text-[13px] text-muted-foreground">{__('When CSS is rebuilt.', 'proto-blocks')}</p>
							</div>
							<RadioGroup
								aria-labelledby="pb-tw-mode-label"
								value={settings.mode}
								onValueChange={(mode) => onChange({ mode: mode as Mode })}
								className="gap-3 sm:grid-cols-2"
							>
								<RadioCard
									value="cached"
									checked={settings.mode === 'cached'}
									title={__('Cached', 'proto-blocks')}
									help={__('Compiled once and served from cache. Use Compile now to update. For production.', 'proto-blocks')}
								/>
								<RadioCard
									value="on_reload"
									checked={settings.mode === 'on_reload'}
									title={__('On reload', 'proto-blocks')}
									help={__("Recompiled on each page load while you're logged in as an admin. Development only.", 'proto-blocks')}
								/>
							</RadioGroup>
						</div>

						<div
							role="group"
							aria-labelledby="pb-tw-engine-label"
							className="grid gap-3 py-5 md:grid-cols-[200px_minmax(0,1fr)] md:gap-6"
						>
							<div className="grid content-start gap-1">
								<p id="pb-tw-engine-label" className="text-sm font-medium">
									{__('Compile Engine', 'proto-blocks')}
								</p>
								<p className="text-[13px] text-muted-foreground">
									{interpolate(
										/* translators: %s: compile engine name, CLI binary or Browser. */
										__('Currently using: %s', 'proto-blocks'),
										<strong className="text-foreground">{engineLabel(status.engine)}</strong>
									)}
								</p>
							</div>
							<div className="grid min-w-0 gap-3">
								{status.shell_available ? null : (
									<Alert variant="warning">
										<TerminalIcon aria-hidden="true" />
										<AlertTitle className="line-clamp-none">
											{__("Shell access isn't available on this server", 'proto-blocks')}
										</AlertTitle>
										<AlertDescription>
											<p>
												{__(
													"The CLI binary can't run here, so Automatic uses the browser compiler and Download CLI is hidden.",
													'proto-blocks'
												)}
											</p>
										</AlertDescription>
									</Alert>
								)}
								<RadioGroup
									aria-labelledby="pb-tw-engine-label"
									value={settings.engine}
									onValueChange={(engine) => onChange({ engine: engine as Engine })}
									className="gap-3"
								>
									<RadioCard
										value="auto"
										checked={settings.engine === 'auto'}
										title={__('Automatic', 'proto-blocks')}
										badge={
											<Badge variant="secondary" className="rounded-sm">
												{__('Recommended', 'proto-blocks')}
											</Badge>
										}
										help={__(
											'Uses the CLI binary when the server allows shell access, otherwise the browser compiler.',
											'proto-blocks'
										)}
									/>
									<RadioCard
										value="cli"
										checked={settings.engine === 'cli'}
										disabled={cliUnavailable}
										title={__('CLI binary (server)', 'proto-blocks')}
										help={
											cliUnavailable
												? __('Unavailable on this server.', 'proto-blocks')
												: __(
														'Fast, compiles on the server, supports JS config and plugins. Needs shell access and a downloaded binary.',
														'proto-blocks'
													)
										}
									/>
									<RadioCard
										value="browser"
										checked={settings.engine === 'browser'}
										title={__('Browser', 'proto-blocks')}
										help={__(
											"Works on any host. Compiles in an admin's browser with a browser-safe subset of Tailwind (no JS config or plugins).",
											'proto-blocks'
										)}
									/>
								</RadioGroup>
							</div>
						</div>

						<div className="flex items-start justify-between gap-6 py-5">
							<div className="grid gap-1">
								<Label htmlFor="pb-tw-global-styles" className="text-sm font-medium">
									{__('Disable WP Global Styles', 'proto-blocks')}
								</Label>
								<p className="text-[13px] text-muted-foreground">
									{__(
										"Stop WordPress global styles loading on the front end so they don't conflict with Tailwind.",
										'proto-blocks'
									)}
								</p>
							</div>
							<Switch
								id="pb-tw-global-styles"
								checked={settings.disableGlobalStyles}
								onCheckedChange={(disableGlobalStyles) => onChange({ disableGlobalStyles })}
							/>
						</div>
					</>
				) : (
					<div className="py-5">
						<p className="rounded-md bg-muted p-3 text-[13px] text-muted-foreground">
							{__(
								"Turn on Tailwind to choose a compile mode and engine. Your saved settings are kept while it's off.",
								'proto-blocks'
							)}
						</p>
					</div>
				)}
			</div>
		</SectionCard>
	);
}

function ThemeCard({
	config,
	fileModsAllowed,
	busy,
	onCreate,
}: {
	config: TailwindData['config'];
	fileModsAllowed: boolean;
	busy: boolean;
	onCreate: () => void;
}) {
	const [open, setOpen] = useState(false);
	const dirWritable = config.dirWritable !== false;

	return (
		<SectionCard aria-labelledby="pb-tw-theme">
			<CardHead
				id="pb-tw-theme"
				title={__('Theme Configuration', 'proto-blocks')}
				description={interpolate(
					/* translators: %s: @theme */
					__(
						'Your %s tokens are read from a CSS file in the active theme. Edit it in your theme repository; nothing is stored in the database.',
						'proto-blocks'
					),
					code('@theme')
				)}
			/>
			<div className="grid gap-3 p-5 pt-3">
				<div className="flex flex-wrap items-center gap-2">
					<span className="text-[13px] text-muted-foreground">{__('Theme file:', 'proto-blocks')}</span>
					<code className="font-mono text-[12.5px] break-all">{config.path}</code>
					{config.exists ? (
						<Badge variant="outline" className="rounded-sm border-transparent bg-success-soft text-success">
							<CheckIcon aria-hidden="true" />
							{__('Found', 'proto-blocks')}
						</Badge>
					) : (
						<Badge variant="outline" className="rounded-sm text-muted-foreground">
							<FileXIcon aria-hidden="true" />
							{__('Not found', 'proto-blocks')}
						</Badge>
					)}
				</div>

				{config.exists ? (
					<>
						{config.preview ? (
							<div>
								<Button
									variant="ghost"
									size="sm"
									className="-ml-3"
									aria-expanded={open}
									aria-controls="pb-tw-theme-preview"
									onClick={() => setOpen((value) => !value)}
								>
									{open ? <ChevronDownIcon aria-hidden="true" /> : <ChevronRightIcon aria-hidden="true" />}
									{open ? __('Hide file contents', 'proto-blocks') : __('Show file contents', 'proto-blocks')}
								</Button>
							</div>
						) : null}
						{open ? (
							<pre
								id="pb-tw-theme-preview"
								className="max-h-72 overflow-auto rounded-md border bg-[#0f1d2e] p-4 font-mono text-[12.5px] leading-relaxed text-[#e6edf5]"
							>
								{config.preview}
							</pre>
						) : null}
					</>
				) : (
					<>
						<p className="text-[13px] text-muted-foreground">
							{dirWritable
								? __('Create a minimal starter file in your theme, then edit the tokens in your repository.', 'proto-blocks')
								: __('The theme folder is not writable, so add the file through your theme repository.', 'proto-blocks')}
						</p>
						<div>
							<Button
								variant="outline"
								size="sm"
								disabled={!fileModsAllowed || !dirWritable || busy}
								aria-describedby={fileModsAllowed ? undefined : READ_ONLY_BANNER_ID}
								onClick={onCreate}
							>
								<FilePlusIcon aria-hidden="true" />
								{__('Create starter file', 'proto-blocks')}
							</Button>
						</div>
					</>
				)}

				<p className="text-[13px] text-muted-foreground">
					{interpolate(
						/* translators: %s: proto_blocks_theme_css_path */
						__('Override the path with the %s filter.', 'proto-blocks'),
						code('proto_blocks_theme_css_path')
					)}
				</p>
			</div>
		</SectionCard>
	);
}

function StatusCard({
	data,
	busy,
	compiling,
	fileModsAllowed,
	onCompile,
	onDownload,
	onClear,
}: {
	data: TailwindData;
	busy: boolean;
	compiling: boolean;
	fileModsAllowed: boolean;
	onCompile: () => void;
	onDownload: () => void;
	onClear: () => void;
}) {
	const { status } = data;
	const state = cliState(status);
	const needsCli = status.engine === 'cli' && !status.cli_functional;
	const tile = 'rounded-md bg-muted p-3';
	const overline = 'text-[11px] font-medium tracking-wider text-muted-foreground uppercase';

	let downloadLabel: string = __('Re-download CLI', 'proto-blocks');
	if (status.cli_functional) {
		downloadLabel = __('Update CLI', 'proto-blocks');
	} else if (!status.cli_installed) {
		downloadLabel = __('Download CLI', 'proto-blocks');
	}

	return (
		<SectionCard aria-labelledby="pb-tw-status">
			<CardHead id="pb-tw-status" title={__('Compiler status', 'proto-blocks')} />
			<div className="grid gap-3 p-5 pt-3">
				<dl className="grid gap-3">
					<div className={tile}>
						<dt className={overline}>{__('CLI Status', 'proto-blocks')}</dt>
						<CliStatus status={status} />
					</div>
					<div className="grid grid-cols-2 gap-3">
						<div className={tile}>
							<dt className={overline}>{__('Last Compiled', 'proto-blocks')}</dt>
							<dd className="mt-1 font-medium tabular-nums">{lastCompiledLabel(data.settings.lastCompiled)}</dd>
						</div>
						<div className={tile}>
							<dt className={overline}>{__('CSS Size', 'proto-blocks')}</dt>
							<dd className="mt-1 font-medium tabular-nums">{data.cssSizeFormatted}</dd>
						</div>
					</div>
				</dl>

				<Button
					className="w-full"
					disabled={busy || needsCli}
					aria-describedby={needsCli ? 'pb-tw-compile-help' : undefined}
					onClick={onCompile}
				>
					{compiling ? (
						<>
							<LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" />
							{__('Compiling…', 'proto-blocks')}
						</>
					) : (
						<>
							<RefreshCwIcon aria-hidden="true" />
							{__('Compile now', 'proto-blocks')}
						</>
					)}
				</Button>
				{needsCli ? (
					<p id="pb-tw-compile-help" className="-mt-1 text-[13px] text-muted-foreground">
						{__('Download a working CLI first.', 'proto-blocks')}
					</p>
				) : null}

				<div className={cn('grid gap-2', status.shell_available && state !== 'browser' ? 'grid-cols-2' : 'grid-cols-1')}>
					{status.shell_available && state !== 'browser' ? (
						<Button
							variant="outline"
							size="sm"
							disabled={busy || !fileModsAllowed}
							aria-describedby={fileModsAllowed ? undefined : READ_ONLY_BANNER_ID}
							onClick={onDownload}
						>
							<DownloadIcon aria-hidden="true" />
							{downloadLabel}
						</Button>
					) : null}
					<Button
						variant="outline"
						size="sm"
						className="border-destructive/40 text-destructive hover:bg-destructive-soft hover:text-destructive"
						disabled={busy}
						onClick={onClear}
					>
						<EraserIcon aria-hidden="true" />
						{__('Clear cache', 'proto-blocks')}
					</Button>
				</div>
			</div>
		</SectionCard>
	);
}

function LoadingState() {
	return (
		<div aria-busy="true" className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
			<div className="grid gap-6">
				<Skeleton className="h-80 rounded-lg" />
				<Skeleton className="h-40 rounded-lg" />
			</div>
			<Skeleton className="h-64 rounded-lg" />
		</div>
	);
}

type LoadState = { status: 'loading' } | { status: 'error'; error: unknown } | { status: 'ready'; data: TailwindData };

export default function TailwindScreen() {
	const bootstrap = getBootstrap();
	const { fileModsAllowed } = bootstrap;
	const [state, setState] = useState<LoadState>({ status: 'loading' });
	/** Optimistic settings while a change is saving; null when in sync. */
	const [pending, setPending] = useState<TailwindData['settings'] | null>(null);
	const [busy, setBusy] = useState<null | 'compile' | 'download' | 'clear' | 'theme'>(null);
	const [confirmClear, setConfirmClear] = useState(false);
	const mounted = useRef(true);

	useEffect(
		() => () => {
			mounted.current = false;
		},
		[]
	);

	/** Fetch admin/tailwind; `quiet` keeps the current screen up while it loads. */
	const load = useCallback(async (quiet = false): Promise<TailwindData | null> => {
		if (!quiet) {
			setState({ status: 'loading' });
		}
		try {
			const data = await adminGet<TailwindData>('admin/tailwind');
			if (mounted.current && data) {
				setState({ status: 'ready', data });
			}
			return data ?? null;
		} catch (error) {
			if (mounted.current && !quiet) {
				setState({ status: 'error', error });
			}
			return null;
		}
	}, []);

	useEffect(() => {
		void load();
	}, [load]);

	if (state.status === 'loading') {
		return (
			<>
				<Header />
				<LoadingState />
			</>
		);
	}
	if (state.status === 'error') {
		return (
			<>
				<Header />
				<ScreenError
					screen={__('Tailwind Settings', 'proto-blocks')}
					error={state.error instanceof ApiError ? state.error : undefined}
					onRetry={() => void load()}
				/>
			</>
		);
	}

	const { data } = state;
	const settings = pending ?? data.settings;

	const changeSettings = async (patch: SettingsPatch) => {
		setPending({ ...settings, ...patch });
		try {
			await adminPost<TailwindData>('admin/tailwind/settings', patch);
			toast.success(settingsToast(patch));
		} catch (error) {
			toast.error(__("Couldn't save the setting.", 'proto-blocks'), { description: errorMessage(error) });
		}
		await load(true);
		if (mounted.current) {
			setPending(null);
		}
	};

	const compile = async () => {
		setBusy('compile');
		try {
			if (data.status.engine === 'browser') {
				const compiler = (window as unknown as { ProtoBlocksTailwind?: BrowserCompiler }).ProtoBlocksTailwind;
				if (!compiler || !bootstrap.tailwind) {
					toast.error(__('Compile failed.', 'proto-blocks'), {
						description: __("The browser compiler didn't load. Reload the page and try again.", 'proto-blocks'),
					});
				} else {
					const { ajaxUrl, actionPrefix, nonce } = bootstrap.tailwind;
					const result = await compiler.runBrowserCompile({ ajaxUrl, actionPrefix, nonce });
					if (result.success) {
						toast.success(result.message, { description: result.cssSizeFormatted });
					} else {
						toast.error(__('Compile failed.', 'proto-blocks'), { description: result.message });
					}
				}
			} else {
				const next = await adminPost<TailwindData>('admin/tailwind/compile');
				toast.success(__('Tailwind CSS compiled.', 'proto-blocks'), { description: next?.cssSizeFormatted });
			}
		} catch (error) {
			toast.error(__('Compile failed.', 'proto-blocks'), { description: errorMessage(error) });
		}
		await load(true);
		if (mounted.current) {
			setBusy(null);
		}
	};

	const downloadCli = async () => {
		setBusy('download');
		try {
			await adminPost<TailwindData>('admin/tailwind/cli');
			const next = await load(true);
			const version = next?.status.cli_version;
			toast.success(
				version
					? /* translators: %s: Tailwind CLI version, e.g. 4.1.3. */
						sprintf(__('Tailwind CLI v%s installed.', 'proto-blocks'), version)
					: __('Tailwind CLI installed.', 'proto-blocks')
			);
		} catch (error) {
			toast.error(__("Couldn't download the Tailwind CLI.", 'proto-blocks'), { description: errorMessage(error) });
			await load(true);
		}
		if (mounted.current) {
			setBusy(null);
		}
	};

	const clearCache = async () => {
		setBusy('clear');
		try {
			await adminDelete<TailwindData>('admin/tailwind/cache');
			toast.success(__('Cache cleared.', 'proto-blocks'));
		} catch (error) {
			toast.error(__("Couldn't clear the cache.", 'proto-blocks'), { description: errorMessage(error) });
		}
		if (mounted.current) {
			setConfirmClear(false);
		}
		await load(true);
		if (mounted.current) {
			setBusy(null);
		}
	};

	const createThemeFile = async () => {
		setBusy('theme');
		try {
			await adminPost<TailwindData>('admin/tailwind/theme-file');
			toast.success(__('Created tailwind-theme.css in your theme.', 'proto-blocks'));
		} catch (error) {
			toast.error(__("Couldn't create the theme file.", 'proto-blocks'), { description: errorMessage(error) });
		}
		await load(true);
		if (mounted.current) {
			setBusy(null);
		}
	};

	return (
		<>
			<Header />
			{fileModsAllowed ? null : (
				<div className="pb-6">
					<ReadOnlyBanner
						reason="file_mods"
						description={__(
							'Download CLI and Create starter file are off. Settings, compiling and clearing the cache still work.',
							'proto-blocks'
						)}
					/>
				</div>
			)}
			{settings.enabled ? (
				<div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
					<div className="grid min-w-0 gap-6">
						<SettingsCard data={data} settings={settings} onChange={(patch) => void changeSettings(patch)} />
						<ThemeCard
							config={data.config}
							fileModsAllowed={fileModsAllowed}
							busy={busy !== null}
							onCreate={() => void createThemeFile()}
						/>
					</div>
					<aside className="grid min-w-0 gap-6 lg:sticky lg:top-10" aria-label={__('Compiler', 'proto-blocks')}>
						<StatusCard
							data={data}
							busy={busy !== null}
							compiling={busy === 'compile'}
							fileModsAllowed={fileModsAllowed}
							onCompile={() => void compile()}
							onDownload={() => void downloadCli()}
							onClear={() => setConfirmClear(true)}
						/>
					</aside>
				</div>
			) : (
				<div className="max-w-[760px]">
					<SettingsCard data={data} settings={settings} onChange={(patch) => void changeSettings(patch)} />
				</div>
			)}
			<ConfirmDialog
				open={confirmClear}
				destructive
				title={__('Clear the Tailwind cache?', 'proto-blocks')}
				description={__('Compiled CSS is deleted. Blocks render unstyled until you compile again.', 'proto-blocks')}
				confirmLabel={__('Clear cache', 'proto-blocks')}
				onConfirm={() => void clearCache()}
				onCancel={() => setConfirmClear(false)}
			/>
		</>
	);
}

function Header() {
	return (
		<PageHeader
			title={__('Tailwind Settings', 'proto-blocks')}
			description={__('Compile Tailwind utility classes for your blocks without a Node.js build step.', 'proto-blocks')}
		/>
	);
}

function settingsToast(patch: SettingsPatch): string {
	if (patch.enabled !== undefined) {
		return patch.enabled ? __('Tailwind CSS enabled.', 'proto-blocks') : __('Tailwind CSS disabled.', 'proto-blocks');
	}
	if (patch.mode !== undefined) {
		/* translators: %s: compilation mode, Cached or On reload. */
		return sprintf(__('Compilation mode set to %s.', 'proto-blocks'), modeLabel(patch.mode));
	}
	if (patch.engine !== undefined) {
		/* translators: %s: compile engine, Automatic, CLI binary or Browser. */
		return sprintf(__('Compile engine set to %s.', 'proto-blocks'), engineLabel(patch.engine));
	}
	return patch.disableGlobalStyles
		? __('WordPress global styles turned off on the front end.', 'proto-blocks')
		: __('WordPress global styles turned back on.', 'proto-blocks');
}
