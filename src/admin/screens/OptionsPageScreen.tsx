/**
 * A rendered settings page: one form for the whole page, with Phase 2's
 * FieldsForm for each field group. Tabs are presentational: every tab stays
 * mounted (hidden when inactive), so one save sends every tab and unsaved
 * edits on other tabs are kept.
 *
 * Data comes from includes/OptionsPages/PageRenderer.php (a JSON element, no
 * inline script). The server is the authority on validation; FieldsForm
 * mirrors it on blur. After a save each FieldsForm re-mounts with the stored
 * values; after a 422 it re-mounts with the submitted values and the errors.
 */
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CircleAlertIcon, InfoIcon, LoaderCircleIcon, LockIcon, PencilIcon } from 'lucide-react';
import { toast } from 'sonner';
import { adminPost, ApiError } from '@/admin/api';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card, CardContent, CardFooter } from '@/admin/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/admin/components/ui/tabs';
import { submissionFromForm } from '@/admin/options-pages/formData';
import type { OptionsPageData, OptionsSaveResponse } from '@/admin/options-pages/types';
import { FieldsForm } from '@/fields/box/FieldsForm';
import { domId } from '@/fields/box/names';
import type { BoxPayload } from '@/fields/model/types';

const DATA_ID = 'proto-blocks-options-page-data';

function readPageData(): OptionsPageData | null {
	const el = document.getElementById(DATA_ID);
	if (el?.textContent) {
		try {
			const data: unknown = JSON.parse(el.textContent);
			if (data && typeof data === 'object' && Array.isArray((data as OptionsPageData).groups)) {
				return data as OptionsPageData;
			}
		} catch {
			// fall through to the window global
		}
	}
	return window.protoBlocksOptionsPage ?? null;
}

type Notice = { field: string | null; message: string };

const isRecord = (v: unknown): v is Record<string, unknown> => v !== null && typeof v === 'object' && !Array.isArray(v);

/** The 422 field errors, path => message; null for any other error. */
function fieldErrors(error: unknown): Record<string, string> | null {
	if (!(error instanceof ApiError) || error.status !== 422 || !isRecord(error.data.errors)) {
		return null;
	}
	const out: Record<string, string> = {};
	for (const [path, message] of Object.entries(error.data.errors)) {
		if (typeof message === 'string') {
			out[path] = message;
		}
	}
	return out;
}

const topName = (path: string) => path.split('.')[0];

function GroupForm({ payload }: { payload: BoxPayload }) {
	return (
		<div className="proto-blocks-fields">
			<FieldsForm payload={payload} />
		</div>
	);
}

export default function OptionsPageScreen(): JSX.Element {
	const data = useMemo(readPageData, []);
	if (!data) {
		throw new Error(__('The settings page data is missing.', 'proto-blocks'));
	}
	return <OptionsPage data={data} />;
}

