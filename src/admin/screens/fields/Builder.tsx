import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeftIcon, PlusIcon, SaveIcon } from 'lucide-react';
import { toast } from 'sonner';
import { adminGet, ApiError } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import { ConflictDialog } from '@/admin/components/content-model/ConflictDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import type { FileResult } from '@/admin/content-model/types';
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
import { putSchemaFile, type SchemaKind } from './schema-files';

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
}

interface Problem {
	message: string;
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
		return { text: JSON.stringify(detail.group, null, 2), group: detail.group, pointer: [], hash: null, savedNames: namesByKey(detail.group) };
	}
	const pointer = groupPointer(file);
	const fromText = valueAt(file.text, pointer);
	const group = isObject(fromText) ? fromText : detail.group;
	return { text: file.text, group, pointer, hash: file.hash, savedNames: namesByKey(group) };
}

function newDraft(): Draft {
	const group: RawGroup = { key: newGroupKey(), title: __('New field group', 'proto-blocks'), fields: [], location: [] };
	return { text: toFileText('', group, []), group, pointer: [], hash: null, savedNames: new Map() };
}

const settingId = (key: string, setting: string) => `pb-field-${key}-${setting.replace(/\./g, '-')}`;

export interface BuilderProps {
	/** The group key from `?group=`; null for `?new=1`. */
	group: string | null;
	onBack: () => void;
	/** A new group was written; the screen opens it by key. */
	onCreated?: (key: string) => void;
}

/**
 * The visual field-group builder: the field tree, the selected field's
 * settings and the file as it will be written. Every action goes through
 * `reduce` and then `toFileText` on the current text, so the preview is the
 * exact text Save posts (with the hash the file was loaded with).
 */
