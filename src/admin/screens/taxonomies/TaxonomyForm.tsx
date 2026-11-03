import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useForm, type FieldErrors, type Resolver } from 'react-hook-form';
import { toast } from 'sonner';
import { ArrowLeftIcon, LockIcon, TrashIcon } from 'lucide-react';
import { adminGet, adminPost, ApiError } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { ConflictDialog } from '@/admin/components/content-model/ConflictDialog';
import { DeclarationShell } from '@/admin/components/content-model/DeclarationShell';
import { DeleteDeclarationDialog } from '@/admin/components/content-model/DeleteDeclarationDialog';
import { GuardrailDialog } from '@/admin/components/content-model/GuardrailDialog';
import { LabelsEditor } from '@/admin/components/content-model/LabelsEditor';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Alert, AlertDescription } from '@/admin/components/ui/alert';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { applyDeclarationEdits } from '@/admin/content-model/jsonc';
import { taxonomySchema } from '@/admin/content-model/schema';
import type { FileResult, Guardrail, ListItem } from '@/admin/content-model/types';
import { useDeclarationFile } from '@/admin/content-model/useDeclarationFile';
import { interpolate } from '@/admin/lib/interpolate';
import { EditInPostTypeLink, inlineParent, useAsync, type TaxonomyDetail } from '@/admin/screens/taxonomies/TaxonomyList';

type Values = Record<string, unknown>;
type Reason = ListItem['readOnlyReason'];

const FIELDS = ['name', 'plural', 'post_types', 'hierarchical', 'public', 'show_in_rest', 'show_admin_column', 'rewrite', 'labels'] as const;
const SLUG_PATTERN = /^[a-z][a-z0-9_-]{0,31}$/;

const code = (text: ReactNode) => <code className="font-mono text-[12.5px]">{text}</code>;
const isObject = (value: unknown): value is Record<string, unknown> => value !== null && typeof value === 'object' && !Array.isArray(value);

/** Drops unset keys and empty strings, one level into objects, so they read as "not in the file". */
function clean(values: Values): Values {
	const out: Values = {};
	for (const [key, value] of Object.entries(values)) {
		if (value === undefined || value === '') {
			continue;
		}
		out[key] = isObject(value) ? clean(value) : value;
	}
	return out;
}

/** Validates the cleaned values against the same schema the server uses. */
function makeResolver(requireName: boolean): Resolver<Values> {
	return async (values) => {
		const errors: Record<string, { type: string; message: string }> = {};
		if (requireName && (typeof values.name !== 'string' || values.name.trim() === '')) {
			errors.name = { type: 'required', message: __('Enter a name.', 'proto-blocks') };
		}
		const parsed = taxonomySchema.safeParse(clean(values));
		if (!parsed.success) {
			for (const issue of parsed.error.issues) {
				const key = String(issue.path[0] ?? 'name');
				errors[key] ??= { type: 'validate', message: issue.message };
			}
		}
		return Object.keys(errors).length > 0 ? { values: {}, errors: errors as FieldErrors<Values> } : { values, errors: {} };
	};
}

function pick(data: Record<string, unknown>): Values {
	const out: Values = {};
	for (const key of FIELDS) {
		if (key in data) {
			out[key] = data[key];
		}
	}
	return out;
}

/** The slug WordPress would accept for `name`. */
function slugify(name: string): string {
	return name
		.toLowerCase()
		.replace(/[^a-z0-9_-]+/g, '-')
		.replace(/^[^a-z]+/, '')
		.replace(/-+$/, '')
		.slice(0, 32);
}

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
	const id = useId();
	return (
		<Card role="region" aria-labelledby={id} className="gap-4 rounded-lg p-5 shadow-xs">
			<div className="grid gap-1">
				<h2 id={id} className="text-[15px] leading-[1.3] font-semibold">
					{title}
				</h2>
				{description ? <p className="text-[13px] text-muted-foreground">{description}</p> : null}
			</div>
			{children}
		</Card>
	);
}

function SwitchRow({ label, description, checked, disabled, onChange }: { label: string; description?: string; checked: boolean; disabled: boolean; onChange: (next: boolean) => void }) {
	const id = useId();
	return (
		<div className="flex items-start justify-between gap-4">
			<div className="grid gap-0.5">
				<Label htmlFor={id}>{label}</Label>
				{description ? <p className="text-[13px] text-muted-foreground">{description}</p> : null}
			</div>
			<Switch id={id} checked={checked} disabled={disabled} onCheckedChange={onChange} />
		</div>
	);
}

