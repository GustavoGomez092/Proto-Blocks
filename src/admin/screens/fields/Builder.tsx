import { useCallback, useEffect, useId, useMemo, useRef, useState, type FormEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeftIcon, BracesIcon, ListTreeIcon, PlusIcon, SaveIcon, XIcon } from 'lucide-react';
import { toast } from 'sonner';
import { adminGet, ApiError } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import { ConflictDialog } from '@/admin/components/content-model/ConflictDialog';
import { GuardrailDialog } from '@/admin/components/content-model/GuardrailDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import {
	AlertDialog,
	AlertDialogAction,
	AlertDialogCancel,
	AlertDialogContent,
	AlertDialogDescription,
	AlertDialogFooter,
	AlertDialogHeader,
	AlertDialogTitle,
} from '@/admin/components/ui/alert-dialog';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/admin/components/ui/dialog';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Skeleton } from '@/admin/components/ui/skeleton';
import type { FileResult, Guardrail, ListResult } from '@/admin/content-model/types';
import { AddFieldMenu } from './AddFieldMenu';
import {
	fieldAt,
	groupPointer,
	isObject,
	newGroupKey,
	parseErrorPath,
	reduceWithNotices,
	siblingsAt,
	slugifyName,
	toFileText,
	validateGroup,
	valueAt,
	walkFields,
	type BuilderAction,
	type FieldPath,
	type RawField,
	type RawGroup,
} from './builder-state';
import { FieldSettings, type Choice } from './FieldSettings';
import { FieldTree, rowId } from './FieldTree';
import { GroupSettings } from './GroupSettings';
import { JsoncPane, jsoncErrors } from './JsoncPane';
import { setAtPath } from './jsonc-edit';
import { LocationBuilder, readLocation } from './LocationBuilder';
import { getSchemaFile, putSchemaFile, type SchemaKind } from './schema-files';

/** GET admin/fields/<key> (FieldsRoutes::detail). */
export interface FieldGroupDetail {
	group: RawGroup;
	issues: { level: string; code: string; path: string; message: string }[];
	file: (FileResult & { kind: SchemaKind; slug: string; index: number | null }) | null;
	postTypes: Choice[];
	taxonomies: Choice[];
	roles: Choice[];
	optionsPages: Choice[];
	pageTemplates: Choice[];
	/** Why the group cannot be saved here, when the server says (as in the list's GroupRow). */
	readOnlyReason?: 'file_mods' | 'multisite' | 'not_writable' | 'acf' | 'inline' | null;
}

/** The new-group file slug grammar (the field-group kind's slug). */
const NEW_SLUG = /^[a-z][a-z0-9_-]{0,31}$/;

/** A title as a valid file slug: 'Event details' → 'event-details'. */
function sanitizeSlug(title: string): string {
	return slugifyName(title).replace(/_/g, '-').slice(0, 32).replace(/-+$/, '') || 'group';
}

function readOnlyText(reason: FieldGroupDetail['readOnlyReason']): string {
	switch (reason) {
		case 'acf':
			return __('Advanced Custom Fields owns this key. Edit the group in ACF.', 'proto-blocks');
		case 'inline':
			return __('This group is declared in PHP or inside another declaration. Edit it there.', 'proto-blocks');
		case 'not_writable':
			return __('Its file is outside the theme folders Proto-Blocks can write to, or its folder is not writable. Edit the file directly.', 'proto-blocks');
		default:
			return __('It is not stored in a file this screen can write. Edit it where it is declared.', 'proto-blocks');
	}
}

/** The ACF detail payload carries only {key, title} and no file. */
function isAcfGroup(detail: FieldGroupDetail): boolean {
	return detail.readOnlyReason === 'acf' || (detail.file === null && !Array.isArray(detail.group.fields));
}

