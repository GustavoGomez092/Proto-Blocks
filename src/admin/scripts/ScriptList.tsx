import { __, sprintf } from '@wordpress/i18n';
import { CodeIcon, PlusIcon, PowerOffIcon, TriangleAlertIcon } from 'lucide-react';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { EmptyCallout } from '@/admin/components/content-model/Layout';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { Panel } from '@/admin/components/shell/Panel';
import { READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { ScriptsReadOnlyBanner } from './ScriptsReadOnlyBanner';
import { LOCATIONS, type Location, type ScriptsIndex } from './types';

const LOCATION_BADGES: Record<Location, { short: string; long: string }> = {
	head: { short: 'H', long: __('In <head>', 'proto-blocks') },
	bodyOpen: { short: 'B', long: __('After <body>', 'proto-blocks') },
	bodyClose: { short: 'F', long: __('Before </body>', 'proto-blocks') },
};

/** H / B / F badges for the locations a script prints at. */
export function LocationBadges({ locations }: { locations: Location[] }) {
	return (
		<span className="inline-flex gap-1">
			{LOCATIONS.map((location) => {
				const on = locations.includes(location);
				return (
					<Badge
						key={location}
						variant={on ? 'brand' : 'dashed'}
						title={LOCATION_BADGES[location].long}
						aria-label={on ? LOCATION_BADGES[location].long : sprintf(/* translators: %s: a page location */ __('Nothing %s', 'proto-blocks'), LOCATION_BADGES[location].long.toLowerCase())}
						className="font-mono"
					>
						{LOCATION_BADGES[location].short}
					</Badge>
				);
			})}
		</span>
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
	onCreate: () => void;
	onToggle: (slug: string, enabled: boolean) => void;
	/** Slug whose switch is saving. */
	busy?: string | null;
}) {
	const title = __('Scripts', 'proto-blocks');
	const enabled = result?.moduleEnabled !== false;
	const readOnly = result !== null && !result.canWrite;
	const header = (
		<PageHeader
			title={title}
			eyebrow={__('Content model', 'proto-blocks')}
			description={__('Tracking tags, verification metas and widgets printed in <head>, after <body> and before </body>, from files in the theme.', 'proto-blocks')}
			actions={
				result && enabled ? (
					<Button onClick={onCreate} disabled={readOnly} aria-describedby={readOnly ? READ_ONLY_BANNER_ID : undefined}>
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
			{result.items.length === 0 ? (
				<EmptyCallout
					icon={<CodeIcon />}
					title={__('No scripts yet', 'proto-blocks')}
					action={
						readOnly ? null : (
							<Button variant="outline" size="sm" onClick={onCreate}>
								{__('New script', 'proto-blocks')}
							</Button>
						)
					}
				>
					{__('Add Google Tag Manager, a verification meta or a chat widget as files the theme keeps in git.', 'proto-blocks')}{' '}
					<code className="font-mono text-micro text-link">proto_script('GTM', ['head' =&gt; '…']);</code>
				</EmptyCallout>
			) : (
				<Panel className="overflow-hidden" aria-busy={loading}>
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>{__('Script', 'proto-blocks')}</TableHead>
								<TableHead>{__('Enabled', 'proto-blocks')}</TableHead>
								<TableHead>{__('Locations', 'proto-blocks')}</TableHead>
								<TableHead>{__('Where', 'proto-blocks')}</TableHead>
								<TableHead>{__('Priority', 'proto-blocks')}</TableHead>
								<TableHead>
									<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
								</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{result.items.map((item) => {
								const edit = item.editable ? __('Edit', 'proto-blocks') : __('View', 'proto-blocks');
								return (
									<TableRow key={item.slug}>
										<TableCell className="whitespace-normal">
											<div className="grid gap-0.5">
												<div className="flex flex-wrap items-center gap-x-2 gap-y-1">
													<span className="text-body font-medium">{item.title}</span>
													<OriginBadge origin={item.origin} label={item.originLabel} double={item.double} />
													{item.issues.length > 0 ? (
														<Badge variant={item.issues.some((i) => i.level === 'error') ? 'danger' : 'warning'} title={item.issues.map((i) => i.message).join(' ')}>
															<TriangleAlertIcon aria-hidden="true" />
															{__('Check', 'proto-blocks')}
														</Badge>
													) : null}
												</div>
												<code className="font-mono text-micro text-muted-foreground">{item.slug}</code>
											</div>
										</TableCell>
										<TableCell>
											<Switch
												checked={item.enabled}
												disabled={!item.editable || busy === item.slug}
												/* translators: %s: script title */
												aria-label={sprintf(__('Enable %s', 'proto-blocks'), item.title)}
												aria-describedby={!item.editable && readOnly ? READ_ONLY_BANNER_ID : undefined}
												onCheckedChange={(checked) => onToggle(item.slug, checked)}
											/>
										</TableCell>
										<TableCell>
											<LocationBadges locations={item.locations} />
										</TableCell>
										<TableCell className="text-label whitespace-normal">{item.whereSummary}</TableCell>
										<TableCell className="font-mono text-label">{item.priority}</TableCell>
										<TableCell className="text-right whitespace-nowrap">
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
				</Panel>
			)}
		</div>
	);
}