function SaveDialogs({
	path,
	conflict,
	guardrails,
	onReload,
	onKeepEditing,
	onConfirm,
	onGoBack,
}: {
	path: string;
	conflict: boolean;
	guardrails: Guardrail[] | null;
	onReload: () => void;
	onKeepEditing: () => void;
	onConfirm: (acknowledge: string[]) => void;
	onGoBack: () => void;
}) {
	return (
		<>
			<ConflictDialog open={conflict} path={path} onReload={onReload} onCancel={onKeepEditing} />
			<GuardrailDialog open={guardrails !== null} guardrails={guardrails ?? []} onConfirm={onConfirm} onCancel={onGoBack} />
		</>
	);
}

/** The fields shared by create and edit. */
function Fields({
	form,
	mode,
	slug,
	onSlug,
	slugHint,
	effective,
	fallbackTypes,
	types,
	disabled,
}: {
	form: ReturnType<typeof useForm<Values>>;
	mode: 'create' | 'edit';
	slug: string;
	onSlug?: (slug: string) => void;
	slugHint: ReactNode;
	effective: Record<string, unknown>;
	/** Types the taxonomy is attached to when the file does not say. */
	fallbackTypes: string[];
	types: ListItem[];
	disabled: boolean;
}) {
	const { register, setValue, watch, formState } = form;
	const values = watch();
	const errors = formState.errors;
	const nameId = useId();
	const pluralId = useId();
	const slugId = useId();
	const rewriteId = useId();

	const flag = (key: string, fallback: boolean) => (typeof values[key] === 'boolean' ? (values[key] as boolean) : typeof effective[key] === 'boolean' ? (effective[key] as boolean) : fallback);
	const set = (key: string, value: unknown) => setValue(key, value as never, { shouldDirty: true, shouldValidate: formState.isSubmitted });

	const attached = Array.isArray(values.post_types) ? (values.post_types as string[]) : fallbackTypes;
	const rewrite = isObject(values.rewrite) ? values.rewrite : null;
	const effectiveRewrite = isObject(effective.rewrite) ? effective.rewrite : {};
	const rewriteOff = values.rewrite === false || (values.rewrite === undefined && effective.rewrite === false);
	const setRewrite = (key: string, value: unknown) => {
		const next = { ...(rewrite ?? {}), [key]: value };
		if (value === '') {
			delete next[key];
		}
		set('rewrite', next);
	};
	const message = (key: string) => {
		const error = (errors as Record<string, { message?: string } | undefined>)[key];
		return error?.message ? (
			<p role="alert" className="text-[13px] text-destructive">
				{error.message}
			</p>
		) : null;
	};
	const slugInvalid = mode === 'create' && slug !== '' && !SLUG_PATTERN.test(slug);

	return (
		<>
			<Section title={__('Names', 'proto-blocks')}>
				<div className="grid gap-4 sm:grid-cols-3">
					<div className="grid gap-1.5">
						<Label htmlFor={nameId}>{__('Name', 'proto-blocks')}</Label>
						<Input
							id={nameId}
							disabled={disabled}
							aria-invalid={errors.name ? true : undefined}
							{...register('name', {
								onChange: (event) => {
									if (mode === 'create') {
										onSlug?.(slugify(event.target.value));
									}
								},
							})}
						/>
						{message('name')}
					</div>
					<div className="grid gap-1.5">
						<Label htmlFor={pluralId}>{__('Plural name', 'proto-blocks')}</Label>
						<Input id={pluralId} disabled={disabled} aria-invalid={errors.plural ? true : undefined} {...register('plural')} />
						{message('plural')}
					</div>
					<div className="grid gap-1.5">
						<Label htmlFor={slugId}>
							{__('Slug', 'proto-blocks')}
							{mode === 'edit' ? <LockIcon aria-hidden="true" className="size-3 text-muted-foreground" /> : null}
						</Label>
						<Input
							id={slugId}
							className="font-mono"
							value={slug}
							readOnly={mode === 'edit'}
							disabled={disabled && mode === 'create'}
							aria-invalid={slugInvalid ? true : undefined}
							onChange={(event) => onSlug?.(event.target.value)}
						/>
						<p className="text-[13px] text-muted-foreground">{slugHint}</p>
						{slugInvalid ? (
							<p role="alert" className="text-[13px] text-destructive">
								{__('Use lowercase letters, numbers, dashes and underscores, starting with a letter (32 characters at most).', 'proto-blocks')}
							</p>
						) : null}
					</div>
				</div>
			</Section>

			<Section title={__('Attached post types', 'proto-blocks')} description={__('Where this taxonomy appears. It may attach to types it does not own.', 'proto-blocks')}>
				<div className="grid gap-2 sm:grid-cols-2">
					{types.map((type) => {
						const id = `${slugId}-${type.slug}`;
						return (
							<div key={type.slug} className="flex items-center gap-3 rounded-md border p-3">
								<Checkbox
									id={id}
									disabled={disabled}
									checked={attached.includes(type.slug)}
									onCheckedChange={(checked) =>
										set('post_types', checked === true ? [...attached.filter((s) => s !== type.slug), type.slug] : attached.filter((s) => s !== type.slug))
									}
								/>
								<Label htmlFor={id} className="flex-1 flex-wrap gap-x-2">
									<span>{type.label}</span>
									<code className="font-mono text-[12.5px] font-normal text-muted-foreground">{type.slug}</code>
								</Label>
								<OriginBadge origin={type.origin} label={type.originLabel} />
							</div>
						);
					})}
				</div>
				{message('post_types')}
			</Section>

			<Section title={__('Behaviour', 'proto-blocks')}>
				<SwitchRow
					label={__('Hierarchical (like categories)', 'proto-blocks')}
					description={__('Terms can have parents and editors pick from a checklist. Off means flat, like tags.', 'proto-blocks')}
					checked={flag('hierarchical', false)}
					disabled={disabled}
					onChange={(next) => set('hierarchical', next)}
				/>
				<SwitchRow
					label={__('Show as a column in admin lists', 'proto-blocks')}
					checked={flag('show_admin_column', false)}
					disabled={disabled}
					onChange={(next) => set('show_admin_column', next)}
				/>
				<SwitchRow
					label={__('Public', 'proto-blocks')}
					description={__('Term archive pages exist on the site.', 'proto-blocks')}
					checked={flag('public', true)}
					disabled={disabled}
					onChange={(next) => set('public', next)}
				/>
				<SwitchRow
					label={__('Show in REST API', 'proto-blocks')}
					description={__("Required for the block editor's term panel.", 'proto-blocks')}
					checked={flag('show_in_rest', true)}
					disabled={disabled}
					onChange={(next) => set('show_in_rest', next)}
				/>
			</Section>

			<Section title={__('URLs', 'proto-blocks')}>
				{rewriteOff ? <p className="text-[13px] text-muted-foreground">{interpolate(__('Pretty URLs are turned off in the file (%s).', 'proto-blocks'), code('"rewrite": false'))}</p> : null}
				<div className="grid gap-1.5 sm:max-w-sm">
					<Label htmlFor={rewriteId}>{__('URL slug', 'proto-blocks')}</Label>
					<Input
						id={rewriteId}
						className="font-mono"
						disabled={disabled || rewriteOff}
						value={typeof rewrite?.slug === 'string' ? rewrite.slug : ''}
						placeholder={typeof effectiveRewrite.slug === 'string' ? effectiveRewrite.slug : slug}
						onChange={(event) => setRewrite('slug', event.target.value)}
					/>
				</div>
				<SwitchRow
					label={__('Prefix with permalink base', 'proto-blocks')}
					checked={typeof rewrite?.with_front === 'boolean' ? rewrite.with_front : effectiveRewrite.with_front !== false}
					disabled={disabled || rewriteOff}
					onChange={(next) => setRewrite('with_front', next)}
				/>
			</Section>

			<Section title={__('Labels', 'proto-blocks')}>
				<LabelsEditor
					value={isObject(values.labels) ? (values.labels as Record<string, string>) : undefined}
					generated={isObject(effective.labels) ? (effective.labels as Record<string, string>) : {}}
					disabled={disabled}
					onChange={(next) => set('labels', next)}
				/>
			</Section>
		</>
	);
}

