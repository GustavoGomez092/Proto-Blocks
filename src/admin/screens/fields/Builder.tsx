import { useCallback, useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowLeftIcon, InfoIcon, PlusIcon, XIcon } from 'lucide-react';
import { toast } from 'sonner';
import { adminGet, ApiError } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import { ConflictDialog } from '@/admin/components/content-model/ConflictDialog';
import { GuardrailDialog } from '@/admin/components/content-model/GuardrailDialog';
import { LogoMark, PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Dot } from '@/admin/components/shell/Signals';
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
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/admin/components/ui/dialog';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Skeleton } from '@/admin/components/ui/skeleton';
import type { FileResult, Guardrail, ListResult } from '@/admin/content-model/types';
import { interpolate } from '@/admin/lib/interpolate';
import { cn } from '@/admin/lib/utils';
import { AddFieldMenu } from './AddFieldMenu';
import {
	canonicalType,
	fieldAt,
	groupPointer,
	layoutList,
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
import { DeleteGroupDialog } from './GroupList';
import { GroupSettings } from './GroupSettings';
import { JsoncPane, jsoncErrors } from './JsoncPane';
import { setAtPath } from './jsonc-edit';
import { LocationBuilder, readLocation } from './LocationBuilder';
import { BTN_DANGER, BTN_GHOST, BTN_PRIMARY, BTN_SECONDARY, CODE, EYEBROW } from './pb-ui';
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

/** A group pointer as written in error paths: `[0]`, `fields[1]`, `/` for the whole file. */
function pointerLabel(pointer: (string | number)[]): string {
	return pointer.map((p, i) => (typeof p === 'number' ? `[${p}]` : i === 0 ? p : `.${p}`)).join('') || '/';
}

/** A line comment cannot hold a line break: one would let the title write JSON. */
function commentSafe(text: string): string {
	return text.replace(/[\r\n\u2028\u2029]+/g, ' ');
}

/** Card frame of the editor's sections. */
const CARD = 'overflow-hidden rounded-xl border border-solid border-border bg-card';

type GroupPane = 'location' | 'presentation' | 'file';

const subFieldsOf = (owner: RawField | null | undefined): RawField[] => (owner && Array.isArray(owner.sub_fields) ? owner.sub_fields : []).filter(isObject);
const listOfLayout = (owner: RawField | null | undefined, layout: number): RawField[] => (owner ? subFieldsOf(layoutList(owner)[layout]) : []);

/** The parent field path of `path` (dropping a trailing layout address), or null at the top level. */
function parentOf(path: FieldPath): FieldPath | null {
	if (path.length < 2) {
		return null;
	}
	const parent = path.slice(0, -1);
	return parent[parent.length - 2] === 'layouts' ? parent.slice(0, -2) : parent;
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
								aria-invalid={slugError || serverError ? true : undefined}
								aria-describedby={[slugError ? `${slugId}-error` : '', serverError ? `${slugId}-server` : ''].filter(Boolean).join(' ') || undefined}
								onChange={(e) => {
									setSlugText(e.target.value);
									setServerError(null);
								}}
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
						<p id={`${slugId}-server`} role="alert" className="text-[13px] text-destructive">
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
	const [adding, setAdding] = useState<{ parent: FieldPath | null; layout?: number; at?: number; label: ReactNode } | null>(null);
	const [pane, setPane] = useState<GroupPane>('location');
	const [deleting, setDeleting] = useState(false);
	const saveRef = useRef<(() => void) | null>(null);
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

	// Cmd/Ctrl+S saves, when Save is enabled.
	useEffect(() => {
		const onKey = (event: KeyboardEvent) => {
			if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 's') {
				event.preventDefault();
				saveRef.current?.();
			}
		};
		window.addEventListener('keydown', onKey);
		return () => window.removeEventListener('keydown', onKey);
	}, []);

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
	const groupMissing = draft !== null && syntaxErrors.length === 0 && !isObject(valueAt(draft.text, draft.pointer));
	const textBlocked = syntaxErrors.length > 0 || groupMissing;

	const backButton = (
		<Button variant="outline" onClick={() => (dirty ? setLeaving(true) : onBack())}>
			<ArrowLeftIcon aria-hidden="true" />
			{__('Back to field groups', 'proto-blocks')}
		</Button>
	);

	if (groupKey === null) {
		return (
			<div className="grid gap-6">
				<PageHeader title={__('Add field group', 'proto-blocks')} actions={backButton} />
				<NewGroupDialog onCancel={onBack} onCreated={(key) => onCreated?.(key)} />
			</div>
		);
	}

	if (error) {
		return (
			<div className="grid gap-6">
				<PageHeader title={groupKey} actions={backButton} />
				<ScreenError screen={__('Fields', 'proto-blocks')} error={error} onRetry={load} />
			</div>
		);
	}

	if (!draft) {
		return (
			<div className="grid gap-6" aria-busy="true">
				<PageHeader title={groupKey} actions={backButton} />
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
	const counts = { fields: 0, tabs: 0, repeaters: 0 };
	walkFields(draft.group.fields, (f) => {
		const type = canonicalType(f.type);
		if (type === 'tab') {
			counts.tabs++;
		} else {
			counts.fields++;
		}
		if (type === 'repeater') {
			counts.repeaters++;
		}
	});
	const fieldCount = sprintf(/* translators: %d: number of fields */ _n('%d field', '%d fields', counts.fields, 'proto-blocks'), counts.fields);

	const showProblems = (list: Problem[]) => {
		setProblems(list);
		const first = list.find((p) => p.key);
		// In the JSONC view the list is the answer; the builder selection waits for the user.
		if (first?.key && view === 'builder') {
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
		if (!current || !file || readOnly || jsoncErrors(current.text, current.pointer).length > 0 || !isObject(valueAt(current.text, current.pointer))) {
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
		// No group at the pointer (or no parse): the builder holds nothing rather than a stale copy.
		setDraft({ ...current, text, group: isObject(at) ? at : {} });
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
		const group = dispatch({ type: 'add', parent: target.parent, layout: target.layout, at: target.at, fieldType: type });
		if (group) {
			walkFields(group.fields, (f) => {
				if (typeof f.key === 'string' && !before.has(f.key)) {
					setSelected(f.key);
					focusRef.current = settingId(f.key, 'label');
				}
			});
		}
	};

	/** The picker's footer: "Adds after <field> in <tab or parent>". */
	const describeTarget = (list: RawField[], at: number, parent: FieldPath | null): ReactNode => {
		const strong = (text: string) => <strong className="font-medium text-foreground">{text}</strong>;
		const prev = list[at - 1];
		const tab = list
			.slice(0, at)
			.reverse()
			.find((f) => canonicalType(f.type) === 'tab');
		const ownerField = parent ? fieldAt(draft.group, parent) : undefined;
		const within = tab ? String(tab.label || tab.name || '') : ownerField ? String(ownerField.label || ownerField.name || '') : '';
		if (prev && within !== '' && prev !== tab) {
			/* translators: 1: field label, 2: tab or parent field label */
			return interpolate(__('Adds after %1$s in %2$s', 'proto-blocks'), strong(String(prev.label || prev.name || '')), strong(within));
		}
		if (prev) {
			/* translators: %s: field label */
			return interpolate(__('Adds after %s', 'proto-blocks'), strong(String(prev.label || prev.name || '')));
		}
		/* translators: %s: parent field label */
		return within !== '' ? interpolate(__('Adds to %s', 'proto-blocks'), strong(within)) : __('Adds the first field', 'proto-blocks');
	};

	/** "Add field" inside a container (FieldTree): appended to that list. */
	const openAdd = (parent: FieldPath | null, layout?: number) => {
		const list = parent === null ? fields : layout !== undefined ? listOfLayout(fieldAt(draft.group, parent), layout) : subFieldsOf(fieldAt(draft.group, parent));
		setAdding({ parent, layout, label: describeTarget(list, list.length, parent) });
	};

	/** "Add field" in the card header or footer: after the open field at its level, else at the end. */
	const openAddHere = () => {
		if (selectedPath) {
			const index = selectedPath[selectedPath.length - 1] as number;
			const parent = parentOf(selectedPath);
			const layout = selectedPath.length >= 3 && selectedPath[selectedPath.length - 3] === 'layouts' ? (selectedPath[selectedPath.length - 2] as number) : undefined;
			const list = siblingsAt(draft.group, selectedPath).filter(isObject);
			setAdding({ parent, layout, at: index + 1, label: describeTarget(list, index + 1, parent) });
			return;
		}
		openAdd(null);
	};
	saveRef.current = readOnly || saving || textBlocked ? null : () => void save();

	const blockedSave = readOnly || saving || textBlocked;
	const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
	const location = readLocation(draft.group.location);
	const ruleCount = location.reduce((n, branch) => n + branch.length, 0);
	const single = implied === undefined && location.length === 1 && location[0].length === 1 && location[0][0].param === 'post_type' && location[0][0].operator === '==' ? location[0][0] : null;
	const singleLabel = single ? (detail?.postTypes.find((c) => c.slug === single.value)?.label ?? single.value) : '';
	const format =
		file === null
			? __('Not stored in a file', 'proto-blocks')
			: typeof draft.pointer[0] === 'number'
				? __('ACF export (list root) — other groups in this file are kept on save', 'proto-blocks')
				: file.kind === 'type'
					? __('Inside a post type file — the rest of the file is kept on save', 'proto-blocks')
					: file.kind === 'taxonomy'
						? __('Inside a taxonomy file — the rest of the file is kept on save', 'proto-blocks')
						: __('Proto-Blocks group file (JSONC) — comments are kept on save', 'proto-blocks');
	const origin = file ? (
		<Dot tone="brand" className="text-caption">
			{__('Proto-Blocks file', 'proto-blocks')}
		</Dot>
	) : acf ? (
		<Dot tone="warning" className="text-caption">
			{__('Plugin: Advanced Custom Fields', 'proto-blocks')}
		</Dot>
	) : (
		<Dot tone="primary" className="text-caption">
			{__('Proto-Blocks PHP', 'proto-blocks')}
		</Dot>
	);
	const GS: { value: GroupPane; label: string; count?: number }[] = [
		{ value: 'location', label: __('Location rules', 'proto-blocks'), count: implied === undefined ? ruleCount : undefined },
		{ value: 'presentation', label: __('Presentation', 'proto-blocks') },
		{ value: 'file', label: __('File & key', 'proto-blocks') },
	];
	const segment = (on: boolean) =>
		cn(
			'h-7 cursor-pointer rounded-sm border-0 px-3 text-label font-medium whitespace-nowrap outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50',
			on ? 'bg-primary text-primary-foreground' : 'bg-transparent text-muted-foreground hover:text-foreground'
		);

	return (
		<div className="grid gap-6 font-sans text-body text-foreground">
			<header className="flex flex-wrap items-end justify-between gap-6 border-0 border-b border-solid border-border pt-6 pb-5">
				<div className="flex min-w-0 items-center gap-4">
					<LogoMark size={44} />
					<div className="grid min-w-0 gap-1">
						<button
							type="button"
							aria-label={__('Back to field groups', 'proto-blocks')}
							className={cn(EYEBROW, 'inline-flex w-fit cursor-pointer items-center gap-1.5 rounded-sm border-0 bg-transparent p-0 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring')}
							onClick={() => (dirty ? setLeaving(true) : onBack())}
						>
							<ArrowLeftIcon aria-hidden="true" className="size-3" strokeWidth={2.5} />
							{__('Field groups', 'proto-blocks')}
						</button>
						<h1 className="sr-only">{title || __('Untitled field group', 'proto-blocks')}</h1>
						<input
							type="text"
							aria-label={__('Group title', 'proto-blocks')}
							value={title}
							placeholder={__('Untitled field group', 'proto-blocks')}
							readOnly={readOnly}
							onChange={(e) => dispatch({ type: 'group', patch: { title: e.target.value } })}
							className="m-0 h-auto min-h-0 w-full max-w-[420px] min-w-0 rounded-none border-0 border-b border-dashed border-transparent bg-transparent p-0 text-title font-semibold tracking-[-.015em] text-foreground shadow-none outline-none placeholder:text-faint hover:border-input focus:border-solid focus:border-primary focus:shadow-none read-only:hover:border-transparent"
						/>
						<div className="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-caption text-muted-foreground">
							{file ? (
								<>
									<code className="font-mono text-caption break-all text-link">{file.path}</code>
									<span aria-hidden="true">·</span>
								</>
							) : null}
							{origin}
							<span aria-hidden="true">·</span>
							<span>{fieldCount}</span>
						</div>
					</div>
				</div>
				<div className="flex flex-none flex-wrap items-center gap-2">
					<div role="group" aria-label={__('View', 'proto-blocks')} className="inline-flex rounded-md border border-solid border-input bg-background p-[3px]">
						<button
							type="button"
							className={segment(view === 'builder')}
							aria-pressed={view === 'builder'}
							disabled={textBlocked}
							title={textBlocked ? __('Fix the file text first.', 'proto-blocks') : undefined}
							onClick={() => setView('builder')}
						>
							{__('Builder', 'proto-blocks')}
						</button>
						<button type="button" className={cn(segment(view === 'jsonc'), 'font-mono')} aria-pressed={view === 'jsonc'} onClick={() => setView('jsonc')}>
							<span aria-hidden="true">{'{ } '}</span>
							{__('JSONC', 'proto-blocks')}
						</button>
					</div>
					{readOnly ? null : (
						<button
							type="button"
							className={BTN_GHOST}
							aria-label={__('Discard changes', 'proto-blocks')}
							disabled={!dirty || saving}
							onClick={() => {
								setSelected(null);
								setProblems([]);
								setDraft(discarded(draft));
							}}
						>
							{__('Discard', 'proto-blocks')}
						</button>
					)}
					<button
						type="button"
						className={cn(BTN_PRIMARY, 'gap-2')}
						onClick={() => void save()}
						disabled={blockedSave}
						aria-disabled={invalidGroup || undefined}
						aria-keyshortcuts={isMac ? 'Meta+S' : 'Control+S'}
					>
						{saving ? __('Saving…', 'proto-blocks') : __('Save to file', 'proto-blocks')}
						<span aria-hidden="true" className="font-mono text-eyebrow text-[#9fb6d3]">
							{isMac ? '⌘S' : 'Ctrl+S'}
						</span>
					</button>
				</div>
			</header>

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
				<section aria-label={__('JSONC', 'proto-blocks')} className={cn(CARD, 'grid gap-3 px-6 py-5')}>
					{groupMissing ? (
						<p role="status" className="text-label text-destructive">
							{sprintf(/* translators: %s: where the group should be in the file, e.g. "[0]" or "fields[1]" */ __('Group not found at %s', 'proto-blocks'), pointerLabel(draft.pointer))}
						</p>
					) : null}
					<JsoncPane text={draft.text} pointer={draft.pointer} kind={file?.kind ?? 'field-group'} index={file?.index ?? null} readOnly={readOnly} onChange={editText} />
				</section>
			) : (
				<>
					<section aria-labelledby="pb-fields-heading" className={CARD}>
						<div className="flex flex-wrap items-center justify-between gap-4 border-0 border-b border-solid border-border px-5 py-3.5">
							<div className="flex min-w-0 flex-wrap items-baseline gap-x-2.5 gap-y-1">
								<h2 id="pb-fields-heading" className="text-section font-semibold">
									{__('Fields', 'proto-blocks')}
								</h2>
								<span className="text-label text-muted-foreground">
									{readOnly
										? __('Click a row to see its settings.', 'proto-blocks')
										: __('Click a row to edit it. Drag the handle, or focus it and press Space + arrows, to reorder.', 'proto-blocks')}
								</span>
							</div>
							<button type="button" className={BTN_SECONDARY} disabled={readOnly} onClick={openAddHere}>
								<PlusIcon aria-hidden="true" className="size-3.5" strokeWidth={2.5} />
								{__('Add field', 'proto-blocks')}
							</button>
						</div>
						{fields.length === 0 ? (
							<p className="border-0 border-b border-solid border-divider px-5 py-6 text-label text-muted-foreground">{__('No fields yet.', 'proto-blocks')}</p>
						) : (
							<FieldTree
								fields={fields}
								selectedKey={selected}
								onSelect={(_, key) => setSelected((current) => (current === key ? null : key))}
								dispatch={dispatch}
								onAddField={openAdd}
								readOnly={readOnly}
								renderPanel={(field, _path, key) => (
									<>
										<FieldSettings
											key={key}
											field={field}
											siblings={siblings as RawField[]}
											savedName={draft.savedNames.get(key)}
											choices={{ postTypes: detail?.postTypes, taxonomies: detail?.taxonomies }}
											readOnly={readOnly}
											idPrefix={`pb-field-${key}`}
											onChange={(patch) => {
												const path = draftRef.current ? pathOf(draftRef.current.group, key) : null;
												if (path) {
													dispatch({ type: 'update', path, patch });
												}
											}}
											onLayout={(action) => {
												const path = draftRef.current ? pathOf(draftRef.current.group, key) : null;
												if (path) {
													dispatch({ ...action, path });
												}
											}}
										/>
										<div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-0 border-t border-solid border-border pt-3.5">
											<span className="text-caption text-muted-foreground">{__('Changes apply to the draft; nothing is written until you save.', 'proto-blocks')}</span>
											<button
												type="button"
												className={BTN_SECONDARY}
												onClick={() => {
													setSelected(null);
													focusRef.current = `pb-row-${key}`;
												}}
											>
												{__('Close field', 'proto-blocks')}
											</button>
										</div>
									</>
								)}
							/>
						)}
						<div className="flex flex-wrap items-center justify-between gap-3 bg-subtle px-5 py-3.5">
							<span className="text-caption text-muted-foreground">
								{[
									fieldCount,
									sprintf(/* translators: %d: number of tabs */ _n('%d tab', '%d tabs', counts.tabs, 'proto-blocks'), counts.tabs),
									sprintf(/* translators: %d: number of repeaters */ _n('%d repeater', '%d repeaters', counts.repeaters, 'proto-blocks'), counts.repeaters),
								].join(' · ')}
							</span>
							<button type="button" className={cn(BTN_PRIMARY, 'pl-3')} disabled={readOnly} onClick={openAddHere}>
								<PlusIcon aria-hidden="true" className="size-4" />
								{__('Add field', 'proto-blocks')}
							</button>
						</div>
					</section>

					<section aria-labelledby="pb-group-settings" className={cn(CARD, 'grid md:grid-cols-[220px_minmax(0,1fr)]')}>
						<nav aria-labelledby="pb-group-settings" className="grid content-start gap-0.5 border-0 border-b border-solid border-border bg-subtle px-3 py-4 md:border-r md:border-b-0">
							<h2 id="pb-group-settings" className={cn(EYEBROW, 'px-2.5 pt-1 pb-2.5')}>
								{__('Group settings', 'proto-blocks')}
							</h2>
							{GS.map((item) => {
								const on = pane === item.value;
								return (
									<button
										key={item.value}
										type="button"
										aria-current={on ? 'true' : undefined}
										onClick={() => setPane(item.value)}
										className={cn(
											'flex h-9 cursor-pointer items-center justify-between rounded-md border-0 px-2.5 text-left text-body font-medium outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
											on ? 'bg-primary-soft text-primary' : 'bg-transparent text-foreground hover:bg-code'
										)}
									>
										{item.label}
										{item.count !== undefined ? <span className="text-micro text-muted-foreground">{item.count}</span> : null}
									</button>
								);
							})}
						</nav>
						<div className="min-w-0 p-6">
							{pane === 'location' ? (
								<div className="grid max-w-[760px] gap-3.5">
									<div className="grid gap-0.5">
										<h3 className="text-section font-semibold">{__('Show this field group when…', 'proto-blocks')}</h3>
										<p className="text-label text-muted-foreground">
											{interpolate(
												/* translators: 1: the word "and", 2: the word "or" (both emphasised) */
												__('Rules in a group are joined with %1$s; add another group for %2$s.', 'proto-blocks'),
												<em>{__('and', 'proto-blocks')}</em>,
												<em>{__('or', 'proto-blocks')}</em>
											)}
										</p>
									</div>
									<LocationBuilder
										value={location}
										choices={detail ?? {}}
										implied={implied}
										readOnly={readOnly}
										onChange={(next) => dispatch({ type: 'group', patch: { location: next } })}
									/>
									{single ? (
										<p className="flex items-center gap-2.5 rounded-lg bg-code px-3.5 py-2.5 text-label text-link">
											<InfoIcon aria-hidden="true" className="size-3.5 flex-none" />
											<span>
												{interpolate(
													/* translators: 1: post type label, 2: link "open one" */
													__('Currently shows on the %1$s edit screen — %2$s to preview.', 'proto-blocks'),
													<strong className="font-medium">{singleLabel}</strong>,
													<a href={`edit.php?post_type=${encodeURIComponent(single.value)}`} className="text-link underline-offset-2 hover:text-link-hover hover:underline">
														{__('open one', 'proto-blocks')}
													</a>
												)}
											</span>
										</p>
									) : null}
								</div>
							) : pane === 'presentation' ? (
								<GroupSettings group={draft.group} dispatch={dispatch} readOnly={readOnly} />
							) : (
								<div className="grid max-w-[760px] gap-4">
									<dl className="m-0 grid grid-cols-[140px_minmax(0,1fr)] items-center gap-x-4 gap-y-2.5 text-label">
										<dt className="text-muted-foreground">{__('Group key', 'proto-blocks')}</dt>
										<dd className="m-0">
											<code className={CODE}>{String(draft.group.key ?? groupKey)}</code>
										</dd>
										<dt className="text-muted-foreground">{__('File', 'proto-blocks')}</dt>
										<dd className="m-0 min-w-0">{file ? <code className={cn(CODE, 'break-all')}>{file.path}</code> : __('None', 'proto-blocks')}</dd>
										<dt className="text-muted-foreground">{__('Format', 'proto-blocks')}</dt>
										<dd className="m-0">{format}</dd>
									</dl>
									<div className="flex flex-wrap gap-2">
										<button type="button" className={cn(BTN_SECONDARY, 'h-[34px]')} onClick={() => setView('jsonc')}>
											{__('Open JSONC view', 'proto-blocks')}
										</button>
										{file && !readOnly ? (
											<button type="button" className={BTN_DANGER} onClick={() => setDeleting(true)}>
												{__('Delete field group…', 'proto-blocks')}
											</button>
										) : null}
									</div>
								</div>
							)}
						</div>
					</section>
				</>
			)}

			<AddFieldMenu open={adding !== null} onOpenChange={(open) => !open && setAdding(null)} onPick={addField} target={adding?.label} />

			{deleting && file ? (
				<DeleteGroupDialog
					row={{ key: String(draft.group.key ?? groupKey), title, file: { kind: file.kind, slug: file.slug, index: file.index ?? undefined, path: file.path } }}
					onCancel={() => setDeleting(false)}
					onDone={() => {
						setDeleting(false);
						onBack();
					}}
				/>
			) : null}

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
