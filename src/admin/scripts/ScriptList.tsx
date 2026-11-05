import { __, sprintf } from '@wordpress/i18n';
import { PlusIcon, PowerOffIcon, TriangleAlertIcon } from 'lucide-react';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { DoubleBadge, ORIGIN_TONES } from '@/admin/components/content-model/OriginBadge';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { Panel } from '@/admin/components/shell/Panel';
import { READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Dot, Eyebrow } from '@/admin/components/shell/Signals';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { interpolate } from '@/admin/lib/interpolate';
import { cn } from '@/admin/lib/utils';
import { printOrder } from './order';
import { targetLabel } from './schema';
import { ScriptsReadOnlyBanner } from './ScriptsReadOnlyBanner';
import { SCRIPT_TEMPLATES } from './templates';
import { LOCATIONS, type Location, type ScriptRow, type ScriptsIndex, type Where } from './types';

const LOCATION_BADGES: Record<Location, { short: string; long: string }> = {
	head: { short: 'head', long: __('In <head>', 'proto-blocks') },
	bodyOpen: { short: 'body', long: __('After <body>', 'proto-blocks') },
	bodyClose: { short: 'footer', long: __('Before </body>', 'proto-blocks') },
};

/** Tags drawn around each location in the print-order aside. */
const TAG: Record<Location, [string, string]> = {
	head: ['<head>', '</head>'],
	bodyOpen: ['<body>', ''],
	bodyClose: ['', '</body>'],
};

/** Mono head / body / footer chips: teal wash where the script prints, muted where it does not. */
export function LocationBadges({ locations }: { locations: Location[] }) {
	return (
		<span className="inline-flex gap-1">
			{LOCATIONS.map((location) => {
				const on = locations.includes(location);
				const { short, long } = LOCATION_BADGES[location];
				return (
					<span
						key={location}
						title={long}
						data-on={on}
						aria-label={on ? long : sprintf(/* translators: %s: a page location */ __('Nothing %s', 'proto-blocks'), long.toLowerCase())}
						className={cn(
							'rounded-sm px-[7px] font-mono text-eyebrow leading-5 font-medium',
							on ? 'bg-brand-soft text-brand-ink' : 'bg-muted text-input'
						)}
					>
						{short}
					</span>
				);
			})}
		</span>
	);
}

export const roleName = (roles: { slug: string; name: string }[], slug: string) => roles.find((r) => r.slug === slug)?.name ?? slug;

const rolePill = 'rounded-full bg-code px-1.5 text-caption text-link';

/** "All pages except page:privacy-policy · hidden from Administrator", as chips. */
export function WhereCell({ where, roles }: { where: Where; roles: { slug: string; name: string }[] }) {
	const include = where.include.length === 0 ? __('Nowhere', 'proto-blocks') : where.include.map(targetLabel).join(', ');
	return (
		<div className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-label">
			<span>{include}</span>
			{where.loggedIn !== null ? (
				<span className="text-muted-foreground">
					{where.loggedIn ? __('· logged-in visitors', 'proto-blocks') : __('· logged-out visitors', 'proto-blocks')}
				</span>
			) : null}
			{where.exclude.length > 0 ? (
				<>
					<span className="text-muted-foreground">{__('except', 'proto-blocks')}</span>
					{where.exclude.map((target) => (
						<code key={target} className="rounded-sm bg-destructive-soft px-1.5 font-mono text-micro text-destructive">
							{target}
						</code>
					))}
				</>
			) : null}
			{where.roles.length > 0 ? (
				<>
					<span className="text-muted-foreground">{__('· only for', 'proto-blocks')}</span>
					{where.roles.map((slug) => (
						<span key={slug} className={rolePill}>
							{roleName(roles, slug)}
						</span>
					))}
				</>
			) : null}
			{where.excludeRoles.length > 0 ? (
				<>
					<span className="text-muted-foreground">{__('· hidden from', 'proto-blocks')}</span>
					{where.excludeRoles.map((slug) => (
						<span key={slug} className={rolePill}>
							{roleName(roles, slug)}
						</span>
					))}
				</>
			) : null}
		</div>
	);
}

