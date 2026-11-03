import { useEffect, useId, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { EyeOffIcon, InfoIcon, PlusIcon, PowerOffIcon, Trash2Icon, TriangleAlertIcon } from 'lucide-react';
import { ApiError } from '@/admin/api';
import type { WriteReason } from '@/admin/bootstrap';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner, READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/admin/components/ui/tooltip';
import type { Guardrail, Issue, Origin } from '@/admin/content-model/types';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { interpolate } from '@/admin/lib/interpolate';
import { setAtPath } from './jsonc-edit';
import { deleteSchemaFile, getSchemaFile, putSchemaFile, type SchemaKind } from './schema-files';

/** GET admin/fields (includes/API/Admin/FieldsRoutes.php). Keep in step with it. */
export type GroupOrigin = 'file' | 'php' | 'type-file' | 'taxonomy-file' | 'acf';

export interface GroupIssue {
	level: 'error' | 'warning';
	code: string;
	/** The file (relative to the theme root) or PHP location the issue comes from. */
	path: string;
	message: string;
}

export interface GroupFileRef {
	kind: SchemaKind;
	slug: string;
	/** Position in the file's group list (`fields[index]` of a type file, or an ACF export). */
	index?: number;
	/** Relative to the theme root, e.g. `proto-fields/events/group.jsonc`. */
	path: string;
}

export interface GroupRow {
	key: string;
	title: string;
	origin: GroupOrigin;
	/** Where a save goes; null for PHP and ACF groups. */
	file: GroupFileRef | null;
	/** The declaring file (theme-relative) or PHP call, for display. */
	source: string | null;
	/** "post_type == event or ..." */
	location: string;
	fieldCount: number;
	position: string;
	status: 'active' | 'unrenderable' | 'skipped-acf';
	issues: GroupIssue[];
	doubleDeclared: boolean;
	editable: boolean;
	readOnlyReason: 'file_mods' | 'multisite' | 'not_writable' | 'acf' | 'inline' | null;
}

export interface FieldsListResult {
	moduleEnabled: boolean;
	acfActive: boolean;
	createTarget: { path: string; writable: boolean; reason: string | null };
	groups: GroupRow[];
	/** Problems not tied to a listed group (a skipped folder, a broken file). */
	issues: Issue[];
}

const code = (text: string) => <code className="font-mono text-[12.5px] break-all">{text}</code>;

function originBadge(origin: GroupOrigin): { origin: Origin; label: string } {
	switch (origin) {
		case 'php':
			return { origin: 'php', label: __('Proto-Blocks PHP', 'proto-blocks') };
		case 'acf':
			return { origin: 'plugin', label: __('Plugin: Advanced Custom Fields', 'proto-blocks') };
		default:
			return { origin: 'file', label: __('Proto-Blocks file', 'proto-blocks') };
	}
}

function positionLabel(position: string): string {
	switch (position) {
		case 'side':
			return __('Sidebar', 'proto-blocks');
		case 'acf_after_title':
			return __('After title', 'proto-blocks');
		default:
			return __('Below editor', 'proto-blocks');
	}
}

/** Why a group has no delete button. */
function readOnlyExplanation(row: GroupRow): string {
	if (row.origin === 'acf') {
		return __('Advanced Custom Fields owns this key. Edit or remove the group in ACF.', 'proto-blocks');
	}
	if (row.origin === 'php') {
		return row.source
			? sprintf(
					/* translators: %s: PHP file and line that declares the group. */
					__('This group is declared in PHP (%s). Remove it from the theme code instead.', 'proto-blocks'),
					row.source
				)
			: __('This group is declared in PHP. Remove it from the theme code instead.', 'proto-blocks');
	}
	if (row.origin === 'taxonomy-file' && row.file === null) {
		return __('This group belongs to a taxonomy declared inside a post type file. Edit it in that file.', 'proto-blocks');
	}
	if (row.readOnlyReason === 'not_writable') {
		return row.source
			? sprintf(
					/* translators: %s: path of the file that declares the group. */
					__('%s is outside the theme folders Proto-Blocks can write to, or its folder is not writable. Edit the file directly.', 'proto-blocks'),
					row.source
				)
			: __('This file is outside the theme folders Proto-Blocks can write to, or its folder is not writable. Edit the file directly.', 'proto-blocks');
	}
	return __('This group is declared inside another declaration file. Edit it there.', 'proto-blocks');
}

/** The sentence that says why an unrenderable group shows nowhere. */
function notShownText(row: GroupRow): string {
	const rule = row.issues.find((issue) => issue.code === 'unsupported_location_rule' || issue.code === 'empty_location_branch');
	return rule ? rule.message : __('No location rule can match, so this group is not shown anywhere.', 'proto-blocks');
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

/**
 * A proto-fields file's root when it is a list (an ACF export), else [].
 * parseJsonc wants an object root, so the list is read as `{"groups": <list>}`.
 */
function parseListRoot(text: string): unknown[] {
	const wrapped = parseJsonc('{"groups":' + text + '\n}');
	const groups = wrapped.errors.length === 0 ? wrapped.data.groups : undefined;
	return Array.isArray(groups) ? groups : [];
}

/** The group's position in its declaration file's `fields` (or list root), checked against the key. */
function groupIndex(data: unknown, row: GroupRow): number {
	const groups = Array.isArray(data) ? data : (data as Record<string, unknown> | null)?.fields;
	if (!Array.isArray(groups)) {
		return -1;
	}
	const keyAt = (i: number) => {
		const group = groups[i] as Record<string, unknown> | undefined;
		return group && typeof group === 'object' ? group.key : undefined;
	};
	if (row.file?.index !== undefined && keyAt(row.file.index) === row.key) {
		return row.file.index;
	}
	return groups.findIndex((_, i) => keyAt(i) === row.key);
}

/** Titles (or keys) of the other groups in a list of raw groups. */
function siblingNames(data: unknown, row: GroupRow): string[] {
	if (!Array.isArray(data)) {
		return [];
	}
	return data
		.filter((group): group is Record<string, unknown> => group !== null && typeof group === 'object' && (group as Record<string, unknown>).key !== row.key)
		.map((group) => (typeof group.title === 'string' && group.title !== '' ? group.title : String(group.key ?? '')));
}

/**
 * Confirms and runs a delete. A `proto-fields` file holding only this group
 * is deleted (the server wants the slug typed). A group that shares its file
 * (an ACF export list, or the `fields` of a type or taxonomy file) is removed
 * from that file only, other groups and comments kept, with the hash it was
 * read with; the dialog names the groups that stay. A guardrail answer
 * (stored values) asks once more.
 */
function DeleteGroupDialog({ row, onCancel, onDone }: { row: GroupRow; onCancel: () => void; onDone: () => void }) {
	const file = row.file as GroupFileRef;
	/** A proto-fields list file: whether others share it is known once it is read. */
	const sharedCandidate = file.kind === 'field-group' && file.index !== undefined;
	const [siblings, setSiblings] = useState<string[] | null>(null);
	const ownFile = file.kind === 'field-group' && (!sharedCandidate || (siblings !== null && siblings.length === 0));
	const [typed, setTyped] = useState('');
	const [busy, setBusy] = useState(false);
	const [guardrails, setGuardrails] = useState<Guardrail[] | null>(null);
	const inputId = useId();

	useEffect(() => {
		setTyped('');
		setGuardrails(null);
		setSiblings(null);
		if (!sharedCandidate) {
			return;
		}
		let live = true;
		getSchemaFile(file.kind, file.slug).then(
			(current) => {
				if (live) {
					setSiblings(siblingNames(parseListRoot(current.text), row));
				}
			},
			(error: unknown) => {
				if (live) {
					toast.error(error instanceof Error ? error.message : String(error));
					onCancel();
				}
			}
		);
		return () => {
			live = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [row.key]);

	const fail = (error: unknown) => {
		toast.error(error instanceof Error ? error.message : String(error));
		onDone();
	};

	const run = async () => {
		setBusy(true);
		try {
			if (ownFile) {
				await deleteSchemaFile('field-group', file.slug, typed);
			} else {
				const current = await getSchemaFile(file.kind, file.slug);
				const list = file.kind === 'field-group';
				const data: unknown = list ? parseListRoot(current.text) : parseJsonc(current.text).data;
				const index = groupIndex(data, row);
				if (index < 0) {
					throw new Error(__('The group is no longer in the file. The list has been reloaded.', 'proto-blocks'));
				}
				const path: (string | number)[] = list ? [index] : ['fields', index];
				const text = setAtPath(current.text, path, undefined);
				try {
					await putSchemaFile(file.kind, file.slug, text, current.hash, guardrails ? guardrails.map((g) => g.code) : []);
				} catch (error) {
					if (error instanceof ApiError && error.code === 'proto_blocks_needs_confirmation' && guardrails === null) {
						setGuardrails(Array.isArray(error.data.guardrails) ? (error.data.guardrails as Guardrail[]) : []);
						setBusy(false);
						return;
					}
					throw error;
				}
			}
			toast.success(
				sprintf(
					/* translators: %s: field group title. */
					__('Deleted the field group “%s”.', 'proto-blocks'),
					row.title || row.key
				)
			);
			onDone();
		} catch (error) {
			fail(error);
		}
	};

	const title = sprintf(
		/* translators: %s: field group title. */
		__('Delete the %s field group?', 'proto-blocks'),
		row.title || row.key
	);
	const description = ownFile
		? interpolate(
				/* translators: %s: path of the field group file. */
				__('This deletes %s. Values saved in its fields stay in the database but are no longer shown.', 'proto-blocks'),
				code(file.path)
			)
		: interpolate(
				/* translators: %s: path of the file that holds the group. */
				__('This removes the group from %s. Values saved in its fields stay in the database but are no longer shown.', 'proto-blocks'),
				code(file.path)
			);
	const waiting = sharedCandidate && siblings === null;

	return (
		<ConfirmDialog
			open
			title={title}
			description={description}
			confirmLabel={
				ownFile
					? __('Delete field group', 'proto-blocks')
					: guardrails
						? __('Remove anyway', 'proto-blocks')
						: __('Remove field group', 'proto-blocks')
			}
			destructive
			confirmDisabled={busy || waiting || (ownFile && typed !== file.slug)}
			onConfirm={() => void run()}
			onCancel={onCancel}
		>
			{ownFile ? (
				<div className="grid gap-2">
					<Label htmlFor={inputId} className="text-sm font-normal">
						{interpolate(
							/* translators: %s: the folder slug to type. */
							__('Type %s to confirm', 'proto-blocks'),
							code(file.slug)
						)}
					</Label>
					<Input
						id={inputId}
						value={typed}
						autoComplete="off"
						spellCheck={false}
						className="font-mono"
						onChange={(event) => setTyped(event.target.value)}
					/>
				</div>
			) : null}
			{siblings !== null && siblings.length > 0 ? (
				<p className="text-sm">
					{sprintf(
						/* translators: %s: comma-separated titles of the other field groups in the same file. */
						__('The file also holds %s; those groups are kept.', 'proto-blocks'),
						siblings.join(', ')
					)}
				</p>
			) : null}
			{guardrails ? (
				<ul className="list-disc ps-5 text-sm">
					{guardrails.map((guardrail) => (
						<li key={guardrail.code}>{guardrail.message}</li>
					))}
				</ul>
			) : null}
		</ConfirmDialog>
	);
}

/** C1: every field group with where it shows, its origin and its state. */
export function GroupList({
	result,
	loading,
	error,
	readOnly,
	onRetry,
	onEdit,
	onCreate,
	onDeleted,
}: {
	result: FieldsListResult | null;
	loading: boolean;
	error: unknown;
	/** Why file writes are off for this user, or null. */
	readOnly: WriteReason;
	onRetry: () => void;
	onEdit: (key: string) => void;
	onCreate: () => void;
	onDeleted: () => void;
}) {
	const [deleting, setDeleting] = useState<GroupRow | null>(null);
	const title = __('Fields', 'proto-blocks');
	const enabled = result?.moduleEnabled !== false;
	const createReadOnly = readOnly !== null || result?.createTarget.writable === false;

	const header = (
		<PageHeader
			title={title}
			description={__('Groups of custom fields and where they appear. Proto-Blocks ones are saved as files in your theme.', 'proto-blocks')}
			actions={
				result && enabled ? (
					<Button onClick={onCreate} disabled={createReadOnly} aria-describedby={createReadOnly ? READ_ONLY_BANNER_ID : undefined}>
						<PlusIcon aria-hidden="true" />
						{__('Add field group', 'proto-blocks')}
					</Button>
				) : null
			}
		/>
	);

	if (error && !result) {
		return (
			<div className="grid gap-6">
				{header}
				<ScreenError screen={title} error={error} onRetry={onRetry} />
			</div>
		);
	}

	if (!result) {
		return (
			<div className="grid gap-6">
				{header}
				<Card className="py-0 shadow-xs">
					<LoadingRows />
				</Card>
			</div>
		);
	}

	if (!enabled) {
		return (
			<div className="grid gap-6">
				{header}
				<Card className="flex flex-row items-center gap-3 px-6 py-6 shadow-xs">
					<PowerOffIcon aria-hidden="true" className="size-5 flex-none text-muted-foreground" />
					<p className="text-sm">{__('The Fields module is turned off by the proto_blocks_modules filter.', 'proto-blocks')}</p>
				</Card>
			</div>
		);
	}

	return (
		<div className="grid gap-6">
			{header}
			{readOnly !== null ? (
				<ReadOnlyBanner
					reason={readOnly}
					description={__(
						"You can browse every field group, but changes can't be saved. Ask your developer to edit the files in the theme, or to allow file changes on this environment.",
						'proto-blocks'
					)}
				/>
			) : result.createTarget.writable === false ? (
				<ReadOnlyBanner reason="not_writable" path={result.createTarget.path} />
			) : null}
			<IssueList issues={result.issues} />
			{result.groups.length === 0 ? (
				<Card className="grid justify-items-center gap-2 px-6 py-8 text-center shadow-xs">
					<h2 className="text-[15px] font-semibold">{__('No field groups yet', 'proto-blocks')}</h2>
					<p className="text-[13px] text-muted-foreground">
						{__('Add one here, or declare it in your theme with proto_field_group().', 'proto-blocks')}
					</p>
				</Card>
			) : (
				<Card className="py-0 shadow-xs" aria-busy={loading}>
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>{__('Group', 'proto-blocks')}</TableHead>
								<TableHead className="hidden md:table-cell">{__('Shows on', 'proto-blocks')}</TableHead>
								<TableHead className="text-right">{__('Fields', 'proto-blocks')}</TableHead>
								<TableHead className="hidden md:table-cell">{__('Position', 'proto-blocks')}</TableHead>
								<TableHead>{__('Origin', 'proto-blocks')}</TableHead>
								<TableHead>
									<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
								</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{result.groups.map((row) => {
								const name = row.title || row.key;
								const canWrite = readOnly === null && row.editable;
								const badge = originBadge(row.origin);
								// Site-wide read-only is explained by the banner; anything else gets its own reason.
								const explained = row.file === null || (readOnly === null && !row.editable);
								const explanation = explained ? readOnlyExplanation(row) : '';
								return (
									<TableRow key={`${row.origin}:${row.key}`}>
										<TableCell className="max-w-[320px] whitespace-normal">
											<div className="grid gap-0.5">
												<span className="font-medium">{name}</span>
												<code className="font-mono text-[12px] break-all text-muted-foreground">{row.key}</code>
												{row.status === 'unrenderable' ? (
													<span className="text-[12.5px] text-muted-foreground">{notShownText(row)}</span>
												) : null}
											</div>
										</TableCell>
										<TableCell className="hidden max-w-[260px] whitespace-normal md:table-cell">
											{row.location ? (
												<code className="font-mono text-[12px] break-all">{row.location}</code>
											) : (
												<span className="text-muted-foreground">—</span>
											)}
										</TableCell>
										<TableCell className="text-right tabular-nums">{row.fieldCount}</TableCell>
										<TableCell className="hidden md:table-cell">{positionLabel(row.position)}</TableCell>
										<TableCell>
											<span className="inline-flex flex-wrap items-center gap-1.5">
												<OriginBadge origin={badge.origin} label={badge.label} />
												{row.doubleDeclared ? (
													<Badge variant="outline" className="border-warning-border bg-warning-soft text-foreground [&>svg]:text-warning">
														<TriangleAlertIcon aria-hidden="true" />
														{__('Declared twice', 'proto-blocks')}
													</Badge>
												) : null}
												{row.status === 'unrenderable' ? (
													<Badge variant="outline" className="border-warning-border bg-warning-soft text-foreground">
														<EyeOffIcon aria-hidden="true" />
														{__('Not shown', 'proto-blocks')}
													</Badge>
												) : null}
												{row.status === 'skipped-acf' ? (
													<Badge variant="outline" className="border-warning-border bg-warning-soft text-foreground">
														{__('Skipped: ACF registers this key', 'proto-blocks')}
													</Badge>
												) : null}
												{row.issues.length > 0 ? (
													<Badge variant="outline">
														{sprintf(
															/* translators: %d: number of problems found in the group. */
															_n('%d issue', '%d issues', row.issues.length, 'proto-blocks'),
															row.issues.length
														)}
													</Badge>
												) : null}
											</span>
										</TableCell>
										<TableCell className="text-right">
											<span className="inline-flex items-center justify-end gap-1">
												<Button
													variant="ghost"
													size="sm"
													aria-label={sprintf(
														/* translators: %s: field group title. */
														canWrite ? __('Edit %s', 'proto-blocks') : __('View %s', 'proto-blocks'),
														name
													)}
													onClick={() => onEdit(row.key)}
												>
													{canWrite ? __('Edit', 'proto-blocks') : __('View', 'proto-blocks')}
												</Button>
												{!explained ? (
													<Button
														variant="ghost"
														size="icon"
														aria-label={sprintf(
															/* translators: %s: field group title. */
															__('Delete %s', 'proto-blocks'),
															name
														)}
														disabled={!canWrite}
														aria-describedby={readOnly !== null ? READ_ONLY_BANNER_ID : undefined}
														onClick={() => setDeleting(row)}
													>
														<Trash2Icon aria-hidden="true" />
													</Button>
												) : (
													<Tooltip>
														<TooltipTrigger asChild>
															<Button variant="ghost" size="icon" aria-label={explanation}>
																<InfoIcon aria-hidden="true" />
															</Button>
														</TooltipTrigger>
														<TooltipContent className="max-w-[320px]">{explanation}</TooltipContent>
													</Tooltip>
												)}
											</span>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</Card>
			)}
			{deleting && deleting.file ? (
				<DeleteGroupDialog
					row={deleting}
					onCancel={() => setDeleting(null)}
					onDone={() => {
						setDeleting(null);
						onDeleted();
					}}
				/>
			) : null}
		</div>
	);
}
