import { useEffect, useId, useMemo, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { ArrowDownIcon, ArrowRightIcon, ArrowUpIcon, CopyIcon, EllipsisVerticalIcon, PlusIcon, SearchIcon, Trash2Icon, TriangleAlertIcon } from 'lucide-react';
import { adminPost } from '@/admin/api';
import { EmptyCallout, themeRelative } from '@/admin/components/content-model/Layout';
import { DoubleBadge, ORIGIN_TONES } from '@/admin/components/content-model/OriginBadge';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { Panel } from '@/admin/components/shell/Panel';
import { Dot } from '@/admin/components/shell/Signals';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/admin/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/admin/components/ui/select';
import { Switch } from '@/admin/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/admin/components/ui/tooltip';
import { cn } from '@/admin/lib/utils';
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
			return <code className="font-mono text-label break-all">{rule.to}</code>;
		case 'pass':
			return (
				<span className="text-label">
					{actionLabel('pass')} <code className="font-mono break-all">{rule.to}</code>
				</span>
			);
		default:
			return <span className="text-label text-muted-foreground">{actionLabel(rule.action)}</span>;
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
			<div className="flex flex-wrap items-center gap-2">
				<div className="relative max-w-[360px] min-w-[220px] flex-1">
					<Label htmlFor={ids.search} className="sr-only">
						{__('Search redirects', 'proto-blocks')}
					</Label>
					<SearchIcon aria-hidden="true" className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
					<Input id={ids.search} type="search" className="h-[38px] ps-9" value={search} placeholder={__('Search source, target or title', 'proto-blocks')} onChange={(event) => setSearch(event.target.value)} />
				</div>
				<div className="flex flex-1 flex-wrap gap-1.5">
					<FilterPill id={ids.group} label={__('Group', 'proto-blocks')} value={slug ?? 'all'} onChange={(value) => onSlug(value === 'all' ? null : value)} options={[['all', __('All groups', 'proto-blocks')], ...groups.map((g): [string, string] => [g.slug, g.title])]} />
					<FilterPill id={ids.code} label={__('Code', 'proto-blocks')} value={code} onChange={setCode} options={[['all', __('All codes', 'proto-blocks')], ...codes.map((c): [string, string] => [String(c), String(c)])]} />
					<FilterPill
						id={ids.condition}
						label={__('Condition', 'proto-blocks')}
						value={condition}
						onChange={setCondition}
						options={[['all', __('Any condition', 'proto-blocks')], ['none', conditionLabel('none')], ...CONDITIONS.map((type): [string, string] => [type, conditionLabel(type)])]}
					/>
					<FilterPill
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
				</div>
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
				<Panel className="overflow-hidden">
					<Table aria-label={__('Redirect rules', 'proto-blocks')}>
						<TableHeader>
							<TableRow>
								<TableHead className="w-11 pr-0">
									<Checkbox aria-label={__('Select all shown rules', 'proto-blocks')} checked={allVisibleSelected} onCheckedChange={(checked) => toggleAll(checked === true)} />
								</TableHead>
								<TableHead>{__('Rule', 'proto-blocks')}</TableHead>
								<TableHead className="w-[90px]">{__('Code', 'proto-blocks')}</TableHead>
								<TableHead className="w-[140px]">{__('Group', 'proto-blocks')}</TableHead>
								<TableHead className="w-[70px] text-right">{__('Hits', 'proto-blocks')}</TableHead>
								<TableHead className="w-[130px]">{__('Last hit', 'proto-blocks')}</TableHead>
								<TableHead className="w-[70px]">{__('On', 'proto-blocks')}</TableHead>
								<TableHead className="w-[120px]">
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
								const on = rule.enabled && group.enabled;
								const reason = groupReadOnlyReason(group);
								return (
									<TableRow key={key} data-state={selected.has(key) ? 'selected' : undefined} data-enabled={on} className={on ? undefined : 'bg-subtle text-muted-foreground'}>
										<TableCell className="pr-0 align-top">
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
										<TableCell className="min-w-[260px] py-3 align-top whitespace-normal">
											<div className={cn('flex flex-wrap items-center gap-x-2 gap-y-1', on ? 'text-foreground' : 'text-muted-foreground')}>
												<code className="font-mono text-label break-all">{label}</code>
												{rule.regex ? <span className="rounded-sm bg-code px-1.5 font-mono text-eyebrow leading-[18px] font-medium text-link">{__('regex', 'proto-blocks')}</span> : null}
												<ArrowRightIcon aria-hidden="true" className="size-3.5 flex-none text-faint" />
												<TargetCell rule={rule} />
												{rule.when ? <span className="rounded-full bg-brand-soft px-2 text-[11.5px] leading-5 font-medium text-brand-ink">{conditionLabel(rule.when.type)}</span> : null}
												{group.double ? <DoubleBadge double={group.double} /> : null}
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
											</div>
											<div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-caption text-muted-foreground">
												<Dot tone={ORIGIN_TONES[rule.origin] ?? 'neutral'} className="gap-1.5 text-caption">
													{rule.origin === 'php' ? __('PHP', 'proto-blocks') : __('File', 'proto-blocks')}
												</Dot>
												{rule.title ? (
													<>
														<span aria-hidden="true">·</span>
														<span>{rule.title}</span>
													</>
												) : null}
												{rule.action === 'url' && rule.else ? (
													<>
														<span aria-hidden="true">·</span>
														<span>
															{__('otherwise', 'proto-blocks')} <code className="font-mono text-micro break-all">{rule.else}</code>
														</span>
													</>
												) : null}
											</div>
										</TableCell>
										<TableCell className="py-3 align-top">
											<CodeCell code={rule.code} />
										</TableCell>
										<TableCell className="py-3 align-top text-label whitespace-normal text-muted-foreground">
											{group.title}
											{!group.enabled ? <span className="block text-micro">{__('(group off)', 'proto-blocks')}</span> : null}
											{reason !== null && reason !== 'php' ? <span className="block max-w-[200px] text-micro">{readOnlyReasonLabel(group)}</span> : null}
										</TableCell>
										<TableCell className={cn('py-3 text-right align-top tabular-nums', rule.hits === 0 && 'text-faint')}>{rule.hits}</TableCell>
										<TableCell className="py-3 align-top text-label whitespace-nowrap text-muted-foreground">{formatDate(rule.lastHit)}</TableCell>
										<TableCell className="py-3 align-top">
											<Switch
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
										<TableCell className="py-2.5 text-right align-top whitespace-nowrap">
											<span className="inline-flex items-center gap-1">
												<Button variant="outline" size="sm" aria-label={sprintf(/* translators: %s: rule source. */ __('Edit %s', 'proto-blocks'), label)} {...writeGuard(readOnly, busy || !canEdit(group))} onClick={() => setDialog({ rule, group: groupEditable(group) ? group.slug : defaultGroup(index) })}>
													{__('Edit', 'proto-blocks')}
												</Button>
												<RowMenu label={label}>
													<MenuItem icon={<ArrowUpIcon />} label={sprintf(/* translators: %s: rule source. */ __('Move %s up', 'proto-blocks'), label)} {...writeGuard(readOnly, !editable || busy || rule.index === 0)} onClick={() => void runWrites([groupWrite(group, (text) => moveRule(text, refOf(rule), -1))])}>
														{__('Move up', 'proto-blocks')}
													</MenuItem>
													<MenuItem icon={<ArrowDownIcon />} label={sprintf(/* translators: %s: rule source. */ __('Move %s down', 'proto-blocks'), label)} {...writeGuard(readOnly, !editable || busy || rule.index >= siblings - 1)} onClick={() => void runWrites([groupWrite(group, (text) => moveRule(text, refOf(rule), 1))])}>
														{__('Move down', 'proto-blocks')}
													</MenuItem>
													<MenuItem
														icon={<CopyIcon />}
														label={sprintf(/* translators: %s: rule source. */ __('Duplicate %s', 'proto-blocks'), label)}
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
														{__('Duplicate', 'proto-blocks')}
													</MenuItem>
													<MenuItem icon={<Trash2Icon />} danger label={sprintf(/* translators: %s: rule source. */ __('Delete %s', 'proto-blocks'), label)} {...guard} onClick={() => setDeleting([rule])}>
														{__('Delete', 'proto-blocks')}
													</MenuItem>
												</RowMenu>
											</span>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
					{visible.length === 0 ? <p className="px-6 py-4 text-label text-muted-foreground">{__('No rules match these filters.', 'proto-blocks')}</p> : null}
					<div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t bg-subtle px-5 py-3 text-caption text-muted-foreground">
						<span>
							{sprintf(
								/* translators: 1: number of rules, 2: number of groups. */
								_n('%1$d rule in %2$s', '%1$d rules in %2$s', visible.length, 'proto-blocks'),
								visible.length,
								sprintf(/* translators: %d: number of groups. */ _n('%d group', '%d groups', new Set(visible.map((row) => row.group.slug)).size, 'proto-blocks'), new Set(visible.map((row) => row.group.slug)).size)
							)}
							{slug && visible[0]?.rule.origin === 'file' ? (
								<>
									{' · '}
									<code className="font-mono text-micro text-link">{themeRelative(visible[0].rule.source)}</code>
								</>
							) : null}
						</span>
						<span>{__('Rules are matched top to bottom; use the ⋮ menu → Move up or Move down to reorder.', 'proto-blocks')}</span>
					</div>
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

/**
 * Toolbar filter as a pill (design): dashed "+ Code" while unset, solid
 * "Code 301" once chosen. The visually hidden label names the combobox.
 */
function FilterPill({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (value: string) => void; options: [string, string][] }) {
	const active = value !== 'all';
	const current = options.find(([optionValue]) => optionValue === value)?.[1] ?? value;
	return (
		<>
			<Label htmlFor={id} className="sr-only">
				{label}
			</Label>
			<Select value={value} onValueChange={onChange}>
				<SelectTrigger
					id={id}
					data-active={active}
					className={cn(
						'h-[34px] w-auto max-w-[240px] gap-1.5 rounded-full py-0 pr-2.5 pl-3 text-label font-medium shadow-none',
						active ? 'bg-card text-foreground' : 'border-dashed bg-transparent text-muted-foreground [&>svg:last-child]:hidden'
					)}
				>
					{active ? (
						<span className="truncate">
							<span className="font-normal text-muted-foreground">{label}</span> {current}
						</span>
					) : (
						<span className="inline-flex items-center gap-1.5">
							<PlusIcon aria-hidden="true" className="size-[13px]" />
							{label}
						</span>
					)}
				</SelectTrigger>
				<SelectContent>
					{options.map(([optionValue, optionLabel]) => (
						<SelectItem key={optionValue} value={optionValue}>
							{optionLabel}
						</SelectItem>
					))}
				</SelectContent>
			</Select>
		</>
	);
}

/** Code with its ink: redirects plain, temporary ones noted, errors amber; none for pass/nothing. */
function CodeCell({ code }: { code: number | null }) {
	if (code === null) {
		return <span className="text-faint">—</span>;
	}
	const temporary = code === 302 || code === 303 || code === 307;
	return (
		<span className={cn('inline-flex items-center gap-1.5 text-label font-medium tabular-nums', code >= 400 ? 'text-warning' : 'text-foreground')}>
			<span>{code}</span>
			{temporary ? <span className="text-eyebrow font-normal text-faint">{__('temp', 'proto-blocks')}</span> : null}
		</span>
	);
}

/** The row's "⋮" menu: reorder, duplicate and delete. */
function RowMenu({ label, children }: { label: string; children: ReactNode }) {
	const [open, setOpen] = useState(false);
	return (
		<Popover open={open} onOpenChange={setOpen}>
			<PopoverTrigger asChild>
				<Button variant="ghost" size="icon-sm" className="text-muted-foreground hover:bg-code hover:text-foreground" aria-label={sprintf(/* translators: %s: rule source. */ __('More actions for %s', 'proto-blocks'), label)}>
					<EllipsisVerticalIcon aria-hidden="true" />
				</Button>
			</PopoverTrigger>
			<PopoverContent align="end" className="grid w-48 gap-px p-1.5" onClick={() => setOpen(false)}>
				{children}
			</PopoverContent>
		</Popover>
	);
}

function MenuItem({ icon, label, danger = false, children, ...props }: { icon: ReactNode; label: string; danger?: boolean; children: ReactNode; disabled?: boolean; 'aria-describedby'?: string; onClick: () => void }) {
	return (
		<button
			type="button"
			aria-label={label}
			className={cn(
				'flex h-8 w-full cursor-pointer items-center gap-2 rounded-[5px] px-2.5 text-left text-label outline-none hover:bg-code focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent [&_svg]:size-4 [&_svg]:text-muted-foreground',
				danger && 'text-destructive [&_svg]:text-destructive'
			)}
			{...props}
		>
			<span aria-hidden="true">{icon}</span>
			{children}
		</button>
	);
}
