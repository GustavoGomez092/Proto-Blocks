import { useCallback, useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { PlusIcon } from 'lucide-react';
import { adminGet } from '@/admin/api';
import { getBootstrap, getWriteReason } from '@/admin/bootstrap';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { EmptyCallout, NameCell, ValuePill } from '@/admin/components/content-model/Layout';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { Panel } from '@/admin/components/shell/Panel';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Button } from '@/admin/components/ui/button';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import type { ListItem, ListResult } from '@/admin/content-model/types';

/** Loads `load()` on mount and whenever `deps` change; `reload` runs it again. */
export function useAsync<T>(load: () => Promise<T>, deps: unknown[]) {
	const [state, setState] = useState<{ data: T | null; error: unknown }>({ data: null, error: null });
	const [tick, setTick] = useState(0);
	// eslint-disable-next-line react-hooks/exhaustive-deps
	const run = useCallback(load, deps);
	useEffect(() => {
		let live = true;
		setState({ data: null, error: null });
		run().then(
			(data) => live && setState({ data, error: null }),
			(error) => live && setState({ data: null, error })
		);
		return () => {
			live = false;
		};
	}, [run, tick]);
	return { ...state, reload: () => setTick((n) => n + 1) };
}

/** GET admin/taxonomies/<slug> (includes/API/Admin/TaxonomiesRoutes.php). */
export interface TaxonomyDetail {
	item: ListItem;
	effective: Record<string, unknown>;
	objectTypes: string[];
	content: { count: number };
}

/** Link to a type's edit screen on the Post Types screen. */
export function postTypeUrl(type: string): string {
	return `${getBootstrap().adminUrl}?page=proto-blocks-post-types&type=${encodeURIComponent(type)}`;
}

export function EditInPostTypeLink({ type, title }: { type: string; title?: string }) {
	return (
		<Button asChild variant="outline" size="sm">
			<a href={postTypeUrl(type)} title={title}>
				{__('Edit in post type', 'proto-blocks')}
			</a>
		</Button>
	);
}

export function TaxonomyList({
	types,
	onOpen,
	onNew,
}: {
	/** Registered post types, for the "Attached to" labels. */
	types: ListItem[];
	onOpen: (slug: string) => void;
	onNew: () => void;
}) {
	const writeReason = getWriteReason();
	// One request: every list item carries its object types and inline parent.
	const list = useAsync(() => adminGet<ListResult>('admin/taxonomies'), []);

	const header = (canAdd: boolean) => (
		<PageHeader
			title={__('Taxonomies', 'proto-blocks')}
			eyebrow={__('Content model', 'proto-blocks')}
			description={__('Ways to group content, like categories and tags.', 'proto-blocks')}
			actions={
				canAdd ? (
					<Button disabled={writeReason !== null || !list.data?.createTarget.writable} onClick={onNew}>
						<PlusIcon aria-hidden="true" />
						{__('Add taxonomy', 'proto-blocks')}
					</Button>
				) : null
			}
		/>
	);

	if (list.error) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header(false)}
				<ScreenError screen={__('Taxonomies', 'proto-blocks')} error={list.error} onRetry={list.reload} />
			</div>
		);
	}
	if (!list.data) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header(false)}
				<Skeleton className="h-40 w-full rounded-xl" />
			</div>
		);
	}
	if (!list.data.moduleEnabled) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header(false)}
				<Panel className="p-6 text-label text-muted-foreground">
					{__('The Taxonomies module is disabled by the proto_blocks_modules filter.', 'proto-blocks')}
				</Panel>
			</div>
		);
	}

	const typeLabel = (slug: string) => types.find((type) => type.slug === slug)?.label ?? slug;
	const { items, issues } = list.data;
	const canAdd = writeReason === null && list.data.createTarget.writable;
	const own = items.filter((item) => item.origin === 'file' || item.origin === 'php');
	const firstOwnType = types.find((type) => type.origin === 'file' || type.origin === 'php');

	return (
		<div className="grid gap-6 [&>header]:mb-0">
			{header(true)}
			{writeReason !== null ? <ReadOnlyBanner reason={writeReason} /> : null}
			<IssueList issues={issues} />
			{own.length === 0 ? (
				<EmptyCallout
					icon={<PlusIcon />}
					title={__('No taxonomies of your own yet', 'proto-blocks')}
					action={
						canAdd ? (
							<Button variant="outline" size="sm" onClick={onNew}>
								{__('Add taxonomy', 'proto-blocks')}
							</Button>
						) : null
					}
				>
					{firstOwnType
						? sprintf(
								/* translators: %s: plural name of a post type, e.g. Events. */
								__('Group %s by topic or industry — or declare one in PHP:', 'proto-blocks'),
								firstOwnType.label
							)
						: __('Group your content by topic, industry or format — or declare one in PHP:', 'proto-blocks')}{' '}
					<code className="font-mono text-micro text-link">{`proto_taxonomy('Topic', '${firstOwnType?.slug ?? 'post'}');`}</code>
				</EmptyCallout>
			) : null}
			{items.length > 0 ? (
				<Panel className="overflow-hidden">
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>{__('Taxonomy', 'proto-blocks')}</TableHead>
								<TableHead>{__('Origin', 'proto-blocks')}</TableHead>
								<TableHead>{__('Attached to', 'proto-blocks')}</TableHead>
								<TableHead className="text-end">{__('Terms', 'proto-blocks')}</TableHead>
								<TableHead className="text-end">
									<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
								</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{items.map((item) => {
								const parent = item.parentType ?? null;
								const owned = item.origin === 'file' || item.origin === 'php';
								return (
									<TableRow key={item.slug}>
										<TableCell>
											<NameCell label={item.label} slug={item.slug} />
										</TableCell>
										<TableCell>
											<OriginBadge origin={item.origin} label={item.originLabel} double={item.double} />
										</TableCell>
										<TableCell className="whitespace-normal">
											<span className="flex flex-wrap gap-1">
												{(item.objectTypes ?? []).map((type) => (
													<ValuePill key={type}>{typeLabel(type)}</ValuePill>
												))}
											</span>
										</TableCell>
										<TableCell className="text-end tabular-nums" data-testid="count">
											{item.count}
										</TableCell>
										<TableCell className="text-end">
											{parent ? (
												<EditInPostTypeLink type={parent} title={item.readOnlyMessage ?? undefined} />
											) : (
												<Button asChild variant={owned ? 'outline' : 'ghost'} size="sm">
													<a
														href={`?page=proto-blocks-taxonomies&taxonomy=${encodeURIComponent(item.slug)}`}
														title={item.source ?? undefined}
														onClick={(event) => {
															event.preventDefault();
															onOpen(item.slug);
														}}
													>
														{owned ? __('Edit', 'proto-blocks') : __('Override', 'proto-blocks')}
													</a>
												</Button>
											)}
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</Panel>
			) : null}
		</div>
	);
}
