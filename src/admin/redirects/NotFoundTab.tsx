import { Fragment, useId, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { ChevronDownIcon, ChevronRightIcon, DownloadIcon, EyeOffIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { adminDelete, adminGet } from '@/admin/api';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { Panel } from '@/admin/components/shell/Panel';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { asApiError, download, query, usePaged } from './api';
import { addRule, newRuleId } from './edits';
import { RuleDialog } from './RuleDialog';
import type { RuleFormValues } from './rule-form';
import { defaultGroup, formatDate, Pager, ruleWrites, useWriteFlow, writableGroups, writeGuard, type TabProps } from './shared';
import type { FileRule, NotFoundGroupRow, NotFoundRow, Paged } from './types';

const PER_PAGE = 50;
const BASE = 'admin/redirects/404';

/** Every logged hit of exactly `url` (the list's search is "contains"). */
async function hitsOf(url: string, perPage = 200): Promise<NotFoundRow[]> {
	const result = await adminGet<Paged<NotFoundRow>>(BASE + query({ search: url, per_page: perPage }));
	return (Array.isArray(result?.rows) ? result.rows : []).filter((row) => row.url === url);
}

/** Deletes the logged hits of exactly `url`. */
export async function deleteHitsOf(url: string): Promise<number> {
	const ids = (await hitsOf(url)).map((row) => row.id);
	if (ids.length > 0) {
		await adminDelete(BASE + query({ ids }));
	}
	return ids.length;
}

function Hits({ url }: { url: string }) {
	const { data, error, loading } = usePaged<NotFoundRow>(BASE + query({ search: url, per_page: 10 }));
	if (error) {
		return <p className="text-label text-destructive">{asApiError(error).message}</p>;
	}
	if (loading && !data) {
		return <Skeleton className="h-6 w-full" />;
	}
	const rows = (data?.rows ?? []).filter((row) => row.url === url);
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
export default function NotFoundTab({ index, reload, readOnly }: TabProps) {
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
			const count = await deleteHitsOf(url);
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

	const saveRedirect = async (values: RuleFormValues, rule: FileRule) => {
		const url = adding;
		const ok = await runWrites(ruleWrites(index, null, values, rule), __('Redirect added.', 'proto-blocks'));
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

	return (
		<div className="grid gap-4">
			<div className="flex flex-wrap items-end gap-2">
				<div className="grid min-w-[200px] flex-1 gap-1">
					<Label htmlFor={ids.search} className="text-caption">
						{__('URL contains', 'proto-blocks')}
					</Label>
					<Input id={ids.search} type="search" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
				</div>
				<div className="grid gap-1">
					<Label htmlFor={ids.from} className="text-caption">
						{__('From', 'proto-blocks')}
					</Label>
					<Input id={ids.from} type="date" value={from} onChange={(event) => { setFrom(event.target.value); setPage(1); }} />
				</div>
				<div className="grid gap-1">
					<Label htmlFor={ids.to} className="text-caption">
						{__('To', 'proto-blocks')}
					</Label>
					<Input id={ids.to} type="date" value={to} onChange={(event) => { setTo(event.target.value); setPage(1); }} />
				</div>
				<Button variant="outline" onClick={() => void exportCsv()}>
					<DownloadIcon aria-hidden="true" />
					{__('Export CSV', 'proto-blocks')}
				</Button>
				<Button variant="outline" disabled={rows.length === 0} onClick={() => setConfirm({ kind: 'all' })}>
					<Trash2Icon aria-hidden="true" />
					{__('Clear all', 'proto-blocks')}
				</Button>
			</div>

			{list.error && !list.data ? (
				<ScreenError screen={__('404s', 'proto-blocks')} error={list.error} onRetry={refresh} />
			) : !list.data ? (
				<Panel className="grid gap-3 p-4" aria-busy="true">
					{[0, 1, 2].map((i) => (
						<Skeleton key={i} className="h-8 w-full" />
					))}
				</Panel>
			) : rows.length === 0 ? (
				<Panel className="p-6 text-label text-muted-foreground">{__('No 404s logged.', 'proto-blocks')}</Panel>
			) : (
				<Panel className="overflow-x-auto" aria-busy={list.loading}>
					<Table aria-label={__('404s by URL', 'proto-blocks')}>
						<TableHeader>
							<TableRow>
								<TableHead>{__('URL', 'proto-blocks')}</TableHead>
								<TableHead className="text-right">{__('Count', 'proto-blocks')}</TableHead>
								<TableHead>{__('Last seen', 'proto-blocks')}</TableHead>
								<TableHead>{__('Last referrer', 'proto-blocks')}</TableHead>
								<TableHead>
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
											<TableCell className="max-w-[340px] whitespace-normal">
												<Button
													variant="ghost"
													size="sm"
													className="h-auto px-1 py-0.5 text-left"
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
													{expanded ? <ChevronDownIcon aria-hidden="true" /> : <ChevronRightIcon aria-hidden="true" />}
													<code className="font-mono text-micro break-all">{row.url}</code>
												</Button>
											</TableCell>
											<TableCell className="text-right tabular-nums">{row.count}</TableCell>
											<TableCell className="whitespace-nowrap">{formatDate(row.lastSeen)}</TableCell>
											<TableCell className="max-w-[220px] text-micro break-all whitespace-normal">{row.lastReferrer ?? '—'}</TableCell>
											<TableCell className="text-right whitespace-nowrap">
												<span className="inline-flex gap-1">
													<Button size="sm" variant="outline" aria-label={sprintf(/* translators: %s: URL. */ __('Add redirect for %s', 'proto-blocks'), row.url)} {...writeGuard(readOnly, busy)} onClick={() => setAdding(row.url)}>
														<PlusIcon aria-hidden="true" />
														{__('Add redirect', 'proto-blocks')}
													</Button>
													<Button size="sm" variant="ghost" aria-label={sprintf(/* translators: %s: URL. */ __('Ignore %s', 'proto-blocks'), row.url)} {...writeGuard(readOnly, busy)} onClick={() => ignore(row.url)}>
														<EyeOffIcon aria-hidden="true" />
														{__('Ignore', 'proto-blocks')}
													</Button>
													<Button size="icon-sm" variant="ghost" aria-label={sprintf(/* translators: %s: URL. */ __('Delete 404s of %s', 'proto-blocks'), row.url)} onClick={() => setConfirm({ kind: 'url', url: row.url })}>
														<Trash2Icon aria-hidden="true" />
													</Button>
												</span>
											</TableCell>
										</TableRow>
										{expanded ? (
											<TableRow className="hover:bg-transparent">
												<TableCell colSpan={5} className="bg-muted/50 px-6">
													<Hits url={row.url} />
												</TableCell>
											</TableRow>
										) : null}
									</Fragment>
								);
							})}
						</TableBody>
					</Table>
					<Pager page={page} perPage={PER_PAGE} total={list.data.total} onPage={setPage} label={__('404 pages', 'proto-blocks')} />
				</Panel>
			)}

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