function OptionsPage({ data }: { data: OptionsPageData }) {
	const allGroups = useMemo(() => [...data.groups, ...data.tabs.flatMap((t) => t.groups)], [data]);

	/** Top-level field name => the group that renders it (first wins, like the server). */
	const owner = useMemo(() => {
		const map = new Map<string, string>();
		for (const g of allGroups) {
			for (const f of g.fields) {
				if (f.name !== '' && !map.has(f.name)) {
					map.set(f.name, g.groupKey);
				}
			}
		}
		return map;
	}, [allGroups]);

	const labels = useMemo(() => {
		const map = new Map<string, string>();
		for (const g of allGroups) {
			for (const f of g.fields) {
				if (f.name !== '' && !map.has(f.name)) {
					map.set(f.name, f.label || f.name);
				}
			}
		}
		return map;
	}, [allGroups]);

	const [values, setValues] = useState<Record<string, Record<string, unknown>>>(() =>
		Object.fromEntries(allGroups.map((g) => [g.groupKey, isRecord(g.values) ? g.values : {}]))
	);
	const [errors, setErrors] = useState<Record<string, string>>({});
	const [generation, setGeneration] = useState(0);
	const [notices, setNotices] = useState<Notice[]>([]);
	const [saving, setSaving] = useState(false);
	const [activeTab, setActiveTab] = useState('tab-0');
	const formRef = useRef<HTMLFormElement>(null);
	const dirty = useRef(false);
	const focusAfterMount = useRef<string | null>(null);

	useEffect(() => {
		const onBeforeUnload = (e: BeforeUnloadEvent) => {
			if (dirty.current) {
				e.preventDefault();
				// Older browsers need returnValue set to show the prompt.
				e.returnValue = '';
			}
		};
		window.addEventListener('beforeunload', onBeforeUnload);
		return () => window.removeEventListener('beforeunload', onBeforeUnload);
	}, []);

	// After a 422 re-mount: FieldsForm focuses its own summary on mount; move
	// focus on to the first invalid field once that has run.
	useEffect(() => {
		const id = focusAfterMount.current;
		if (id === null) {
			return undefined;
		}
		focusAfterMount.current = null;
		const timer = window.setTimeout(() => document.getElementById(id)?.focus(), 0);
		return () => window.clearTimeout(timer);
	}, [generation]);

	const errorsFor = useCallback(
		(groupKey: string) => Object.fromEntries(Object.entries(errors).filter(([path]) => owner.get(topName(path)) === groupKey)),
		[errors, owner]
	);

	const payloadFor = (g: BoxPayload): BoxPayload => ({ ...g, values: values[g.groupKey] ?? {}, errors: errorsFor(g.groupKey) });

	const tabErrorCount = (groups: BoxPayload[]) => {
		const keys = new Set(groups.map((g) => g.groupKey));
		return Object.keys(errors).filter((path) => keys.has(owner.get(topName(path)) ?? '')).length;
	};

	const markDirty = () => {
		dirty.current = true;
	};

	async function onSubmit(e: FormEvent<HTMLFormElement>) {
		e.preventDefault();
		const form = formRef.current;
		if (saving || !form) {
			return;
		}
		const submission = submissionFromForm(form);
		setSaving(true);
		try {
			const response = await adminPost<OptionsSaveResponse>(`options/${data.slug}`, submission);
			const stored = isRecord(response?.values) ? response.values : {};
			setValues(Object.fromEntries(allGroups.map((g) => [g.groupKey, stored])));
			setErrors({});
			setNotices(Array.isArray(response?.notices) ? response.notices : []);
			setGeneration((n) => n + 1);
			dirty.current = false;
			toast.success(__('Settings saved.', 'proto-blocks'));
		} catch (error) {
			const invalid = fieldErrors(error);
			if (invalid === null) {
				// Network or server failure: keep every edit in place.
				const offline = !(error instanceof ApiError) || error.status === 0;
				toast.error(offline ? __("Couldn't reach the server.", 'proto-blocks') : error.message, {
					description: __('Your changes are still here.', 'proto-blocks'),
				});
				return;
			}
			setValues((prev) =>
				Object.fromEntries(allGroups.map((g) => [g.groupKey, { ...(prev[g.groupKey] ?? {}), ...(submission.fields[g.groupKey] ?? {}) }]))
			);
			setErrors(invalid);
			setNotices([]);

			// The first error in page order decides the tab and the focus.
			const ordered = allGroups.flatMap((g) =>
				Object.keys(invalid)
					.filter((path) => owner.get(topName(path)) === g.groupKey)
					.map((path) => ({ group: g.groupKey, path }))
			);
			const first = ordered[0];
			if (first) {
				const tab = data.tabs.findIndex((t) => t.groups.some((g) => g.groupKey === first.group));
				if (tab >= 0) {
					setActiveTab(`tab-${tab}`);
				}
				focusAfterMount.current = domId(first.group, first.path.split('.'));
			}
			setGeneration((n) => n + 1);
			const count = Object.keys(invalid).length;
			toast.error(
				sprintf(
					/* translators: %d: number of fields with errors */
					_n('Nothing was saved: %d field needs attention.', 'Nothing was saved: %d fields need attention.', count, 'proto-blocks'),
					count
				)
			);
		} finally {
			setSaving(false);
		}
	}

	const readOnly = Object.entries(data.readOnly ?? {});
	const renderGroups = (groups: BoxPayload[]) =>
		groups.map((g) => <GroupForm key={`${g.groupKey}:${generation}`} payload={payloadFor(g)} />);

	return (
		<div className="grid gap-4 pt-3">
			<header className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
				<h1 className="text-[23px] leading-tight font-normal text-[#1d2327]">{data.title}</h1>
				{data.editUrl && (
					<a href={data.editUrl} className="inline-flex items-center gap-1 text-[13px] text-muted-foreground underline-offset-2 hover:underline">
						<PencilIcon aria-hidden="true" className="size-3.5" />
						{__('Edit declaration', 'proto-blocks')}
					</a>
				)}
			</header>

			<div aria-live="polite" className="grid gap-3 empty:hidden">
				{notices.map((n, i) => (
					<Alert key={`${i}:${n.message}`} variant="warning">
						<InfoIcon aria-hidden="true" />
						{n.field && <AlertTitle>{labels.get(n.field) ?? n.field}</AlertTitle>}
						<AlertDescription>{n.message}</AlertDescription>
					</Alert>
				))}
			</div>

			{readOnly.length > 0 && (
				<Alert>
					<LockIcon aria-hidden="true" />
					<AlertTitle>{__('Some fields are read-only for you', 'proto-blocks')}</AlertTitle>
					<AlertDescription>
						<ul className="flex flex-col gap-0.5">
							{readOnly.map(([name]) => (
								<li key={name}>
									<span className="font-medium">{labels.get(name) ?? name}</span>
									{': '}
									{__('Only users with unfiltered HTML may edit this field.', 'proto-blocks')}
								</li>
							))}
						</ul>
					</AlertDescription>
				</Alert>
			)}

			<form ref={formRef} onSubmit={onSubmit} onChange={markDirty} onInput={markDirty} onClick={(e) => {
				if ((e.target as HTMLElement).closest('[data-field]')) {
					markDirty();
				}
			}} noValidate>
				<Card className="gap-0 py-0">
					<CardContent className="grid gap-6 py-6">
						{data.groups.length > 0 && <div className="grid gap-6">{renderGroups(data.groups)}</div>}
						{data.tabs.length > 0 && (
							<Tabs value={activeTab} onValueChange={setActiveTab}>
								<TabsList variant="line">
									{data.tabs.map((tab, i) => {
										const count = tabErrorCount(tab.groups);
										const noteId = `pb-options-tab-${i}-errors`;
										return (
											<TabsTrigger key={tab.title + i} value={`tab-${i}`} aria-describedby={count > 0 ? noteId : undefined}>
												{tab.title}
												{count > 0 && (
													<>
														<Badge variant="destructive" aria-hidden="true" className="h-4 min-w-4 px-1 text-[11px]">
															{count}
														</Badge>
														<span id={noteId} className="sr-only">
															{sprintf(
																/* translators: %d: number of fields with errors */
																_n('%d field has errors', '%d fields have errors', count, 'proto-blocks'),
																count
															)}
														</span>
													</>
												)}
											</TabsTrigger>
										);
									})}
								</TabsList>
								{data.tabs.map((tab, i) => (
									<TabsContent key={tab.title + i} value={`tab-${i}`} forceMount className="grid gap-6 pt-4 data-[state=inactive]:hidden">
										{renderGroups(tab.groups)}
									</TabsContent>
								))}
							</Tabs>
						)}
					</CardContent>
					<CardFooter className="sticky bottom-0 flex flex-wrap items-center gap-3 rounded-b-xl border-t bg-card py-4">
						<Button type="submit" disabled={saving}>
							{saving && <LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" />}
							{saving ? __('Saving…', 'proto-blocks') : __('Save settings', 'proto-blocks')}
						</Button>
						{data.tabs.length > 1 && <p className="text-[13px] text-muted-foreground">{__('Saves every tab.', 'proto-blocks')}</p>}
						{Object.keys(errors).length > 0 && (
							<p className="flex items-center gap-1.5 text-[13px] text-destructive">
								<CircleAlertIcon aria-hidden="true" className="size-4" />
								{__('Nothing was saved. Fix the marked fields and save again.', 'proto-blocks')}
							</p>
						)}
					</CardFooter>
				</Card>
			</form>
		</div>
	);
}
