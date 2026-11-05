import { useId, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { DownloadIcon, SearchIcon, Trash2Icon } from 'lucide-react';
import { adminDelete } from '@/admin/api';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { Panel } from '@/admin/components/shell/Panel';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { cn } from '@/admin/lib/utils';
import { asApiError, download, query, usePaged } from './api';
import { formatDate, Pager, type TabProps } from './shared';
import type { LogRow } from './types';

const PER_PAGE = 50;
const BASE = 'admin/redirects/log';

/** Log tab (spec §6): the redirect log, filters, delete selected, clear, export CSV. */
export default function LogTab({ index, onEditRule }: TabProps & { onEditRule: (group: string, id: string) => void }) {
	const [search, setSearch] = useState('');
	const [rule, setRule] = useState('');
	const [code, setCode] = useState('');
	const [from, setFrom] = useState('');
	const [to, setTo] = useState('');
	const [page, setPage] = useState(1);
	const [selected, setSelected] = useState<Set<number>>(new Set());
	const [confirm, setConfirm] = useState<'selected' | 'all' | null>(null);
	const ids = { search: useId(), rule: useId(), code: useId(), from: useId(), to: useId() };
	const filters = { search: search.trim(), rule: rule.trim(), code: code.trim(), from, to };
	const list = usePaged<LogRow>(BASE + query({ page, per_page: PER_PAGE, ...filters }));
	const rows = list.data?.rows ?? [];
	const known = new Set(index.groups.flatMap((g) => g.rules.map((r) => `${g.slug}/${r.id}`)));

	const filter = (set: (value: string) => void) => (event: { target: { value: string } }) => {
		set(event.target.value);
		setPage(1);
		setSelected(new Set());
	};

	const remove = async (kind: 'selected' | 'all') => {
		try {
			await adminDelete(BASE + (kind === 'all' ? query({ all: 1, ...filters }) : query({ ids: [...selected] })));
			toast.success(kind === 'all' ? __('Redirect log cleared.', 'proto-blocks') : _n('Log entry deleted.', 'Log entries deleted.', selected.size, 'proto-blocks'));
			setSelected(new Set());
		} catch (e) {
			toast.error(__('Could not delete the log entries.', 'proto-blocks'), { description: asApiError(e).message });
		}
		void list.reload();
	};

	const exportCsv = async () => {
		try {
			await download(`${BASE}/export${query({ format: 'csv', ...filters })}`, 'redirect-log.csv');
		} catch (e) {
			toast.error(__('The export failed.', 'proto-blocks'), { description: asApiError(e).message });
		}
	};

	const allSelected = rows.length > 0 && rows.every((row) => selected.has(row.id));

	return (
		<div className="grid gap-4">
			<div className="flex flex-wrap items-end gap-2">
				<div className="relative max-w-[360px] min-w-[220px] flex-1">
					<Label htmlFor={ids.search} className="sr-only">
						{__('URL contains', 'proto-blocks')}
					</Label>
					<SearchIcon aria-hidden="true" className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
					<Input id={ids.search} type="search" className="h-[38px] ps-9" placeholder={__('URL contains', 'proto-blocks')} value={search} onChange={filter(setSearch)} />
				</div>
				<div className="grid w-36 gap-1">
					<Label htmlFor={ids.rule} className="text-caption">
						{__('Rule id', 'proto-blocks')}
					</Label>
					<Input id={ids.rule} value={rule} placeholder="r_…" className="font-mono" onChange={filter(setRule)} />
				</div>
				<div className="grid w-24 gap-1">
					<Label htmlFor={ids.code} className="text-caption">
						{__('Code', 'proto-blocks')}
					</Label>
					<Input id={ids.code} inputMode="numeric" value={code} onChange={filter(setCode)} />
				</div>
				<div className="grid gap-1">
					<Label htmlFor={ids.from} className="text-caption">
						{__('From', 'proto-blocks')}
					</Label>
					<Input id={ids.from} type="date" value={from} onChange={filter(setFrom)} />
				</div>
				<div className="grid gap-1">
					<Label htmlFor={ids.to} className="text-caption">
						{__('To', 'proto-blocks')}
					</Label>
					<Input id={ids.to} type="date" value={to} onChange={filter(setTo)} />
				</div>
				<span className="ms-auto flex flex-wrap gap-2">
				<Button variant="outline" onClick={() => void exportCsv()}>
					<DownloadIcon aria-hidden="true" />
					{__('Export CSV', 'proto-blocks')}
				</Button>
				<Button variant="outline" disabled={selected.size === 0} onClick={() => setConfirm('selected')}>
					{__('Delete selected', 'proto-blocks')}
				</Button>
				<Button variant="outline" disabled={rows.length === 0} onClick={() => setConfirm('all')}>
					<Trash2Icon aria-hidden="true" />
					{__('Clear all', 'proto-blocks')}
				</Button>
				</span>
			</div>

			{list.error && !list.data ? (
				<ScreenError screen={__('Redirect log', 'proto-blocks')} error={list.error} onRetry={() => void list.reload()} />
			) : !list.data ? (
				<Panel className="grid gap-3 p-4" aria-busy="true">
					{[0, 1, 2].map((i) => (
						<Skeleton key={i} className="h-8 w-full" />
					))}
				</Panel>
			) : rows.length === 0 ? (
				<Panel className="p-6 text-label text-muted-foreground">{__('No redirects logged.', 'proto-blocks')}</Panel>
			) : (
				<Panel className="overflow-hidden" aria-busy={list.loading}>
					<Table aria-label={__('Redirect log', 'proto-blocks')}>
						<TableHeader>
							<TableRow>
								<TableHead className="w-11 pr-0">
									<Checkbox
										aria-label={__('Select all shown entries', 'proto-blocks')}
										checked={allSelected}
										onCheckedChange={(checked) => setSelected(checked === true ? new Set(rows.map((row) => row.id)) : new Set())}
									/>
								</TableHead>
								<TableHead>{__('Time', 'proto-blocks')}</TableHead>
								<TableHead>{__('URL', 'proto-blocks')}</TableHead>
								<TableHead>{__('Target', 'proto-blocks')}</TableHead>
								<TableHead className="w-[70px]">{__('Code', 'proto-blocks')}</TableHead>
								<TableHead>{__('Rule', 'proto-blocks')}</TableHead>
								<TableHead>{__('Redirect by', 'proto-blocks')}</TableHead>
								<TableHead>{__('IP', 'proto-blocks')}</TableHead>
								<TableHead>{__('User agent', 'proto-blocks')}</TableHead>
								<TableHead>{__('Referrer', 'proto-blocks')}</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{rows.map((row) => (
								<TableRow key={row.id} data-state={selected.has(row.id) ? 'selected' : undefined}>
									<TableCell className="pr-0 align-top">
										<Checkbox
											aria-label={sprintf(/* translators: 1: URL, 2: time. */ __('Select %1$s at %2$s', 'proto-blocks'), row.url, formatDate(row.created))}
											checked={selected.has(row.id)}
											onCheckedChange={(checked) =>
												setSelected((current) => {
													const next = new Set(current);
													if (checked === true) {
														next.add(row.id);
													} else {
														next.delete(row.id);
													}
													return next;
												})
											}
										/>
									</TableCell>
									<TableCell className="py-3 align-top text-label whitespace-nowrap text-muted-foreground">{formatDate(row.created)}</TableCell>
									<TableCell className="max-w-[260px] py-3 align-top font-mono text-label break-all whitespace-normal">{row.url}</TableCell>
									<TableCell className="max-w-[260px] py-3 align-top font-mono text-label break-all whitespace-normal">{row.target ?? '—'}</TableCell>
									<TableCell className={cn('py-3 align-top text-label font-medium tabular-nums', row.code >= 400 && 'text-warning')}>{row.code}</TableCell>
									<TableCell className="py-3 align-top">
										{row.group && row.ruleId && known.has(`${row.group}/${row.ruleId}`) ? (
											<Button variant="link" size="sm" className="font-mono text-micro" aria-label={sprintf(/* translators: %s: rule id. */ __('Edit rule %s', 'proto-blocks'), row.ruleId)} onClick={() => onEditRule(row.group as string, row.ruleId as string)}>
												{row.ruleId}
											</Button>
										) : (
											<span className="font-mono text-micro text-muted-foreground">{row.ruleId ?? '—'}</span>
										)}
									</TableCell>
									<TableCell className="py-3 align-top text-label text-muted-foreground">{row.redirectBy}</TableCell>
									<TableCell className="py-3 align-top font-mono text-micro text-muted-foreground">{row.ip ?? '—'}</TableCell>
									<TableCell className="max-w-[220px] py-3 align-top text-caption break-all whitespace-normal text-muted-foreground">{row.agent ?? '—'}</TableCell>
									<TableCell className="max-w-[220px] py-3 align-top text-caption break-all whitespace-normal text-muted-foreground">{row.referrer ?? '—'}</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
					<Pager page={page} perPage={list.data.perPage ?? PER_PAGE} total={list.data.total} onPage={setPage} label={__('Log pages', 'proto-blocks')} />
				</Panel>
			)}
			<ConfirmDialog
				open={confirm !== null}
				destructive
				title={
					confirm === 'all'
						? __('Clear the redirect log?', 'proto-blocks')
						: sprintf(/* translators: %d: number of log entries. */ _n('Delete %d log entry?', 'Delete %d log entries?', selected.size, 'proto-blocks'), selected.size)
				}
				description={confirm === 'all' ? __('Every entry that matches the current filters is deleted.', 'proto-blocks') : undefined}
				confirmLabel={confirm === 'all' ? __('Clear all', 'proto-blocks') : __('Delete', 'proto-blocks')}
				onCancel={() => setConfirm(null)}
				onConfirm={() => {
					const kind = confirm;
					setConfirm(null);
					if (kind) {
						void remove(kind);
					}
				}}
			/>
		</div>
	);
}