interface Draft {
	/** The file text with every edit applied; exactly what Save writes and the preview shows. */
	text: string;
	group: RawGroup;
	pointer: (string | number)[];
	/** Hash the file was loaded with; null for a new file. */
	hash: string | null;
	/** Field names by key in the saved file, for the rename warning. */
	savedNames: Map<string, string>;
	/** The file text as last loaded or saved: Discard returns to it, and anything else is unsaved. */
	saved: string;
}

interface Problem {
	message: string;
	/** The server's error path (`fields[0].name`), shown beside the message. */
	path?: string;
	key?: string;
	setting?: string;
}

function namesByKey(group: RawGroup): Map<string, string> {
	const names = new Map<string, string>();
	walkFields(group.fields, (f) => {
		if (typeof f.key === 'string' && typeof f.name === 'string') {
			names.set(f.key, f.name);
		}
	});
	return names;
}

function pathOf(group: RawGroup, key: string | null): FieldPath | null {
	let found: FieldPath | null = null;
	if (key !== null) {
		walkFields(group.fields, (f, path) => {
			if (found === null && rowId(f, path) === key) {
				found = path;
			}
		});
	}
	return found;
}

function draftFrom(detail: FieldGroupDetail): Draft {
	const file = detail.file;
	if (!file) {
		const text = JSON.stringify(detail.group, null, 2);
		return { text, group: detail.group, pointer: [], hash: null, savedNames: namesByKey(detail.group), saved: text };
	}
	const pointer = groupPointer(file);
	const fromText = valueAt(file.text, pointer);
	const group = isObject(fromText) ? fromText : detail.group;
	return { text: file.text, group, pointer, hash: file.hash, savedNames: namesByKey(group), saved: file.text };
}

/** The draft as last saved, keeping where the group sits and its hash. */
function discarded(current: Draft): Draft {
	const at = valueAt(current.saved, current.pointer);
	return { ...current, text: current.saved, group: isObject(at) ? at : current.group };
}

/** A line comment cannot hold a line break: one would let the title write JSON. */
function commentSafe(text: string): string {
	return text.replace(/[\r\n\u2028\u2029]+/g, ' ');
}

const settingId = (key: string, setting: string) => `pb-field-${key}-${setting.replace(/\./g, '-')}`;

// --- New group dialog -------------------------------------------------------

interface NewGroupDialogProps {
	onCancel: () => void;
	onCreated: (key: string) => void;
}

/**
 * Title and destination for a new group, then the file write: its own
 * `proto-fields/<slug>/group.jsonc` (slug checked by the server's GET first:
 * an invalid slug is refused, an existing file is "taken"), or appended to a
 * Proto-Blocks post type file's `fields` with that file's hash.
 */