function BackButton({ onBack }: { onBack: () => void }) {
	return (
		<Button type="button" variant="ghost" onClick={onBack}>
			<ArrowLeftIcon aria-hidden="true" />
			{__('All taxonomies', 'proto-blocks')}
		</Button>
	);
}

function errorText(error: unknown): string {
	return error instanceof Error ? error.message : String(error);
}

function CreateTaxonomy({ types, onBack, onOpen }: { types: ListItem[]; onBack: () => void; onOpen: (slug: string) => void }) {
	const { fileModsAllowed } = getBootstrap();
	const form = useForm<Values>({ defaultValues: {}, resolver: useMemo(() => makeResolver(true), []) });
	const [slug, setSlug] = useState('');
	const [slugTouched, setSlugTouched] = useState(false);
	const [saving, setSaving] = useState(false);
	const [failure, setFailure] = useState<string | null>(null);
	const [conflict, setConflict] = useState(false);
	const [guardrails, setGuardrails] = useState<Guardrail[] | null>(null);
	const formId = useId();
	const readOnly = !fileModsAllowed;

	const create = async (acknowledge: string[]) => {
		const values = clean(form.getValues());
		const name = String(values.name ?? '');
		setSaving(true);
		setFailure(null);
		try {
			const base = `schema-files/taxonomy/${slug}`;
			const template = await adminGet<FileResult>(`${base}?name=${encodeURIComponent(name)}`);
			if (template.exists) {
				throw new Error(sprintf(/* translators: %s: path of the existing declaration file. */ __('A file already exists at %s. Open it from the list instead.', 'proto-blocks'), template.path));
			}
			const text = applyDeclarationEdits(template.text, template.data, { ...template.data, ...values, name });
			try {
				await adminPost(base, { text, hash: template.hash, acknowledge });
			} catch (e) {
				if (e instanceof ApiError && e.code === 'proto_blocks_conflict') {
					setConflict(true);
					return;
				}
				if (e instanceof ApiError && e.code === 'proto_blocks_needs_confirmation') {
					setGuardrails(Array.isArray(e.data.guardrails) ? (e.data.guardrails as Guardrail[]) : []);
					return;
				}
				throw e;
			}
			toast.success(sprintf(/* translators: %s: path of the declaration file that was written. */ __('Saved to %s.', 'proto-blocks'), template.path));
			onOpen(slug);
		} catch (e) {
			setFailure(errorText(e));
		} finally {
			setSaving(false);
		}
	};

	const onSubmit = form.handleSubmit(() => {
		if (SLUG_PATTERN.test(slug)) {
			void create([]);
		}
	});

	return (
		<DeclarationShell
			title={__('Add taxonomy', 'proto-blocks')}
			readOnlyReason={readOnly ? 'file_mods' : null}
			path={slug ? `proto-taxonomies/${slug}/taxonomy.jsonc` : ''}
			actions={
				<>
					<BackButton onBack={onBack} />
					<Button type="submit" form={formId} disabled={readOnly || saving || !SLUG_PATTERN.test(slug)}>
						{__('Save to file', 'proto-blocks')}
					</Button>
				</>
			}
		>
			<form id={formId} className="grid gap-6" noValidate onSubmit={onSubmit}>
				{failure ? (
					<Alert variant="destructive" role="alert">
						<AlertDescription>{failure}</AlertDescription>
					</Alert>
				) : null}
				<Fields
					form={form}
					mode="create"
					slug={slug}
					onSlug={(next) => {
						setSlug(next);
						setSlugTouched(true);
					}}
					slugHint={slugTouched ? __('The folder name of the declaration.', 'proto-blocks') : __('Filled in from the name.', 'proto-blocks')}
					effective={{}}
					fallbackTypes={[]}
					types={types}
					disabled={readOnly}
				/>
			</form>
			<SaveDialogs
				path={`proto-taxonomies/${slug}/taxonomy.jsonc`}
				conflict={conflict}
				guardrails={guardrails}
				onReload={() => {
					setConflict(false);
					void create([]);
				}}
				onKeepEditing={() => setConflict(false)}
				onConfirm={(acknowledge) => {
					setGuardrails(null);
					void create(acknowledge);
				}}
				onGoBack={() => setGuardrails(null)}
			/>
		</DeclarationShell>
	);
}

