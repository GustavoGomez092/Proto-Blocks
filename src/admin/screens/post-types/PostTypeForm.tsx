import { useCallback, useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowLeftIcon, BracesIcon, CircleDotIcon, InfoIcon, LockIcon, PlusIcon, SaveIcon, TriangleAlertIcon, XIcon } from 'lucide-react';
import { toast } from 'sonner';
import { useForm, useFormState, useWatch, Controller, type Control, type FieldErrors, type Resolver } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { adminGet, ApiError } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { ConflictDialog } from '@/admin/components/content-model/ConflictDialog';
import { DeclarationShell } from '@/admin/components/content-model/DeclarationShell';
import { DeleteDeclarationDialog } from '@/admin/components/content-model/DeleteDeclarationDialog';
import { GuardrailDialog } from '@/admin/components/content-model/GuardrailDialog';
import { LabelsEditor } from '@/admin/components/content-model/LabelsEditor';
import { MenuIconField, isPrefillableIcon } from '@/admin/components/content-model/MenuIconField';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { Button } from '@/admin/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/admin/components/ui/card';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { interpolate } from '@/admin/lib/interpolate';
import { applyDeclarationEdits, parseJsonc } from '@/admin/content-model/jsonc';
import { typeSchema } from '@/admin/content-model/schema';
import type { FileResult, Guardrail, ListItem } from '@/admin/content-model/types';
import { useDeclarationFile } from '@/admin/content-model/useDeclarationFile';
import { needsOverride } from './PostTypeList';
import { TemplatesCard, type TemplateTarget } from './TemplatesCard';

/** GET admin/types/<slug>. */
export interface TypeDetail {
	item: ListItem;
	/** Resolved register_post_type args, labels included. */
	effective: Record<string, unknown>;
	content: { count: number; taxonomies?: Record<string, number> };
	blockTheme: boolean;
	templates: TemplateTarget[];
}

type Values = Record<string, unknown>;

const isObject = (value: unknown): value is Record<string, unknown> =>
	value !== null && typeof value === 'object' && !Array.isArray(value);

function same(a: unknown, b: unknown): boolean {
	if (a === b) {
		return true;
	}
	if (Array.isArray(a) && Array.isArray(b)) {
		return a.length === b.length && a.every((item, i) => same(item, b[i]));
	}
	if (isObject(a) && isObject(b)) {
		const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
		return [...keys].every((key) => same(a[key], b[key]));
	}
	return false;
}

/** Drops undefined entries (an emptied field), so the schema sees a missing key, not `undefined`. */
function defined(value: unknown): unknown {
	if (Array.isArray(value)) {
		return value.map(defined);
	}
	if (isObject(value)) {
		const out: Values = {};
		for (const [key, entry] of Object.entries(value)) {
			if (entry !== undefined) {
				out[key] = defined(entry);
			}
		}
		return out;
	}
	return value;
}

const zod = zodResolver(typeSchema as never) as unknown as Resolver<Values>;
const resolver: Resolver<Values> = (values, context, options) => zod(defined(values) as Values, context, options);

export const SUPPORTS: { key: string; label: string }[] = [
	{ key: 'title', label: __('Title', 'proto-blocks') },
	{ key: 'editor', label: __('Editor', 'proto-blocks') },
	{ key: 'excerpt', label: __('Excerpt', 'proto-blocks') },
	{ key: 'thumbnail', label: __('Featured image', 'proto-blocks') },
	{ key: 'revisions', label: __('Revisions', 'proto-blocks') },
	{ key: 'custom-fields', label: __('Custom fields', 'proto-blocks') },
	{ key: 'page-attributes', label: __('Page attributes', 'proto-blocks') },
	{ key: 'comments', label: __('Comments', 'proto-blocks') },
	{ key: 'author', label: __('Author', 'proto-blocks') },
];

/** What a Proto-Blocks declaration supports when its file sets no `supports` (PostTypeArgs defaults). */
export const PLUGIN_SUPPORTS = ['title', 'editor', 'thumbnail', 'excerpt', 'revisions'];
/** register_post_type's own default, for types registered elsewhere. */
const WORDPRESS_SUPPORTS = ['title', 'editor'];

/**
 * The registered features: the server sends a list (get_all_post_type_supports
 * keys); a `{feature: true}` map is accepted too. Without either, the
 * defaults of whoever registers the type.
 */
function effectiveSupports(effective: Values, origin: ListItem['origin'] | undefined): string[] {
	const supports = effective.supports;
	if (Array.isArray(supports)) {
		return supports.filter((entry): entry is string => typeof entry === 'string');
	}
	if (isObject(supports)) {
		return Object.keys(supports).filter((key) => Boolean(supports[key]));
	}
	return origin === 'file' || origin === 'php' || origin === undefined ? PLUGIN_SUPPORTS : WORDPRESS_SUPPORTS;
}

/**
 * Form values: the file's data, with the controlled keys the file leaves
 * out filled from the effective settings so the controls show what
 * WordPress does. Only values that then differ are written back.
 */
