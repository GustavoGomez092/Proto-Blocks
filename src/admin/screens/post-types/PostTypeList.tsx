import { Fragment } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { FilePenIcon, PlusIcon, PowerOffIcon } from 'lucide-react';
import { getBootstrap, type WriteReason } from '@/admin/bootstrap';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner, READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { EmptyCallout, NameCell, TableGroupRow, themeRelative } from '@/admin/components/content-model/Layout';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { Panel } from '@/admin/components/shell/Panel';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import type { ListItem, ListResult } from '@/admin/content-model/types';
import { CreatePostTypeDialog } from './CreatePostTypeDialog';

const REGISTRANTS: ListItem['origin'][] = ['core', 'plugin', 'theme'];

/** Core, plugin and theme types without an override file are overridden, not edited. */
export function needsOverride(item: Pick<ListItem, 'origin' | 'override'>): boolean {
	return REGISTRANTS.includes(item.origin) && !item.override;
}

function itemsUrl(slug: string): string {
	return getBootstrap().adminUrl.replace(/admin\.php$/, 'edit.php') + '?post_type=' + encodeURIComponent(slug);
}

function LoadingRows() {
	return (
		<div aria-busy="true" className="grid gap-3 p-4">
			{[0, 1, 2].map((i) => (
				<div key={i} className="flex items-center gap-3">
					<Skeleton className="size-8" />
					<div className="grid flex-1 gap-1.5">
						<Skeleton className="h-4 w-40" />
						<Skeleton className="h-3 w-24" />
					</div>
					<Skeleton className="h-5 w-28" />
				</div>
			))}
		</div>
	);
}