function EditForm({
	slug,
	detail,
	types,
	onBack,
}: {
	slug: string;
	detail: TaxonomyDetail;
	types: ListItem[];
	onBack: () => void;
}) {
	const { fileModsAllowed } = getBootstrap();
	const { file, loading, error, save, remove, reload } = useDeclarationFile('taxonomy', slug);
	const { item } = detail;
	const defaults = useMemo(() => pick(file?.data ?? {}), [file?.text, file?.hash]); // eslint-disable-line react-hooks/exhaustive-deps
	const form = useForm<Values>({ defaultValues: defaults, resolver: useMemo(() => makeResolver(file !== null && 'name' in file.data), [file]) });
	const [saving, setSaving] = useState(false);
	const [failure, setFailure] = useState<string | null>(null);
	const [conflict, setConflict] = useState(false);
	const [guardrails, setGuardrails] = useState<Guardrail[] | null>(null);
	const [deleting, setDeleting] = useState(false);
	const [deleteContent, setDeleteContent] = useState<{ count: number; taxonomies?: Record<string, number> } | null>(null);
	const pending = useRef<Record<string, unknown> | null>(null);
	const formId = useId();

	useEffect(() => {
		form.reset(defaults);
	}, [defaults]); // eslint-disable-line react-hooks/exhaustive-deps

	if (error) {
		return <ScreenError screen={__('Taxonomies', 'proto-blocks')} error={error} onRetry={reload} />;
	}
	if (!file || loading) {
		return <Skeleton className="h-64 w-full" />;
	}

	const overriding = !file.exists && item.origin !== 'file' && item.origin !== 'php';
	const itemReason: Reason = item.readOnlyReason === 'not_proto' ? null : item.readOnlyReason;
	const reason: Reason = !fileModsAllowed ? 'file_mods' : (itemReason ?? (file.writable ? null : ((file.reason as Reason) ?? 'not_writable')));
	const readOnly = reason !== null;

	const changed = (): Record<string, unknown> => {
		const values = form.getValues();
		const next: Record<string, unknown> = { ...file.data };
		for (const key of FIELDS) {
			const now = isObject(values[key]) ? clean(values[key] as Values) : values[key] === '' ? undefined : values[key];
			const before = defaults[key] === '' ? undefined : defaults[key];
			if (JSON.stringify(now) === JSON.stringify(before)) {
				continue;
			}
			if (now === undefined) {
				delete next[key];
			} else {
				next[key] = now;
			}
		}
		return next;
	};

	const run = async (next: Record<string, unknown>, acknowledge: string[]) => {
		setSaving(true);
		setFailure(null);
		try {
			const outcome = await save(next, acknowledge);
			if (outcome.status === 'conflict') {
				setConflict(true);
			} else if (outcome.status === 'confirm') {
				pending.current = next;
				setGuardrails(outcome.guardrails);
			}
		} catch (e) {
			setFailure(errorText(e));
		} finally {
			setSaving(false);
		}
	};

	const onSubmit = form.handleSubmit(() => run(changed(), []));

	const doDelete = async (confirm?: string) => {
		try {
			const outcome = await remove(confirm);
			if (outcome.status === 'confirm') {
				setDeleteContent(outcome.content);
				return;
			}
			setDeleting(false);
			toast.success(__('Declaration deleted.', 'proto-blocks'));
			onBack();
		} catch (e) {
			setDeleting(false);
			setFailure(errorText(e));
		}
	};

	const count = detail.content.count;
	const termsText = sprintf(/* translators: %d: number of terms. */ _n('This taxonomy has %d term.', 'This taxonomy has %d terms.', count, 'proto-blocks'), count);
	const slugHint =
		count > 0
			? interpolate(
					/* translators: 1: text "This taxonomy has N terms.", 2: a WP-CLI command. */
					__('%1$s Rename it with %2$s.', 'proto-blocks'),
					termsText,
					code(`wp proto-blocks taxonomy rename ${slug} <new> --migrate`)
				)
			: __('The folder name of the declaration. It can be renamed with WP-CLI.', 'proto-blocks');

	return (
		<DeclarationShell
			title={String(file.data.name ?? item.label)}
			readOnlyReason={reason}
			path={file.path}
			actions={
				<>
					<BackButton onBack={onBack} />
					{file.exists ? (
						<Button type="button" variant="outline" disabled={readOnly} onClick={() => setDeleting(true)}>
							<TrashIcon aria-hidden="true" />
							{__('Delete…', 'proto-blocks')}
						</Button>
					) : null}
					<Button type="submit" form={formId} disabled={readOnly || saving}>
						{overriding ? __('Create override file', 'proto-blocks') : __('Save to file', 'proto-blocks')}
					</Button>
				</>
			}
		>
			<div className="flex flex-wrap items-center gap-2">
				<OriginBadge origin={item.origin} label={item.originLabel} double={item.double} />
				{item.origin === 'php' && !file.exists && item.source ? (
					<span className="text-[13px] text-muted-foreground">
						{interpolate(/* translators: %s: PHP file that declares the taxonomy. */ __('Declared in PHP at %s. Saving writes a file that takes over.', 'proto-blocks'), code(item.source))}
					</span>
				) : null}
			</div>
			{reason === 'outside_root' ? (
				<Alert variant="warning" role="status">
					<AlertDescription>{__("This declaration lives outside the theme's schema folders, so it can't be edited here.", 'proto-blocks')}</AlertDescription>
				</Alert>
			) : null}
			<form id={formId} className="grid gap-6" noValidate onSubmit={onSubmit}>
				{failure ? (
					<Alert variant="destructive" role="alert">
						<AlertDescription>{failure}</AlertDescription>
					</Alert>
				) : null}
				<Fields
					form={form}
					mode="edit"
					slug={slug}
					slugHint={slugHint}
					effective={detail.effective}
					fallbackTypes={detail.objectTypes}
					types={types}
					disabled={readOnly}
				/>
			</form>
			<SaveDialogs
				path={file.path}
				conflict={conflict}
				guardrails={guardrails}
				onReload={() => {
					setConflict(false);
					reload();
				}}
				onKeepEditing={() => setConflict(false)}
				onConfirm={(acknowledge) => {
					setGuardrails(null);
					void run(pending.current ?? changed(), acknowledge);
				}}
				onGoBack={() => setGuardrails(null)}
			/>
			<DeleteDeclarationDialog
				open={deleting}
				kind="taxonomy"
				slug={slug}
				label={String(file.data.name ?? item.label)}
				path={file.path}
				content={deleteContent ?? { count }}
				onConfirm={(confirm) => void doDelete(confirm)}
				onCancel={() => {
					setDeleting(false);
					setDeleteContent(null);
				}}
			/>
		</DeclarationShell>
	);
}

