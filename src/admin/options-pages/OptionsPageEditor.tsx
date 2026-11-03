import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeftIcon, ArrowRightIcon, FilePlusIcon, SaveIcon, Trash2Icon } from 'lucide-react';
import { toast } from 'sonner';
import { adminGet, ApiError } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import { ConflictDialog } from '@/admin/components/content-model/ConflictDialog';
import { CommitNotice, FormPanel, FormSection, JsoncView, useSaveShortcut, ViewToggle, type EditorView } from '@/admin/components/content-model/Layout';
import { DeclarationShell } from '@/admin/components/content-model/DeclarationShell';
import { GuardrailDialog } from '@/admin/components/content-model/GuardrailDialog';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner, READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { applyDeclarationEdits, parseJsonc } from '@/admin/content-model/jsonc';
import { interpolate } from '@/admin/lib/interpolate';
import { optionsSchema } from '@/admin/content-model/schema';
import type { FileResult, Guardrail } from '@/admin/content-model/types';
import { useDeclarationFile } from '@/admin/content-model/useDeclarationFile';
import { CapabilityField, capabilityError } from './CapabilityField';
import { ChildPagesEditor, childProblems } from './ChildPagesEditor';
import {
	dataFromForm,
	formFromData,
	PAGE_SLUG_PATTERN,
	readOnlyReason,
	slugFromTitle,
	type OptionsDetail,
	type OptionsPageForm,
} from './declaration';
import { MenuPlacementField } from './MenuPlacementField';
import { FieldsSection } from './PageFieldsBuilder';
import { optionError, StorageField } from './StorageField';
import { TabsEditor } from './TabsEditor';

type Data = Record<string, unknown>;

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
	return (
		<FormSection title={title} description={description}>
			{children}
		</FormSection>
	);
}

/** The first named field of the page (bare fields or the first group's), for the storage example. */
function firstFieldName(fields: unknown[]): string | undefined {
	for (const item of fields) {
		if (typeof item === 'object' && item !== null) {
			const entry = item as Data;
			if (typeof entry.name === 'string' && entry.name !== '') return entry.name;
			if (Array.isArray(entry.fields)) {
				const inner = firstFieldName(entry.fields);
				if (inner) return inner;
			}
		}
	}
	return undefined;
}

/** Every problem that blocks Save, client side. */
export function formProblems(form: OptionsPageForm, next: Data): string[] {
	const out: string[] = [];
	if (form.title.trim() === '') out.push(__('Enter a title.', 'proto-blocks'));
	const cap = capabilityError(form.capability);
	if (cap) out.push(cap);
	const option = optionError(form.storage);
	if (option) out.push(option);
	if (form.tabs.some((tab) => tab.title.trim() === '')) out.push(__('Every tab needs a title.', 'proto-blocks'));
	out.push(...childProblems(form.children));
	if (out.length === 0) {
		const result = optionsSchema.safeParse(next);
		if (!result.success) {
			out.push(...result.error.issues.map((issue) => `/${issue.path.join('/')}: ${issue.message}`));
		}
	}
	return out;
}

interface FormProps {
	slug: string;
	file: FileResult;
	detail: OptionsDetail | null;
	/** Capabilities from the index payload, for a page with no detail yet. */
	fallbackCapabilities: string[];
	save: ReturnType<typeof useDeclarationFile>['save'];
	remove: ReturnType<typeof useDeclarationFile>['remove'];
	reload: () => Promise<void>;
	onSaved: () => void;
	onDeleted: () => void;
	onBack: () => void;
	/** New page: the title and slug inputs sit above the form. */
	header?: ReactNode;
}

