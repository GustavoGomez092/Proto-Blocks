import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import {
	CheckIcon,
	ChevronDownIcon,
	ChevronRightIcon,
	DownloadIcon,
	EraserIcon,
	FilePlusIcon,
	LoaderCircleIcon,
	RefreshCwIcon,
} from 'lucide-react';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { READ_ONLY_BANNER_ID, ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { Panel, SettingRow } from '@/admin/components/shell/Panel';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Eyebrow } from '@/admin/components/shell/Signals';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem, SegmentedRadioItem, segmentedGroupClassName } from '@/admin/components/ui/radio-group';
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
	/** Compiled CSS size in bytes. */
	cssSize?: number;
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

/** "just now" under a minute, then "4 minutes ago" in the browser's locale, or "Never". */
export function lastCompiledLabel(timestamp: number | null, now: number = Date.now()): string {
	if (!timestamp) {
		return __('Never', 'proto-blocks');
	}
	const seconds = Math.round(timestamp - now / 1000);
	if (Math.abs(seconds) < 60) {
		return __('just now', 'proto-blocks');
	}
	const units: [Intl.RelativeTimeFormatUnit, number][] = [
		['day', 86400],
		['hour', 3600],
		['minute', 60],
	];
	const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'always' });
	for (const [unit, size] of units) {
		if (Math.abs(seconds) >= size) {
			return format.format(Math.round(seconds / size), unit);
		}
	}
	return __('just now', 'proto-blocks');
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

/** CLI / engine status for the dark compiler panel: label plus a tone for the dot. */
function cliView(status: TailwindStatus): { tone: 'ok' | 'warn' | 'bad'; text: string } {
	switch (cliState(status)) {
		case 'browser':
			return { tone: 'ok', text: __('Browser compiler', 'proto-blocks') };
		case 'functional':
			/* translators: %s: Tailwind CLI version, e.g. 4.1.3. */
			return { tone: 'ok', text: sprintf(__('Installed · v%s', 'proto-blocks'), status.cli_version ?? '') };
		case 'mismatch':
			return { tone: 'bad', text: __('Checksum mismatch — re-download', 'proto-blocks') };
		case 'unverified':
			return { tone: 'warn', text: __('No recorded checksum — re-download', 'proto-blocks') };
		case 'not_runnable':
			return { tone: 'warn', text: __('Installed but not runnable', 'proto-blocks') };
		default:
			return { tone: 'bad', text: __('Not installed', 'proto-blocks') };
	}
}

const PANEL_TONE: Record<'ok' | 'warn' | 'bad', string> = {
	ok: 'text-brand',
	warn: 'text-warning-border',
	bad: 'text-[#f4a4a8]',
};