function NewGroupDialog({ onCancel, onCreated }: NewGroupDialogProps) {
	const titleId = useId();
	const slugId = useId();
	const typeId = useId();
	const [title, setTitle] = useState('');
	const [slugText, setSlugText] = useState<string | null>(null);
	const [destination, setDestination] = useState<'own' | 'type'>('own');
	const [types, setTypes] = useState<Choice[] | null>(null);
	const [type, setType] = useState('');
	const [serverError, setServerError] = useState<string | null>(null);
	const [busy, setBusy] = useState(false);

	useEffect(() => {
		if (destination !== 'type' || types !== null) {
			return;
		}
		adminGet<ListResult>('admin/types').then(
			(result) => {
				const own = result.items.filter((item) => item.origin === 'file' && item.editable).map((item) => ({ slug: item.slug, label: item.label }));
				setTypes(own);
				setType((current) => current || own[0]?.slug || '');
			},
			() => setTypes([])
		);
	}, [destination, types]);

	const slug = slugText ?? sanitizeSlug(title);
	const slugError = destination === 'own' && !NEW_SLUG.test(slug) ? __('Use 1 to 32 lowercase letters, digits, "-" or "_", starting with a letter.', 'proto-blocks') : null;
	const ready = title.trim() !== '' && !busy && (destination === 'own' ? slugError === null : type !== '');

	const submit = async (event: FormEvent) => {
		event.preventDefault();
		if (!ready) {
			if (slugError) {
				document.getElementById(slugId)?.focus();
			}
			return;
		}
		setBusy(true);
		setServerError(null);
		const name = title.trim();
		const group: RawGroup = { key: newGroupKey(), title: name, fields: [] };
		try {
			let path: string;
			if (destination === 'own') {
				const existing = await getSchemaFile('field-group', slug);
				if (existing.exists) {
					setServerError(sprintf(/* translators: %s: file path */ __('%s already exists. Pick another file name.', 'proto-blocks'), existing.path));
					return;
				}
				const text = `// Field group: ${commentSafe(name)}. Edited by the Proto-Blocks Fields screen; comments are kept.\n${JSON.stringify({ ...group, location: [] }, null, 2)}\n`;
				path = (await putSchemaFile('field-group', slug, text, null)).path;
			} else {
				const file = await getSchemaFile('type', type);
				const list = valueAt(file.text, ['fields']);
				const text = Array.isArray(list) ? setAtPath(file.text, ['fields', -1], group) : setAtPath(file.text, ['fields'], [group]);
				path = (await putSchemaFile('type', type, text, file.hash)).path;
			}
			toast.success(sprintf(/* translators: %s: file path */ __('Field group saved to %s.', 'proto-blocks'), path));
			onCreated(String(group.key));
		} catch (e) {
			setServerError(e instanceof ApiError ? e.message : __('The field group could not be created.', 'proto-blocks'));
		} finally {
			setBusy(false);
		}
	};

	return (
		<Dialog open onOpenChange={(open) => !open && onCancel()}>
			<DialogContent>
				<form onSubmit={submit} className="grid gap-4" noValidate>
					<DialogHeader>
						<DialogTitle>{__('Add field group', 'proto-blocks')}</DialogTitle>
						<DialogDescription>{__('Name the group and choose the file it is written to.', 'proto-blocks')}</DialogDescription>
					</DialogHeader>
					<div className="grid gap-1.5">
						<Label htmlFor={titleId}>{__('Title', 'proto-blocks')}</Label>
						<Input id={titleId} autoFocus value={title} onChange={(e) => setTitle(e.target.value)} />
					</div>
					<fieldset className="grid gap-2">
						<legend className="mb-1 text-sm font-medium">{__('Destination', 'proto-blocks')}</legend>
						<RadioGroup value={destination} onValueChange={(v) => setDestination(v === 'type' ? 'type' : 'own')}>
							<div className="flex items-center gap-2">
								<RadioGroupItem id={`${titleId}-own`} value="own" />
								<Label htmlFor={`${titleId}-own`} className="font-normal">
									{__('Own file', 'proto-blocks')} <span className="font-mono text-xs">{`proto-fields/${slug}/group.jsonc`}</span>
								</Label>
							</div>
							<div className="flex items-center gap-2">
								<RadioGroupItem id={`${titleId}-type`} value="type" />
								<Label htmlFor={`${titleId}-type`} className="font-normal">
									{__('Inside a post type file', 'proto-blocks')}
								</Label>
							</div>
						</RadioGroup>
					</fieldset>
					{destination === 'own' ? (
						<div className="grid gap-1.5">
							<Label htmlFor={slugId}>{__('File name', 'proto-blocks')}</Label>
							<Input
								id={slugId}
								className="font-mono"
								value={slug}
								aria-invalid={slugError ? true : undefined}
								aria-describedby={slugError ? `${slugId}-error` : undefined}
								onChange={(e) => setSlugText(e.target.value)}
							/>
							{slugError ? (
								<p id={`${slugId}-error`} role="alert" className="text-[13px] text-destructive">
									{slugError}
								</p>
							) : null}
						</div>
					) : (
						<div className="grid gap-1.5">
							<Label htmlFor={typeId}>{__('Post type', 'proto-blocks')}</Label>
							{types !== null && types.length === 0 ? (
								<p className="text-sm text-muted-foreground">{__('No post type is declared in a Proto-Blocks file this screen can write.', 'proto-blocks')}</p>
							) : (
								<select id={typeId} className="h-9 rounded-md border bg-transparent px-3 text-sm" value={type} onChange={(e) => setType(e.target.value)}>
									{(types ?? []).map((t) => (
										<option key={t.slug} value={t.slug}>
											{t.label}
										</option>
									))}
								</select>
							)}
							{type ? <p className="font-mono text-xs text-muted-foreground">{`proto-types/${type}/type.jsonc › fields`}</p> : null}
						</div>
					)}
					{serverError ? (
						<p role="alert" className="text-[13px] text-destructive">
							{serverError}
						</p>
					) : null}
					<DialogFooter>
						<Button type="button" variant="outline" onClick={onCancel}>
							{__('Cancel', 'proto-blocks')}
						</Button>
						<Button type="submit" aria-disabled={!ready || undefined}>
							{busy ? __('Creating…', 'proto-blocks') : __('Create field group', 'proto-blocks')}
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

// --- Builder ----------------------------------------------------------------

export interface BuilderProps {
	/** The group key from `?group=`; null for `?new=1` (the new-group dialog). */
	group: string | null;
	onBack: () => void;
	/** A new group was written; the screen opens it by key. */
	onCreated?: (key: string) => void;
}

/**
 * The visual field-group builder: the group settings and location, the field
 * tree, the selected field's settings, and a JSONC view of the same draft.
 * Every builder action goes through `reduce` and then `toFileText` on the
 * current text; a valid edit in the JSONC view is parsed back into the
 * group. Save posts exactly that text with the hash the file was loaded with.
 */
export function Builder({ group: groupKey, onBack, onCreated }: BuilderProps) {
	const [detail, setDetail] = useState<FieldGroupDetail | null>(null);
	const [draft, setDraftState] = useState<Draft | null>(null);
	const [error, setError] = useState<unknown>(null);
	const [selected, setSelected] = useState<string | null>(null);
	const [adding, setAdding] = useState<{ parent: FieldPath | null; layout?: number; label: string } | null>(null);
	const [problems, setProblems] = useState<Problem[]>([]);
	const [saving, setSaving] = useState(false);
	const [conflict, setConflict] = useState(false);
	const [guardrails, setGuardrails] = useState<Guardrail[] | null>(null);
	const [view, setView] = useState<'builder' | 'jsonc'>('builder');
	const [leaving, setLeaving] = useState(false);
	const draftRef = useRef<Draft | null>(draft);
	const focusRef = useRef<string | null>(null);

	const setDraft = (next: Draft) => {
		draftRef.current = next;
		setDraftState(next);
	};

	const load = useCallback(async () => {
		if (groupKey === null) {
			return;
		}
		try {
			const result = await adminGet<FieldGroupDetail>(`admin/fields/${encodeURIComponent(groupKey)}`);
			setDetail(result);
			const next = draftFrom(result);
			draftRef.current = next;
			setDraftState(next);
			setError(null);
			setProblems([]);
		} catch (e) {
			setError(e);
		}
	}, [groupKey]);

	useEffect(() => {
		void load();
	}, [load]);

	// Moves focus where the last action asked (a new field's label, the first invalid setting).
	useEffect(() => {
		if (focusRef.current) {
			const el = document.getElementById(focusRef.current);
			if (el) {
				el.focus();
				if (el instanceof HTMLInputElement) {
					el.select();
				}
				focusRef.current = null;
			}
		}
	});

	const dirty = draft !== null && draft.text !== draft.saved;

	// Leaving the page with unsaved changes asks first.
	useEffect(() => {
		if (!dirty) {
			return;
		}
		const onUnload = (event: BeforeUnloadEvent) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener('beforeunload', onUnload);
		return () => window.removeEventListener('beforeunload', onUnload);
	}, [dirty]);

	const writeReason = getWriteReason();
	const file = detail?.file ?? null;
	const acf = detail !== null && isAcfGroup(detail);
	const readOnly = writeReason !== null || !file || !file.writable;

	const dispatch = useCallback(
		(action: BuilderAction): RawGroup | null => {
			const current = draftRef.current;
			if (!current || readOnly) {
				return null;
			}
			const { group, notices } = reduceWithNotices(current.group, action);
			if (group === current.group) {
				return null;
			}
			setDraft({ ...current, group, text: toFileText(current.text, group, current.pointer) });
			for (const notice of notices) {
				toast(
					sprintf(
						/* translators: %s: field label */
						__('Removed the rules on "%s" that read the deleted field.', 'proto-blocks'),
						notice.label
					)
				);
			}
			return group;
		},
		[readOnly]
	);

	const selectedPath = draft ? pathOf(draft.group, selected) : null;
	const selectedField = draft && selectedPath ? fieldAt(draft.group, selectedPath) : null;
	const siblings = useMemo(() => {
		if (!draft || !selectedPath) {
			return [];
		}
		const index = selectedPath[selectedPath.length - 1];
		return siblingsAt(draft.group, selectedPath).filter((_, i) => i !== index);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [draft?.group, selected]);
	const syntaxErrors = useMemo(() => (draft ? jsoncErrors(draft.text, draft.pointer) : []), [draft]);

	const back = (
		<Button variant="outline" onClick={() => (dirty ? setLeaving(true) : onBack())}>
			<ArrowLeftIcon aria-hidden="true" />
			{__('Back to field groups', 'proto-blocks')}
		</Button>
	);

	if (groupKey === null) {
		return (
			<div className="grid gap-6">
				<PageHeader title={__('Add field group', 'proto-blocks')} actions={back} />
				<NewGroupDialog onCancel={onBack} onCreated={(key) => onCreated?.(key)} />
			</div>
		);
	}

	if (error) {
		return (
			<div className="grid gap-6">
				<PageHeader title={groupKey} actions={back} />
				<ScreenError screen={__('Fields', 'proto-blocks')} error={error} onRetry={load} />
			</div>
		);
	}

	if (!draft) {
		return (
			<div className="grid gap-6" aria-busy="true">
				<PageHeader title={groupKey} actions={back} />
				<div className="grid gap-2">
					{[0, 1, 2, 3, 4].map((i) => (
						<Skeleton key={i} className="h-10 w-full" />
					))}
				</div>
			</div>
		);
	}

	const fields = (Array.isArray(draft.group.fields) ? draft.group.fields : []).filter(isObject);
	// A group inside a type or taxonomy file is shown on that object: its file sets the location.
	const owner = file && file.kind !== 'field-group' ? (file.kind === 'type' ? detail?.postTypes : detail?.taxonomies)?.find((c) => c.slug === file.slug) : undefined;
	const implied = file && file.kind !== 'field-group' ? (owner?.label ?? file.slug) : undefined;
	const title = typeof draft.group.title === 'string' ? draft.group.title : '';
	const invalidGroup = validateGroup(draft.group).length > 0;

	const showProblems = (list: Problem[]) => {
		setProblems(list);
		const first = list.find((p) => p.key);
		if (first?.key) {
			setView('builder');
			setSelected(first.key);
			focusRef.current = first.setting === 'name' || first.setting === 'label' ? settingId(first.key, first.setting) : `pb-row-${first.key}`;
		}
	};

	const keyAt = (path: FieldPath): string | undefined => {
		const field = fieldAt(draft.group, path);
		return field ? rowId(field, path) : undefined;
	};

	const save = async (acknowledge: string[] = []) => {
		const current = draftRef.current;
		if (!current || !file || readOnly || jsoncErrors(current.text, current.pointer).length > 0) {
			return;
		}
		const invalid = validateGroup(current.group);
		if (invalid.length > 0) {
			showProblems(invalid.map((e) => ({ message: e.message, key: keyAt(e.path), setting: e.setting })));
			return;
		}
		setSaving(true);
		try {
			const result = await putSchemaFile(file.kind, file.slug, current.text, current.hash, acknowledge);
			// Edits made while the request was in flight stay; only the hash and saved state change.
			const latest = draftRef.current ?? current;
			setDraft({ ...latest, hash: result.hash, savedNames: namesByKey(current.group), saved: current.text });
			setProblems([]);
			toast.success(sprintf(/* translators: %s: file path */ __('Field group saved to %s.', 'proto-blocks'), result.path));
		} catch (e) {
			const apiError = e instanceof ApiError ? e : new ApiError('unknown_error', String(e), 0);
			if (apiError.code === 'proto_blocks_conflict') {
				setConflict(true);
			} else if (apiError.code === 'proto_blocks_needs_confirmation') {
				setGuardrails(Array.isArray(apiError.data.guardrails) ? (apiError.data.guardrails as Guardrail[]) : []);
			} else {
				const errors = Array.isArray(apiError.data.errors) ? (apiError.data.errors as { path?: unknown; message?: unknown }[]) : [];
				showProblems(
					errors.length > 0
						? errors.map((err) => {
								const path = typeof err.path === 'string' ? err.path : undefined;
								const at = path !== undefined ? parseErrorPath(path, current.pointer) : null;
								return { message: String(err.message ?? ''), path, key: at ? keyAt(at.path) : undefined, setting: at?.setting };
							})
						: [{ message: apiError.message }]
				);
			}
		} finally {
			setSaving(false);
		}
	};

	/** A JSONC edit: kept as typed; valid text also becomes the builder's group. */
	const editText = (text: string) => {
		const current = draftRef.current;
		if (!current || readOnly) {
			return;
		}
		const at = jsoncErrors(text, current.pointer).length === 0 ? valueAt(text, current.pointer) : undefined;
		setDraft({ ...current, text, group: isObject(at) ? at : current.group });
	};

	const addField = (type: string) => {
		const target = adding;
		setAdding(null);
		const current = draftRef.current;
		if (!target || !current) {
			return;
		}
		const before = new Set<string>();
		walkFields(current.group.fields, (f) => typeof f.key === 'string' && before.add(f.key));
		const group = dispatch({ type: 'add', parent: target.parent, layout: target.layout, fieldType: type });
		if (group) {
			walkFields(group.fields, (f) => {
				if (typeof f.key === 'string' && !before.has(f.key)) {
					setSelected(f.key);
					focusRef.current = settingId(f.key, 'label');
				}
			});
		}
	};

	const openAdd = (parent: FieldPath | null, layout?: number) => {
		let label: string = __('Top level', 'proto-blocks');
		if (parent) {
			const owner = fieldAt(draft.group, parent);
			label = String(owner?.label || owner?.name || '');
		}
		setAdding({ parent, layout, label });
	};

	const selectedLabel = selectedField ? String(selectedField.label || selectedField.name || '') : '';
	const blockedSave = readOnly || saving || syntaxErrors.length > 0;

	return (
		<div className="grid gap-6">
			<PageHeader
				title={title || __('Untitled field group', 'proto-blocks')}
				description={file ? <span className="font-mono">{file.path}</span> : undefined}
				actions={
					<>
						{back}
						<div role="group" aria-label={__('View', 'proto-blocks')} className="inline-flex rounded-md border p-0.5">
							<Button
								size="sm"
								variant={view === 'builder' ? 'secondary' : 'ghost'}
								aria-pressed={view === 'builder'}
								disabled={syntaxErrors.length > 0}
								title={syntaxErrors.length > 0 ? __('Fix the file text first.', 'proto-blocks') : undefined}
								onClick={() => setView('builder')}
							>
								<ListTreeIcon aria-hidden="true" />
								{__('Builder', 'proto-blocks')}
							</Button>
							<Button size="sm" variant={view === 'jsonc' ? 'secondary' : 'ghost'} aria-pressed={view === 'jsonc'} onClick={() => setView('jsonc')}>
								<BracesIcon aria-hidden="true" />
								{__('JSONC', 'proto-blocks')}
							</Button>
						</div>
						{readOnly ? null : (
							<Button variant="ghost" disabled={!dirty || saving} onClick={() => {
									setSelected(null);
									setProblems([]);
									setDraft(discarded(draft));
								}}>
								{__('Discard changes', 'proto-blocks')}
							</Button>
						)}
						<Button onClick={() => void save()} disabled={blockedSave} aria-disabled={invalidGroup || undefined}>
							<SaveIcon aria-hidden="true" />
							{saving ? __('Saving…', 'proto-blocks') : __('Save to file', 'proto-blocks')}
						</Button>
					</>
				}
			/>

			{writeReason !== null ? (
				<ReadOnlyBanner reason={writeReason} />
			) : acf ? (
				<Alert>
					<AlertTitle>{__('This group is registered by Advanced Custom Fields.', 'proto-blocks')}</AlertTitle>
					<AlertDescription>{readOnlyText('acf')}</AlertDescription>
				</Alert>
			) : readOnly ? (
				<Alert>
					<AlertTitle>{__('This group is read-only here.', 'proto-blocks')}</AlertTitle>
					<AlertDescription>{readOnlyText(detail?.readOnlyReason ?? (file ? 'not_writable' : null))}</AlertDescription>
				</Alert>
			) : null}

			{problems.length > 0 ? (
				<Alert variant="destructive" role="alert">
					<AlertTitle className="flex items-center justify-between gap-2">
						{__('The group was not saved.', 'proto-blocks')}
						<Button variant="ghost" size="icon-sm" aria-label={__('Dismiss', 'proto-blocks')} onClick={() => setProblems([])}>
							<XIcon aria-hidden="true" />
						</Button>
					</AlertTitle>
					<AlertDescription>
						<ul className="list-disc pl-4">
							{problems.map((p, i) => (
								<li key={i}>
									{p.path ? <code className="mr-1 font-mono">{p.path}</code> : null}
									{p.message}
								</li>
							))}
						</ul>
					</AlertDescription>
				</Alert>
			) : null}

			{view === 'jsonc' ? (
				<Card className="px-6 py-5 shadow-xs">
					<JsoncPane
						text={draft.text}
						pointer={draft.pointer}
						kind={file?.kind ?? 'field-group'}
						index={file?.index ?? null}
						readOnly={readOnly}
						onChange={editText}
					/>
				</Card>
			) : (
				<>
					<Card className="grid gap-4 px-6 py-5 shadow-xs">
						<h2 className="text-sm font-semibold">{__('Group settings', 'proto-blocks')}</h2>
						<div className="grid gap-6 lg:grid-cols-2">
							<GroupSettings group={draft.group} dispatch={dispatch} readOnly={readOnly} />
							<section className="grid content-start gap-2" aria-labelledby="pb-group-location">
								<h3 id="pb-group-location" className="text-sm font-medium">
									{__('Location', 'proto-blocks')}
								</h3>
								<LocationBuilder
									value={readLocation(draft.group.location)}
									choices={detail ?? {}}
									implied={implied}
									readOnly={readOnly}
									onChange={(location) => dispatch({ type: 'group', patch: { location } })}
								/>
							</section>
						</div>
					</Card>

					<div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_440px]">
						<Card className="grid gap-3 px-4 py-4 shadow-xs">
							<h2 className="text-sm font-semibold">{__('Fields', 'proto-blocks')}</h2>
							{fields.length === 0 ? <p className="text-sm text-muted-foreground">{__('No fields yet.', 'proto-blocks')}</p> : null}
							<FieldTree fields={fields} selectedKey={selected} onSelect={(_, key) => setSelected(key)} dispatch={dispatch} onAddField={openAdd} readOnly={readOnly} />
							<Button variant="outline" className="justify-self-start" disabled={readOnly} onClick={() => openAdd(null)}>
								<PlusIcon aria-hidden="true" />
								{__('Add field', 'proto-blocks')}
							</Button>
						</Card>
						<aside
							aria-label={
								selectedField ? sprintf(/* translators: %s: field label */ __('Field settings: %s', 'proto-blocks'), selectedLabel) : __('Field settings', 'proto-blocks')
							}
							className="xl:sticky xl:top-8"
						>
							<Card className="px-5 py-5 shadow-xs">
								{selectedField && selected ? (
									<FieldSettings
										key={selected}
										field={selectedField}
										siblings={siblings as RawField[]}
										savedName={draft.savedNames.get(selected)}
										choices={{ postTypes: detail?.postTypes, taxonomies: detail?.taxonomies }}
										readOnly={readOnly}
										idPrefix={`pb-field-${selected}`}
										onChange={(patch) => {
											const path = draftRef.current ? pathOf(draftRef.current.group, selected) : null;
											if (path) {
												dispatch({ type: 'update', path, patch });
											}
										}}
										onLayout={(action) => {
											const path = draftRef.current ? pathOf(draftRef.current.group, selected) : null;
											if (path) {
												dispatch({ ...action, path });
											}
										}}
									/>
								) : (
									<p className="text-sm text-muted-foreground">{__('Select a field to edit its settings.', 'proto-blocks')}</p>
								)}
							</Card>
						</aside>
					</div>

					<Card className="grid gap-2 px-6 py-5 shadow-xs">
						<h2 id="pb-jsonc-preview" className="text-sm font-semibold">
							{__('File preview', 'proto-blocks')}
						</h2>
						<pre aria-label={__('JSONC preview', 'proto-blocks')} className="max-h-[480px] overflow-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre">
							{draft.text}
						</pre>
					</Card>
				</>
			)}

			<AddFieldMenu
				open={adding !== null}
				onOpenChange={(open) => !open && setAdding(null)}
				onPick={addField}
				target={adding ? sprintf(/* translators: %s: where the field is added */ __('Adds to: %s', 'proto-blocks'), adding.label) : undefined}
			/>

			{conflict && file ? (
				<ConflictDialog
					path={file.path}
					onCancel={() => setConflict(false)}
					onReload={() => {
						setConflict(false);
						void load();
					}}
				/>
			) : null}

			{guardrails ? (
				<GuardrailDialog
					guardrails={guardrails}
					onCancel={() => setGuardrails(null)}
					onConfirm={(acknowledge) => {
						setGuardrails(null);
						void save(acknowledge);
					}}
				/>
			) : null}

			<AlertDialog open={leaving} onOpenChange={setLeaving}>
				<AlertDialogContent>
					<AlertDialogHeader>
						<AlertDialogTitle>{__('Leave without saving?', 'proto-blocks')}</AlertDialogTitle>
						<AlertDialogDescription>{__('Your changes to this field group are not saved to the file yet.', 'proto-blocks')}</AlertDialogDescription>
					</AlertDialogHeader>
					<AlertDialogFooter>
						<AlertDialogCancel>{__('Stay', 'proto-blocks')}</AlertDialogCancel>
						<AlertDialogAction onClick={onBack}>{__('Leave', 'proto-blocks')}</AlertDialogAction>
					</AlertDialogFooter>
				</AlertDialogContent>
			</AlertDialog>
		</div>
	);
}