/** A1: every post type with its origin, source and post count. */
export function PostTypeList({
	title,
	result,
	loading,
	error,
	readOnly,
	creating,
	onRetry,
	onEdit,
	onCreateOpen,
	onCreated,
}: {
	title: string;
	result: ListResult | null;
	loading: boolean;
	error: unknown;
	/** Why file writes are off, or null when they are allowed. */
	readOnly: WriteReason;
	creating: boolean;
	onRetry: () => void;
	onEdit: (slug: string) => void;
	onCreateOpen: (open: boolean) => void;
	onCreated: (slug: string) => void;
}) {
	const enabled = result?.moduleEnabled !== false;
	const createReadOnly = readOnly !== null || result?.createTarget.writable === false;
	const description = __('Proto-Blocks types are saved as files in your theme. Core and plugin types can be overridden.', 'proto-blocks');

	const header = (
		<PageHeader
			title={title}
			eyebrow={__('Content model', 'proto-blocks')}
			description={description}
			actions={
				result && enabled ? (
					<Button
						onClick={() => onCreateOpen(true)}
						disabled={createReadOnly}
						aria-describedby={createReadOnly ? READ_ONLY_BANNER_ID : undefined}
					>
						<PlusIcon aria-hidden="true" />
						{__('Add post type', 'proto-blocks')}
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
			<div className="grid gap-6 [&>header]:mb-0">
				{header}
				<Panel>
					<LoadingRows />
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
					<p className="text-body">{__('The Post Types module is disabled by the proto_blocks_modules filter.', 'proto-blocks')}</p>
				</Panel>
			</div>
		);
	}

	const custom = result.items.filter((item) => item.origin === 'file' || item.origin === 'php');
	const byOrigin = (origin: ListItem['origin']) => result.items.filter((item) => item.origin === origin);
	const core = byOrigin('core');
	const plugin = byOrigin('plugin');
	const theme = byOrigin('theme');
	const groups: { key: string; label: string; brand: boolean; items: ListItem[] }[] = [
		/* translators: %d: number of post types in the group. */
		{ key: 'yours', label: sprintf(__('Yours · %d', 'proto-blocks'), custom.length), brand: true, items: custom },
		/* translators: %d: number of post types in the group. */
		{ key: 'core', label: sprintf(__('WordPress core · %d', 'proto-blocks'), core.length), brand: false, items: core },
		/* translators: %d: number of post types in the group. */
		{ key: 'plugin', label: sprintf(__('Plugins · %d', 'proto-blocks'), plugin.length), brand: false, items: plugin },
		/* translators: %d: number of post types in the group. */
		{ key: 'theme', label: sprintf(__('Theme · %d', 'proto-blocks'), theme.length), brand: false, items: theme },
	].filter((group) => group.items.length > 0);

	const row = (item: ListItem) => {
		const override = needsOverride(item);
		let action: string = override ? __('Override', 'proto-blocks') : __('Edit', 'proto-blocks');
		/* translators: %s: post type name. */
		let actionLabel: string = sprintf(override ? __('Override %s', 'proto-blocks') : __('Edit %s', 'proto-blocks'), item.label);
		if (readOnly !== null) {
			action = __('View', 'proto-blocks');
			/* translators: %s: post type name. */
			actionLabel = sprintf(__('View %s', 'proto-blocks'), item.label);
		}
		return (
			<TableRow key={item.slug}>
				<TableCell>
					<NameCell label={item.label} slug={item.slug} />
				</TableCell>
				<TableCell>
					<span className="inline-flex flex-wrap items-center gap-x-2.5 gap-y-1">
						<OriginBadge origin={item.origin} label={item.originLabel} double={item.double} />
						{item.override ? (
							<Badge variant="brand">
								<FilePenIcon aria-hidden="true" />
								{__('Overridden', 'proto-blocks')}
							</Badge>
						) : null}
					</span>
				</TableCell>
				<TableCell className="hidden max-w-[340px] whitespace-normal md:table-cell">
					{item.source ? (
						<code className="font-mono text-micro break-all text-link" title={item.source}>
							{themeRelative(item.source)}
						</code>
					) : (
						<span className="text-label text-faint">—</span>
					)}
				</TableCell>
				<TableCell className="text-right tabular-nums">
					<a href={itemsUrl(item.slug)} data-testid="count">
						{item.count.toLocaleString()}
					</a>
				</TableCell>
				<TableCell className="text-right">
					<Button variant={override ? 'ghost' : 'outline'} size="sm" aria-label={actionLabel} onClick={() => onEdit(item.slug)}>
						{action}
					</Button>
				</TableCell>
			</TableRow>
		);
	};

	return (
		<div className="grid gap-6 [&>header]:mb-0">
			{header}
			{readOnly !== null ? (
				<ReadOnlyBanner
					reason={readOnly}
					description={__(
						"You can browse everything, but changes can't be saved. Ask your developer to edit the files in the theme, or to allow file changes on this environment.",
						'proto-blocks'
					)}
				/>
			) : result.createTarget.writable === false ? (
				<ReadOnlyBanner reason="not_writable" path={result.createTarget.path} />
			) : null}
			<IssueList issues={result.issues} />
			{custom.length === 0 ? (
				<EmptyCallout
					icon={<PlusIcon />}
					title={__('No custom post types yet', 'proto-blocks')}
					action={
						createReadOnly ? null : (
							<Button variant="outline" size="sm" onClick={() => onCreateOpen(true)}>
								{__('Add post type', 'proto-blocks')}
							</Button>
						)
					}
				>
					{__('Add one here, or declare it in your theme with a single line:', 'proto-blocks')}{' '}
					<code className="font-mono text-micro text-link">proto_post_type('Product');</code>
				</EmptyCallout>
			) : null}
			<Panel className="overflow-hidden" aria-busy={loading}>
				<Table>
					<TableHeader>
						<TableRow>
							<TableHead>{__('Post type', 'proto-blocks')}</TableHead>
							<TableHead>{__('Origin', 'proto-blocks')}</TableHead>
							<TableHead className="hidden md:table-cell">{__('Declaration', 'proto-blocks')}</TableHead>
							<TableHead className="text-right">{__('Posts', 'proto-blocks')}</TableHead>
							<TableHead>
								<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
							</TableHead>
						</TableRow>
					</TableHeader>
					<TableBody>
						{groups.map((group, i) => (
							<Fragment key={group.key}>
								<TableGroupRow label={group.label} colSpan={5} brand={group.brand} first={i === 0} />
								{group.items.map(row)}
							</Fragment>
						))}
					</TableBody>
				</Table>
			</Panel>
			<CreatePostTypeDialog
				open={creating && !createReadOnly}
				taken={result.items.map((item) => item.slug)}
				root={result.createTarget.path}
				onOpenChange={onCreateOpen}
				onCreated={onCreated}
			/>
		</div>
	);
}