/** Dark aside: what prints at each location, in order. */
function PrintOrder({ items }: { items: ScriptRow[] }) {
	const order = printOrder(items);
	return (
		<aside aria-labelledby="pb-scripts-order" className="grid gap-3.5 rounded-xl bg-panel px-[22px] py-5 text-panel-foreground">
			<h2 id="pb-scripts-order">
				<Eyebrow tone="panel">{__('Rendered on the front end', 'proto-blocks')}</Eyebrow>
			</h2>
			<pre data-testid="print-order" className="m-0 grid overflow-hidden font-mono text-micro leading-[1.75] whitespace-pre text-input">
				{LOCATIONS.map((location) => (
					<span key={location} className="contents">
						{TAG[location][0] ? <span>{TAG[location][0]}</span> : null}
						{order[location].length === 0 ? (
							<span className="text-faint">{'  ' + __('(nothing)', 'proto-blocks')}</span>
						) : (
							order[location].map((entry) => (
								<span key={entry.slug} className={entry.enabled ? 'text-brand' : 'text-faint line-through'}>
									{'  ▸ ' +
										(entry.enabled
											? entry.title
											: sprintf(/* translators: %s: script title */ __('%s (off)', 'proto-blocks'), entry.title))}
								</span>
							))
						)}
						{TAG[location][1] ? <span>{TAG[location][1]}</span> : null}
					</span>
				))}
			</pre>
			<p className="text-caption text-faint">
				{__('Print order at each location. Each script’s placement still decides which pages and visitors get it.', 'proto-blocks')}
			</p>
		</aside>
	);
}

/** Empty state: what a script is and the templates to start from. */
function EmptyScripts({ readOnly, onCreate }: { readOnly: boolean; onCreate: (template: string | null) => void }) {
	const tile =
		'grid cursor-pointer gap-0.5 rounded-lg border px-3.5 py-3 text-left transition-colors hover:border-primary disabled:cursor-not-allowed disabled:opacity-50';
	const describedBy = readOnly ? READ_ONLY_BANNER_ID : undefined;
	return (
		<Panel aria-labelledby="pb-scripts-empty" className="grid gap-5 p-8">
			<div className="grid gap-2">
				<Eyebrow tone="brand">{__('No scripts yet', 'proto-blocks')}</Eyebrow>
				<h2 id="pb-scripts-empty" className="text-[20px] leading-tight font-semibold tracking-[-.01em]">
					{__('Keep tracking tags out of the theme’s PHP.', 'proto-blocks')}
				</h2>
				<p className="text-label text-muted-foreground">
					{interpolate(
						/* translators: %s: the file name script.jsonc */
						__('Each script is a folder with a %s and up to three HTML snippets. Start from a template:', 'proto-blocks'),
						<code className="font-mono text-micro text-link">script.jsonc</code>
					)}
				</p>
			</div>
			<div className="grid gap-2 sm:grid-cols-2">
				{SCRIPT_TEMPLATES.map((template) => (
					<button
						key={template.id}
						type="button"
						disabled={readOnly}
						aria-describedby={describedBy}
						className={cn(tile, 'bg-card hover:bg-code')}
						onClick={() => onCreate(template.id)}
					>
						<span className="font-medium">{template.title}</span>
						<span className="text-caption text-muted-foreground">{template.description}</span>
					</button>
				))}
				<button
					type="button"
					disabled={readOnly}
					aria-describedby={describedBy}
					className={cn(tile, 'border-dashed border-input bg-transparent text-muted-foreground hover:text-primary')}
					onClick={() => onCreate(null)}
				>
					<span className="font-medium">{__('Blank script', 'proto-blocks')}</span>
					<span className="text-caption">{__('Start from nothing', 'proto-blocks')}</span>
				</button>
			</div>
		</Panel>
	);
}

