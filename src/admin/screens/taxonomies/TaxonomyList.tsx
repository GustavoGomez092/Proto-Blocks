import { useCallback, useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { PlusIcon } from 'lucide-react';
import { adminGet } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
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

/**
 * The type a taxonomy is declared inside, or null for a standalone file or a
 * registration without a declaration. An inline taxonomy's `source` is its
 * type's file (`.../proto-types/<type>/type.jsonc`).
 */
export function inlineParent(source: string | null): string | null {
	const match = source ? /(?:^|\/)([^/]+)\/type\.jsonc?$/.exec(source) : null;
	return match ? match[1] : null;
}

/** Link to a type's edit screen on the Post Types screen. */
export function postTypeUrl(type: string): string {
	return `${getBootstrap().adminUrl}?page=proto-blocks-post-types&type=${encodeURIComponent(type)}`;
}

export function EditInPostTypeLink({ type }: { type: string }) {
	return (
		<Button asChild variant="outline" size="sm">
			<a href={postTypeUrl(type)}>{__('Edit in post type', 'proto-blocks')}</a>
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
	const { fileModsAllowed } = getBootstrap();
	const list = useAsync(() => adminGet<ListResult>('admin/taxonomies'), []);
	const [attached, setAttached] = useState<Record<string, string[]>>({});

	// The list carries no object types; each taxonomy's detail does.
	useEffect(() => {
		let live = true;
		for (const item of list.data?.items ?? []) {
			adminGet<TaxonomyDetail>(`admin/taxonomies/${item.slug}`)
				.then((detail) => live && setAttached((current) => ({ ...current, [item.slug]: detail.objectTypes })))
				.catch(() => {});
		}
		return () => {
			live = false;
		};
	}, [list.data]);

	const header = (canAdd: boolean) => (
		<PageHeader
			title={__('Taxonomies', 'proto-blocks')}
			description={__('Ways to group content, like categories and tags.', 'proto-blocks')}
			actions={
				canAdd ? (
					<Button disabled={!fileModsAllowed || !list.data?.createTarget.writable} onClick={onNew}>
						<PlusIcon aria-hidden="true" />
						{__('Add taxonomy', 'proto-blocks')}
					</Button>
				) : null
			}
		/>
	);

	if (list.error) {
		return (
			<div className="grid gap-6">
				{header(false)}
				<ScreenError screen={__('Taxonomies', 'proto-blocks')} error={list.error} onRetry={list.reload} />
			</div>
		);
	}
	if (!list.data) {
		return (
			<div className="grid gap-6">
				{header(false)}
				<Skeleton className="h-40 w-full" />
			</div>
		);
	}
	if (!list.data.moduleEnabled) {
		return (
			<div className="grid gap-6">
				{header(false)}
				<Card className="px-6 py-8 text-center text-[13px] text-muted-foreground">
					{__('The Taxonomies module is disabled by the proto_blocks_modules filter.', 'proto-blocks')}
				</Card>
			</div>
		);
	}

	const typeLabel = (slug: string) => types.find((type) => type.slug === slug)?.label ?? slug;
	const { items, issues } = list.data;

	return (
		<div className="grid gap-6">
			{header(true)}
			{!fileModsAllowed ? <ReadOnlyBanner reason="file_mods" /> : null}
			<IssueList issues={issues} />
			{items.length === 0 ? (
				<Card className="px-6 py-8 text-center text-[13px] text-muted-foreground">
					{__('No taxonomies yet. Group your content by topic, industry or format.', 'proto-blocks')}
				</Card>
			) : (
				<Card className="gap-0 overflow-hidden rounded-lg py-0 shadow-xs">
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>{__('Name', 'proto-blocks')}</TableHead>
								<TableHead>{__('Slug', 'proto-blocks')}</TableHead>
								<TableHead>{__('Origin', 'proto-blocks')}</TableHead>
								<TableHead>{__('Attached to', 'proto-blocks')}</TableHead>
								<TableHead className="text-end">{__('Terms', 'proto-blocks')}</TableHead>
								<TableHead>{__('Source', 'proto-blocks')}</TableHead>
								<TableHead className="text-end">
									<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
								</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{items.map((item) => {
								const parent = inlineParent(item.source);
								return (
									<TableRow key={item.slug}>
										<TableCell className="font-medium">{item.label}</TableCell>
										<TableCell>
											<code className="font-mono text-[12.5px]">{item.slug}</code>
										</TableCell>
										<TableCell>
											<OriginBadge origin={item.origin} label={item.originLabel} double={item.double} />
										</TableCell>
										<TableCell>
											<span className="flex flex-wrap gap-1">
												{(attached[item.slug] ?? []).map((type) => (
													<Badge key={type} variant="secondary">
														{typeLabel(type)}
													</Badge>
												))}
											</span>
										</TableCell>
										<TableCell className="text-end tabular-nums">{item.count}</TableCell>
										<TableCell>
											{item.source ? <code className="font-mono text-[12.5px] break-all">{item.source}</code> : <span className="text-muted-foreground">—</span>}
										</TableCell>
										<TableCell className="text-end">
											{parent ? (
												<EditInPostTypeLink type={parent} />
											) : (
												<Button asChild variant="outline" size="sm">
													<a
														href={`?page=proto-blocks-taxonomies&taxonomy=${encodeURIComponent(item.slug)}`}
														onClick={(event) => {
															event.preventDefault();
															onOpen(item.slug);
														}}
													>
														{item.origin === 'file' || item.origin === 'php' ? __('Edit', 'proto-blocks') : __('Override', 'proto-blocks')}
													</a>
												</Button>
											)}
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</Card>
			)}
		</div>
	);
}