function PageForm({ slug, file, detail, fallbackCapabilities, save, remove, reload, onSaved, onDeleted, onBack, header }: FormProps) {
	const row = detail?.page ?? null;
	const php = row?.origin === 'php';
	// A PHP page's form shows its declaration; the file (a template) does not exist yet.
	const base: Data = useMemo(() => (php ? { ...file.data, ...(detail?.declaration ?? {}) } : file.data), [php, file.data, detail]);
	const [form, setForm] = useState<OptionsPageForm>(() => formFromData(base));
	const [saving, setSaving] = useState(false);
	const [conflict, setConflict] = useState(false);
	const [pending, setPending] = useState<{ next: Data; guardrails: Guardrail[] } | null>(null);
	const [serverErrors, setServerErrors] = useState<{ path: string; message: string }[]>([]);
	const [deleting, setDeleting] = useState(false);
	const [view, setView] = useState<EditorView>('editor');
	const savedStorage = file.exists ? formFromData(file.data).storage : null;

	const writeReason = getWriteReason();
	const reason = writeReason ?? readOnlyReason(php ? null : row?.readOnlyReason) ?? (file.writable ? null : 'not_writable');
	const readOnly = reason !== null || php;
	const createBlocked = writeReason !== null || (row?.readOnlyReason ?? null) !== null;

	const next = useMemo(() => dataFromForm(base, form), [base, form]);
	const problems = useMemo(() => formProblems(form, next), [form, next]);
	const preview = useMemo(() => {
		try {
			return applyDeclarationEdits(file.text, parseJsonc(file.text).data, next);
		} catch {
			return file.text;
		}
	}, [file.text, next]);

	const patch = (changes: Partial<OptionsPageForm>) => setForm((current) => ({ ...current, ...changes }));

	const write = async (data: Data, acknowledge: string[] = []) => {
		setSaving(true);
		try {
			const outcome = await save(data, acknowledge);
			if (outcome.status === 'conflict') {
				setConflict(true);
			} else if (outcome.status === 'confirm') {
				setPending({ next: data, guardrails: outcome.guardrails });
			} else {
				setServerErrors([]);
				onSaved();
			}
		} catch (e) {
			const error = e instanceof ApiError ? e : new ApiError('unknown_error', String(e), 0);
			const errors = Array.isArray(error.data.errors) ? (error.data.errors as { path?: unknown; message?: unknown }[]) : [];
			setServerErrors(
				errors.length > 0 ? errors.map((err) => ({ path: String(err.path ?? ''), message: String(err.message ?? '') })) : [{ path: '', message: error.message }]
			);
		} finally {
			setSaving(false);
		}
	};

	useSaveShortcut(readOnly || saving || problems.length > 0 ? null : () => void write(next));

	const childErrors: Record<number, string> = {};
	for (const err of serverErrors) {
		const match = /^\/children\/(\d+)\/slug$/.exec(err.path);
		if (match) childErrors[Number(match[1])] = err.message;
	}

	// After a failed save, focus the first child slug the server refused, else the error summary.
	useEffect(() => {
		if (serverErrors.length === 0) return;
		const child = Object.keys(childErrors)[0];
		const target = document.getElementById(child !== undefined ? `pb-op-child-${child}-slug` : 'pb-op-save-errors');
		target?.focus();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [serverErrors]);

	const confirmDelete = async () => {
		try {
			await remove();
			setDeleting(false);
			toast.success(__('Options page deleted.', 'proto-blocks'));
			onDeleted();
		} catch (e) {
			setDeleting(false);
			toast.error(e instanceof Error ? e.message : String(e));
		}
	};

	const folder = `proto-options/${slug}/`;
	const title = form.title || row?.title || __('New options page', 'proto-blocks');

	return (
		<DeclarationShell
			title={title}
			readOnlyReason={reason === 'php' || reason === 'unknown' ? null : reason}
			path={file.path}
			actions={
				<>
					<ViewToggle view={view} onChange={setView} />
					{row ? <OriginBadge origin={row.origin} label={row.originLabel} double={row.double} /> : null}
					{row?.url && file.exists ? (
						<Button variant="outline" asChild>
							<a href={row.url}>{__('Open page', 'proto-blocks')}</a>
						</Button>
					) : null}
				</>
			}
			back={{ label: __('All options pages', 'proto-blocks'), onClick: onBack }}
		>
			{header}
			{reason === 'unknown' ? (
				<Alert>
					<AlertTitle>{__("This page can't be edited here.", 'proto-blocks')}</AlertTitle>
					<AlertDescription>{__('Edit it where it is declared.', 'proto-blocks')}</AlertDescription>
				</Alert>
			) : null}
			{php ? (
				<Alert>
					<AlertTitle>
						{sprintf(
							/* translators: %s: PHP file and line */
							__('Declared in PHP at %s. Saving creates a file that takes over.', 'proto-blocks'),
							row?.source ?? ''
						)}
					</AlertTitle>
					<AlertDescription>
						<Button type="button" className="mt-2" disabled={createBlocked || saving} aria-describedby={createBlocked ? READ_ONLY_BANNER_ID : undefined} onClick={() => void write({ ...file.data, ...(detail?.declaration ?? {}) })}>
							<FilePlusIcon aria-hidden="true" />
							{__('Create file', 'proto-blocks')}
						</Button>
					</AlertDescription>
				</Alert>
			) : null}
			{writeReason !== null && php ? <ReadOnlyBanner reason={writeReason} /> : null}
			{(row?.warnings ?? []).map((warning) => (
				<Alert key={warning} variant="warning">
					<AlertDescription>{warning}</AlertDescription>
				</Alert>
			))}
			{serverErrors.length > 0 ? (
				<Alert id="pb-op-save-errors" tabIndex={-1} variant="destructive" role="alert">
					<AlertTitle>{__('Nothing was saved.', 'proto-blocks')}</AlertTitle>
					<AlertDescription>
						<ul className="list-disc pl-4">
							{serverErrors.map((err, i) => (
								<li key={i}>{err.message}</li>
							))}
						</ul>
					</AlertDescription>
				</Alert>
			) : null}

			{view === 'editor' ? <CommitNotice /> : null}
			<div className="grid gap-6">
				<form
					className="grid min-w-0 gap-6"
					onSubmit={(event) => {
						event.preventDefault();
						if (!readOnly && problems.length === 0) {
							void write(next);
						}
					}}
				>
					{view === 'jsonc' ? (
						<JsoncView path={file.path} text={preview} label={__('File text that Save writes', 'proto-blocks')} />
					) : null}
					<div hidden={view === 'jsonc'} className={view === 'jsonc' ? 'hidden' : 'grid gap-6'}>
					<FormPanel>
					<Section title={__('Page', 'proto-blocks')}>
						<div className="grid content-start gap-1.5">
							<Label htmlFor="pb-op-title">{__('Title', 'proto-blocks')}</Label>
							<Input id="pb-op-title" value={form.title} disabled={readOnly} onChange={(e) => patch({ title: e.target.value })} />
						</div>
						<MenuPlacementField value={form} parents={detail?.parents ?? []} exclude={row?.children ?? []} disabled={readOnly} onChange={patch} />
					</Section>
					<Section title={__('Access & storage', 'proto-blocks')}>
						<CapabilityField
							id="pb-op-capability"
							label={__('Who can use this page', 'proto-blocks')}
							value={form.capability}
							capabilities={detail?.capabilities ?? fallbackCapabilities}
							disabled={readOnly}
							onChange={(capability) => patch({ capability })}
						/>
						<StorageField slug={slug} firstField={firstFieldName(form.fields)} storage={form.storage} saved={savedStorage} autoload={form.autoload} disabled={readOnly} onChange={patch} />
					</Section>
					</FormPanel>
					<FieldsSection fields={form.fields} readOnly={readOnly} hint={__('Fields shown on the page, above any tabs.', 'proto-blocks')} onChange={(fields) => patch({ fields })} />
					<FormPanel>
					<Section title={__('Tabs', 'proto-blocks')} description={__('Tabs split one page. Each holds its own fields.', 'proto-blocks')}>
						<TabsEditor tabs={form.tabs} disabled={readOnly} onChange={(tabs) => patch({ tabs })} />
					</Section>
					<Section
						title={__('Child pages', 'proto-blocks')}
						/* translators: %s: page title */
						description={sprintf(__('Separate submenu pages under %s.', 'proto-blocks'), title)}
					>
						<ChildPagesEditor
							parentSlug={slug}
							children={form.children}
							capabilities={detail?.capabilities ?? fallbackCapabilities}
							disabled={readOnly}
							errors={childErrors}
							onChange={(children) => patch({ children })}
						/>
					</Section>
					</FormPanel>
					</div>
					<div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-5 py-3 shadow-sm">
						<p className="min-w-0 text-label break-all" aria-live="polite">
							{problems.length > 0
								? problems[0]
								: sprintf(/* translators: %s: path of the declaration file */ __('Saves to %s', 'proto-blocks'), file.path)}
						</p>
						<div className="flex gap-2">
							{file.exists && !readOnly ? (
								<Button type="button" variant="outline" onClick={() => setDeleting(true)}>
									<Trash2Icon aria-hidden="true" />
									{__('Delete', 'proto-blocks')}
								</Button>
							) : null}
							<Button type="submit" disabled={readOnly || saving || problems.length > 0} aria-describedby={readOnly ? READ_ONLY_BANNER_ID : undefined}>
								<SaveIcon aria-hidden="true" />
								{__('Save', 'proto-blocks')}
							</Button>
						</div>
					</div>
				</form>
			</div>

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
			{pending ? (
				<GuardrailDialog
					guardrails={pending.guardrails}
					onCancel={() => setPending(null)}
					onConfirm={(acknowledge) => {
						const data = pending.next;
						setPending(null);
						void write(data, acknowledge);
					}}
				/>
			) : null}
			<ConfirmDialog
				open={deleting}
				/* translators: %s: options page title */
				title={sprintf(__('Delete %s?', 'proto-blocks'), title)}
				description={sprintf(
					/* translators: %s: folder of the declaration file */
					__('Removes %s from the theme. Saved values are not deleted.', 'proto-blocks'),
					folder
				)}
				confirmLabel={__('Delete', 'proto-blocks')}
				destructive
				onCancel={() => setDeleting(false)}
				onConfirm={() => void confirmDelete()}
			/>
		</DeclarationShell>
	);
}

function EditSkeleton() {
	return (
		<div aria-busy="true" className="grid gap-6">
			<Skeleton className="h-10 w-64" />
			<Skeleton className="h-48" />
		</div>
	);
}

function FileForm({ slug, name, detail, fallbackCapabilities = [], onSaved, onBack, header }: { slug: string; name?: string; detail: OptionsDetail | null; fallbackCapabilities?: string[]; onSaved: () => void; onBack: () => void; header?: ReactNode }) {
	const { file, error, save, remove, reload } = useDeclarationFile('options', slug, name);
	if (error && !file) {
		return <ScreenError screen={slug} error={error} onRetry={() => void reload()} />;
	}
	if (!file) {
		return <EditSkeleton />;
	}
	return (
		<PageForm
			key={file.hash ?? 'new'}
			slug={slug}
			file={file}
			detail={detail}
			fallbackCapabilities={fallbackCapabilities}
			save={save}
			remove={remove}
			reload={reload}
			onSaved={onSaved}
			onDeleted={onBack}
			onBack={onBack}
			header={header}
		/>
	);
}

/** Edit one options page: GET admin/options-pages/<slug>, then its page.jsonc. */
export function OptionsPageEditor({ slug, onBack }: { slug: string; onBack: () => void }) {
	const [detail, setDetail] = useState<OptionsDetail | null>(null);
	const [error, setError] = useState<unknown>(null);
	const request = useRef(0);

	const load = useCallback(async () => {
		const id = ++request.current;
		try {
			const result = await adminGet<OptionsDetail>(`admin/options-pages/${slug}`);
			if (id === request.current) {
				setDetail(result);
				setError(null);
			}
		} catch (e) {
			if (id === request.current) setError(e);
		}
	}, [slug]);

	useEffect(() => {
		void load();
	}, [load]);

	if (error && !detail) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				<PageHeader
					title={__('Options pages', 'proto-blocks')}
					actions={
						<Button type="button" variant="ghost" onClick={onBack}>
							<ArrowLeftIcon aria-hidden="true" />
							{__('All options pages', 'proto-blocks')}
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
	return <FileForm slug={slug} name={detail.page.origin === 'php' ? detail.page.title : undefined} detail={detail} onSaved={() => void load()} onBack={onBack} />;
}

/** `?new=1`: a title proposes the slug; Continue loads the server template for it, which the form then edits. */
export function NewOptionsPage({ taken, capabilities = [], onCreated, onBack }: { taken: string[]; capabilities?: string[]; onCreated: (slug: string) => void; onBack: () => void }) {
	const [title, setTitle] = useState('');
	const [slug, setSlug] = useState('');
	const [slugEdited, setSlugEdited] = useState(false);
	const [chosen, setChosen] = useState<{ slug: string; title: string } | null>(null);
	const effective = slugEdited ? slug : slugFromTitle(title);
	const slugError = effective === ''
		? null
		: !PAGE_SLUG_PATTERN.test(effective)
			? __('Use lowercase letters, digits and hyphens.', 'proto-blocks')
			: taken.includes(effective)
				? __('An options page with this slug already exists.', 'proto-blocks')
				: null;

	if (chosen) {
		return <FileForm slug={chosen.slug} name={chosen.title} detail={null} fallbackCapabilities={capabilities} onSaved={() => onCreated(chosen.slug)} onBack={onBack} />;
	}

	return (
		<div className="grid gap-8 [&>header]:mb-0">
			<PageHeader title={__('New options page', 'proto-blocks')} back={{ label: __('All options pages', 'proto-blocks'), onClick: onBack }} />
			<div className="mx-auto grid w-full max-w-[640px] gap-5 rounded-2xl border bg-card p-8">
				<ol aria-label={__('Steps', 'proto-blocks')} className="flex flex-wrap items-center gap-3 text-caption text-muted-foreground">
					{[__('Name', 'proto-blocks'), __('Menu & access', 'proto-blocks'), __('Fields', 'proto-blocks')].map((step, i) => (
						<li key={step} className="flex items-center gap-3" aria-current={i === 0 ? 'step' : undefined}>
							{i > 0 ? <span aria-hidden="true" className="h-px w-6 bg-border" /> : null}
							<span className={i === 0 ? 'inline-flex items-center gap-1.5 font-medium text-primary' : 'inline-flex items-center gap-1.5'}>
								<span
									aria-hidden="true"
									className={
										i === 0
											? 'grid size-5 place-items-center rounded-full bg-primary text-[11px] text-primary-foreground'
											: 'grid size-5 place-items-center rounded-full border border-input text-[11px]'
									}
								>
									{i + 1}
								</span>
								{step}
							</span>
						</li>
					))}
				</ol>
				<form
					className="grid gap-5"
					onSubmit={(event) => {
						event.preventDefault();
						if (title.trim() !== '' && effective !== '' && !slugError) setChosen({ slug: effective, title: title.trim() });
					}}
				>
					<div className="grid content-start gap-1.5">
						<Label htmlFor="pb-op-new-title">{__('Page title', 'proto-blocks')}</Label>
						<Input id="pb-op-new-title" className="h-10 text-section" value={title} aria-describedby="pb-op-new-title-hint" onChange={(e) => setTitle(e.target.value)} />
						<p id="pb-op-new-title-hint" className="text-label text-muted-foreground">
							{__('Shown in the admin menu and at the top of the page.', 'proto-blocks')}
						</p>
					</div>
					<div className="grid content-start gap-1.5">
						<Label htmlFor="pb-op-new-slug">
							{__('Slug', 'proto-blocks')}
							<span aria-hidden="true" className="font-normal text-muted-foreground">
								{__('· generated', 'proto-blocks')}
							</span>
						</Label>
						<Input
							id="pb-op-new-slug"
							className="font-mono text-label"
							value={effective}
							aria-invalid={slugError ? true : undefined}
							aria-describedby="pb-op-new-slug-hint"
							onChange={(e) => {
								setSlugEdited(true);
								setSlug(e.target.value);
							}}
						/>
						<p id="pb-op-new-slug-hint" role={slugError ? 'alert' : undefined} className={slugError ? 'text-label text-destructive' : 'text-label text-muted-foreground'}>
							{slugError ??
								interpolate(
									/* translators: %s: path of the declaration file that will be written. */
									__('Writes %s', 'proto-blocks'),
									<code className="font-mono text-micro text-link">{`proto-options/${effective || '…'}/page.jsonc`}</code>
								)}
						</p>
					</div>
					<div className="flex justify-end gap-2 pt-1">
						<Button type="button" variant="ghost" onClick={onBack}>
							{__('Cancel', 'proto-blocks')}
						</Button>
						<Button type="submit" disabled={title.trim() === '' || effective === '' || slugError !== null}>
							{__('Continue', 'proto-blocks')}
							<ArrowRightIcon aria-hidden="true" />
						</Button>
					</div>
				</form>
			</div>
		</div>
	);
}