/** The Scripts list: every file and PHP script with its locations, scope and origin. */
export function ScriptList({
	result,
	loading,
	error,
	onRetry,
	onEdit,
	onCreate,
	onToggle,
	busy = null,
}: {
	result: ScriptsIndex | null;
	loading: boolean;
	error: unknown;
	onRetry: () => void;
	onEdit: (slug: string) => void;
	/** Opens the create screen, prefilled from a template id (null: blank). */
	onCreate: (template: string | null) => void;
	onToggle: (slug: string, enabled: boolean) => void;
	/** Slug whose switch is saving. */
	busy?: string | null;
}) {
	const title = __('Scripts', 'proto-blocks');
	const enabled = result?.moduleEnabled !== false;
	const readOnly = result !== null && !result.canWrite;
	const empty = result !== null && enabled && result.items.length === 0;
	const header = (
		<PageHeader
			title={title}
			eyebrow={__('Site', 'proto-blocks')}
			description={
				empty
					? undefined
					: interpolate(
							/* translators: %s: the folder name proto-scripts/ */
							__('Tracking tags, verification metas and widgets printed into the page — from files in %s.', 'proto-blocks'),
							<code className="font-mono text-micro text-link">proto-scripts/</code>
						)
			}
			actions={
				result && enabled && !empty ? (
					<Button onClick={() => onCreate(null)} disabled={readOnly} aria-describedby={readOnly ? READ_ONLY_BANNER_ID : undefined}>
						<PlusIcon aria-hidden="true" />
						{__('New script', 'proto-blocks')}
					</Button>
				) : null
			}
		/>
	);

	if (error && !result) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header}
				<ScreenError screen={title} error={error} onRetry={onRetry} />
			</div>
		);
	}
	if (!result) {
		return (
			<div className="grid gap-6 [&>header]:mb-0" aria-busy="true">
				{header}
				<Panel className="grid gap-3 p-4">
					{[0, 1, 2].map((i) => (
						<Skeleton key={i} className="h-8 w-full" />
					))}
				</Panel>
			</div>
		);
	}
	if (!enabled) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header}
				<Panel className="flex items-center gap-3 p-6">
					<PowerOffIcon aria-hidden="true" className="size-5 flex-none text-muted-foreground" />
					<p className="text-body">{__('The Scripts module is disabled by the proto_blocks_modules filter.', 'proto-blocks')}</p>
				</Panel>
			</div>
		);
	}

	return (
		<div className="grid gap-6 [&>header]:mb-0">
			{header}
			{result.writeReason !== null ? (
				<ScriptsReadOnlyBanner reason={result.writeReason} />
			) : result.createTarget.writable === false ? (
				<ScriptsReadOnlyBanner reason="not_writable" path={result.createTarget.path} />
			) : null}
			<IssueList issues={result.issues} />
			{empty ? (
				<div className="max-w-[680px]">
					<EmptyScripts readOnly={readOnly} onCreate={onCreate} />
				</div>
			) : (
				<div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
					<Panel className="overflow-hidden" aria-busy={loading}>
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>{__('Script', 'proto-blocks')}</TableHead>
									<TableHead className="w-[150px]">{__('Prints in', 'proto-blocks')}</TableHead>
									<TableHead>{__('Where it runs', 'proto-blocks')}</TableHead>
									<TableHead className="w-20 text-right">{__('Priority', 'proto-blocks')}</TableHead>
									<TableHead className="w-[70px]">{__('On', 'proto-blocks')}</TableHead>
									<TableHead className="w-[90px]">
										<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
									</TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{result.items.map((item) => {
									const edit = item.editable ? __('Edit', 'proto-blocks') : __('View', 'proto-blocks');
									return (
										<TableRow key={item.slug} data-enabled={item.enabled}>
											<TableCell className="align-top whitespace-normal">
												<div className="grid gap-0.5">
													<div className="flex flex-wrap items-center gap-x-2 gap-y-1">
														<Dot tone={ORIGIN_TONES[item.origin] ?? 'neutral'}>
															<span className="sr-only">{item.originLabel}</span>
														</Dot>
														<span className={cn('text-body font-medium', !item.enabled && 'text-muted-foreground')}>{item.title}</span>
														{item.double ? <DoubleBadge double={item.double} /> : null}
														{item.issues.length > 0 ? (
															<Badge variant={item.issues.some((i) => i.level === 'error') ? 'danger' : 'warning'} title={item.issues.map((i) => i.message).join(' ')}>
																<TriangleAlertIcon aria-hidden="true" />
																{__('Check', 'proto-blocks')}
															</Badge>
														) : null}
													</div>
													<code className="ml-4 font-mono text-micro break-all text-muted-foreground">
														{item.origin === 'php' ? item.source : `proto-scripts/${item.slug}/`}
													</code>
												</div>
											</TableCell>
											<TableCell className="align-top">
												<LocationBadges locations={item.locations} />
											</TableCell>
											<TableCell className="align-top whitespace-normal" title={item.whereSummary}>
												<WhereCell where={item.where} roles={result.roles} />
											</TableCell>
											<TableCell className="text-right align-top text-muted-foreground tabular-nums">{item.priority}</TableCell>
											<TableCell className="align-top">
												<Switch
													checked={item.enabled}
													disabled={!item.editable || busy === item.slug}
													/* translators: %s: script title */
													aria-label={sprintf(__('Enable %s', 'proto-blocks'), item.title)}
													aria-describedby={!item.editable && readOnly ? READ_ONLY_BANNER_ID : undefined}
													onCheckedChange={(checked) => onToggle(item.slug, checked)}
												/>
											</TableCell>
											<TableCell className="text-right align-top whitespace-nowrap">
												{/* translators: 1: Edit or View, 2: script title */}
												<Button variant="outline" size="sm" aria-label={sprintf(__('%1$s %2$s', 'proto-blocks'), edit, item.title)} onClick={() => onEdit(item.slug)}>
													{edit}
												</Button>
											</TableCell>
										</TableRow>
									);
								})}
							</TableBody>
						</Table>
						<p className="border-t bg-subtle px-5 py-3 text-caption text-muted-foreground">
							{__('Lower priority prints first at each location. Scripts in the same location print in priority order, then by folder name.', 'proto-blocks')}
						</p>
					</Panel>
					<PrintOrder items={result.items} />
				</div>
			)}
		</div>
	);
}
