import { Fragment, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Tabs as TabsPrimitive } from 'radix-ui';
import { toast } from 'sonner';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { useSaveShortcut } from '@/admin/components/content-model/Layout';
import { DoubleBadge, ORIGIN_TONES } from '@/admin/components/content-model/OriginBadge';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { Panel } from '@/admin/components/shell/Panel';
import { READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Dot, Eyebrow } from '@/admin/components/shell/Signals';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Switch } from '@/admin/components/ui/switch';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { cn } from '@/admin/lib/utils';
import { CodeEditor } from './CodeEditor';
import { formFromData, newScriptText, scriptText, type ScriptForm } from './edits';
import { printsBefore } from './order';
import { PlacementBuilder } from './PlacementBuilder';
import { scriptErrors, SLUG_PATTERN, slugFromTitle, snippetError, targetLabel } from './schema';
import { ScriptsReadOnlyBanner } from './ScriptsReadOnlyBanner';
import { findTemplate } from './templates';
import { LOCATIONS, SNIPPET_FILES, type Location, type SavePayload, type SaveResponse, type ScriptDetail, type ScriptRow, type ScriptsWriteReason } from './types';

const EMPTY_SNIPPETS: Record<Location, string> = { head: '', bodyOpen: '', bodyClose: '' };

/** Accessible names of the three snippet editors. */
const LOCATION_LABELS: Record<Location, string> = {
	head: __('In <head>', 'proto-blocks'),
	bodyOpen: __('Right after <body>', 'proto-blocks'),
	bodyClose: __('Before </body>', 'proto-blocks'),
};

/** Snippet tab captions (mono). */
const TAB_LABELS: Record<Location, string> = {
	head: '<head>',
	bodyOpen: __('after <body>', 'proto-blocks'),
	bodyClose: __('before </body>', 'proto-blocks'),
};

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

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

/** Joins nodes with ", " and a final word ("a, b and c"). */
function joinNodes(nodes: ReactNode[], last: string): ReactNode {
	return nodes.map((node, i) => (
		<Fragment key={i}>
			{i > 0 ? (i === nodes.length - 1 ? ` ${last} ` : ', ') : null}
			{node}
		</Fragment>
	));
}

const strong = (text: string) => <strong className="font-medium">{text}</strong>;
const tag = (text: string) => <code className="font-mono text-[12.5px] text-link">{text}</code>;

/** "Prints in <head> and right after <body> on every page except Page · privacy-policy, for anyone…", from the form. */
export function PlainWords({ form, roles }: { form: ScriptForm; roles: { slug: string; name: string }[] }) {
	const used = LOCATIONS.filter((location) => form.snippets[location].trim() !== '');
	if (used.length === 0) {
		return <p className="text-body">{__('Prints nothing yet: every snippet is empty.', 'proto-blocks')}</p>;
	}
	const where = form.where;
	const name = (slug: string) => roles.find((r) => r.slug === slug)?.name ?? slug;
	const places: Record<Location, ReactNode> = {
		head: <>{__('in', 'proto-blocks')} {tag('<head>')}</>,
		bodyOpen: <>{__('right after', 'proto-blocks')} {tag('<body>')}</>,
		bodyClose: <>{__('before', 'proto-blocks')} {tag('</body>')}</>,
	};
	const pages =
		where.include.length === 0
			? strong(__('no page', 'proto-blocks'))
			: where.include.includes('all')
				? strong(__('every page', 'proto-blocks'))
				: joinNodes(where.include.map((t) => strong(targetLabel(t))), __('or', 'proto-blocks'));
	const who =
		where.roles.length > 0
			? <>{__('for users with the role', 'proto-blocks')} {joinNodes(where.roles.map((r) => strong(name(r))), __('or', 'proto-blocks'))}</>
			: where.loggedIn === true
				? __('for logged-in visitors', 'proto-blocks')
				: where.loggedIn === false
					? __('for logged-out visitors', 'proto-blocks')
					: __('for anyone', 'proto-blocks');
	return (
		<p data-testid="plain-words" className="text-body leading-[1.55]">
			{__('Prints', 'proto-blocks')} {joinNodes(used.map((l) => places[l]), __('and', 'proto-blocks'))} {__('on', 'proto-blocks')} {pages}
			{where.exclude.length > 0 ? (
				<>
					{' '}
					{__('except', 'proto-blocks')} {joinNodes(where.exclude.map((t) => strong(targetLabel(t))), __('or', 'proto-blocks'))}
				</>
			) : null}
			, {who}
			{where.excludeRoles.length > 0 ? (
				<>
					, {__('except users with the role', 'proto-blocks')} {joinNodes(where.excludeRoles.map((r) => strong(name(r))), __('or', 'proto-blocks'))}
				</>
			) : null}
			.
		</p>
	);
}

