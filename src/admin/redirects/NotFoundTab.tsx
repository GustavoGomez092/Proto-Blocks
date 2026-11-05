import { Fragment, useId, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { RadioGroup as RadioGroupPrimitive } from 'radix-ui';
import { ArrowRightIcon, ChevronDownIcon, ChevronRightIcon, DownloadIcon, InfoIcon, SearchIcon, Trash2Icon } from 'lucide-react';
import { adminDelete } from '@/admin/api';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { Panel } from '@/admin/components/shell/Panel';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Eyebrow } from '@/admin/components/shell/Signals';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup } from '@/admin/components/ui/radio-group';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { asApiError, deleteNotFoundUrl, download, query, usePaged } from './api';
import { addRule, newRuleId } from './edits';
import { RuleDialog } from './RuleDialog';
import { defaultGroup, formatDate, Pager, saveRule, useWriteFlow, writableGroups, writeGuard, type RuleSubmit, type TabProps } from './shared';
import type { FileRule, NotFoundGroupRow, NotFoundRow } from './types';

const PER_PAGE = 50;

/** The local date `days` before today as YYYY-MM-DD (the date inputs' format). */
function daysAgo(days: number): string {
	const date = new Date();
	date.setDate(date.getDate() - days);
	const pad = (n: number) => String(n).padStart(2, '0');
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Which period chip the From/To dates match ('' for hand-picked dates). */
function rangeOf(from: string, to: string): string {
	if (to === '' && from === '') {
		return 'all';
	}
	if (to === '' && from === daysAgo(6)) {
		return '7';
	}
	if (to === '' && from === daysAgo(29)) {
		return '30';
	}
	return '';
}
const BASE = 'admin/redirects/404';

/** The latest hits of exactly `url` (`?url=` is an exact filter; `search` is "contains"). */
function Hits({ url }: { url: string }) {
	const { data, error, loading } = usePaged<NotFoundRow>(BASE + query({ url, per_page: 10 }));
	if (error) {
		return <p className="text-label text-destructive">{asApiError(error).message}</p>;
	}
	if (loading && !data) {
		return <Skeleton className="h-6 w-full" />;
	}
	const rows = data?.rows ?? [];
	return (
		<Table aria-label={sprintf(/* translators: %s: URL. */ __('Recent hits of %s', 'proto-blocks'), url)}>
			<TableHeader>
				<TableRow>
					<TableHead>{__('Time', 'proto-blocks')}</TableHead>
					<TableHead>{__('Method', 'proto-blocks')}</TableHead>
					<TableHead>{__('IP', 'proto-blocks')}</TableHead>
					<TableHead>{__('Referrer', 'proto-blocks')}</TableHead>
					<TableHead>{__('User agent', 'proto-blocks')}</TableHead>
				</TableRow>
			</TableHeader>
			<TableBody>
				{rows.map((row) => (
					<TableRow key={row.id}>
						<TableCell className="whitespace-nowrap">{formatDate(row.created)}</TableCell>
						<TableCell>{row.method}</TableCell>
						<TableCell className="font-mono text-micro">{row.ip ?? '—'}</TableCell>
						<TableCell className="max-w-[220px] text-micro break-all whitespace-normal">{row.referrer ?? '—'}</TableCell>
						<TableCell className="max-w-[260px] text-micro break-all whitespace-normal">{row.agent ?? '—'}</TableCell>
					</TableRow>
				))}
			</TableBody>
		</Table>
	);
}

/**
 * 404s tab (spec §6): logged 404s grouped by URL. "Add redirect" opens the
 * rule dialog with the source filled in and deletes the URL's 404 rows once
 * the rule is saved; "Ignore" adds an `action: nothing` rule.
 */
export default function NotFoundTab({ index, reload, readOnly, onSettings }: TabProps & { onSettings?: () => void }) {
	const [search, setSearch] = useState('');
	const [from, setFrom] = useState('');
	const [to, setTo] = useState('');
	const [page, setPage] = useState(1);
	const [open, setOpen] = useState<Set<string>>(new Set());
	const [adding, setAdding] = useState<string | null>(null);
	const [confirm, setConfirm] = useState<{ kind: 'url'; url: string } | { kind: 'all' } | null>(null);
	const ids = { search: useId(), from: useId(), to: useId() };
	const filters = { search: search.trim(), from, to };
	const list = usePaged<NotFoundGroupRow>(BASE + query({ group_by: 'url', page, per_page: PER_PAGE, ...filters }));
	const { runWrites, busy, dialogs } = useWriteFlow(reload);

	const refresh = () => void list.reload();

	const removeUrl = async (url: string) => {
		try {
			const count = await deleteNotFoundUrl(url);
			toast.success(sprintf(/* translators: %d: number of log rows. */ _n('Deleted %d 404 entry.', 'Deleted %d 404 entries.', count, 'proto-blocks'), count));
		} catch (e) {
			toast.error(__('Could not delete the 404 entries.', 'proto-blocks'), { description: asApiError(e).message });
		}
		refresh();
	};

	const clearAll = async () => {
		try {
			await adminDelete(BASE + query({ all: 1, ...filters }));
			toast.success(__('404 log cleared.', 'proto-blocks'));
		} catch (e) {
			toast.error(__('Could not clear the 404 log.', 'proto-blocks'), { description: asApiError(e).message });
		}
		refresh();
	};

	const saveRedirect: RuleSubmit = async (values, rule, refused) => {
		const url = adding;
		const ok = await saveRule(runWrites, index, null, values, rule, __('Redirect added.', 'proto-blocks'), refused);
		if (ok && url) {
			await removeUrl(url);
		}
		return ok;
	};

	const ignore = (url: string) => {
		const slug = defaultGroup(index);
		const group = writableGroups(index).find((g) => g.slug === slug);
		const rule: FileRule = { id: newRuleId(index.groups.flatMap((g) => g.rules.map((r) => r.id))), from: url, action: 'nothing' };
		void runWrites(
			[group ? { slug: group.slug, expectedHash: group.hash, edit: (text) => addRule(text, rule) } : { slug: 'redirects', title: __('Redirects', 'proto-blocks'), edit: (text) => addRule(text, rule) }],
			sprintf(/* translators: %s: URL. */ __('%s is ignored from now on.', 'proto-blocks'), url)
		);
	};

	const exportCsv = async () => {
		try {
			await download(`${BASE}/export${query({ format: 'csv', ...filters })}`, '404s.csv');
		} catch (e) {
			toast.error(__('The export failed.', 'proto-blocks'), { description: asApiError(e).message });
		}
	};

	const rows = list.data?.rows ?? [];
	const range = rangeOf(from, to);
	const shownHits = rows.reduce((sum, row) => sum + row.count, 0);
	const morePages = (list.data?.total ?? 0) > rows.length;
	const ignored = index.groups.reduce((sum, group) => sum + group.rules.filter((rule) => rule.action === 'nothing').length, 0);
	const retention = index.settings?.log?.notFound;
	const setRange = (value: string) => {
		setFrom(value === '7' ? daysAgo(6) : value === '30' ? daysAgo(29) : '');
		setTo('');
		setPage(1);
	};

	return (
		<div className="grid gap-4">
			<div className="flex flex-wrap items-end gap-2">
				<div className="relative max-w-[360px] min-w-[220px] flex-1">
					<Label htmlFor={ids.search} className="sr-only">
						{__('URL contains', 'proto-blocks')}
					</Label>
					<SearchIcon aria-hidden="true" className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
					<Input id={ids.search} type="search" className="h-[38px] ps-9" placeholder={__('Path contains', 'proto-blocks')} value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
				</div>
				<div className="flex items-center gap-1.5">
					<Label htmlFor={ids.from} className="text-caption font-normal text-muted-foreground">
						{__('From', 'proto-blocks')}
					</Label>
					<Input id={ids.from} type="date" className="h-[34px] w-auto" value={from} onChange={(event) => { setFrom(event.target.value); setPage(1); }} />
					<Label htmlFor={ids.to} className="text-caption font-normal text-muted-foreground">
						{__('To', 'proto-blocks')}
					</Label>
					<Input id={ids.to} type="date" className="h-[34px] w-auto" value={to} onChange={(event) => { setTo(event.target.value); setPage(1); }} />
				</div>
				<span className="ms-auto flex gap-2">
					<Button variant="outline" onClick={() => void exportCsv()}>
						<DownloadIcon aria-hidden="true" />
						{__('Export CSV', 'proto-blocks')}
					</Button>
					<Button variant="outline" disabled={rows.length === 0} onClick={() => setConfirm({ kind: 'all' })}>
						<Trash2Icon aria-hidden="true" />
						{__('Clear all', 'proto-blocks')}
					</Button>
				</span>
			</div>

			<div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
				<Panel className="overflow-hidden" aria-busy={list.loading}>
					<div className="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
						<RadioGroup value={range} onValueChange={setRange} aria-label={__('Period', 'proto-blocks')} className="flex flex-wrap gap-1.5">
							{(
								[
									['7', __('Last 7 days', 'proto-blocks')],
									['30', __('30 days', 'proto-blocks')],
									['all', __('All time', 'proto-blocks')],
								] as const
							).map(([value, text]) => (
								<RadioGroupPrimitive.Item
									key={value}
									value={value}
									className="cursor-pointer rounded-full border border-border bg-card px-3 py-[5px] text-caption leading-tight font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground"
								>
									{text}
								</RadioGroupPrimitive.Item>
							))}
						</RadioGroup>
						<span className="text-caption text-muted-foreground">{__('Grouped by path', 'proto-blocks')}</span>
					</div>
					{list.error && !list.data ? (
						<div className="p-5">
							<ScreenError screen={__('404s', 'proto-blocks')} error={list.error} onRetry={refresh} />
						</div>
					) : !list.data ? (
						<div className="grid gap-3 p-4" aria-busy="true">
							{[0, 1, 2].map((i) => (
								<Skeleton key={i} className="h-8 w-full" />
							))}
						</div>
					) : rows.length === 0 ? (
						<p className="p-6 text-label text-muted-foreground">{__('No 404s logged.', 'proto-blocks')}</p>
					) : (
						<>
							<Table aria-label={__('404s by URL', 'proto-blocks')}>
								<TableHeader>
									<TableRow>
										<TableHead>{__('Requested path', 'proto-blocks')}</TableHead>
										<TableHead className="w-[70px] text-right">{__('Hits', 'proto-blocks')}</TableHead>
										<TableHead className="w-[200px]">{__('Last referrer', 'proto-blocks')}</TableHead>
										<TableHead className="w-[110px]">{__('Last seen', 'proto-blocks')}</TableHead>
										<TableHead className="w-[200px]">
											<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
										</TableHead>
									</TableRow>
								</TableHeader>
								<TableBody>
									{rows.map((row) => {
										const expanded = open.has(row.url);
										return (
											<Fragment key={row.url}>
												<TableRow>
													<TableCell className="max-w-[340px] py-3 whitespace-normal">
														<button
															type="button"
															className="inline-flex cursor-pointer items-start gap-1 rounded-sm text-left text-foreground hover:text-link"
															aria-expanded={expanded}
															aria-label={sprintf(/* translators: %s: URL. */ __('Show hits of %s', 'proto-blocks'), row.url)}
															onClick={() =>
																setOpen((current) => {
																	const next = new Set(current);
																	if (expanded) {
																		next.delete(row.url);
																	} else {
																		next.add(row.url);
																	}
																	return next;
																})
															}
														>
															{expanded ? <ChevronDownIcon aria-hidden="true" className="mt-0.5 size-4 flex-none text-muted-foreground" /> : <ChevronRightIcon aria-hidden="true" className="mt-0.5 size-4 flex-none text-muted-foreground" />}
															<code className="font-mono text-label break-all">{row.url}</code>
														</button>
													</TableCell>
													<TableCell className="py-3 text-right font-medium tabular-nums">{row.count}</TableCell>
													<TableCell className="max-w-[200px] truncate py-3 text-caption text-muted-foreground" title={row.lastReferrer ?? undefined}>
														{row.lastReferrer ?? __('— direct', 'proto-blocks')}
													</TableCell>
													<TableCell className="py-3 text-caption whitespace-nowrap text-muted-foreground">{formatDate(row.lastSeen)}</TableCell>
													<TableCell className="py-2.5 text-right whitespace-nowrap">
														<span className="inline-flex items-center gap-1">
															<Button size="sm" variant="outline" aria-label={sprintf(/* translators: %s: URL. */ __('Add redirect for %s', 'proto-blocks'), row.url)} {...writeGuard(readOnly, busy)} onClick={() => setAdding(row.url)}>
																<ArrowRightIcon aria-hidden="true" />
																{__('Redirect…', 'proto-blocks')}
															</Button>
															<Button size="sm" variant="ghost" className="px-2 text-muted-foreground hover:bg-code hover:text-foreground" aria-label={sprintf(/* translators: %s: URL. */ __('Ignore %s', 'proto-blocks'), row.url)} {...writeGuard(readOnly, busy)} onClick={() => ignore(row.url)}>
																{__('Ignore', 'proto-blocks')}
															</Button>
															<Button size="icon-sm" variant="ghost" className="text-muted-foreground hover:bg-destructive-soft hover:text-destructive" aria-label={sprintf(/* translators: %s: URL. */ __('Delete 404s of %s', 'proto-blocks'), row.url)} onClick={() => setConfirm({ kind: 'url', url: row.url })}>
																<Trash2Icon aria-hidden="true" />
															</Button>
														</span>
													</TableCell>
												</TableRow>
												{expanded ? (
													<TableRow className="hover:bg-transparent">
														<TableCell colSpan={5} className="bg-subtle px-6">
															<Hits url={row.url} />
														</TableCell>
													</TableRow>
												) : null}
											</Fragment>
										);
									})}
								</TableBody>
							</Table>
							<Pager page={page} perPage={list.data.perPage ?? PER_PAGE} total={list.data.total} onPage={setPage} label={__('404 pages', 'proto-blocks')} />
						</>
					)}
				</Panel>
				<aside className="grid gap-3" aria-label={__('404 summary', 'proto-blocks')}>
					<Panel className="grid gap-3.5 px-5 py-[18px]">
						<Eyebrow>{range === '7' ? __('Last 7 days', 'proto-blocks') : range === '30' ? __('Last 30 days', 'proto-blocks') : range === 'all' ? __('All time', 'proto-blocks') : __('Chosen dates', 'proto-blocks')}</Eyebrow>
						<div className="flex items-baseline gap-2.5">
							<span data-testid="nf-hits" className="text-stat font-semibold tabular-nums">
								{shownHits}
								{morePages ? '+' : ''}
							</span>
							<span className="text-label text-muted-foreground">{morePages ? __('404 hits on this page', 'proto-blocks') : __('404 hits', 'proto-blocks')}</span>
						</div>
						<dl className="grid gap-1.5 text-label">
							<div className="flex justify-between gap-2">
								<dt className="text-muted-foreground">{__('Distinct paths', 'proto-blocks')}</dt>
								<dd className="tabular-nums">{list.data?.total ?? '—'}</dd>
							</div>
							<div className="flex justify-between gap-2">
								<dt className="text-muted-foreground">{__('Ignored paths (rules)', 'proto-blocks')}</dt>
								<dd className="tabular-nums">{ignored}</dd>
							</div>
						</dl>
					</Panel>
					{typeof retention === 'number' ? (
						<p className="flex items-center gap-2.5 rounded-xl border bg-card px-4 py-3 text-caption text-muted-foreground">
							<InfoIcon aria-hidden="true" className="size-3.5 flex-none" />
							<span>
								{retention === -1
									? __('404s are not being logged.', 'proto-blocks')
									: retention === 0
										? __('404s are kept until you clear them.', 'proto-blocks')
										: sprintf(/* translators: %d: number of days. */ _n('Logs are pruned after %d day.', 'Logs are pruned after %d days.', retention, 'proto-blocks'), retention)}{' '}
								{onSettings ? (
									<button type="button" className="cursor-pointer rounded-sm text-link hover:text-link-hover hover:underline" onClick={onSettings}>
										{__('Change in Settings', 'proto-blocks')}
									</button>
								) : null}
							</span>
						</p>
					) : null}
				</aside>
			</div>

			{adding !== null ? (
				<RuleDialog
					open
					index={index}
					rule={null}
					prefill={{ from: adding }}
					group={defaultGroup(index)}
					title={__('Add redirect for this 404', 'proto-blocks')}
					onOpenChange={(next) => (next ? null : setAdding(null))}
					onSubmit={saveRedirect}
				/>
			) : null}
			<ConfirmDialog
				open={confirm !== null}
				destructive
				title={confirm?.kind === 'all' ? __('Clear the 404 log?', 'proto-blocks') : __('Delete the 404s of this URL?', 'proto-blocks')}
				description={confirm?.kind === 'url' ? confirm.url : __('Every 404 that matches the current filters is deleted.', 'proto-blocks')}
				confirmLabel={confirm?.kind === 'all' ? __('Clear all', 'proto-blocks') : __('Delete', 'proto-blocks')}
				onCancel={() => setConfirm(null)}
				onConfirm={() => {
					const current = confirm;
					setConfirm(null);
					if (current?.kind === 'all') {
						void clearAll();
					} else if (current) {
						void removeUrl(current.url);
					}
				}}
			/>
			{dialogs}
		</div>
	);
}