export function Builder({ group: groupKey, onBack, onCreated }: BuilderProps) {
	const [detail, setDetail] = useState<FieldGroupDetail | null>(null);
	const [draft, setDraftState] = useState<Draft | null>(() => (groupKey === null ? newDraft() : null));
	const [error, setError] = useState<unknown>(null);
	const [selected, setSelected] = useState<string | null>(null);
	const [adding, setAdding] = useState<{ parent: FieldPath | null; layout?: number; label: string } | null>(null);
	const [problems, setProblems] = useState<Problem[]>([]);
	const [saving, setSaving] = useState(false);
	const [conflict, setConflict] = useState(false);
	const [slug, setSlug] = useState<string | null>(null);
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

	const writeReason = getWriteReason();
	const file = detail?.file ?? null;
	const readOnly = writeReason !== null || (groupKey !== null && (!file || !file.writable));

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

	const back = (
		<Button variant="outline" onClick={onBack}>
			<ArrowLeftIcon aria-hidden="true" />
			{__('Back to field groups', 'proto-blocks')}
		</Button>
	);

	if (error) {
		return (
			<div className="grid gap-6">
				<PageHeader title={groupKey ?? ''} actions={back} />
				<ScreenError screen={__('Fields', 'proto-blocks')} error={error} onRetry={load} />
			</div>
		);
	}

	if (!draft) {
		return (
			<div className="grid gap-6" aria-busy="true">
				<PageHeader title={groupKey ?? ''} actions={back} />
				<div className="grid gap-2">
					{[0, 1, 2, 3, 4].map((i) => (
						<Skeleton key={i} className="h-10 w-full" />
					))}
				</div>
			</div>
		);
	}

	const fields = (Array.isArray(draft.group.fields) ? draft.group.fields : []).filter(isObject);
	const title = typeof draft.group.title === 'string' ? draft.group.title : '';
	const fileSlug = slug ?? (slugifyName(title).replace(/_/g, '-') || 'group');

	const showProblems = (list: Problem[]) => {
		setProblems(list);
		const first = list.find((p) => p.key);
		if (first?.key) {
			setSelected(first.key);
			focusRef.current = first.setting === 'name' || first.setting === 'label' ? settingId(first.key, first.setting) : `pb-row-${first.key}`;
		}
	};

	const keyAt = (path: FieldPath): string | undefined => {
		const field = fieldAt(draft.group, path);
		return field ? rowId(field, path) : undefined;
	};

	const save = async () => {
		const current = draftRef.current;
		if (!current) {
			return;
		}
		const invalid = validateGroup(current.group);
		if (invalid.length > 0) {
			showProblems(invalid.map((e) => ({ message: e.message, key: keyAt(e.path), setting: e.setting })));
			return;
		}
		const target = file ? { kind: file.kind, slug: file.slug } : { kind: 'field-group' as const, slug: fileSlug };
		setSaving(true);
		try {
			const result = await putSchemaFile(target.kind, target.slug, current.text, current.hash);
			setDraft({ ...current, hash: result.hash, savedNames: namesByKey(current.group) });
			setProblems([]);
			toast.success(sprintf(/* translators: %s: file path */ __('Field group saved to %s.', 'proto-blocks'), result.path));
			if (!file && onCreated && typeof current.group.key === 'string') {
				onCreated(current.group.key);
			}
		} catch (e) {
			const apiError = e instanceof ApiError ? e : new ApiError('unknown_error', String(e), 0);
			if (apiError.code === 'proto_blocks_conflict') {
				setConflict(true);
			} else {
				const errors = Array.isArray(apiError.data.errors) ? (apiError.data.errors as { path?: unknown; message?: unknown }[]) : [];
				showProblems(
					errors.length > 0
						? errors.map((err) => {
								const at = typeof err.path === 'string' ? parseErrorPath(err.path, current.pointer) : null;
								return { message: String(err.message ?? ''), key: at ? keyAt(at.path) : undefined, setting: at?.setting };
							})
						: [{ message: apiError.message }]
				);
			}
		} finally {
			setSaving(false);
		}
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

	return (
		<div className="grid gap-6">
			<PageHeader
				title={title || __('Untitled field group', 'proto-blocks')}
				description={file ? <span className="font-mono">{file.path}</span> : <span className="font-mono">{`proto-fields/${fileSlug}/group.jsonc`}</span>}
				actions={
					<>
						{back}
						{readOnly ? null : (
							<Button onClick={() => void save()} disabled={saving}>
								<SaveIcon aria-hidden="true" />
								{saving ? __('Saving…', 'proto-blocks') : __('Save to file', 'proto-blocks')}
							</Button>
						)}
					</>
				}
			/>

			{writeReason !== null ? (
				<ReadOnlyBanner reason={writeReason} />
			) : readOnly ? (
				<Alert>
					<AlertTitle>{__('This group is read-only here.', 'proto-blocks')}</AlertTitle>
					<AlertDescription>{__('It is not stored in a file this screen can write. Edit it where it is declared.', 'proto-blocks')}</AlertDescription>
				</Alert>
			) : null}

			{problems.length > 0 ? (
				<Alert variant="destructive" role="alert">
					<AlertTitle>{__('The group was not saved.', 'proto-blocks')}</AlertTitle>
					<AlertDescription>
						<ul className="list-disc pl-4">
							{problems.map((p, i) => (
								<li key={i}>{p.message}</li>
							))}
						</ul>
					</AlertDescription>
				</Alert>
			) : null}

			<Card className="grid gap-4 px-6 py-5 shadow-xs sm:grid-cols-2">
				<div className="grid gap-1.5">
					<Label htmlFor="pb-group-title">{__('Group title', 'proto-blocks')}</Label>
					<Input id="pb-group-title" value={title} disabled={readOnly} onChange={(e) => dispatch({ type: 'group', patch: { title: e.target.value } })} />
				</div>
				{file === null && groupKey === null ? (
					<div className="grid gap-1.5">
						<Label htmlFor="pb-group-slug">{__('File name', 'proto-blocks')}</Label>
						<Input id="pb-group-slug" className="font-mono" value={fileSlug} onChange={(e) => setSlug(e.target.value)} />
					</div>
				) : null}
			</Card>

			<div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_440px]">
				<Card className="grid gap-3 px-4 py-4 shadow-xs">
					<h2 className="text-sm font-semibold">{__('Fields', 'proto-blocks')}</h2>
					{fields.length === 0 ? <p className="text-sm text-muted-foreground">{__('No fields yet.', 'proto-blocks')}</p> : null}
					<FieldTree fields={fields} selectedKey={selected} onSelect={(_, key) => setSelected(key)} dispatch={dispatch} onAddField={openAdd} readOnly={readOnly} />
					{readOnly ? null : (
						<Button variant="outline" className="justify-self-start" onClick={() => openAdd(null)}>
							<PlusIcon aria-hidden="true" />
							{__('Add field', 'proto-blocks')}
						</Button>
					)}
				</Card>
				<aside
					aria-label={selectedField ? sprintf(/* translators: %s: field label */ __('Field settings: %s', 'proto-blocks'), selectedLabel) : __('Field settings', 'proto-blocks')}
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
		</div>
	);
}