export function toFormValues(data: Values, effective: Values, origin?: ListItem['origin']): Values {
	const bool = (key: string, fallback: boolean) => {
		if (typeof data[key] === 'boolean') {
			return data[key];
		}
		return typeof effective[key] === 'boolean' ? effective[key] : fallback;
	};
	const effRewrite = isObject(effective.rewrite) ? effective.rewrite : {};
	const fileRewrite = isObject(data.rewrite) ? data.rewrite : {};
	let hasArchive: unknown = data.has_archive;
	if (typeof hasArchive !== 'boolean' && typeof hasArchive !== 'string') {
		hasArchive = typeof effective.has_archive === 'boolean' || typeof effective.has_archive === 'string' ? effective.has_archive : false;
	}
	let supports: unknown = data.supports;
	if (!Array.isArray(supports) && supports !== false) {
		supports = effectiveSupports(effective, origin);
	}
	let menuPosition: unknown = data.menu_position;
	if (typeof menuPosition !== 'number' && typeof effective.menu_position === 'number') {
		menuPosition = effective.menu_position;
	}
	let withFront = true;
	if (typeof fileRewrite.with_front === 'boolean') {
		withFront = fileRewrite.with_front;
	} else if (typeof effRewrite.with_front === 'boolean') {
		withFront = effRewrite.with_front;
	}
	let menuIcon: unknown = data.menu_icon;
	// Only short, typeable icons are prefilled. A registered data: URI or theme
	// path the file did not set is shown as a summary instead (MenuIconField).
	if (typeof menuIcon !== 'string' && typeof effective.menu_icon === 'string' && isPrefillableIcon(effective.menu_icon)) {
		menuIcon = effective.menu_icon;
	}

	return {
		...data,
		public: bool('public', false),
		show_in_rest: bool('show_in_rest', false),
		hierarchical: bool('hierarchical', false),
		has_archive: hasArchive,
		// `rewrite: false` (no pretty URLs) stays as it is: the URL controls are off.
		rewrite: data.rewrite === false ? false : { ...fileRewrite, with_front: withFront },
		supports,
		menu_position: menuPosition,
		menu_icon: menuIcon,
		taxonomies: Array.isArray(data.taxonomies) ? data.taxonomies : [],
	};
}

/**
 * The keys the user changed, compared with the form's starting values.
 * `rewrite` is merged key by key over the file's own object, so changing
 * one URL setting writes just that setting.
 */
export function changedValues(values: Values, defaults: Values, data: Values): Values {
	const changes: Values = {};
	for (const key of new Set([...Object.keys(values), ...Object.keys(defaults)])) {
		if (same(values[key], defaults[key])) {
			continue;
		}
		const now = values[key];
		const was = defaults[key];
		if (key === 'rewrite' && isObject(now)) {
			const merged: Values = isObject(data.rewrite) ? { ...data.rewrite } : {};
			const before = isObject(was) ? was : {};
			for (const sub of new Set([...Object.keys(now), ...Object.keys(before)])) {
				if (!same(now[sub], before[sub])) {
					merged[sub] = now[sub];
				}
			}
			changes.rewrite = defined(merged);
			continue;
		}
		changes[key] = now;
	}
	return changes;
}

/** Every message under `path` in react-hook-form's errors (nested ones included). */
function messagesAt(errors: FieldErrors<Values>, path: string): string[] {
	let node: unknown = errors;
	for (const part of path.split('.')) {
		node = isObject(node) || Array.isArray(node) ? (node as Record<string, unknown>)[part] : undefined;
	}
	const out: string[] = [];
	const walk = (entry: unknown, depth: number) => {
		if (!entry || typeof entry !== 'object' || depth > 6) {
			return;
		}
		const record = entry as Record<string, unknown>;
		if (typeof record.message === 'string' && record.message !== '') {
			out.push(record.message);
		}
		for (const [key, child] of Object.entries(record)) {
			if (key !== 'ref' && key !== 'message' && key !== 'type' && key !== 'types') {
				walk(child, depth + 1);
			}
		}
	};
	walk(node, 0);
	return [...new Set(out)];
}

/** Errors for one field; subscribes to that field only. */
function FieldError({ control, name, id }: { control: Control<Values>; name: string; id?: string }) {
	const { errors } = useFormState({ control, name: name as never });
	const messages = messagesAt(errors, name);
	if (messages.length === 0) {
		return null;
	}
	return (
		<p id={id} className="text-[13px] text-destructive" role="alert">
			{messages.join(' ')}
		</p>
	);
}

function Section({ id, title, description, children }: { id: string; title: string; description?: string; children: ReactNode }) {
	return (
		<section id={id} aria-labelledby={`${id}-title`}>
			<Card className="shadow-xs">
				<CardHeader>
					<CardTitle role="heading" aria-level={2} id={`${id}-title`}>
						{title}
					</CardTitle>
					{description ? <CardDescription>{description}</CardDescription> : null}
				</CardHeader>
				<CardContent className="grid gap-4">{children}</CardContent>
			</Card>
		</section>
	);
}

function SwitchRow({
	control,
	name,
	label,
	help,
	disabled,
}: {
	control: Control<Values>;
	name: string;
	label: string;
	help?: string;
	disabled: boolean;
}) {
	const id = useId();
	return (
		<Controller
			control={control}
			name={name}
			render={({ field }) => (
				<div className="flex items-start justify-between gap-4 py-1">
					<div className="grid gap-0.5">
						<Label htmlFor={id} className="text-sm font-medium">
							{label}
						</Label>
						{help ? (
							<p id={`${id}-help`} className="text-[13px] text-muted-foreground">
								{help}
							</p>
						) : null}
					</div>
					<Switch
						id={id}
						ref={field.ref}
						checked={field.value === true}
						disabled={disabled}
						aria-describedby={help ? `${id}-help` : undefined}
						onCheckedChange={(next) => field.onChange(next)}
						onBlur={field.onBlur}
					/>
				</div>
			)}
		/>
	);
}