/** Edit screen for one taxonomy. An inline one is edited in its type's file, so it links there. */
function EditTaxonomy({ slug, types, onBack }: { slug: string; types: ListItem[]; onBack: () => void }) {
	const detail = useAsync(() => adminGet<TaxonomyDetail>(`admin/taxonomies/${slug}`), [slug]);

	if (detail.error) {
		return <ScreenError screen={__('Taxonomies', 'proto-blocks')} error={detail.error} onRetry={detail.reload} />;
	}
	if (!detail.data) {
		return <Skeleton className="h-64 w-full" />;
	}

	const parent = inlineParent(detail.data.item.source);
	if (parent) {
		return (
			<DeclarationShell
				title={detail.data.item.label}
				readOnlyReason={null}
				path={detail.data.item.source ?? ''}
				actions={
					<>
						<BackButton onBack={onBack} />
						<EditInPostTypeLink type={parent} />
					</>
				}
			>
				<Alert role="status">
					<AlertDescription>
						{interpolate(
							/* translators: %s: slug of the post type whose file declares the taxonomy. */
							__('This taxonomy is declared inside the %s post type file, so it is edited there.', 'proto-blocks'),
							code(parent)
						)}
					</AlertDescription>
				</Alert>
			</DeclarationShell>
		);
	}

	return <EditForm slug={slug} detail={detail.data} types={types} onBack={onBack} />;
}

/** `slug` null creates a taxonomy. */
export function TaxonomyForm({ slug, types, onBack, onOpen }: { slug: string | null; types: ListItem[]; onBack: () => void; onOpen: (slug: string) => void }) {
	return slug === null ? <CreateTaxonomy types={types} onBack={onBack} onOpen={onOpen} /> : <EditTaxonomy key={slug} slug={slug} types={types} onBack={onBack} />;
}