/** One engine choice: the whole card is the label; the radio dot sits top right. */
function RadioCard({
	value,
	title,
	help,
	disabled,
	checked,
}: {
	value: string;
	title: string;
	help: string;
	disabled?: boolean;
	checked: boolean;
}) {
	const id = useId();
	return (
		<label
			htmlFor={`${id}-item`}
			className={cn(
				'grid cursor-pointer content-start gap-2 rounded-lg border bg-background p-3.5 transition-colors hover:border-input',
				checked && 'border-primary bg-code shadow-[inset_0_0_0_1px_var(--primary)] hover:border-primary',
				disabled && 'cursor-not-allowed bg-subtle opacity-60 hover:border-border'
			)}
		>
			<span className="flex items-center justify-between gap-3">
				<span id={`${id}-title`} className="font-medium">
					{title}
				</span>
				<RadioGroupItem
					id={`${id}-item`}
					value={value}
					disabled={disabled}
					aria-labelledby={`${id}-title`}
					aria-describedby={`${id}-help`}
				/>
			</span>
			<span id={`${id}-help`} className="text-caption text-muted-foreground">
				{help}
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
	const strong = (text: ReactNode) => <strong className="font-medium text-foreground">{text}</strong>;

	return (
		<Panel aria-labelledby="pb-tw-settings">
			<div className="flex items-center justify-between gap-6 border-b px-6 py-5">
				<div className="grid gap-0.5">
					<h2 id="pb-tw-settings" className="text-section font-semibold">
						{__('Tailwind CSS', 'proto-blocks')}
					</h2>
					<p className="text-label text-muted-foreground">
						{__('Compile and load utilities for every Proto-Block. Changes save as you make them.', 'proto-blocks')}
					</p>
				</div>
				<Switch
					id="pb-tw-enabled"
					aria-label={__('Enable Tailwind CSS', 'proto-blocks')}
					checked={settings.enabled}
					onCheckedChange={(enabled) => onChange({ enabled })}
				/>
			</div>

			{settings.enabled ? (
				<>
					<SettingRow
						labelId="pb-tw-mode-label"
						label={__('Compilation mode', 'proto-blocks')}
						help={__('When the CSS is rebuilt.', 'proto-blocks')}
						className="border-b"
					>
						<div className="grid gap-2.5">
							<RadioGroup
								aria-labelledby="pb-tw-mode-label"
								value={settings.mode}
								onValueChange={(mode) => onChange({ mode: mode as Mode })}
								className={segmentedGroupClassName}
							>
								<SegmentedRadioItem value="cached">{__('Cached', 'proto-blocks')}</SegmentedRadioItem>
								<SegmentedRadioItem value="on_reload">{__('On reload', 'proto-blocks')}</SegmentedRadioItem>
							</RadioGroup>
							<p className="text-label text-muted-foreground">
								{settings.mode === 'cached'
									? interpolate(
											/* translators: 1: "Compile now" (button label), 2: "On reload" (mode name). */
											__('Compiled once and served from cache — use %1$s after changing templates. Switch to %2$s while developing; it recompiles on every admin page load.', 'proto-blocks'),
											strong(__('Compile now', 'proto-blocks')),
											<em>{__('On reload', 'proto-blocks')}</em>
										)
									: __("Recompiled on each page load while you're logged in as an admin. Development only.", 'proto-blocks')}
							</p>
						</div>
					</SettingRow>

					<SettingRow
						labelId="pb-tw-engine-label"
						label={__('Compile engine', 'proto-blocks')}
						help={
							status.shell_available
								? interpolate(
										/* translators: %s: compile engine name, CLI binary or Browser. */
										__('Resolved to %s.', 'proto-blocks'),
										strong(engineLabel(status.engine))
									)
								: interpolate(
										/* translators: %s: compile engine name, CLI binary or Browser. */
										__('Resolved to %s — shell access is unavailable on this host.', 'proto-blocks'),
										strong(engineLabel(status.engine))
									)
						}
						className="border-b"
					>
						<RadioGroup
							aria-labelledby="pb-tw-engine-label"
							value={settings.engine}
							onValueChange={(engine) => onChange({ engine: engine as Engine })}
							className="gap-2.5 sm:grid-cols-3"
						>
							<RadioCard
								value="auto"
								checked={settings.engine === 'auto'}
								title={__('Automatic', 'proto-blocks')}
								help={__('CLI when the server allows it, otherwise browser. Recommended.', 'proto-blocks')}
							/>
							<RadioCard
								value="cli"
								checked={settings.engine === 'cli'}
								disabled={cliUnavailable}
								title={__('CLI binary', 'proto-blocks')}
								help={
									cliUnavailable
										? __('Unavailable on this server: it needs shell access.', 'proto-blocks')
										: __('Fastest; supports JS config and plugins. Needs shell access.', 'proto-blocks')
								}
							/>
							<RadioCard
								value="browser"
								checked={settings.engine === 'browser'}
								title={__('Browser', 'proto-blocks')}
								help={__('Works on any host. Browser-safe subset of Tailwind (no JS config).', 'proto-blocks')}
							/>
						</RadioGroup>
					</SettingRow>

					<div className="flex items-center justify-between gap-6 px-6 py-5">
						<div className="grid gap-0.5">
							<Label htmlFor="pb-tw-global-styles">{__('Disable WordPress global styles', 'proto-blocks')}</Label>
							<p className="text-label text-muted-foreground">
								{interpolate(
									/* translators: %s: global-styles-inline-css (a stylesheet handle). */
									__("Stops %s loading on the front end so it can't fight your utilities.", 'proto-blocks'),
									<code className="font-mono text-micro">global-styles-inline-css</code>
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
				<div className="p-6">
					<p className="rounded-lg bg-muted px-4 py-3 text-label text-muted-foreground">
						{__(
							"Turn on Tailwind to choose a compile mode and engine. Your saved settings are kept while it's off.",
							'proto-blocks'
						)}
					</p>
				</div>
			)}
		</Panel>
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
		<Panel aria-labelledby="pb-tw-theme" className="grid gap-2 px-[22px] py-[18px]">
			<div className="flex items-center justify-between gap-3">
				<h2 id="pb-tw-theme" className="text-label font-semibold">
					{__('Theme configuration', 'proto-blocks')}
				</h2>
				{config.exists ? (
					<Badge variant="success">
						<CheckIcon aria-hidden="true" strokeWidth={2.5} />
						{__('Found', 'proto-blocks')}
					</Badge>
				) : (
					<Badge variant="dashed">{__('Not found', 'proto-blocks')}</Badge>
				)}
			</div>
			<code className="font-mono text-micro break-all text-link">{config.path}</code>
			<p className="text-caption text-muted-foreground">
				{interpolate(
					/* translators: %s: @theme */
					__('Your %s tokens live in the theme repo; nothing is stored in the database.', 'proto-blocks'),
					<code className="font-mono text-micro">@theme</code>
				)}
			</p>

			{config.exists ? (
				config.preview ? (
					<>
						<div>
							<Button
								variant="link"
								className="text-label"
								aria-expanded={open}
								aria-controls="pb-tw-theme-preview"
								onClick={() => setOpen((value) => !value)}
							>
								{open ? <ChevronDownIcon aria-hidden="true" /> : <ChevronRightIcon aria-hidden="true" />}
								{open ? __('Hide file contents', 'proto-blocks') : __('Show file contents', 'proto-blocks')}
							</Button>
						</div>
						{open ? (
							<pre
								id="pb-tw-theme-preview"
								className="max-h-72 overflow-auto rounded-lg bg-panel p-4 font-mono text-micro leading-relaxed text-panel-foreground"
							>
								{config.preview}
							</pre>
						) : null}
					</>
				) : null
			) : (
				<>
					<p className="text-caption text-muted-foreground">
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

			<p className="text-micro text-muted-foreground">
				{interpolate(
					/* translators: %s: proto_blocks_theme_css_path */
					__('Override the path with the %s filter.', 'proto-blocks'),
					<code className="font-mono text-micro">proto_blocks_theme_css_path</code>
				)}
			</p>
		</Panel>
	);
}

function StatusCard({
	data,
	busy,
	compiling,
	fileModsAllowed,
	onCompile,
	onDownload,
}: {
	data: TailwindData;
	busy: boolean;
	compiling: boolean;
	fileModsAllowed: boolean;
	onCompile: () => void;
	onDownload: () => void;
}) {
	const { status } = data;
	const state = cliState(status);
	const view = cliView(status);
	const needsCli = status.engine === 'cli' && !status.cli_functional;
	const themeFile = data.config.path.split('/').pop() || data.config.path;
	const row = 'flex justify-between gap-3 text-label';
	const term = 'text-faint';

	let downloadLabel: string = __('Re-download CLI', 'proto-blocks');
	if (status.cli_functional) {
		downloadLabel = __('Update CLI', 'proto-blocks');
	} else if (!status.cli_installed) {
		downloadLabel = __('Download CLI', 'proto-blocks');
	}

	return (
		<section
			aria-labelledby="pb-tw-status"
			className="grid gap-4 rounded-xl bg-panel px-[22px] py-5 text-panel-foreground"
		>
			<div className="flex items-center justify-between gap-3">
				<h2 id="pb-tw-status">
					<Eyebrow tone="panel">{__('Compiler', 'proto-blocks')}</Eyebrow>
				</h2>
				<span className={cn('inline-flex items-center gap-1.5 text-caption', PANEL_TONE[needsCli ? 'bad' : status.needs_recompilation ? 'warn' : view.tone])}>
					<span aria-hidden="true" className="size-[7px] rounded-full bg-current" />
					{needsCli
						? __('Needs the CLI', 'proto-blocks')
						: status.needs_recompilation
							? __('Out of date', 'proto-blocks')
							: __('Ready', 'proto-blocks')}
				</span>
			</div>
			<dl className="grid gap-3">
				<div className={row}>
					<dt className={term}>{__('Engine', 'proto-blocks')}</dt>
					<dd className={cn('text-right', view.tone === 'ok' ? undefined : PANEL_TONE[view.tone])}>{view.text}</dd>
				</div>
				<div className={row}>
					<dt className={term}>{__('Last compiled', 'proto-blocks')}</dt>
					<dd className="tabular-nums">{lastCompiledLabel(data.cssSize === 0 ? null : data.settings.lastCompiled)}</dd>
				</div>
				<div className={row}>
					<dt className={term}>{__('Output', 'proto-blocks')}</dt>
					<dd className="font-mono text-caption tabular-nums">{data.cssSizeFormatted}</dd>
				</div>
				<div className={row}>
					<dt className={term}>{__('Theme tokens', 'proto-blocks')}</dt>
					<dd className="truncate font-mono text-caption">{data.config.exists ? themeFile : '—'}</dd>
				</div>
			</dl>

			<Button
				className="w-full bg-brand font-semibold text-panel hover:bg-[#6fd8d1] hover:text-panel focus-visible:outline-offset-[3px]"
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
				<p id="pb-tw-compile-help" className="-mt-2 text-label text-faint">
					{__('Download a working CLI first.', 'proto-blocks')}
				</p>
			) : null}

			{status.shell_available && state !== 'browser' ? (
				<Button
					variant="outline"
					size="sm"
					className="w-full border-panel-line bg-transparent text-panel-foreground hover:border-faint hover:bg-white/5 hover:text-panel-foreground"
					disabled={busy || !fileModsAllowed}
					aria-describedby={fileModsAllowed ? undefined : READ_ONLY_BANNER_ID}
					onClick={onDownload}
				>
					<DownloadIcon aria-hidden="true" />
					{downloadLabel}
				</Button>
			) : null}
		</section>
	);
}

function LoadingState() {
	return (
		<div aria-busy="true" className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
			<Skeleton className="h-[480px] rounded-xl" />
			<div className="grid gap-4">
				<Skeleton className="h-64 rounded-xl" />
				<Skeleton className="h-36 rounded-xl" />
			</div>
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
				<Header actions={null} />
				<LoadingState />
			</>
		);
	}
	if (state.status === 'error') {
		return (
			<>
				<Header actions={null} />
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
			<Header
				actions={
					settings.enabled ? (
						<Button variant="outline" disabled={busy !== null} onClick={() => setConfirmClear(true)}>
							<EraserIcon aria-hidden="true" />
							{__('Clear cache', 'proto-blocks')}
						</Button>
					) : null
				}
			/>
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
					<SettingsCard data={data} settings={settings} onChange={(patch) => void changeSettings(patch)} />
					<aside className="grid min-w-0 gap-4 lg:sticky lg:top-10" aria-label={__('Compiler', 'proto-blocks')}>
						<StatusCard
							data={data}
							busy={busy !== null}
							compiling={busy === 'compile'}
							fileModsAllowed={fileModsAllowed}
							onCompile={() => void compile()}
							onDownload={() => void downloadCli()}
						/>
						<ThemeCard
							config={data.config}
							fileModsAllowed={fileModsAllowed}
							busy={busy !== null}
							onCreate={() => void createThemeFile()}
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

function Header({ actions }: { actions: ReactNode }) {
	return (
		<PageHeader
			eyebrow={__('Styling', 'proto-blocks')}
			title={__('Tailwind', 'proto-blocks')}
			description={__('Compile utility classes for your blocks without a Node.js build step.', 'proto-blocks')}
			actions={actions}
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