function ArchiveField({ control, slug, disabled }: { control: Control<Values>; slug: string; disabled: boolean }) {
	const id = useId();
	const lastCustom = useRef<string>(slug);
	return (
		<Controller
			control={control}
			name="has_archive"
			render={({ field }) => {
				const value = field.value;
				const mode = typeof value === 'string' ? 'custom' : value === true ? 'on' : 'off';
				if (typeof value === 'string') {
					lastCustom.current = value;
				}
				return (
					<fieldset className="grid gap-2">
						<legend className="mb-2 text-sm font-medium">{__('Archive page', 'proto-blocks')}</legend>
						<RadioGroup
							value={mode}
							disabled={disabled}
							onValueChange={(next) => {
								if (next === 'on') {
									field.onChange(true);
								} else if (next === 'off') {
									field.onChange(false);
								} else {
									field.onChange(lastCustom.current || slug);
								}
							}}
						>
							<div className="flex items-center gap-2">
								<RadioGroupItem value="on" id={`${id}-on`} />
								<Label htmlFor={`${id}-on`} className="font-normal">
									{__('Automatic archive', 'proto-blocks')}
								</Label>
							</div>
							<div className="flex items-center gap-2">
								<RadioGroupItem value="custom" id={`${id}-custom`} />
								<Label htmlFor={`${id}-custom`} className="font-normal">
									{__('Automatic archive at a custom path', 'proto-blocks')}
								</Label>
							</div>
							<div className="flex items-center gap-2">
								<RadioGroupItem value="off" id={`${id}-off`} />
								<Label htmlFor={`${id}-off`} className="font-normal">
									{__("No archive — I'll use a page", 'proto-blocks')}
								</Label>
							</div>
						</RadioGroup>
						{mode === 'custom' ? (
							<div className="grid gap-1.5 ps-6">
								<Label htmlFor={`${id}-path`}>{__('Archive path', 'proto-blocks')}</Label>
								<Input
									id={`${id}-path`}
									ref={field.ref}
									className="font-mono"
									value={typeof value === 'string' ? value : ''}
									disabled={disabled}
									spellCheck={false}
									onChange={(event) => field.onChange(event.target.value)}
									onBlur={field.onBlur}
								/>
								<FieldError control={control} name="has_archive" />
							</div>
						) : null}
					</fieldset>
				);
			}}
		/>
	);
}

function SupportsField({
	control,
	count,
	initial,
	disabled,
}: {
	control: Control<Values>;
	count: number;
	initial: unknown;
	disabled: boolean;
}) {
	const id = useId();
	return (
		<Controller
			control={control}
			name="supports"
			render={({ field }) => {
				const list = Array.isArray(field.value) ? (field.value as string[]) : [];
				const before = Array.isArray(initial) ? (initial as string[]) : [];
				const removed = before.filter((key) => !list.includes(key));
				const toggle = (key: string, on: boolean) => {
					field.onChange(on ? [...list.filter((entry) => entry !== key), key] : list.filter((entry) => entry !== key));
				};
				return (
					<fieldset className="grid gap-3">
						<legend className="mb-2 text-sm font-medium">{__('Supports', 'proto-blocks')}</legend>
						<div className="grid grid-cols-2 gap-x-4 gap-y-2.5 sm:grid-cols-3">
							{SUPPORTS.map((support) => (
								<div key={support.key} className="flex items-center gap-2">
									<Checkbox
										id={`${id}-${support.key}`}
										checked={list.includes(support.key)}
										disabled={disabled}
										onCheckedChange={(next) => toggle(support.key, next === true)}
									/>
									<Label htmlFor={`${id}-${support.key}`} className="font-normal">
										{support.label}
									</Label>
								</div>
							))}
						</div>
						{count > 0 && removed.length > 0 ? (
							<Alert variant="warning" role="status">
								<TriangleAlertIcon aria-hidden="true" />
								<AlertDescription>
									{sprintf(
										/* translators: 1: number of posts, 2: comma-separated features such as editor, thumbnail. */
										_n(
											'%1$d post uses this type. Removing %2$s hides its box in the editor; nothing is deleted. You will confirm this when you save.',
											'%1$d posts use this type. Removing %2$s hides its box in the editor; nothing is deleted. You will confirm this when you save.',
											count,
											'proto-blocks'
										),
										count,
										removed.join(', ')
									)}
								</AlertDescription>
							</Alert>
						) : null}
					</fieldset>
				);
			}}
		/>
	);
}

type TaxonomyEntry = string | Record<string, unknown>;

