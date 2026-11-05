import { useCallback, useEffect, useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { SaveIcon, Trash2Icon } from 'lucide-react';
import { toast } from 'sonner';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { FormPanel, FormSection } from '@/admin/components/content-model/Layout';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { Panel } from '@/admin/components/shell/Panel';
import { READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { CodeEditor } from './CodeEditor';
import { formFromData, newScriptText, scriptText, type ScriptForm } from './edits';
import { PlacementBuilder } from './PlacementBuilder';
import { scriptErrors, SLUG_PATTERN, slugFromTitle, snippetError } from './schema';
import { ScriptsReadOnlyBanner } from './ScriptsReadOnlyBanner';
import { LOCATIONS, SNIPPET_FILES, type Location, type SavePayload, type SaveResponse, type ScriptDetail, type ScriptsWriteReason } from './types';

const EMPTY_SNIPPETS: Record<Location, string> = { head: '', bodyOpen: '', bodyClose: '' };

const LOCATION_LABELS: Record<Location, string> = {
	head: __('In <head>', 'proto-blocks'),
	bodyOpen: __('Right after <body>', 'proto-blocks'),
	bodyClose: __('Before </body>', 'proto-blocks'),
};

type Errors = Partial<Record<'title' | 'slug' | 'priority' | 'where' | Location | 'file', string>>;

/**
 * The checks run before a save (the server repeats every one): title, slug,
 * priority, snippets (no PHP, 256 KB), and the schema on the text that would
 * be written.
 */
export function validateForm(form: ScriptForm, text: string, slug: string | null): Errors {
	const errors: Errors = {};
	if (form.title.trim() === '') {
		errors.title = __('A title is required.', 'proto-blocks');
	} else if (/[<>]/.test(form.title)) {
		errors.title = __('The title cannot contain "<" or ">".', 'proto-blocks');
	}
	if (slug !== null && !SLUG_PATTERN.test(slug)) {
		errors.slug = __('Start with a lowercase letter and use only a-z, 0-9, "_" and "-" (32 characters at most).', 'proto-blocks');
	}
	if (!Number.isInteger(form.priority) || Math.abs(form.priority) > 100000) {
		errors.priority = __('The priority is a whole number between -100000 and 100000.', 'proto-blocks');
	}
	for (const location of LOCATIONS) {
		const problem = snippetError(form.snippets[location]);
		if (problem) {
			errors[location] = problem;
		}
	}
	const parsed = parseJsonc(text);
	if (parsed.errors.length > 0) {
		errors.file = __('script.jsonc cannot be read; fix it in the theme first.', 'proto-blocks');
	} else {
		for (const error of scriptErrors(parsed.data)) {
			if (error.path.startsWith('/where')) {
				errors.where ??= error.message;
			} else if (error.path === '/title') {
				errors.title ??= error.message;
			} else if (error.path === '/priority') {
				errors.priority ??= error.message;
			} else {
				errors.file ??= `${error.path}: ${error.message}`;
			}
		}
	}
	return errors;
}

/** The POST admin/scripts/<slug> body: script.jsonc edited in place plus every snippet. */
export function savePayload(detail: Pick<ScriptDetail, 'text' | 'hash' | 'data'> | null, form: ScriptForm): SavePayload {
	const base = detail ?? { text: newScriptText(form.title), hash: null, data: parseJsonc(newScriptText(form.title)).data };
	return {
		text: scriptText(base.text, base.data, form),
		hash: base.hash,
		snippets: { ...form.snippets },
	};
}

/**
 * Add (slug null) or edit one script: title, enabled, priority, the three
 * snippet editors and the placement builder. One save writes script.jsonc
 * and the three snippet files (POST admin/scripts/<slug>).
 */
export function ScriptEditor({
	slug,
	roles,
	writeReason,
	onBack,
	onSaved,
}: {
	slug: string | null;
	roles: { slug: string; name: string }[];
	writeReason: ScriptsWriteReason;
	onBack: () => void;
	onSaved: (slug: string) => void;
}) {
	const creating = slug === null;
	const [detail, setDetail] = useState<ScriptDetail | null>(null);
	const [loadError, setLoadError] = useState<unknown>(null);
	const [form, setForm] = useState<ScriptForm | null>(
		creating ? formFromData({ title: '', enabled: true, priority: 10 }, EMPTY_SNIPPETS) : null
	);
	const [newSlug, setNewSlug] = useState('');
	const [slugTouched, setSlugTouched] = useState(false);
	const [errors, setErrors] = useState<Errors>({});
	const [saving, setSaving] = useState(false);
	const [confirmDelete, setConfirmDelete] = useState(false);

	const load = useCallback(async () => {
		if (slug === null) {
			return;
		}
		try {
			const next = await adminGet<ScriptDetail>(`admin/scripts/${slug}`);
			setDetail(next);
			setForm(formFromData(next.data, next.snippets));
			setLoadError(null);
		} catch (e) {
			setLoadError(e);
		}
	}, [slug]);

	useEffect(() => {
		void load();
	}, [load]);

	const phpOnly = !creating && detail !== null && !detail.exists && detail.item?.origin === 'php';
	const reason: ScriptsWriteReason | 'not_writable' | null = creating
		? writeReason
		: detail === null
			? null
			: (detail.writeReason ?? (detail.writable ? null : 'not_writable'));
	const readOnly = reason !== null || phpOnly;
	const targetSlug = creating ? (slugTouched ? newSlug : slugFromTitle(form?.title ?? '')) : slug;

	const title = creating
		? __('New script', 'proto-blocks')
		: (detail?.item?.title ?? form?.title ?? slug ?? '');
	const header = <PageHeader title={title} back={{ label: __('All scripts', 'proto-blocks'), onClick: onBack }} />;

	const payload = useMemo(() => (form ? savePayload(creating ? null : detail, form) : null), [creating, detail, form]);

	if (loadError) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header}
				<ScreenError screen={__('Scripts', 'proto-blocks')} error={loadError} onRetry={() => void load()} />
			</div>
		);
	}
	if (!form || !payload || (!creating && !detail)) {
		return (
			<div className="grid gap-6 [&>header]:mb-0" aria-busy="true">
				{header}
				<Panel className="grid gap-3 p-4">
					<Skeleton className="h-8 w-full" />
					<Skeleton className="h-40 w-full" />
				</Panel>
			</div>
		);
	}

	const update = (patch: Partial<ScriptForm>) => setForm({ ...form, ...patch });

	const save = async () => {
		const found = validateForm(form, payload.text, creating ? targetSlug : null);
		setErrors(found);
		if (Object.keys(found).length > 0 || targetSlug === null) {
			toast.error(__('Fix the highlighted fields before saving.', 'proto-blocks'));
			return;
		}
		setSaving(true);
		try {
			const saved = await adminPost<SaveResponse>(`admin/scripts/${targetSlug}`, payload);
			toast.success(
				saved.warnings.length > 0
					? sprintf(/* translators: %d: number of warnings */ __('Saved, with %d warning(s).', 'proto-blocks'), saved.warnings.length)
					: __('Script saved.', 'proto-blocks')
			);
			if (creating) {
				onSaved(targetSlug);
			} else {
				await load();
			}
		} catch (e) {
			const error = e instanceof ApiError ? e : null;
			if (error?.code === 'proto_blocks_conflict') {
				toast.error(
					creating
						? __('A script with this slug already exists. Choose another slug.', 'proto-blocks')
						: __('The file changed on disk since it was loaded. Reload it and apply your changes again.', 'proto-blocks')
				);
			} else {
				toast.error(error?.message ?? __('The script could not be saved.', 'proto-blocks'));
			}
		} finally {
			setSaving(false);
		}
	};

	const remove = async () => {
		setConfirmDelete(false);
		if (slug === null) {
			return;
		}
		try {
			await adminDelete(`admin/schema-files/script/${slug}?confirm=${encodeURIComponent(slug)}`);
			toast.success(__('Script deleted.', 'proto-blocks'));
			onBack();
		} catch (e) {
			toast.error(e instanceof ApiError ? e.message : __('The script could not be deleted.', 'proto-blocks'));
		}
	};

	const describedBy = readOnly ? READ_ONLY_BANNER_ID : undefined;

	return (
		<div className="grid gap-6 [&>header]:mb-0">
			<PageHeader
				title={title}
				back={{ label: __('All scripts', 'proto-blocks'), onClick: onBack }}
				actions={
					<>
						{!creating && detail?.exists && !readOnly ? (
							<Button variant="outline" onClick={() => setConfirmDelete(true)}>
								<Trash2Icon aria-hidden="true" />
								{__('Delete', 'proto-blocks')}
							</Button>
						) : null}
						<Button onClick={() => void save()} disabled={readOnly || saving} aria-describedby={describedBy}>
							<SaveIcon aria-hidden="true" />
							{saving ? __('Saving…', 'proto-blocks') : __('Save', 'proto-blocks')}
						</Button>
					</>
				}
			/>
			{reason !== null ? <ScriptsReadOnlyBanner reason={reason} path={detail?.path} /> : null}
			{phpOnly && detail?.item ? (
				<p className="text-label text-muted-foreground">
					{sprintf(
						/* translators: %s: PHP file and line */
						__('Declared in PHP at %s. Change it in the theme code.', 'proto-blocks'),
						detail.item.source
					)}
				</p>
			) : null}
			{detail?.item ? (
				<div className="flex flex-wrap items-center gap-3">
					<OriginBadge origin={detail.item.origin} label={detail.item.originLabel} double={detail.item.double} />
					<code className="font-mono text-micro text-muted-foreground">{detail.path}</code>
				</div>
			) : null}
			<IssueList issues={detail?.item?.issues ?? []} />
			{errors.file ? (
				<p role="alert" className="text-label text-destructive">
					{errors.file}
				</p>
			) : null}
			<FormPanel>
				<FormSection title={__('Script', 'proto-blocks')} description={__('Lower priorities print first at each location.', 'proto-blocks')}>
					<div className="grid gap-1.5">
						<Label htmlFor="pb-script-title">{__('Title', 'proto-blocks')}</Label>
						<Input
							id="pb-script-title"
							value={form.title}
							readOnly={readOnly}
							aria-invalid={errors.title ? true : undefined}
							aria-describedby={errors.title ? 'pb-script-title-error' : describedBy}
							onChange={(event) => update({ title: event.target.value })}
						/>
						{errors.title ? (
							<p id="pb-script-title-error" className="text-caption text-destructive">
								{errors.title}
							</p>
						) : null}
					</div>
					{creating ? (
						<div className="grid gap-1.5">
							<Label htmlFor="pb-script-slug">{__('Slug (folder name)', 'proto-blocks')}</Label>
							<Input
								id="pb-script-slug"
								className="font-mono"
								value={targetSlug ?? ''}
								aria-invalid={errors.slug ? true : undefined}
								aria-describedby={errors.slug ? 'pb-script-slug-error' : undefined}
								onChange={(event) => {
									setSlugTouched(true);
									setNewSlug(event.target.value);
								}}
							/>
							{errors.slug ? (
								<p id="pb-script-slug-error" className="text-caption text-destructive">
									{errors.slug}
								</p>
							) : null}
						</div>
					) : null}
					<div className="flex items-center gap-2">
						<Switch id="pb-script-enabled" checked={form.enabled} disabled={readOnly} onCheckedChange={(enabled) => update({ enabled })} />
						<Label htmlFor="pb-script-enabled" className="font-normal">
							{__('Enabled', 'proto-blocks')}
						</Label>
					</div>
					<div className="grid gap-1.5">
						<Label htmlFor="pb-script-priority">{__('Priority', 'proto-blocks')}</Label>
						<Input
							id="pb-script-priority"
							type="number"
							step={1}
							className="w-32 font-mono"
							value={Number.isNaN(form.priority) ? '' : String(form.priority)}
							readOnly={readOnly}
							aria-invalid={errors.priority ? true : undefined}
							aria-describedby={errors.priority ? 'pb-script-priority-error' : undefined}
							onChange={(event) => update({ priority: event.target.value === '' ? Number.NaN : Number(event.target.value) })}
						/>
						{errors.priority ? (
							<p id="pb-script-priority-error" className="text-caption text-destructive">
								{errors.priority}
							</p>
						) : null}
					</div>
				</FormSection>
				<FormSection
					title={__('Snippets', 'proto-blocks')}
					description={__('Printed exactly as written. HTML, CSS and JavaScript only. Leave a location empty to print nothing there.', 'proto-blocks')}
				>
					{LOCATIONS.map((location) => (
						<CodeEditor
							key={location}
							label={LOCATION_LABELS[location]}
							hint={SNIPPET_FILES[location]}
							value={form.snippets[location]}
							readOnly={readOnly}
							error={errors[location] ?? null}
							describedBy={describedBy}
							onChange={(code) => update({ snippets: { ...form.snippets, [location]: code } })}
						/>
					))}
				</FormSection>
				<FormSection title={__('Placement', 'proto-blocks')} description={__('Where and for whom the snippets print.', 'proto-blocks')}>
					<PlacementBuilder where={form.where} roles={roles} readOnly={readOnly} onChange={(where) => update({ where })} />
					{errors.where ? (
						<p role="alert" className="text-caption text-destructive">
							{errors.where}
						</p>
					) : null}
				</FormSection>
			</FormPanel>
			<ConfirmDialog
				open={confirmDelete}
				title={sprintf(/* translators: %s: script title */ __('Delete the %s script?', 'proto-blocks'), title)}
				description={sprintf(
					/* translators: %s: folder path */
					__('This deletes script.jsonc and its snippet files in %s. Files you added to that folder are kept.', 'proto-blocks'),
					detail?.path.replace(/\/script\.jsonc?$/, '') ?? ''
				)}
				confirmLabel={__('Delete script', 'proto-blocks')}
				destructive
				onConfirm={() => void remove()}
				onCancel={() => setConfirmDelete(false)}
			/>
		</div>
	);
}
