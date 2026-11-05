import { useEffect, useId, useMemo, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { ArrowDownIcon, ArrowUpIcon, CopyIcon, PencilIcon, PlusIcon, SearchIcon, Trash2Icon, TriangleAlertIcon } from 'lucide-react';
import { adminPost } from '@/admin/api';
import { EmptyCallout } from '@/admin/components/content-model/Layout';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { Panel } from '@/admin/components/shell/Panel';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/admin/components/ui/select';
import { Switch } from '@/admin/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/admin/components/ui/tooltip';
import { asApiError, deleteHitRows, type GroupWrite } from './api';
import { actionLabel, conditionLabel, CONDITIONS } from './codes';
import { addRule, moveRule, newRuleId, readRules, removeRule, setRuleEnabled } from './edits';
import { GroupMenu } from './GroupMenu';
import { RuleDialog } from './RuleDialog';
import { defaultGroup, formatDate, groupEditable, groupReadOnlyReason, readOnlyReasonLabel, saveRule, sortedGroups, useWriteFlow, writableGroups, writeGuard, type RuleSubmit, type TabProps } from './shared';
import { TestBox } from './TestBox';
import type { FileRule, Group, Rule } from './types';

interface Row {
	rule: Rule;
	group: Group;
	key: string;
}

const keyOf = (rule: Pick<Rule, 'group' | 'id'>) => `${rule.group}/${rule.id}`;
const refOf = (rule: Rule) => ({ id: rule.id, index: rule.index });
const sourceLabel = (rule: Rule) => rule.from ?? __('(any 404)', 'proto-blocks');

/** The rule as it stands in its file (found by id, or by index for a rule written without one). */
function rawRule(text: string, rule: Rule): FileRule | null {
	const rules = readRules(text);
	return rules.find((raw) => raw.id === rule.id) ?? (rules[rule.index] && rules[rule.index].id === undefined ? rules[rule.index] : null);
}

function TargetCell({ rule }: { rule: Rule }) {
	switch (rule.action) {
		case 'url':
			return (
				<div className="grid gap-0.5">
					<code className="font-mono text-micro break-all">{rule.to}</code>
					{rule.else ? (
						<span className="text-micro text-muted-foreground">
							{__('otherwise', 'proto-blocks')} <code className="font-mono break-all">{rule.else}</code>
						</span>
					) : null}
				</div>
			);
		case 'pass':
			return (
				<span className="text-label">
					{actionLabel('pass')} <code className="font-mono text-micro break-all">{rule.to}</code>
				</span>
			);
		default:
			return <span className="text-label">{actionLabel(rule.action)}</span>;
	}
}

/**
 * Redirects tab (spec §6): every rule across groups, filters, the add/edit
 * dialog, row and bulk actions, the group menu and the test box. Every write
 * edits the group file's text and posts it with the file's hash.
 */
export function RedirectsTab({
	index,
	reload,
	readOnly,
	slug,
	onSlug,
	editRequest,
	onEditHandled,
}: TabProps & {
	slug: string | null;
	onSlug: (slug: string | null) => void;
	editRequest: { group: string; id: string } | null;
	onEditHandled: () => void;
}) {
	const { run, runWrites, busy, dialogs } = useWriteFlow(reload);
	const [search, setSearch] = useState('');
	const [code, setCode] = useState('all');
	const [condition, setCondition] = useState('all');
	const [status, setStatus] = useState('all');
	const [selected, setSelected] = useState<Set<string>>(new Set());
	const [dialog, setDialog] = useState<{ rule: Rule | null; group: string } | null>(null);
	const [deleting, setDeleting] = useState<Rule[] | null>(null);
	const [moveTarget, setMoveTarget] = useState('');
	const ids = { search: useId(), group: useId(), code: useId(), condition: useId(), status: useId(), move: useId() };

	const groups = useMemo(() => sortedGroups(index), [index]);
	const rows: Row[] = useMemo(
		() => groups.flatMap((group) => [...group.rules].sort((a, b) => a.index - b.index).map((rule) => ({ rule, group, key: keyOf(rule) }))),
		[groups]
	);
	const codes = [...new Set(rows.map((row) => row.rule.code).filter((c): c is number => c !== null))].sort((a, b) => a - b);

	const needle = search.trim().toLowerCase();
	const visible = rows.filter(({ rule }) => {
		if (slug && rule.group !== slug) {
			return false;
		}
		if (needle && ![rule.from, rule.to, rule.title, rule.else].some((text) => (text ?? '').toLowerCase().includes(needle))) {
			return false;
		}
		if (code !== 'all' && String(rule.code) !== code) {
			return false;
		}
		if (condition !== 'all' && (rule.when?.type ?? 'none') !== condition) {
			return false;
		}
		if (status !== 'all' && rule.enabled !== (status === 'enabled')) {
			return false;
		}
		return true;
	});

	useEffect(() => {
		// Drop selections that left the list (deleted, reloaded).
		setSelected((current) => {
			const kept = [...current].filter((key) => rows.some((row) => row.key === key));
			return kept.length === current.size ? current : new Set(kept);
		});
	}, [rows]);

	useEffect(() => {
		if (!editRequest) {
			return;
		}
		const row = rows.find(({ rule }) => rule.group === editRequest.group && rule.id === editRequest.id);
		if (row) {
			setDialog({ rule: row.rule, group: groupEditable(row.group) ? row.group.slug : defaultGroup(index) });
		} else {
			toast.error(__('That rule is no longer in the files.', 'proto-blocks'));
		}
		onEditHandled();
	}, [editRequest, rows, index, onEditHandled]);

	const fileEditable = (group: Group) => !readOnly && groupEditable(group);
	/** A PHP rule is edited by copying it into a file group; a locked file group's rules are not edited at all. */
	const canEdit = (group: Group) => groupEditable(group) || groupReadOnlyReason(group) === 'php';
	const groupWrite = (group: Group, edit: (text: string) => string): GroupWrite => ({ slug: group.slug, expectedHash: group.hash, edit });

	const save: RuleSubmit = (values, rule, refused) =>
		saveRule(runWrites, index, dialog?.rule ?? null, values, rule, dialog?.rule ? __('Redirect saved.', 'proto-blocks') : __('Redirect added.', 'proto-blocks'), refused);

	const selectedRows = rows.filter((row) => selected.has(row.key));
	const selectedFile = selectedRows.filter((row) => fileEditable(row.group));

	/** One write per group, each applying `edit` to that group's selected rules (highest index first). */
	const bulkWrites = (edit: (text: string, rule: Rule) => string): GroupWrite[] => {
		const byGroup = new Map<string, Row[]>();
		for (const row of selectedFile) {
			byGroup.set(row.group.slug, [...(byGroup.get(row.group.slug) ?? []), row]);
		}
		return [...byGroup.values()].map((list) =>
			groupWrite(list[0].group, (text) => [...list].sort((a, b) => b.rule.index - a.rule.index).reduce((out, row) => edit(out, row.rule), text))
		);
	};

	const bulkMove = async () => {
		const target = groups.find((group) => group.slug === moveTarget);
		if (!target) {
			return;
		}
		const moving = selectedFile.filter((row) => row.group.slug !== target.slug);
		if (moving.length === 0) {
			return;
		}
		const moved: FileRule[] = [];
		const sources = new Map<string, Row[]>();
		for (const row of moving) {
			sources.set(row.group.slug, [...(sources.get(row.group.slug) ?? []), row]);
		}
		const removals = [...sources.values()].map((list) =>
			groupWrite(list[0].group, (text) =>
				[...list]
					.sort((a, b) => b.rule.index - a.rule.index)
					.reduce((out, row) => {
						const raw = rawRule(out, row.rule);
						if (raw) {
							moved.unshift({ ...raw, id: row.rule.id });
						}
						return removeRule(out, refOf(row.rule));
					}, text)
			)
		);
		const insert = groupWrite(target, (text) => moved.reduce((out, rule) => addRule(out, rule), text));
		if (await runWrites([...removals, insert], sprintf(
			/* translators: 1: number of rules, 2: group title. */
			_n('Moved %1$d rule to %2$s.', 'Moved %1$d rules to %2$s.', moved.length, 'proto-blocks'),
			moved.length,
			target.title
		))) {
			setSelected(new Set());
		}
	};

	const resetHits = async (list: Rule[]) => {
		try {
			for (const rule of list) {
				await adminPost('admin/redirects/hits/reset', { group: rule.group, ruleId: rule.id });
			}
			toast.success(_n('Hit count reset.', 'Hit counts reset.', list.length, 'proto-blocks'));
			await reload();
		} catch (e) {
			toast.error(__('Could not reset the hit counts.', 'proto-blocks'), { description: asApiError(e).message });
		}
	};

	const allVisibleSelected = visible.length > 0 && visible.every((row) => selected.has(row.key));
	const toggleAll = (checked: boolean) =>
		setSelected((current) => {
			const next = new Set(current);
			for (const row of visible) {
				if (checked) {
					next.add(row.key);
				} else {
					next.delete(row.key);
				}
			}
			return next;
		});

	const addGuard = writeGuard(readOnly, busy);

	return (
		<div className="grid gap-4">
			<div className="flex flex-wrap items-end gap-2">
				<div className="grid min-w-[200px] flex-1 gap-1">
					<Label htmlFor={ids.search} className="text-caption">
						{__('Search redirects', 'proto-blocks')}
					</Label>
					<div className="relative">
						<SearchIcon aria-hidden="true" className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
						<Input id={ids.search} type="search" className="ps-8" value={search} placeholder={__('Source, target or title', 'proto-blocks')} onChange={(event) => setSearch(event.target.value)} />
					</div>
				</div>
				<FilterSelect id={ids.group} label={__('Group', 'proto-blocks')} value={slug ?? 'all'} onChange={(value) => onSlug(value === 'all' ? null : value)} options={[['all', __('All groups', 'proto-blocks')], ...groups.map((g): [string, string] => [g.slug, g.title])]} />
				<FilterSelect id={ids.code} label={__('Code', 'proto-blocks')} value={code} onChange={setCode} options={[['all', __('All codes', 'proto-blocks')], ...codes.map((c): [string, string] => [String(c), String(c)])]} />
				<FilterSelect
					id={ids.condition}
					label={__('Condition', 'proto-blocks')}
					value={condition}
					onChange={setCondition}
					options={[['all', __('Any condition', 'proto-blocks')], ['none', conditionLabel('none')], ...CONDITIONS.map((type): [string, string] => [type, conditionLabel(type)])]}
				/>
				<FilterSelect
					id={ids.status}
					label={__('Status', 'proto-blocks')}
					value={status}
					onChange={setStatus}
					options={[
						['all', __('Enabled and disabled', 'proto-blocks')],
						['enabled', __('Enabled', 'proto-blocks')],
						['disabled', __('Disabled', 'proto-blocks')],
					]}
				/>
				<GroupMenu index={index} readOnly={readOnly} busy={busy} runWrites={runWrites} run={run} />
				<Button {...addGuard} onClick={() => setDialog({ rule: null, group: slug && writableGroups(index).some((g) => g.slug === slug) ? slug : defaultGroup(index) })}>
					<PlusIcon aria-hidden="true" />
					{__('Add redirect', 'proto-blocks')}
				</Button>
			</div>

			{selectedRows.length > 0 ? (
				<div role="region" aria-label={__('Bulk actions', 'proto-blocks')} className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted px-4 py-2.5">
					<span className="text-label font-medium">
						{sprintf(
							/* translators: %d: number of selected rules. */
							_n('%d selected', '%d selected', selectedRows.length, 'proto-blocks'),
							selectedRows.length
						)}
					</span>
					<Button size="sm" variant="outline" {...writeGuard(readOnly, busy || selectedFile.length === 0)} onClick={() => void runWrites(bulkWrites((text, rule) => setRuleEnabled(text, refOf(rule), true)), __('Rules enabled.', 'proto-blocks'))}>
						{__('Enable', 'proto-blocks')}
					</Button>
					<Button size="sm" variant="outline" {...writeGuard(readOnly, busy || selectedFile.length === 0)} onClick={() => void runWrites(bulkWrites((text, rule) => setRuleEnabled(text, refOf(rule), false)), __('Rules disabled.', 'proto-blocks'))}>
						{__('Disable', 'proto-blocks')}
					</Button>
					<Button size="sm" variant="outline" {...writeGuard(readOnly, busy || selectedFile.length === 0)} onClick={() => setDeleting(selectedFile.map((row) => row.rule))}>
						{__('Delete', 'proto-blocks')}
					</Button>
					<Button size="sm" variant="outline" disabled={busy} onClick={() => void resetHits(selectedRows.map((row) => row.rule))}>
						{__('Reset hits', 'proto-blocks')}
					</Button>
					<span className="ms-auto flex items-center gap-2">
						<Label htmlFor={ids.move} className="text-label">
							{__('Move to group', 'proto-blocks')}
						</Label>
						<Select value={moveTarget} onValueChange={setMoveTarget} disabled={readOnly}>
							<SelectTrigger id={ids.move} className="h-[30px] w-44" aria-describedby={readOnly ? writeGuard(true)['aria-describedby'] : undefined}>
								<SelectValue placeholder={__('Choose a group', 'proto-blocks')} />
							</SelectTrigger>
							<SelectContent>
								{writableGroups(index).map((group) => (
									<SelectItem key={group.slug} value={group.slug}>
										{group.title}
									</SelectItem>
								))}
							</SelectContent>
						</Select>
						<Button size="sm" {...writeGuard(readOnly, busy || moveTarget === '' || selectedFile.length === 0)} onClick={() => void bulkMove()}>
							{__('Move', 'proto-blocks')}
						</Button>
					</span>
				</div>
			) : null}

			{rows.length === 0 ? (
				<EmptyCallout
					icon={<PlusIcon />}
					title={__('No redirects yet', 'proto-blocks')}
					action={
						readOnly ? null : (
							<Button variant="outline" size="sm" onClick={() => setDialog({ rule: null, group: defaultGroup(index) })}>
								{__('Add redirect', 'proto-blocks')}
							</Button>
						)
					}
				>
					{__('Add one here, or declare it in PHP:', 'proto-blocks')} <code className="font-mono text-micro text-link">{"proto_redirect('/old', '/new');"}</code>
				</EmptyCallout>
			) : (
				<Panel className="overflow-x-auto">
					<Table aria-label={__('Redirect rules', 'proto-blocks')}>
						<TableHeader>
							<TableRow>
								<TableHead className="w-8">
									<Checkbox aria-label={__('Select all shown rules', 'proto-blocks')} checked={allVisibleSelected} onCheckedChange={(checked) => toggleAll(checked === true)} />
								</TableHead>
								<TableHead>{__('On', 'proto-blocks')}</TableHead>
								<TableHead>{__('Source', 'proto-blocks')}</TableHead>
								<TableHead>{__('Target', 'proto-blocks')}</TableHead>
								<TableHead>{__('Code', 'proto-blocks')}</TableHead>
								<TableHead>{__('Condition', 'proto-blocks')}</TableHead>
								<TableHead>{__('Group', 'proto-blocks')}</TableHead>
								<TableHead className="text-right">{__('Hits', 'proto-blocks')}</TableHead>
								<TableHead>{__('Last hit', 'proto-blocks')}</TableHead>
								<TableHead>{__('Origin', 'proto-blocks')}</TableHead>
								<TableHead>
									<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
								</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{visible.map(({ rule, group, key }) => {
								const editable = fileEditable(group);
								const guard = writeGuard(readOnly, !editable || busy);
								const label = sourceLabel(rule);
								const siblings = group.rules.length;
								return (
									<TableRow key={key} data-state={selected.has(key) ? 'selected' : undefined} className={rule.enabled && group.enabled ? undefined : 'text-muted-foreground'}>
										<TableCell>
											<Checkbox
												aria-label={sprintf(
													/* translators: %s: rule source. */
													__('Select %s', 'proto-blocks'),
													label
												)}
												checked={selected.has(key)}
												onCheckedChange={(checked) =>
													setSelected((current) => {
														const next = new Set(current);
														if (checked === true) {
															next.add(key);
														} else {
															next.delete(key);
														}
														return next;
													})
												}
											/>
										</TableCell>
										<TableCell>
											<Switch
												size="sm"
												checked={rule.enabled}
												aria-label={sprintf(
													/* translators: %s: rule source. */
													__('Enable %s', 'proto-blocks'),
													label
												)}
												{...guard}
												onCheckedChange={(checked) =>
													void runWrites([groupWrite(group, (text) => setRuleEnabled(text, refOf(rule), checked))], checked ? __('Redirect enabled.', 'proto-blocks') : __('Redirect disabled.', 'proto-blocks'))
												}
											/>
										</TableCell>
										<TableCell className="max-w-[280px] whitespace-normal">
											<div className="grid gap-0.5">
												<span className="flex flex-wrap items-center gap-1.5">
													<code className="font-mono text-micro break-all">{label}</code>
													{rule.regex ? <Badge variant="info">{__('Regex', 'proto-blocks')}</Badge> : null}
												</span>
												{rule.title ? <span className="text-micro text-muted-foreground">{rule.title}</span> : null}
											</div>
										</TableCell>
										<TableCell className="max-w-[260px] whitespace-normal">
											<TargetCell rule={rule} />
										</TableCell>
										<TableCell className="tabular-nums">{rule.code ?? '—'}</TableCell>
										<TableCell>{rule.when ? <Badge variant="secondary">{conditionLabel(rule.when.type)}</Badge> : <span className="text-muted-foreground">—</span>}</TableCell>
										<TableCell className="text-label">
											{group.title}
											{!group.enabled ? <span className="block text-micro">{__('(group off)', 'proto-blocks')}</span> : null}
											{groupReadOnlyReason(group) !== null && groupReadOnlyReason(group) !== 'php' ? <span className="block max-w-[200px] text-micro whitespace-normal text-muted-foreground">{readOnlyReasonLabel(group)}</span> : null}
										</TableCell>
										<TableCell className="text-right tabular-nums">{rule.hits}</TableCell>
										<TableCell className="text-label whitespace-nowrap">{formatDate(rule.lastHit)}</TableCell>
										<TableCell>
											<span className="inline-flex flex-wrap items-center gap-1.5">
												<OriginBadge origin={rule.origin} label={rule.origin === 'php' ? __('PHP', 'proto-blocks') : __('File', 'proto-blocks')} double={group.double} />
												{rule.issues.length > 0 ? (
													<Tooltip>
														<TooltipTrigger type="button" className="rounded-full" aria-label={rule.issues.map((issue) => issue.message).join(' ')}>
															<Badge variant={rule.issues.some((issue) => issue.level === 'error') ? 'danger' : 'warning'}>
																<TriangleAlertIcon aria-hidden="true" />
																{rule.issues.length}
															</Badge>
														</TooltipTrigger>
														<TooltipContent className="max-w-[360px]">{rule.issues.map((issue) => issue.message).join(' ')}</TooltipContent>
													</Tooltip>
												) : null}
											</span>
										</TableCell>
										<TableCell className="text-right whitespace-nowrap">
											<span className="inline-flex items-center gap-0.5">
												<Button variant="ghost" size="icon-sm" aria-label={sprintf(/* translators: %s: rule source. */ __('Edit %s', 'proto-blocks'), label)} {...writeGuard(readOnly, busy || !canEdit(group))} onClick={() => setDialog({ rule, group: groupEditable(group) ? group.slug : defaultGroup(index) })}>
													<PencilIcon aria-hidden="true" />
												</Button>
												<Button variant="ghost" size="icon-sm" aria-label={sprintf(/* translators: %s: rule source. */ __('Move %s up', 'proto-blocks'), label)} {...writeGuard(readOnly, !editable || busy || rule.index === 0)} onClick={() => void runWrites([groupWrite(group, (text) => moveRule(text, refOf(rule), -1))])}>
													<ArrowUpIcon aria-hidden="true" />
												</Button>
												<Button variant="ghost" size="icon-sm" aria-label={sprintf(/* translators: %s: rule source. */ __('Move %s down', 'proto-blocks'), label)} {...writeGuard(readOnly, !editable || busy || rule.index >= siblings - 1)} onClick={() => void runWrites([groupWrite(group, (text) => moveRule(text, refOf(rule), 1))])}>
													<ArrowDownIcon aria-hidden="true" />
												</Button>
												<Button
													variant="ghost"
													size="icon-sm"
													aria-label={sprintf(/* translators: %s: rule source. */ __('Duplicate %s', 'proto-blocks'), label)}
													{...guard}
													onClick={() =>
														void runWrites(
															[
																groupWrite(group, (text) => {
																	const raw = rawRule(text, rule) ?? {};
																	return addRule(text, { ...raw, id: newRuleId(rows.map((r) => r.rule.id)) }, rule.index + 1);
																}),
															],
															__('Redirect duplicated.', 'proto-blocks')
														)
													}
												>
													<CopyIcon aria-hidden="true" />
												</Button>
												<Button variant="ghost" size="icon-sm" aria-label={sprintf(/* translators: %s: rule source. */ __('Delete %s', 'proto-blocks'), label)} {...guard} onClick={() => setDeleting([rule])}>
													<Trash2Icon aria-hidden="true" />
												</Button>
											</span>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
					{visible.length === 0 ? <p className="px-6 py-4 text-label text-muted-foreground">{__('No rules match these filters.', 'proto-blocks')}</p> : null}
				</Panel>
			)}

			<TestBox />

			{dialog ? (
				<RuleDialog open index={index} rule={dialog.rule} group={dialog.group} onOpenChange={(open) => (open ? null : setDialog(null))} onSubmit={save} />
			) : null}
			<ConfirmDialog
				open={deleting !== null}
				destructive
				title={
					deleting
						? sprintf(
								/* translators: %d: number of rules. */
								_n('Delete %d redirect?', 'Delete %d redirects?', deleting.length, 'proto-blocks'),
								deleting.length
							)
						: ''
				}
				description={__('The rules are removed from their group files. Git still has them.', 'proto-blocks')}
				confirmLabel={
					deleting
						? sprintf(
								/* translators: %d: number of rules. */
								_n('Delete %d redirect', 'Delete %d redirects', deleting.length, 'proto-blocks'),
								deleting.length
							)
						: ''
				}
				onCancel={() => setDeleting(null)}
				onConfirm={() => {
					const list = deleting ?? [];
					setDeleting(null);
					const byGroup = new Map<string, Rule[]>();
					for (const rule of list) {
						byGroup.set(rule.group, [...(byGroup.get(rule.group) ?? []), rule]);
					}
					const writes = [...byGroup.entries()].map(([slugOf, rules]) => {
						const group = groups.find((g) => g.slug === slugOf) as Group;
						return groupWrite(group, (text) => [...rules].sort((a, b) => b.index - a.index).reduce((out, rule) => removeRule(out, refOf(rule)), text));
					});
					void runWrites(writes, _n('Redirect deleted.', 'Redirects deleted.', list.length, 'proto-blocks')).then(async (ok) => {
						if (!ok) {
							return;
						}
						setSelected(new Set());
						if (index.db.tables === false) {
							return;
						}
						// The rules are gone from the files; their hit rows go too. The delete stands either way.
						const { failed, error } = await deleteHitRows(list.filter((rule) => rule.origin === 'file'));
						if (failed > 0) {
							toast.warning(_n('The hit count of the deleted rule was not cleared.', 'The hit counts of the deleted rules were not cleared.', failed, 'proto-blocks'), { description: error?.message });
						}
					});
				}}
			/>
			{dialogs}
		</div>
	);
}

function FilterSelect({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (value: string) => void; options: [string, string][] }) {
	return (
		<div className="grid gap-1">
			<Label htmlFor={id} className="text-caption">
				{label}
			</Label>
			<Select value={value} onValueChange={onChange}>
				<SelectTrigger id={id} className="w-40">
					<SelectValue />
				</SelectTrigger>
				<SelectContent>
					{options.map(([optionValue, optionLabel]) => (
						<SelectItem key={optionValue} value={optionValue}>
							{optionLabel}
						</SelectItem>
					))}
				</SelectContent>
			</Select>
		</div>
	);
}