function TaxonomiesField({ control, disabled }: { control: Control<Values>; disabled: boolean }) {
	const id = useId();
	// Row keys that follow the rows (not their index), so removing a row
	// doesn't hand its inputs' state to the next one.
	const keys = useRef<string[]>([]);
	const counter = useRef(0);
	const keysFor = (length: number) => {
		while (keys.current.length < length) {
			keys.current.push(`row-${counter.current++}`);
		}
		keys.current.length = length;
		return keys.current;
	};
	return (
		<Controller
			control={control}
			name="taxonomies"
			render={({ field }) => {
				const list = Array.isArray(field.value) ? (field.value as TaxonomyEntry[]) : [];
				const update = (index: number, next: TaxonomyEntry) => field.onChange(list.map((entry, i) => (i === index ? next : entry)));
				const rowKeys = keysFor(list.length);
				const remove = (index: number) => {
					keys.current.splice(index, 1);
					field.onChange(list.filter((_, i) => i !== index));
				};
				const setKey = (index: number, entry: Record<string, unknown>, key: string, value: unknown) => {
					const next = { ...entry };
					if (value === undefined || value === '') {
						delete next[key];
					} else {
						next[key] = value;
					}
					update(index, next);
				};
				return (
					<div className="grid gap-3">
						{list.length === 0 ? (
							<p className="text-[13px] text-muted-foreground">{__('No taxonomies are attached in this file.', 'proto-blocks')}</p>
						) : (
							<ul className="grid gap-2">
								{list.map((entry, index) => {
									const rowId = `${id}-${rowKeys[index]}`;
									if (typeof entry === 'string') {
										return (
											<li key={rowKeys[index]} className="flex flex-wrap items-end gap-2 rounded-md border p-3">
												<div className="grid min-w-[200px] flex-1 gap-1.5">
													<Label htmlFor={`${rowId}-slug`}>{__('Existing taxonomy slug', 'proto-blocks')}</Label>
													<Input
														id={`${rowId}-slug`}
														className="font-mono"
														value={entry}
														disabled={disabled}
														spellCheck={false}
														onChange={(event) => update(index, event.target.value)}
													/>
												</div>
												<RemoveButton disabled={disabled} label={entry} onClick={() => remove(index)} />
											</li>
										);
									}
									return (
										<li key={rowKeys[index]} className="flex flex-wrap items-end gap-2 rounded-md border p-3">
											<div className="grid min-w-[160px] flex-1 gap-1.5">
												<Label htmlFor={`${rowId}-name`}>{__('Taxonomy name', 'proto-blocks')}</Label>
												<Input
													id={`${rowId}-name`}
													value={typeof entry.name === 'string' ? entry.name : ''}
													disabled={disabled}
													onChange={(event) => setKey(index, entry, 'name', event.target.value)}
												/>
											</div>
											<div className="grid min-w-[140px] flex-1 gap-1.5">
												<Label htmlFor={`${rowId}-slug`}>{__('Taxonomy slug', 'proto-blocks')}</Label>
												<Input
													id={`${rowId}-slug`}
													className="font-mono"
													value={typeof entry.slug === 'string' ? entry.slug : ''}
													placeholder={__('auto', 'proto-blocks')}
													disabled={disabled}
													spellCheck={false}
													onChange={(event) => setKey(index, entry, 'slug', event.target.value)}
												/>
											</div>
											<div className="flex h-9 items-center gap-2">
												<Checkbox
													id={`${rowId}-hierarchical`}
													checked={entry.hierarchical === true}
													disabled={disabled}
													onCheckedChange={(next) => setKey(index, entry, 'hierarchical', next === true ? true : undefined)}
												/>
												<Label htmlFor={`${rowId}-hierarchical`} className="font-normal">
													{__('Like categories', 'proto-blocks')}
												</Label>
											</div>
											<RemoveButton
												disabled={disabled}
												label={typeof entry.name === 'string' ? entry.name : ''}
												onClick={() => remove(index)}
											/>
										</li>
									);
								})}
							</ul>
						)}
						<FieldError control={control} name="taxonomies" />
						<div className="flex flex-wrap gap-2">
							<Button type="button" variant="outline" size="sm" disabled={disabled} onClick={() => field.onChange([...list, { name: '' }])}>
								<PlusIcon aria-hidden="true" />
								{__('Add taxonomy', 'proto-blocks')}
							</Button>
							<Button type="button" variant="outline" size="sm" disabled={disabled} onClick={() => field.onChange([...list, ''])}>
								<PlusIcon aria-hidden="true" />
								{__('Attach existing taxonomy', 'proto-blocks')}
							</Button>
						</div>
					</div>
				);
			}}
		/>
	);
}

function RemoveButton({ disabled, label, onClick }: { disabled: boolean; label: string; onClick: () => void }) {
	return (
		<Button
			type="button"
			variant="ghost"
			size="icon"
			disabled={disabled}
			aria-label={sprintf(/* translators: %s: taxonomy name or slug. */ __('Remove %s', 'proto-blocks'), label || __('taxonomy', 'proto-blocks'))}
			onClick={onClick}
		>
			<XIcon aria-hidden="true" />
		</Button>
	);
}

/** The text that Save would write, from the current values (re-renders only itself). */
function JsoncPanel({
	control,
	file,
	base,
	defaults,
	onClose,
}: {
	control: Control<Values>;
	file: FileResult;
	base: Values;
	defaults: Values;
	onClose: () => void;
}) {
	const values = useWatch({ control }) as Values;
	const text = useMemo(() => {
		try {
			return applyDeclarationEdits(file.text, parseJsonc(file.text).data, { ...base, ...changedValues(values, defaults, file.data) });
		} catch {
			return file.text;
		}
	}, [values, file, base, defaults]);

	return (
		<Card className="sticky top-10 gap-0 overflow-hidden py-0 shadow-xs" aria-label={__('File preview', 'proto-blocks')}>
			<div className="flex items-center justify-between gap-2 border-b px-4 py-2">
				<span className="font-mono text-[12.5px]">type.jsonc</span>
				<Button type="button" variant="ghost" size="icon" aria-label={__('Close the file preview', 'proto-blocks')} onClick={onClose}>
					<XIcon aria-hidden="true" />
				</Button>
			</div>
			<pre
				tabIndex={0}
				aria-label={__('File text that Save to file writes', 'proto-blocks')}
				className="max-h-[70vh] overflow-auto bg-[#0f1d2e] p-4 font-mono text-[12.5px] leading-relaxed text-[#d6e2f0]"
			>
				{text}
			</pre>
			<p className="border-t px-4 py-2 text-[12px] text-muted-foreground">{__('Comments and key order are kept.', 'proto-blocks')}</p>
		</Card>
	);
}

/** Bottom bar: unsaved count, Save (Ctrl/⌘+S), and the unload warning while dirty. */
function SaveBar({
	control,
	label,
	path,
	readOnly,
	saving,
	onSave,
}: {
	control: Control<Values>;
	label: string;
	path: string;
	readOnly: boolean;
	saving: boolean;
	onSave: () => void;
}) {
	const { dirtyFields, isDirty, errors } = useFormState({ control });
	const changed = Object.keys(dirtyFields).length;
	const problems = Object.keys(errors).length;

	const saveRef = useRef(onSave);
	saveRef.current = onSave;
	useEffect(() => {
		if (readOnly) {
			return undefined;
		}
		const onKey = (event: KeyboardEvent) => {
			if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
				event.preventDefault();
				saveRef.current();
			}
		};
		window.addEventListener('keydown', onKey);
		return () => window.removeEventListener('keydown', onKey);
	}, [readOnly]);

	useEffect(() => {
		if (!isDirty || readOnly) {
			return undefined;
		}
		const onUnload = (event: BeforeUnloadEvent) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener('beforeunload', onUnload);
		return () => window.removeEventListener('beforeunload', onUnload);
	}, [isDirty, readOnly]);

	return (
		<div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-background px-4 py-3 shadow-sm">
			<p className="flex min-w-0 items-center gap-2 text-[13px]" aria-live="polite">
				{isDirty ? <CircleDotIcon aria-hidden="true" className="size-4 flex-none text-warning" /> : null}
				<span className="min-w-0">
					{isDirty
						? sprintf(
								/* translators: %d: number of changed settings. */
								_n('%d unsaved change', '%d unsaved changes', changed, 'proto-blocks'),
								changed
							) + ' · '
						: ''}
					{interpolate(
						/* translators: %s: path of the declaration file. */
						__('saves to %s', 'proto-blocks'),
						<code className="font-mono text-[12.5px] break-all">{path}</code>
					)}
					{problems > 0
						? ' · ' +
							sprintf(
								/* translators: %d: number of invalid settings. */
								_n('%d problem must be fixed before saving', '%d problems must be fixed before saving', problems, 'proto-blocks'),
								problems
							)
						: ''}
				</span>
			</p>
			<Button type="submit" disabled={readOnly || saving}>
				<SaveIcon aria-hidden="true" />
				{label}
			</Button>
		</div>
	);
}

