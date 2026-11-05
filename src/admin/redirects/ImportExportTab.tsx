import { useEffect, useId, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { DownloadIcon, FileUpIcon, LoaderCircleIcon, PlugIcon } from 'lucide-react';
import { adminGet, adminPost } from '@/admin/api';
import { Panel, PanelHeader } from '@/admin/components/shell/Panel';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/admin/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { asApiError, download, query, type GroupWrite } from './api';
import { actionLabel } from './codes';
import { detectFormat, groupTextFrom, mergeRules } from './import';
import { NEW_GROUP } from './rule-form';
import { groupSlugError, slugifyGroup } from './schema';
import { defaultGroup, sortedGroups, useWriteFlow, writableGroups, writeGuard, type TabProps } from './shared';
import type { DuplicateMode, ExportFormat, FileGroup, FileRule, ParsedRow, ParseResult, PluginImporter, PluginPreview, RedirectsIndex } from './types';

const EXPORT_FORMATS: [ExportFormat, string, string][] = [
	['csv', 'CSV', 'redirects.csv'],
	['json', 'JSON', 'redirects.json'],
	['apache', 'Apache (.htaccess)', 'redirects.htaccess'],
	['nginx', 'Nginx', 'redirects.nginx.conf'],
	['redirects', 'Netlify (_redirects)', '_redirects'],
];

/** File contents as text (FileReader where Blob.text is missing). */
function readText(file: File): Promise<string> {
	if (typeof file.text === 'function') {
		return file.text();
	}
	return new Promise((resolve, reject) => {
		const reader = new FileReader();
		reader.onload = () => resolve(String(reader.result ?? ''));
		reader.onerror = () => reject(reader.error);
		reader.readAsText(file);
	});
}

function statusBadge(status: ParsedRow['status']) {
	switch (status) {
		case 'new':
			return <Badge variant="success">{__('New', 'proto-blocks')}</Badge>;
		case 'duplicate':
			return <Badge variant="warning">{__('Duplicate', 'proto-blocks')}</Badge>;
		default:
			return <Badge variant="danger">{__('Invalid', 'proto-blocks')}</Badge>;
	}
}

function targetText(rule: FileRule): string {
	const action = rule.action ?? 'url';
	return action === 'url' ? (rule.to ?? '') : action === 'pass' ? `${actionLabel('pass')} ${rule.to ?? ''}` : actionLabel(action);
}

function PreviewTable({ rows, label }: { rows: ParsedRow[]; label: string }) {
	return (
		<div className="max-h-[420px] overflow-auto rounded-lg border">
			<Table aria-label={label}>
				<TableHeader>
					<TableRow>
						<TableHead>{__('Line', 'proto-blocks')}</TableHead>
						<TableHead>{__('Source', 'proto-blocks')}</TableHead>
						<TableHead>{__('Target', 'proto-blocks')}</TableHead>
						<TableHead>{__('Code', 'proto-blocks')}</TableHead>
						<TableHead>{__('Status', 'proto-blocks')}</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{rows.map((row, i) => (
						<TableRow key={`${row.line ?? i}-${i}`}>
							<TableCell className="tabular-nums text-muted-foreground">{row.line ?? i + 1}</TableCell>
							<TableCell className="max-w-[260px] font-mono text-micro break-all whitespace-normal">{row.rule.from ?? '—'}</TableCell>
							<TableCell className="max-w-[260px] font-mono text-micro break-all whitespace-normal">{targetText(row.rule)}</TableCell>
							<TableCell className="tabular-nums">{row.rule.code ?? '—'}</TableCell>
							<TableCell className="whitespace-normal">
								<span className="flex flex-wrap items-center gap-1.5">
									{statusBadge(row.status)}
									{row.message || row.reason ? <span className="text-micro text-muted-foreground">{row.message || row.reason}</span> : null}
								</span>
							</TableCell>
						</TableRow>
					))}
				</TableBody>
			</Table>
		</div>
	);
}

function DuplicateChoice({ value, onChange, name }: { value: DuplicateMode; onChange: (mode: DuplicateMode) => void; name: string }) {
	const id = useId();
	const options: [DuplicateMode, string][] = [
		['skip', __('Skip rules whose source already exists', 'proto-blocks')],
		['replace', __('Replace the existing rule', 'proto-blocks')],
		['add', __('Add anyway', 'proto-blocks')],
	];
	return (
		<fieldset className="grid gap-2">
			<legend className="mb-1 text-label font-medium">{name}</legend>
			<RadioGroup value={value} onValueChange={(next) => onChange(next as DuplicateMode)} className="gap-2">
				{options.map(([mode, label]) => (
					<div key={mode} className="flex items-center gap-2">
						<RadioGroupItem id={`${id}-${mode}`} value={mode} />
						<Label htmlFor={`${id}-${mode}`} className="font-normal">
							{label}
						</Label>
					</div>
				))}
			</RadioGroup>
		</fieldset>
	);
}

function GroupChoice({ index, value, onChange, title, onTitle }: { index: RedirectsIndex; value: string; onChange: (slug: string) => void; title: string; onTitle: (title: string) => void }) {
	const selectId = useId();
	const titleId = useId();
	const error = value === NEW_GROUP && title.trim() !== '' ? groupSlugError(slugifyGroup(title), index.groups.map((g) => g.slug)) : null;
	return (
		<div className="flex flex-wrap items-end gap-3">
			<div className="grid gap-1.5">
				<Label htmlFor={selectId}>{__('Import into group', 'proto-blocks')}</Label>
				<Select value={value} onValueChange={onChange}>
					<SelectTrigger id={selectId} className="w-56">
						<SelectValue />
					</SelectTrigger>
					<SelectContent>
						{writableGroups(index).map((group) => (
							<SelectItem key={group.slug} value={group.slug}>
								{group.title}
							</SelectItem>
						))}
						<SelectItem value={NEW_GROUP}>{__('New group…', 'proto-blocks')}</SelectItem>
					</SelectContent>
				</Select>
			</div>
			{value === NEW_GROUP ? (
				<div className="grid gap-1.5">
					<Label htmlFor={titleId}>{__('New group name', 'proto-blocks')}</Label>
					<Input id={titleId} value={title} aria-invalid={error ? true : undefined} aria-describedby={error ? `${titleId}-error` : undefined} onChange={(event) => onTitle(event.target.value)} />
					{error ? (
						<p id={`${titleId}-error`} className="text-label text-destructive">
							{error}
						</p>
					) : null}
				</div>
			) : null}
		</div>
	);
}

/** The writes that import `groups` (slug → rules), merging into existing files with `mode`. */
function importWrites(index: RedirectsIndex, groups: { slug: string; group: FileGroup; rules: FileRule[] }[], mode: DuplicateMode, counts: { added: number; replaced: number; skipped: number }): GroupWrite[] {
	const taken = index.groups.flatMap((g) => g.rules.map((r) => r.id));
	return groups.map(({ slug, group, rules }) => {
		const existing = index.groups.find((g) => g.slug === slug && g.origin === 'file');
		return {
			slug,
			expectedHash: existing ? existing.hash : undefined,
			title: group.title ?? slug,
			initial: existing ? undefined : groupTextFrom(slug, group),
			edit: (text: string) => {
				const result = mergeRules(text, rules, mode, taken);
				counts.added += result.added;
				counts.replaced += result.replaced;
				counts.skipped += result.skipped;
				return result.text;
			},
		};
	});
}

function summary(counts: { added: number; replaced: number; skipped: number }): string {
	return sprintf(
		/* translators: 1: rules added, 2: rules replaced, 3: rules skipped. */
		__('Imported: %1$d added, %2$d replaced, %3$d skipped.', 'proto-blocks'),
		counts.added,
		counts.replaced,
		counts.skipped
	);
}

function FileImport({ index, readOnly, runWrites, busy }: TabProps & { runWrites: (writes: GroupWrite[]) => Promise<boolean>; busy: boolean }) {
	const fileId = useId();
	const [file, setFile] = useState<File | null>(null);
	const [parsing, setParsing] = useState(false);
	const [result, setResult] = useState<ParseResult | null>(null);
	const [group, setGroup] = useState(defaultGroup(index));
	const [newTitle, setNewTitle] = useState('');
	const [mode, setMode] = useState<DuplicateMode>('skip');
	const [keepGroups, setKeepGroups] = useState(true);
	const keepId = useId();

	const parse = async () => {
		if (!file) {
			return;
		}
		setParsing(true);
		try {
			const text = await readText(file);
			setResult(await adminPost<ParseResult>('admin/redirects/import/parse', { format: detectFormat(file.name) ?? '', text, filename: file.name }));
		} catch (e) {
			setResult(null);
			toast.error(__('The file could not be read.', 'proto-blocks'), { description: asApiError(e).message });
		} finally {
			setParsing(false);
		}
	};

	const usable = (result?.rules ?? []).filter((row) => row.status !== 'invalid');
	const fileGroups = result?.groups && Object.keys(result.groups).length > 0 ? result.groups : null;
	const slug = group === NEW_GROUP ? slugifyGroup(newTitle) : group;
	const groupInvalid = !(fileGroups && keepGroups) && (group === NEW_GROUP ? newTitle.trim() === '' || groupSlugError(slug, index.groups.map((g) => g.slug)) !== null : group === '');

	const run = async () => {
		const counts = { added: 0, replaced: 0, skipped: 0 };
		const groups =
			fileGroups && keepGroups
				? Object.entries(fileGroups).map(([key, value]) => ({ slug: key, group: value, rules: (value.rules ?? []) as FileRule[] }))
				: [{ slug, group: { title: group === NEW_GROUP ? newTitle.trim() : slug }, rules: usable.map((row) => row.rule) }];
		if (await runWrites(importWrites(index, groups, mode, counts))) {
			toast.success(summary(counts));
			setResult(null);
			setFile(null);
		}
	};

	const counts = { new: 0, duplicate: 0, invalid: 0 };
	for (const row of result?.rules ?? []) {
		counts[row.status]++;
	}

	return (
		<Panel aria-labelledby="pb-redirects-import">
			<PanelHeader id="pb-redirects-import" title={__('Import a file', 'proto-blocks')} description={__('CSV, JSON (Proto-Blocks or Redirection), .htaccess or Netlify _redirects.', 'proto-blocks')} />
			<div className="grid gap-4 px-6 py-4">
				<div className="flex flex-wrap items-end gap-2">
					<div className="grid gap-1.5">
						<Label htmlFor={fileId}>{__('Redirect file', 'proto-blocks')}</Label>
						<Input
							id={fileId}
							type="file"
							accept=".csv,.json,.htaccess,.txt,.conf,_redirects,text/csv,application/json,text/plain"
							onChange={(event) => {
								setFile(event.target.files?.[0] ?? null);
								setResult(null);
							}}
						/>
					</div>
					<Button variant="outline" disabled={!file || parsing} onClick={() => void parse()}>
						{parsing ? <LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" /> : <FileUpIcon aria-hidden="true" />}
						{__('Preview', 'proto-blocks')}
					</Button>
				</div>
				{result ? (
					<>
						<p className="text-label" role="status">
							{sprintf(
								/* translators: 1: new rules, 2: duplicates, 3: invalid rows. */
								__('%1$d new, %2$d duplicate, %3$d invalid.', 'proto-blocks'),
								counts.new,
								counts.duplicate,
								counts.invalid
							)}
						</p>
						<PreviewTable rows={result.rules} label={__('Import preview', 'proto-blocks')} />
						{fileGroups ? (
							<div className="flex items-center gap-2">
								<Checkbox id={keepId} checked={keepGroups} onCheckedChange={(checked) => setKeepGroups(checked === true)} />
								<Label htmlFor={keepId} className="font-normal">
									{sprintf(
										/* translators: %s: comma-separated group slugs. */
										__("Keep the file's groups (%s)", 'proto-blocks'),
										Object.keys(fileGroups).join(', ')
									)}
								</Label>
							</div>
						) : null}
						{!(fileGroups && keepGroups) ? <GroupChoice index={index} value={group} onChange={setGroup} title={newTitle} onTitle={setNewTitle} /> : null}
						<DuplicateChoice value={mode} onChange={setMode} name={__('Duplicates', 'proto-blocks')} />
						<div>
							<Button {...writeGuard(readOnly, busy || usable.length === 0 || groupInvalid)} onClick={() => void run()}>
								{sprintf(
									/* translators: %d: number of rules. */
									_n('Import %d rule', 'Import %d rules', usable.length, 'proto-blocks'),
									usable.length
								)}
							</Button>
						</div>
					</>
				) : null}
			</div>
		</Panel>
	);
}

function PluginImport({ index, readOnly, runWrites, busy, reload }: TabProps & { runWrites: (writes: GroupWrite[]) => Promise<boolean>; busy: boolean }) {
	const [importers, setImporters] = useState<PluginImporter[] | null>(null);
	const [active, setActive] = useState<PluginImporter | null>(null);
	const [preview, setPreview] = useState<Record<string, FileGroup> | null>(null);
	const [skipped, setSkipped] = useState<ParsedRow[]>([]);
	const [mode, setMode] = useState<DuplicateMode>('skip');
	const [deactivate, setDeactivate] = useState(false);
	const [loading, setLoading] = useState(false);
	const deactivateId = useId();

	useEffect(() => {
		let live = true;
		adminGet<{ importers?: PluginImporter[] }>('admin/redirects/import/plugins')
			.then((result) => {
				if (live) {
					setImporters(Array.isArray(result?.importers) ? result.importers : []);
				}
			})
			.catch(() => live && setImporters([]));
		return () => {
			live = false;
		};
	}, []);

	const load = async (importer: PluginImporter) => {
		setActive(importer);
		setPreview(null);
		setSkipped([]);
		setDeactivate(false);
		setLoading(true);
		try {
			const result = await adminPost<Partial<PluginPreview>>(`admin/redirects/import/plugins/${encodeURIComponent(importer.id)}/preview`);
			setPreview(result?.groups && typeof result.groups === 'object' && !Array.isArray(result.groups) ? result.groups : {});
			setSkipped(Array.isArray(result?.skipped) ? result.skipped : []);
		} catch (e) {
			toast.error(__('The preview failed.', 'proto-blocks'), { description: asApiError(e).message });
			setActive(null);
		} finally {
			setLoading(false);
		}
	};

	const groups = Object.entries(preview ?? {});
	const total = groups.reduce((sum, [, group]) => sum + (group.rules?.length ?? 0), 0);

	const run = async () => {
		if (!active) {
			return;
		}
		const counts = { added: 0, replaced: 0, skipped: 0 };
		const ok = await runWrites(importWrites(index, groups.map(([slug, group]) => ({ slug, group, rules: (group.rules ?? []) as FileRule[] })), mode, counts));
		if (!ok) {
			return;
		}
		toast.success(summary(counts));
		if (deactivate) {
			try {
				const result = await adminPost<{ deactivated?: string[]; skipped?: { plugin: string; reason: string }[] }>('admin/redirects/import/deactivate', { id: active.id });
				if (Array.isArray(result?.deactivated) && result.deactivated.length > 0) {
					toast.success(sprintf(/* translators: %s: plugin name. */ __('%s deactivated.', 'proto-blocks'), active.label));
				} else if (result?.skipped?.some((row) => row.reason === 'network')) {
					toast.warning(sprintf(/* translators: %s: plugin name. */ __('%s is network-activated; deactivate it from the network admin.', 'proto-blocks'), active.label));
				} else {
					toast.info(sprintf(/* translators: %s: plugin name. */ __('%s was not active.', 'proto-blocks'), active.label));
				}
				await reload();
			} catch (e) {
				toast.error(__('The plugin was not deactivated.', 'proto-blocks'), { description: asApiError(e).message });
			}
		}
		setActive(null);
		setPreview(null);
		setSkipped([]);
	};

	const canDeactivate = active !== null && active.canDeactivate !== false && Boolean(active.plugin) && (active.active === undefined || active.active.length > 0);

	return (
		<Panel aria-labelledby="pb-redirects-plugins">
			<PanelHeader id="pb-redirects-plugins" title={__('Import from a plugin', 'proto-blocks')} description={__('Only plugins whose redirects are on this site are listed.', 'proto-blocks')} />
			<div className="grid gap-4 px-6 py-4">
				{importers === null ? (
					<p className="text-label text-muted-foreground">{__('Looking for redirect plugins…', 'proto-blocks')}</p>
				) : importers.length === 0 ? (
					<p className="text-label text-muted-foreground">{__('No other redirect plugin data found.', 'proto-blocks')}</p>
				) : (
					<ul className="grid gap-2" aria-label={__('Plugin importers', 'proto-blocks')}>
						{importers.map((importer) => (
							<li key={importer.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-2.5">
								<span className="flex items-center gap-2 text-body font-medium">
									<PlugIcon aria-hidden="true" className="size-4 text-muted-foreground" />
									{importer.label}
								</span>
								<Button size="sm" variant="outline" disabled={loading} aria-label={sprintf(/* translators: %s: plugin name. */ __('Preview %s', 'proto-blocks'), importer.label)} onClick={() => void load(importer)}>
									{__('Preview', 'proto-blocks')}
								</Button>
							</li>
						))}
					</ul>
				)}
				{active && preview ? (
					<div className="grid gap-4 border-t pt-4">
						<h3 className="text-body font-medium">
							{sprintf(
								/* translators: 1: plugin name, 2: number of rules, 3: number of groups. */
								__('%1$s: %2$d rules in %3$d groups', 'proto-blocks'),
								active.label,
								total,
								groups.length
							)}
						</h3>
						{groups.map(([slug, group]) => (
							<div key={slug} className="grid gap-1.5">
								<p className="text-label">
									{group.title ?? slug} <code className="font-mono text-micro text-muted-foreground">{slug}</code>
									{index.groups.some((g) => g.slug === slug) ? <Badge variant="secondary" className="ms-2">{__('merges into existing group', 'proto-blocks')}</Badge> : null}
								</p>
								<PreviewTable rows={(group.rules ?? []).map((rule) => ({ rule, status: 'new', reason: null }))} label={sprintf(/* translators: %s: group title. */ __('Preview of %s', 'proto-blocks'), group.title ?? slug)} />
							</div>
						))}
						{skipped.length > 0 ? (
							<div className="grid gap-1.5">
								<p className="text-label">
									{sprintf(
										/* translators: %d: number of rows. */
										_n('%d row cannot be imported:', '%d rows cannot be imported:', skipped.length, 'proto-blocks'),
										skipped.length
									)}
								</p>
								<PreviewTable rows={skipped.map((row) => ({ ...row, rule: row.rule ?? {}, status: 'invalid' as const }))} label={sprintf(/* translators: %s: plugin name. */ __('Rows of %s that are skipped', 'proto-blocks'), active.label)} />
							</div>
						) : null}
						{active.cleanupHint ? <p className="text-label text-muted-foreground">{active.cleanupHint}</p> : null}
						<DuplicateChoice value={mode} onChange={setMode} name={__('Duplicates', 'proto-blocks')} />
						{canDeactivate ? (
							<div className="flex items-center gap-2">
								<Checkbox id={deactivateId} checked={deactivate} onCheckedChange={(checked) => setDeactivate(checked === true)} />
								<Label htmlFor={deactivateId} className="font-normal">
									{sprintf(/* translators: %s: plugin name. */ __('Deactivate %s after importing', 'proto-blocks'), active.label)}
								</Label>
							</div>
						) : null}
						<div>
							<Button {...writeGuard(readOnly, busy || total === 0)} onClick={() => void run()}>
								{sprintf(/* translators: %d: number of rules. */ _n('Import %d rule', 'Import %d rules', total, 'proto-blocks'), total)}
							</Button>
						</div>
					</div>
				) : null}
			</div>
		</Panel>
	);
}

function ExportPanel({ index }: { index: RedirectsIndex }) {
	const groupId = useId();
	const formatId = useId();
	const [group, setGroup] = useState('all');
	const [format, setFormat] = useState<ExportFormat>('csv');
	const [busy, setBusy] = useState(false);

	const run = async () => {
		setBusy(true);
		try {
			const fallback = EXPORT_FORMATS.find(([id]) => id === format)?.[2] ?? 'redirects.txt';
			const name = await download(`admin/redirects/export${query({ format, group: group === 'all' ? '' : group })}`, fallback);
			toast.success(sprintf(/* translators: %s: file name. */ __('Downloaded %s.', 'proto-blocks'), name));
		} catch (e) {
			toast.error(__('The export failed.', 'proto-blocks'), { description: asApiError(e).message });
		} finally {
			setBusy(false);
		}
	};

	return (
		<Panel aria-labelledby="pb-redirects-export">
			<PanelHeader id="pb-redirects-export" title={__('Export', 'proto-blocks')} description={__('Exports are for other servers and tools; the theme files stay the source of truth.', 'proto-blocks')} />
			<div className="flex flex-wrap items-end gap-3 px-6 py-4">
				<div className="grid gap-1.5">
					<Label htmlFor={groupId}>{__('Group to export', 'proto-blocks')}</Label>
					<Select value={group} onValueChange={setGroup}>
						<SelectTrigger id={groupId} className="w-52">
							<SelectValue />
						</SelectTrigger>
						<SelectContent>
							<SelectItem value="all">{__('All groups', 'proto-blocks')}</SelectItem>
							{sortedGroups(index).map((g) => (
								<SelectItem key={g.slug} value={g.slug}>
									{g.title}
								</SelectItem>
							))}
						</SelectContent>
					</Select>
				</div>
				<div className="grid gap-1.5">
					<Label htmlFor={formatId}>{__('Format', 'proto-blocks')}</Label>
					<Select value={format} onValueChange={(value) => setFormat(value as ExportFormat)}>
						<SelectTrigger id={formatId} className="w-52">
							<SelectValue />
						</SelectTrigger>
						<SelectContent>
							{EXPORT_FORMATS.map(([id, label]) => (
								<SelectItem key={id} value={id}>
									{label}
								</SelectItem>
							))}
						</SelectContent>
					</Select>
				</div>
				<Button disabled={busy} onClick={() => void run()}>
					<DownloadIcon aria-hidden="true" />
					{__('Download', 'proto-blocks')}
				</Button>
			</div>
		</Panel>
	);
}

/** Import / Export tab (spec §6, §9). */
export default function ImportExportTab(props: TabProps) {
	const { runWrites, busy, dialogs } = useWriteFlow(props.reload);
	return (
		<div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
			<div className="grid min-w-0 gap-6">
				<FileImport {...props} runWrites={runWrites} busy={busy} />
				<PluginImport {...props} runWrites={runWrites} busy={busy} />
			</div>
			<ExportPanel index={props.index} />
			{dialogs}
		</div>
	);
}