/** Card title row: 15/600 title with a muted note beside it, optional aside on the right. */
function CardHeader({ id, title, note, aside }: { id: string; title: string; note: string; aside?: ReactNode }) {
	return (
		<div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b px-5 py-3.5">
			<div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
				<h2 id={id} className="text-section font-semibold">
					{title}
				</h2>
				<span className="text-label text-muted-foreground">{note}</span>
			</div>
			{aside}
		</div>
	);
}

/**
 * Add (slug null) or edit one script: title, enabled, priority, the three
 * snippet editors and the placement builder. One save writes script.jsonc
 * and the three snippet files (POST admin/scripts/<slug>).
 */
export function ScriptEditor({
	slug,
	roles,
	items = [],
	template = null,
	writeReason,
	onBack,
	onSaved,
}: {
	slug: string | null;
	roles: { slug: string; name: string }[];
	/** Every listed script (for "Prints before"). */
	items?: ScriptRow[];
	/** Empty-state template the create form starts from. */
	template?: string | null;
	writeReason: ScriptsWriteReason;
	onBack: () => void;
	onSaved: (slug: string) => void;
}) {
	const creating = slug === null;
	const [detail, setDetail] = useState<ScriptDetail | null>(null);
	const [loadError, setLoadError] = useState<unknown>(null);
	const [form, setForm] = useState<ScriptForm | null>(() =>
		creating ? (findTemplate(template)?.form() ?? formFromData({ title: '', enabled: true, priority: 10 }, EMPTY_SNIPPETS)) : null
	);
	const [newSlug, setNewSlug] = useState('');
	const [slugTouched, setSlugTouched] = useState(false);
	const [errors, setErrors] = useState<Errors>({});
	const [saving, setSaving] = useState(false);
	const [confirmDelete, setConfirmDelete] = useState(false);
	const [tab, setTab] = useState<Location>('head');

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

	const payload = useMemo(() => (form ? savePayload(creating ? null : detail, form) : null), [creating, detail, form]);

	const save = async () => {
		if (!form || !payload) {
			return;
		}
		const found = validateForm(form, payload.text, creating ? targetSlug : null);
		setErrors(found);
		if (Object.keys(found).length > 0 || targetSlug === null) {
			const broken = LOCATIONS.find((location) => found[location]);
			if (broken) {
				setTab(broken);
			}
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

	useSaveShortcut(form && !readOnly && !saving ? () => void save() : null);

	const back = { label: __('All scripts', 'proto-blocks'), onClick: onBack };

	if (loadError) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				<PageHeader title={title} back={back} />
				<ScreenError screen={__('Scripts', 'proto-blocks')} error={loadError} onRetry={() => void load()} />
			</div>
		);
	}
	if (!form || !payload || (!creating && !detail)) {
		return (
			<div className="grid gap-6 [&>header]:mb-0" aria-busy="true">
				<PageHeader title={title} back={back} />
				<Panel className="grid gap-3 p-4">
					<Skeleton className="h-8 w-full" />
					<Skeleton className="h-40 w-full" />
				</Panel>
			</div>
		);
	}

	const update = (patch: Partial<ScriptForm>) => setForm({ ...form, ...patch });

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
	const used = LOCATIONS.filter((location) => form.snippets[location].trim() !== '');
	const folder = detail ? detail.path.replace(/\/script\.jsonc?$/, '/') : `proto-scripts/${targetSlug || '…'}/`;
	const next = printsBefore({ slug: targetSlug, priority: form.priority, locations: used }, items);
	const dirty = creating
		? true
		: detail !== null && (payload.text !== detail.text || LOCATIONS.some((l) => form.snippets[l] !== (detail.snippets[l] ?? '')));

	const titleSlot = (
		<>
			<input
				id="pb-script-title"
				aria-label={__('Script title', 'proto-blocks')}
				value={form.title}
				placeholder={__('Untitled script', 'proto-blocks')}
				readOnly={readOnly}
				aria-invalid={errors.title ? true : undefined}
				aria-describedby={errors.title ? 'pb-script-title-error' : describedBy}
				onChange={(event) => update({ title: event.target.value })}
				className="w-full max-w-[420px] rounded-none border-0 border-b border-dashed border-transparent bg-transparent p-0 text-title font-semibold text-foreground hover:border-input focus:border-primary aria-invalid:border-destructive read-only:hover:border-transparent"
			/>
			{errors.title ? (
				<p id="pb-script-title-error" className="text-caption text-destructive">
					{errors.title}
				</p>
			) : null}
			<div className="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-caption text-muted-foreground">
				{detail?.item ? (
					<Dot tone={ORIGIN_TONES[detail.item.origin] ?? 'neutral'} className="gap-1.5 text-caption">
						{detail.item.originLabel}
					</Dot>
				) : (
					<Dot tone="neutral" className="gap-1.5 text-caption">
						{__('New file', 'proto-blocks')}
					</Dot>
				)}
				<span aria-hidden="true">·</span>
				<code className="font-mono break-all text-link">{phpOnly && detail?.item ? detail.item.source : folder}</code>
				{detail?.item?.double ? <DoubleBadge double={detail.item.double} /> : null}
			</div>
		</>
	);

	return (
		<div className="grid gap-6 [&>header]:mb-0">
			<PageHeader
				title={title}
				back={back}
				titleSlot={titleSlot}
				actions={
					<>
						<div className="mr-2 inline-flex items-center gap-2.5">
							<Switch id="pb-script-enabled" checked={form.enabled} disabled={readOnly} onCheckedChange={(enabled) => update({ enabled })} />
							<Label htmlFor="pb-script-enabled">{__('Enabled', 'proto-blocks')}</Label>
						</div>
						{!creating && detail?.exists && !readOnly ? (
							<Button variant="ghost" className="text-muted-foreground hover:bg-destructive-soft hover:text-destructive" onClick={() => setConfirmDelete(true)}>
								{__('Delete', 'proto-blocks')}
							</Button>
						) : null}
						<Button onClick={() => void save()} disabled={readOnly || saving} aria-describedby={describedBy}>
							{saving ? __('Saving…', 'proto-blocks') : __('Save to file', 'proto-blocks')}
							<kbd aria-hidden="true" className="font-mono text-eyebrow font-normal text-panel-key">
								{isMac ? '⌘S' : 'Ctrl S'}
							</kbd>
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
			<IssueList issues={detail?.item?.issues ?? []} />
			{errors.file ? (
				<p role="alert" className="text-label text-destructive">
					{errors.file}
				</p>
			) : null}
			<div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
				<div className="grid min-w-0 gap-6">
					<Panel aria-labelledby="pb-script-snippets" className="overflow-hidden">
						<CardHeader
							id="pb-script-snippets"
							title={__('Snippets', 'proto-blocks')}
							note={__('Printed exactly as written. One file per location.', 'proto-blocks')}
							aside={
								<span className="text-caption text-muted-foreground">
									{sprintf(/* translators: %d: number of locations with code */ __('%d of 3 locations used', 'proto-blocks'), used.length)}
								</span>
							}
						/>
						<TabsPrimitive.Root value={tab} onValueChange={(value) => setTab(value as Location)}>
							<div className="flex flex-wrap items-center gap-x-4 border-b bg-subtle px-5">
								<TabsPrimitive.List aria-label={__('Snippet locations', 'proto-blocks')} className="-mb-px flex flex-wrap gap-0.5">
									{LOCATIONS.map((location) => {
										const has = form.snippets[location].trim() !== '';
										return (
											<TabsPrimitive.Trigger
												key={location}
												value={location}
												className="inline-flex cursor-pointer items-center gap-2 border-b-2 border-transparent px-3.5 py-3 font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring data-[state=active]:border-primary data-[state=active]:text-foreground"
											>
												<code className="font-mono text-[12.5px]">{TAB_LABELS[location]}</code>
												{errors[location] ? (
													<span className="size-1.5 rounded-full bg-destructive">
														<span className="sr-only">{__('has an error', 'proto-blocks')}</span>
													</span>
												) : has ? (
													<span aria-hidden="true" className="size-1.5 rounded-full bg-brand" />
												) : (
													<span className="text-[11.5px] font-normal text-faint">{__('empty', 'proto-blocks')}</span>
												)}
											</TabsPrimitive.Trigger>
										);
									})}
								</TabsPrimitive.List>
								<code className="ml-auto font-mono text-micro text-muted-foreground">{SNIPPET_FILES[tab]}</code>
							</div>
							{LOCATIONS.map((location) => (
								<TabsPrimitive.Content key={location} value={location} className="outline-none">
									<CodeEditor
										bare
										label={LOCATION_LABELS[location]}
										value={form.snippets[location]}
										readOnly={readOnly}
										error={errors[location] ?? null}
										describedBy={describedBy}
										onChange={(code) => update({ snippets: { ...form.snippets, [location]: code } })}
									/>
								</TabsPrimitive.Content>
							))}
						</TabsPrimitive.Root>
						<p className="border-t bg-subtle px-5 py-2.5 text-caption text-muted-foreground">
							{__('HTML, CSS and JavaScript. No PHP — the file is printed verbatim. Leave a location empty to print nothing there.', 'proto-blocks')}
						</p>
					</Panel>
					<Panel aria-labelledby="pb-script-where">
						<CardHeader id="pb-script-where" title={__('Where it runs', 'proto-blocks')} note={__('Pages first, then who’s viewing.', 'proto-blocks')} />
						<PlacementBuilder where={form.where} roles={roles} readOnly={readOnly} onChange={(where) => update({ where })} />
						{errors.where ? (
							<p role="alert" className="border-t bg-destructive-soft px-5 py-2.5 text-caption text-destructive">
								{errors.where}
							</p>
						) : null}
					</Panel>
				</div>
				<aside className="grid gap-3 lg:sticky lg:top-12" aria-label={__('Summary', 'proto-blocks')}>
					<Panel className="grid gap-3.5 px-5 py-[18px]">
						<Eyebrow>{__('In plain words', 'proto-blocks')}</Eyebrow>
						<PlainWords form={form} roles={roles} />
						<div className="grid gap-2 border-t border-divider pt-3 text-label">
							<div className="flex flex-wrap items-center justify-between gap-2">
								<label htmlFor="pb-script-priority" className="text-muted-foreground">
									{__('Priority', 'proto-blocks')}
								</label>
								<span className="inline-flex items-center gap-1.5">
									<Input
										id="pb-script-priority"
										type="number"
										step={1}
										className="h-7 w-[72px] px-2 text-right tabular-nums"
										value={Number.isNaN(form.priority) ? '' : String(form.priority)}
										readOnly={readOnly}
										aria-invalid={errors.priority ? true : undefined}
										aria-describedby={errors.priority ? 'pb-script-priority-error' : 'pb-script-priority-help'}
										onChange={(event) => update({ priority: event.target.value === '' ? Number.NaN : Number(event.target.value) })}
									/>
									<span id="pb-script-priority-help" className="text-micro text-muted-foreground">
										{__('lower = earlier', 'proto-blocks')}
									</span>
								</span>
							</div>
							{errors.priority ? (
								<p id="pb-script-priority-error" className="text-caption text-destructive">
									{errors.priority}
								</p>
							) : null}
							<div className="flex justify-between gap-2">
								<span className="text-muted-foreground">{__('Prints before', 'proto-blocks')}</span>
								<span data-testid="prints-before" className="text-right">
									{next ? next.title : '—'}
								</span>
							</div>
							{creating ? (
								<div className="grid gap-1.5 pt-1">
									<Label htmlFor="pb-script-slug" className="font-normal text-muted-foreground">
										{__('Slug (folder name)', 'proto-blocks')}
									</Label>
									<Input
										id="pb-script-slug"
										className="h-8 font-mono text-label"
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
						</div>
					</Panel>
					<section aria-labelledby="pb-script-jsonc" className="grid gap-2.5 rounded-xl bg-panel px-5 py-[18px] text-panel-foreground">
						<h2 id="pb-script-jsonc">
							<Eyebrow tone="panel">script.jsonc</Eyebrow>
						</h2>
						<pre
							tabIndex={0}
							aria-label={__('script.jsonc as it will be saved', 'proto-blocks')}
							className="m-0 max-h-80 overflow-auto font-mono text-micro leading-[1.65] whitespace-pre text-input focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
						>
							{payload.text}
						</pre>
						<p className={cn('text-micro', dirty ? 'text-brand' : 'text-faint')}>
							{creating
								? __('Not saved yet', 'proto-blocks')
								: dirty
									? __('Unsaved changes', 'proto-blocks')
									: __('Matches the file on disk', 'proto-blocks')}
						</p>
					</section>
				</aside>
			</div>
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