/** The loaded declaration as a form (A2). */
function TypeForm({
	slug,
	detail,
	file,
	readOnly,
	saveFile,
	removeFile,
	reload,
	onSaved,
	onDeleted,
	onBack,
}: {
	slug: string;
	detail: TypeDetail;
	file: FileResult;
	readOnly: boolean;
	saveFile: ReturnType<typeof useDeclarationFile>['save'];
	removeFile: ReturnType<typeof useDeclarationFile>['remove'];
	reload: () => Promise<void>;
	onSaved: () => void;
	onDeleted: () => void;
	onBack: () => void;
}) {
	const ids = useId();
	const { item, effective, content } = detail;
	const override = needsOverride(item);
	const phpOnly = item.origin === 'php';

	// A new override starts without `name`, so it only overrides what the user sets.
	const base = useMemo<Values>(() => {
		if (!file.exists && override) {
			const { name: _name, ...rest } = file.data;
			return rest;
		}
		return file.data;
	}, [file, override]);
	// Effective settings fill the keys the file leaves out. A detail refresh
	// alone (after scaffolding) must not reset the user's edits, so only a new
	// file text starts the form over.
	const effectiveRef = useRef(effective);
	effectiveRef.current = effective;
	const defaults = useMemo(() => toFormValues(base, effectiveRef.current, item.origin), [base, item.origin]);
	const defaultsRef = useRef(defaults);

	const form = useForm<Values>({ defaultValues: defaults, resolver, mode: 'onSubmit', reValidateMode: 'onChange' });
	const { control, register, reset, handleSubmit, getValues } = form;

	useEffect(() => {
		if (defaultsRef.current !== defaults) {
			defaultsRef.current = defaults;
			reset(defaults);
		}
	}, [defaults, reset]);

	const [saving, setSaving] = useState(false);
	const [pending, setPending] = useState<{ next: Values; guardrails: Guardrail[] } | null>(null);
	const [conflict, setConflict] = useState(false);
	const [deleting, setDeleting] = useState<{ count: number; taxonomies?: Record<string, number> } | null>(null);
	const [discarding, setDiscarding] = useState(false);
	const [panel, setPanel] = useState(false);

	const changes = () => changedValues(getValues(), defaultsRef.current, base);

	const write = async (next: Values, acknowledge: string[] = []) => {
		setSaving(true);
		try {
			const outcome = await saveFile(next, acknowledge);
			if (outcome.status === 'saved') {
				onSaved();
			} else if (outcome.status === 'confirm') {
				setPending({ next, guardrails: outcome.guardrails });
			} else {
				setConflict(true);
			}
		} catch (e) {
			toast.error(
				sprintf(
					/* translators: %s: error message from the server. */
					__('Nothing was saved: %s', 'proto-blocks'),
					e instanceof Error ? e.message : String(e)
				)
			);
		} finally {
			setSaving(false);
		}
	};

	const submit = handleSubmit(
		async () => {
			if (readOnly || saving) {
				return;
			}
			const changed = changes();
			if (file.exists && Object.keys(changed).length === 0) {
				toast.info(__('There are no changes to save.', 'proto-blocks'));
				return;
			}
			await write({ ...base, ...changed });
		},
		(errors) => {
			const first = Object.keys(errors)[0];
			if (first) {
				form.setFocus(first as never);
			}
		}
	);

	const remove = async (confirm?: string) => {
		try {
			const outcome = await removeFile(confirm);
			if (outcome.status === 'confirm') {
				setDeleting(outcome.content);
				return;
			}
			setDeleting(null);
			toast.success(
				sprintf(
					/* translators: %s: path of the declaration file. */
					__('Deleted %s.', 'proto-blocks'),
					file.path
				)
			);
			if (outcome.kept.length > 0) {
				toast.info(
					sprintf(
						/* translators: %s: comma-separated file names left in the folder. */
						__('The folder was kept because it still holds: %s.', 'proto-blocks'),
						outcome.kept.join(', ')
					)
				);
			}
			onDeleted();
		} catch (e) {
			setDeleting(null);
			toast.error(e instanceof Error ? e.message : String(e));
		}
	};

	let submitLabel: string = __('Save to file', 'proto-blocks');
	if (!file.exists && override) {
		submitLabel = __('Create override file', 'proto-blocks');
	} else if (!file.exists && phpOnly) {
		submitLabel = __('Create file & save', 'proto-blocks');
	}

	const labels = useMemo(() => {
		const out: Record<string, string> = {};
		if (isObject(effective.labels)) {
			for (const [key, value] of Object.entries(effective.labels)) {
				if (typeof value === 'string') {
					out[key] = value;
				}
			}
		}
		return out;
	}, [effective]);

	let shellReason: ListItem['readOnlyReason'] = null;
	const writeReason = getWriteReason();
	if (writeReason !== null) {
		shellReason = writeReason;
	} else if (file.reason === 'file_mods' || file.reason === 'multisite') {
		shellReason = file.reason;
	} else if (item.readOnlyReason === 'not_writable' || file.reason === 'not_writable') {
		shellReason = 'not_writable';
	}

	const code = (text: string) => <code className="font-mono text-[12.5px] break-all">{text}</code>;
	const nameHelpId = `${ids}-name-help`;
	const slugHelpId = `${ids}-slug-help`;
	const effRewrite = isObject(effective.rewrite) ? effective.rewrite : {};
	const singular = isObject(effective.labels) && typeof effective.labels.singular_name === 'string' ? effective.labels.singular_name : '';
	const plural = isObject(effective.labels) && typeof effective.labels.name === 'string' ? effective.labels.name : '';
	const textValue = { setValueAs: (value: unknown) => (value === '' ? undefined : value) };

	return (
		<DeclarationShell
			title={item.label}
			readOnlyReason={shellReason}
			path={file.path}
			actions={
				<>
					<Button
						type="button"
						variant="ghost"
						onClick={() => {
							if (!readOnly && Object.keys(changes()).length > 0) {
								setDiscarding(true);
							} else {
								onBack();
							}
						}}
					>
						<ArrowLeftIcon aria-hidden="true" />
						{__('All post types', 'proto-blocks')}
					</Button>
					<Button type="button" variant="outline" aria-pressed={panel} onClick={() => setPanel((open) => !open)}>
						<BracesIcon aria-hidden="true" />
						{__('File', 'proto-blocks')}
					</Button>
					{item.origin === 'file' ? (
						<Button
							type="button"
							variant="outline"
							className="text-destructive"
							disabled={readOnly}
							onClick={() => setDeleting(content)}
						>
							{__('Delete…', 'proto-blocks')}
						</Button>
					) : null}
				</>
			}
		>
			<div className="flex flex-wrap items-center gap-2">
				<OriginBadge origin={item.origin} label={item.originLabel} double={item.double} />
			</div>

			{item.readOnlyReason === 'outside_root' ? (
				<Alert>
					<LockIcon aria-hidden="true" />
					<AlertDescription>
						{__('This declaration is outside the theme folders Proto-Blocks writes to, so it can only be viewed here.', 'proto-blocks')}
					</AlertDescription>
				</Alert>
			) : null}
			{item.double ? (
				<Alert variant="warning" role="status">
					<TriangleAlertIcon aria-hidden="true" />
					<AlertTitle className="line-clamp-none">{__('Declared twice — this file wins', 'proto-blocks')}</AlertTitle>
					<AlertDescription>
						<p>
							{interpolate(
								/* translators: %s: PHP file and line that also declares the type. */
								__('Also declared in PHP at %s. Nothing from the PHP declaration is applied. Delete that line when you are ready.', 'proto-blocks'),
								code(item.double.php)
							)}
						</p>
					</AlertDescription>
				</Alert>
			) : null}
			{phpOnly && !file.exists ? (
				<Alert>
					<InfoIcon aria-hidden="true" />
					<AlertDescription>
						<p>
							{interpolate(
								/* translators: %s: PHP file and line that declares the type. */
								__('Declared in PHP at %s; remove the line from your theme.', 'proto-blocks'),
								code(item.source ?? '')
							)}{' '}
							{interpolate(
								/* translators: %s: path of the declaration file. */
								__('Saving creates %s, which then takes over.', 'proto-blocks'),
								code(file.path)
							)}
						</p>
					</AlertDescription>
				</Alert>
			) : null}
			{override && !file.exists ? (
				<Alert>
					<InfoIcon aria-hidden="true" />
					<AlertDescription>
						<p>
							{sprintf(
								/* translators: %s: who registers the type, e.g. "Core" or "Plugin: WooCommerce". */
								__('Registered by %s. Saving writes an override file; only the settings you change are applied on top.', 'proto-blocks'),
								item.originLabel
							)}
						</p>
					</AlertDescription>
				</Alert>
			) : null}

			<div className={panel ? 'grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_400px]' : 'grid gap-6'}>
				<form onSubmit={submit} noValidate className="grid min-w-0 gap-6" aria-label={item.label}>
					<fieldset disabled={readOnly} className="grid min-w-0 gap-6">
						<Section id="pt-general" title={__('General', 'proto-blocks')}>
							<div className="grid gap-4 sm:grid-cols-2">
								<div className="grid gap-1.5">
									<Label htmlFor={`${ids}-name`}>{__('Singular name', 'proto-blocks')}</Label>
									<Input id={`${ids}-name`} placeholder={singular} {...register('name', textValue)} aria-describedby={nameHelpId} />
									<FieldError control={control} name="name" id={nameHelpId} />
								</div>
								<div className="grid gap-1.5">
									<Label htmlFor={`${ids}-plural`}>{__('Plural name', 'proto-blocks')}</Label>
									<Input id={`${ids}-plural`} placeholder={plural} {...register('plural', textValue)} />
									<FieldError control={control} name="plural" />
								</div>
							</div>
							<div className="grid gap-1.5">
								<Label htmlFor={`${ids}-slug`} className="flex items-center gap-1.5">
									<LockIcon aria-hidden="true" className="size-3.5" />
									{__('Slug', 'proto-blocks')}
								</Label>
								<Input
									id={`${ids}-slug`}
									value={slug}
									readOnly
									aria-readonly="true"
									aria-describedby={slugHelpId}
									className="bg-muted font-mono"
								/>
								<p id={slugHelpId} className="text-[13px] text-muted-foreground">
									{content.count > 0
										? interpolate(
												sprintf(
													/* translators: 1: number of posts; %2$s stays as is (the CLI command is put there). */
													_n('This type has %1$d post. Rename it with %2$s.', 'This type has %1$d posts. Rename it with %2$s.', content.count, 'proto-blocks'),
													content.count,
													'%2$s'
												),
												null,
												code(`wp proto-blocks type rename ${slug} <new> --migrate`)
											)
										: __('The slug is the folder name. It cannot be changed here.', 'proto-blocks')}
								</p>
							</div>
						</Section>

						<Section id="pt-visibility" title={__('Visibility', 'proto-blocks')}>
							<div className="grid divide-y">
								<SwitchRow
									control={control}
									name="public"
									label={__('Public', 'proto-blocks')}
									help={__('Visible on the site and in search. Turn off for internal-only content.', 'proto-blocks')}
									disabled={readOnly}
								/>
								<SwitchRow
									control={control}
									name="show_in_rest"
									label={__('Show in REST API', 'proto-blocks')}
									help={__('Required for the block editor. Turning this off switches the type to the classic editor.', 'proto-blocks')}
									disabled={readOnly}
								/>
								<SwitchRow
									control={control}
									name="hierarchical"
									label={__('Hierarchical', 'proto-blocks')}
									help={__('Items can have parents, like Pages.', 'proto-blocks')}
									disabled={readOnly}
								/>
							</div>
						</Section>

						<Section id="pt-urls" title={__('Archive and URLs', 'proto-blocks')}>
							<ArchiveField control={control} slug={slug} disabled={readOnly} />
							{defaults.rewrite === false ? (
								<div className="grid gap-1.5">
									<Label htmlFor={`${ids}-rewrite-slug`}>{__('URL slug', 'proto-blocks')}</Label>
									<Input id={`${ids}-rewrite-slug`} className="font-mono" disabled value="" aria-describedby={`${ids}-rewrite-off`} />
									<p id={`${ids}-rewrite-off`} className="text-[13px] text-muted-foreground">
										{interpolate(
											/* translators: %s: the JSON setting "rewrite": false. */
											__('Pretty URLs are off for this type (%s in the file). Change it in the file to set a URL slug.', 'proto-blocks'),
											code('"rewrite": false')
										)}
									</p>
								</div>
							) : (
								<>
									<div className="grid gap-1.5">
										<Label htmlFor={`${ids}-rewrite-slug`}>{__('URL slug', 'proto-blocks')}</Label>
										<Input
											id={`${ids}-rewrite-slug`}
											className="font-mono"
											spellCheck={false}
											placeholder={typeof effRewrite.slug === 'string' ? effRewrite.slug : slug}
											{...register('rewrite.slug', textValue)}
										/>
										<FieldError control={control} name="rewrite" />
									</div>
									<SwitchRow
										control={control}
										name="rewrite.with_front"
										label={__('Prefix with permalink base', 'proto-blocks')}
										disabled={readOnly}
									/>
								</>
							)}
						</Section>

						<Section id="pt-editor" title={__('Editor', 'proto-blocks')} description={__('What appears on the edit screen.', 'proto-blocks')}>
							<SupportsField control={control} count={content.count} initial={defaults.supports} disabled={readOnly} />
							<Controller
								control={control}
								name="template_lock"
								render={({ field }) => (
									<div className="grid max-w-[280px] gap-1.5">
										<Label htmlFor={`${ids}-lock`}>{__('Template lock', 'proto-blocks')}</Label>
										<select
											id={`${ids}-lock`}
											ref={field.ref}
											disabled={readOnly}
											className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs"
											value={field.value === undefined ? '' : String(field.value)}
											onBlur={field.onBlur}
											onChange={(event) => {
												const next = event.target.value;
												field.onChange(next === '' ? undefined : next === 'false' ? false : next);
											}}
										>
											<option value="">{__('Not set', 'proto-blocks')}</option>
											<option value="false">{__('None', 'proto-blocks')}</option>
											<option value="all">{__('All (no moving or adding blocks)', 'proto-blocks')}</option>
											<option value="insert">{__('Insert (blocks can move, not be added)', 'proto-blocks')}</option>
											<option value="contentOnly">{__('Content only', 'proto-blocks')}</option>
										</select>
									</div>
								)}
							/>
						</Section>

						<Section id="pt-menu" title={__('Menu', 'proto-blocks')}>
							<Controller
								control={control}
								name="menu_position"
								render={({ field }) => (
									<div className="grid max-w-[200px] gap-1.5">
										<Label htmlFor={`${ids}-position`}>{__('Menu position', 'proto-blocks')}</Label>
										<Input
											id={`${ids}-position`}
											ref={field.ref}
											type="number"
											inputMode="numeric"
											min={0}
											max={10000}
											disabled={readOnly}
											aria-describedby={`${ids}-position-help`}
											value={typeof field.value === 'number' && !Number.isNaN(field.value) ? String(field.value) : ''}
											onBlur={field.onBlur}
											onChange={(event) => field.onChange(event.target.value === '' ? undefined : Number(event.target.value))}
										/>
										<p id={`${ids}-position-help`} className="text-[13px] text-muted-foreground">
											{__('22 = just below Pages', 'proto-blocks')}
										</p>
										<FieldError control={control} name="menu_position" />
									</div>
								)}
							/>
							<Controller
								control={control}
								name="menu_icon"
								render={({ field }) => (
									<MenuIconField
										ref={field.ref}
										id={`${ids}-icon`}
										value={typeof field.value === 'string' ? field.value : undefined}
										registered={typeof effective.menu_icon === 'string' ? effective.menu_icon : undefined}
										disabled={readOnly}
										onChange={field.onChange}
										onBlur={field.onBlur}
									/>
								)}
							/>
							<FieldError control={control} name="menu_icon" />
						</Section>

						<Section
							id="pt-labels"
							title={__('Labels', 'proto-blocks')}
							description={__('Empty labels use the ones WordPress generates from the names.', 'proto-blocks')}
						>
							<Controller
								control={control}
								name="labels"
								render={({ field }) => (
									<LabelsEditor
										value={isObject(field.value) ? (field.value as Record<string, string>) : undefined}
										generated={labels}
										disabled={readOnly}
										onChange={field.onChange}
									/>
								)}
							/>
							<FieldError control={control} name="labels" />
						</Section>

						<Section
							id="pt-taxonomies"
							title={__('Taxonomies', 'proto-blocks')}
							description={__('Declared in this file, or existing taxonomies attached by slug.', 'proto-blocks')}
						>
							<TaxonomiesField control={control} disabled={readOnly} />
						</Section>
					</fieldset>

					<SaveBar
						control={control}
						label={submitLabel}
						path={file.path}
						readOnly={readOnly}
						saving={saving}
						onSave={() => void submit()}
					/>
				</form>
				{panel ? (
					<JsoncPanel control={control} file={file} base={base} defaults={defaults} onClose={() => setPanel(false)} />
				) : null}
			</div>

			<TemplatesCard
				slug={slug}
				templates={detail.templates}
				blockTheme={detail.blockTheme}
				readOnly={readOnly}
				onScaffolded={onSaved}
			/>

			<Card className="shadow-xs">
				<CardHeader>
					<CardTitle role="heading" aria-level={2}>
						{__('Effective settings', 'proto-blocks')}
					</CardTitle>
					<CardDescription>{__('What WordPress registers, after defaults. Read-only.', 'proto-blocks')}</CardDescription>
				</CardHeader>
				<CardContent>
					<pre
						tabIndex={0}
						aria-label={__('Effective settings', 'proto-blocks')}
						className="max-h-[360px] overflow-auto rounded-md bg-muted p-3 font-mono text-[12px]"
					>
						{JSON.stringify(effective, null, 2)}
					</pre>
				</CardContent>
			</Card>

			{pending ? (
				<GuardrailDialog
					guardrails={pending.guardrails}
					onCancel={() => setPending(null)}
					onConfirm={(acknowledge) => {
						const { next } = pending;
						setPending(null);
						void write(next, acknowledge);
					}}
				/>
			) : null}
			{conflict ? (
				<ConflictDialog
					path={file.path}
					onCancel={() => setConflict(false)}
					onReload={() => {
						setConflict(false);
						void reload();
					}}
				/>
			) : null}
			{deleting ? (
				<DeleteDeclarationDialog
					slug={slug}
					label={item.label}
					path={file.path}
					content={deleting}
					onCancel={() => setDeleting(null)}
					onConfirm={(confirm) => void remove(confirm)}
				/>
			) : null}
			<ConfirmDialog
				open={discarding}
				title={__('Discard unsaved changes?', 'proto-blocks')}
				confirmLabel={__('Discard', 'proto-blocks')}
				destructive
				onCancel={() => setDiscarding(false)}
				onConfirm={() => {
					setDiscarding(false);
					onBack();
				}}
			/>
		</DeclarationShell>
	);
}

function EditSkeleton() {
	return (
		<div aria-busy="true" className="grid gap-6">
			<Skeleton className="h-10 w-64" />
			<Skeleton className="h-48" />
			<Skeleton className="h-48" />
		</div>
	);
}

/** Loads the declaration file (once the detail says which name a new file gets) and shows the form. */
function TypeFile({
	slug,
	detail,
	readOnly,
	onSaved,
	onBack,
}: {
	slug: string;
	detail: TypeDetail;
	readOnly: boolean;
	onSaved: () => void;
	onBack: () => void;
}) {
	// Fixed at mount: the hook reloads when `name` changes.
	const [name] = useState<string | undefined>(() => {
		if (detail.item.origin !== 'php') {
			return undefined;
		}
		const labels = detail.effective.labels;
		return isObject(labels) && typeof labels.singular_name === 'string' ? labels.singular_name : undefined;
	});
	const { file, error, save, remove, reload } = useDeclarationFile('type', slug, name);

	if (error && !file) {
		return (
			<div className="grid gap-6">
				<PageHeader
					title={detail.item.label}
					actions={
						<Button type="button" variant="ghost" onClick={onBack}>
							<ArrowLeftIcon aria-hidden="true" />
							{__('All post types', 'proto-blocks')}
						</Button>
					}
				/>
				<ScreenError screen={detail.item.label} error={error} onRetry={() => void reload()} />
			</div>
		);
	}
	if (!file) {
		return <EditSkeleton />;
	}

	const blocked = detail.item.readOnlyReason !== null && detail.item.readOnlyReason !== 'not_proto';
	return (
		<TypeForm
			slug={slug}
			detail={detail}
			file={file}
			readOnly={readOnly || blocked || !file.writable}
			saveFile={save}
			removeFile={remove}
			reload={reload}
			onSaved={onSaved}
			onDeleted={onBack}
			onBack={onBack}
		/>
	);
}

/** Edit (or override) one post type: GET admin/types/<slug>, then its declaration file. */
export function PostTypeEdit({ slug, readOnly, onBack }: { slug: string; readOnly: boolean; onBack: () => void }) {
	const [detail, setDetail] = useState<TypeDetail | null>(null);
	const [error, setError] = useState<unknown>(null);
	const request = useRef(0);

	const load = useCallback(async () => {
		const id = ++request.current;
		try {
			const result = await adminGet<TypeDetail>(`admin/types/${slug}`);
			if (id === request.current) {
				setDetail(result);
				setError(null);
			}
		} catch (e) {
			if (id === request.current) {
				setError(e instanceof ApiError || e instanceof Error ? e : new Error(String(e)));
			}
		}
	}, [slug]);

	useEffect(() => {
		void load();
	}, [load]);

	if (error && !detail) {
		return (
			<div className="grid gap-6">
				<PageHeader
					title={__('Post Types', 'proto-blocks')}
					actions={
						<Button type="button" variant="ghost" onClick={onBack}>
							<ArrowLeftIcon aria-hidden="true" />
							{__('All post types', 'proto-blocks')}
						</Button>
					}
				/>
				<ScreenError screen={slug} error={error} onRetry={() => void load()} />
			</div>
		);
	}
	if (!detail) {
		return <EditSkeleton />;
	}
	return <TypeFile slug={slug} detail={detail} readOnly={readOnly} onSaved={() => void load()} onBack={onBack} />;
}
