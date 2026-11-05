import { useEffect, useId, useMemo, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Controller, useForm, type Control, type FieldErrors, type Resolver } from 'react-hook-form';
import { toast } from 'sonner';
import { DatabaseIcon, LoaderCircleIcon } from 'lucide-react';
import { adminPost } from '@/admin/api';
import { Panel, PanelHeader, SettingRow } from '@/admin/components/shell/Panel';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/admin/components/ui/select';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Textarea } from '@/admin/components/ui/textarea';
import { parseJsonc } from '@/admin/content-model/jsonc';
import type { FileResult } from '@/admin/content-model/types';
import { asApiError, loadFile, postFile } from './api';
import { QUERY_MODES, queryLabel } from './codes';
import { applySettingsEdits } from './edits';
import { serverFindings, settingsFieldErrors, settingsSchema } from './schema';
import { useWriteFlow, writeGuard, type TabProps } from './shared';
import type { IpHeader, IpPolicy, QueryMode, Settings } from './types';

const IP_HEADERS: IpHeader[] = ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP', 'Client-IP', 'True-Client-IP'];
const RETENTION = [-1, 0, 1, 7, 14, 30, 60, 90];
const CACHE = [-1, 0, 1, 24, 168];

export interface SettingsFormValues {
	defaults: { ignoreCase: boolean; ignoreTrailingSlash: boolean; query: QueryMode };
	log: { redirects: string; notFound: string; ip: IpPolicy; headers: boolean; external: boolean; trustedProxies: string; ipHeaders: IpHeader[] };
	trackHits: boolean;
	cache: string;
	monitor: { postTypes: string; group: string; trash: boolean; associated: string };
	headers: { site: string; redirect: string };
	canonical: { https: boolean; www: 'none' | 'www' | 'nowww'; aliases: string; relocate: string };
	coexist: boolean;
}

const list = (items: string[]) => items.join(', ');
const unlist = (text: string) =>
	text
		.split(/[\n,]/)
		.map((item) => item.trim())
		.filter((item) => item !== '');
const headerText = (map: Record<string, string>) =>
	Object.entries(map)
		.map(([name, value]) => `${name}: ${value}`)
		.join('\n');

/** "Name: value" lines → map; null when a line has no colon. */
function parseHeaders(text: string): Record<string, string> | null {
	const out: Record<string, string> = {};
	for (const line of text.split('\n')) {
		if (line.trim() === '') {
			continue;
		}
		const colon = line.indexOf(':');
		if (colon <= 0) {
			return null;
		}
		out[line.slice(0, colon).trim()] = line.slice(colon + 1).trim();
	}
	return out;
}

export function settingsToForm(settings: Settings): SettingsFormValues {
	return {
		defaults: { ...settings.defaults },
		log: {
			redirects: String(settings.log.redirects),
			notFound: String(settings.log.notFound),
			ip: settings.log.ip,
			headers: settings.log.headers,
			external: settings.log.external,
			trustedProxies: list(settings.log.trustedProxies),
			ipHeaders: [...settings.log.ipHeaders],
		},
		trackHits: settings.trackHits,
		cache: String(settings.cache),
		monitor: { postTypes: list(settings.monitor.postTypes), group: settings.monitor.group, trash: settings.monitor.trash, associated: settings.monitor.associated },
		headers: { site: headerText(settings.headers.site), redirect: headerText(settings.headers.redirect) },
		canonical: { https: settings.canonical.https, www: settings.canonical.www === '' ? 'none' : settings.canonical.www, aliases: list(settings.canonical.aliases), relocate: settings.canonical.relocate },
		coexist: settings.coexist,
	};
}

/** The settings object the form writes; header maps that do not parse come back as null. */
export function formToSettings(values: SettingsFormValues): Omit<Settings, 'headers'> & { headers: { site: Record<string, string> | null; redirect: Record<string, string> | null } } {
	return {
		defaults: { ...values.defaults },
		log: {
			redirects: Number(values.log.redirects),
			notFound: Number(values.log.notFound),
			ip: values.log.ip,
			headers: values.log.headers,
			external: values.log.external,
			trustedProxies: unlist(values.log.trustedProxies),
			ipHeaders: IP_HEADERS.filter((header) => values.log.ipHeaders.includes(header)),
		},
		trackHits: values.trackHits,
		cache: Number(values.cache) as Settings['cache'],
		monitor: { postTypes: unlist(values.monitor.postTypes), group: values.monitor.group.trim(), trash: values.monitor.trash, associated: values.monitor.associated.trim() },
		headers: { site: parseHeaders(values.headers.site), redirect: parseHeaders(values.headers.redirect) },
		canonical: { https: values.canonical.https, www: values.canonical.www === 'none' ? '' : values.canonical.www, aliases: unlist(values.canonical.aliases).map((host) => host.toLowerCase()), relocate: values.canonical.relocate.trim() },
		coexist: values.coexist,
	};
}

const settingsResolver: Resolver<SettingsFormValues> = async (values) => {
	const settings = formToSettings(values);
	const errors: Record<string, Record<string, { type: string; message: string }>> = {};
	const add = (path: (string | number)[], message: string) => {
		const [section, key] = path.map(String);
		if (key === undefined) {
			(errors as Record<string, unknown>)[section] ??= { type: 'schema_invalid', message };
			return;
		}
		errors[section] ??= {};
		errors[section][key] ??= { type: 'schema_invalid', message };
	};
	for (const which of ['site', 'redirect'] as const) {
		if (settings.headers[which] === null) {
			add(['headers', which], __('Write one header per line as Name: value.', 'proto-blocks'));
		}
	}
	const result = settingsSchema.safeParse({ ...settings, headers: { site: settings.headers.site ?? {}, redirect: settings.headers.redirect ?? {} } });
	if (!result.success) {
		for (const issue of result.error.issues) {
			add(issue.path as (string | number)[], issue.message);
		}
	}
	return Object.keys(errors).length > 0 ? { values: {}, errors: errors as FieldErrors<SettingsFormValues> } : { values, errors: {} };
};

type BoolPath = 'defaults.ignoreCase' | 'defaults.ignoreTrailingSlash' | 'log.headers' | 'log.external' | 'trackHits' | 'monitor.trash' | 'canonical.https' | 'coexist';
type SelectPath = 'defaults.query' | 'log.redirects' | 'log.notFound' | 'log.ip' | 'cache' | 'canonical.www';

function CheckField({ control, name, label, disabled }: { control: Control<SettingsFormValues>; name: BoolPath; label: string; disabled: boolean }) {
	const id = useId();
	return (
		<Controller
			control={control}
			name={name}
			render={({ field }) => (
				<div className="flex items-center gap-2">
					<Checkbox id={id} checked={field.value as boolean} disabled={disabled} onCheckedChange={(checked) => field.onChange(checked === true)} />
					<Label htmlFor={id} className="font-normal">
						{label}
					</Label>
				</div>
			)}
		/>
	);
}

function SelectField({ control, name, label, options, disabled }: { control: Control<SettingsFormValues>; name: SelectPath; label: string; options: [string, string][]; disabled: boolean }) {
	const id = useId();
	return (
		<div className="grid gap-1.5">
			<Label htmlFor={id}>{label}</Label>
			<Controller
				control={control}
				name={name}
				render={({ field }) => {
					const value = String(field.value);
					const all = options.some(([v]) => v === value) ? options : [...options, [value, value] as [string, string]];
					return (
						<Select value={value} onValueChange={field.onChange} disabled={disabled}>
							<SelectTrigger id={id} className="w-64">
								<SelectValue />
							</SelectTrigger>
							<SelectContent>
								{all.map(([v, text]) => (
									<SelectItem key={v} value={v}>
										{text}
									</SelectItem>
								))}
							</SelectContent>
						</Select>
					);
				}}
			/>
		</div>
	);
}

function TextField({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: string; children: ReactNode }) {
	return (
		<div className="grid gap-1.5">
			<Label htmlFor={id}>{label}</Label>
			{children}
			{hint ? (
				<p id={`${id}-hint`} className="text-caption text-muted-foreground">
					{hint}
				</p>
			) : null}
			{error ? (
				<p id={`${id}-error`} className="text-label text-destructive">
					{error}
				</p>
			) : null}
		</div>
	);
}

function retentionLabel(days: number): string {
	if (days === -1) {
		return __('Do not log', 'proto-blocks');
	}
	if (days === 0) {
		return __('Keep forever', 'proto-blocks');
	}
	/* translators: %d: number of days. */
	return sprintf(__('%d days', 'proto-blocks'), days);
}

function cacheLabel(hours: number): string {
	switch (hours) {
		case -1:
			return __('Send no-cache headers', 'proto-blocks');
		case 0:
			return __('No cache headers', 'proto-blocks');
		case 1:
			return __('1 hour', 'proto-blocks');
		case 24:
			return __('1 day', 'proto-blocks');
		default:
			return __('1 week', 'proto-blocks');
	}
}

/** Settings tab (spec §6): a form over proto-redirects/settings.jsonc, plus the database buttons. */
export default function SettingsTab({ index, reload, readOnly }: TabProps) {
	const [file, setFile] = useState<FileResult | null>(null);
	const [fileError, setFileError] = useState<unknown>(null);
	const { run, busy, dialogs } = useWriteFlow(async () => {
		await reload();
		setFile(await loadFile('redirect-settings', 'settings'));
	});
	const [dbBusy, setDbBusy] = useState(false);
	const defaults = useMemo(() => settingsToForm(index.settings), [index.settings]);
	const form = useForm<SettingsFormValues>({ resolver: settingsResolver, defaultValues: defaults });
	const { errors, isSubmitting } = form.formState;
	const ids = { proxies: useId(), postTypes: useId(), group: useId(), associated: useId(), site: useId(), redirect: useId(), aliases: useId(), relocate: useId() };
	const locked = readOnly || index.settingsFile.writable === false;

	useEffect(() => {
		loadFile('redirect-settings', 'settings').then(setFile, setFileError);
	}, []);

	useEffect(() => {
		form.reset(defaults);
	}, [defaults, form]);

	const submit = form.handleSubmit(async (values) => {
		if (!file) {
			return;
		}
		const base = file.exists || parseJsonc(file.text).errors.length === 0 ? file.text : '{\n}\n';
		const next = formToSettings(values);
		const text = applySettingsEdits(base, index.settings as unknown as Record<string, unknown>, next as unknown as Record<string, unknown>);
		if (file.exists && text === file.text) {
			toast.info(__('Nothing changed.', 'proto-blocks'));
			return;
		}
		await run(
			(acknowledge) => postFile('redirect-settings', 'settings', text, file.exists ? file.hash : null, acknowledge),
			__('Settings saved.', 'proto-blocks'),
			[],
			(error) => {
				// A refusal from SettingsValidator: each finding next to its field, the rest in a toast.
				const findings = serverFindings(error);
				if (findings.length === 0) {
					return false;
				}
				const loose: string[] = [];
				for (const [field, message] of Object.entries(settingsFieldErrors(findings))) {
					if (field !== 'root' && form.getValues(field as never) !== undefined) {
						form.setError(field as never, { type: 'server', message });
					} else {
						loose.push(message);
					}
				}
				toast.error(__('The settings were not saved.', 'proto-blocks'), loose.length > 0 ? { description: loose.join(' ') } : undefined);
				return true;
			}
		);
	});

	const db = async (path: string, success: string) => {
		setDbBusy(true);
		try {
			const result = await adminPost<{ deleted?: number }>(path);
			toast.success(typeof result?.deleted === 'number' ? sprintf(/* translators: %d: number of rows. */ __('%d rows removed.', 'proto-blocks'), result.deleted) : success);
			await reload();
		} catch (e) {
			toast.error(__('The database action failed.', 'proto-blocks'), { description: asApiError(e).message });
		} finally {
			setDbBusy(false);
		}
	};

	if (fileError && !file) {
		return <ScreenError screen={__('Redirect settings', 'proto-blocks')} error={fileError} onRetry={() => loadFile('redirect-settings', 'settings').then(setFile, setFileError)} />;
	}
	if (!file) {
		return <Skeleton className="h-64 w-full" />;
	}

	const c = form.control;
	const guard = writeGuard(locked);
	const err = (section: keyof SettingsFormValues, key?: string): string | undefined => {
		const node = (errors as Record<string, unknown>)[section] as Record<string, { message?: string }> & { message?: string } | undefined;
		return key ? node?.[key]?.message : node?.message;
	};
	const aria = (id: string, error?: string, hint = false) => ({
		'aria-invalid': error ? true : undefined,
		'aria-describedby': [hint ? `${id}-hint` : '', error ? `${id}-error` : '', locked ? guard['aria-describedby'] ?? '' : ''].filter(Boolean).join(' ') || undefined,
	});

	return (
		<div className="grid gap-4">
			<form noValidate onSubmit={submit} className="grid gap-4">
				<Panel aria-labelledby="pb-rs-defaults">
					<PanelHeader id="pb-rs-defaults" title={__('Matching defaults', 'proto-blocks')} description={__('For every rule; groups and rules can override them.', 'proto-blocks')} />
					<SettingRow label={__('Flags', 'proto-blocks')}>
						<div className="grid gap-3">
							<CheckField control={c} name="defaults.ignoreCase" label={__('Ignore case', 'proto-blocks')} disabled={locked} />
							<CheckField control={c} name="defaults.ignoreTrailingSlash" label={__('Ignore trailing slash', 'proto-blocks')} disabled={locked} />
							<SelectField control={c} name="defaults.query" label={__('Query string', 'proto-blocks')} disabled={locked} options={QUERY_MODES.map((mode) => [mode, queryLabel(mode)])} />
						</div>
					</SettingRow>
				</Panel>

				<Panel aria-labelledby="pb-rs-log">
					<PanelHeader id="pb-rs-log" title={__('Logs and hits', 'proto-blocks')} />
					<SettingRow label={__('Retention', 'proto-blocks')}>
						<div className="flex flex-wrap gap-4">
							<SelectField control={c} name="log.redirects" label={__('Redirect log', 'proto-blocks')} disabled={locked} options={RETENTION.map((days) => [String(days), retentionLabel(days)])} />
							<SelectField control={c} name="log.notFound" label={__('404 log', 'proto-blocks')} disabled={locked} options={RETENTION.map((days) => [String(days), retentionLabel(days)])} />
						</div>
					</SettingRow>
					<SettingRow label={__('What is stored', 'proto-blocks')}>
						<div className="grid gap-3">
							<SelectField
								control={c}
								name="log.ip"
								label={__('IP addresses', 'proto-blocks')}
								disabled={locked}
								options={[
									['none', __('Do not store', 'proto-blocks')],
									['masked', __('Masked (last part zeroed)', 'proto-blocks')],
									['full', __('Full address', 'proto-blocks')],
								]}
							/>
							<CheckField control={c} name="log.headers" label={__('Store request headers (never cookies or authorization)', 'proto-blocks')} disabled={locked} />
							<CheckField control={c} name="log.external" label={__('Also log redirects made by other code', 'proto-blocks')} disabled={locked} />
							<CheckField control={c} name="trackHits" label={__('Count hits per rule', 'proto-blocks')} disabled={locked} />
						</div>
					</SettingRow>
					<SettingRow label={__('Proxies', 'proto-blocks')} help={__('Only these addresses may supply the client IP in a header.', 'proto-blocks')}>
						<div className="grid gap-3">
							<TextField id={ids.proxies} label={__('Trusted proxies', 'proto-blocks')} error={err('log', 'trustedProxies')} hint={__('IP addresses or CIDR ranges, separated by commas.', 'proto-blocks')}>
								<Input id={ids.proxies} {...guard} {...aria(ids.proxies, err('log', 'trustedProxies'), true)} {...form.register('log.trustedProxies')} />
							</TextField>
							<fieldset className="grid gap-2">
								<legend className="mb-1 text-label font-medium">{__('Client IP headers', 'proto-blocks')}</legend>
								<Controller
									control={c}
									name="log.ipHeaders"
									render={({ field }) => (
										<div className="flex flex-wrap gap-x-5 gap-y-2">
											{IP_HEADERS.map((header) => {
												const id = `${ids.proxies}-${header}`;
												return (
													<div key={header} className="flex items-center gap-2">
														<Checkbox
															id={id}
															disabled={locked}
															checked={field.value.includes(header)}
															onCheckedChange={(checked) => field.onChange(checked === true ? [...field.value, header] : field.value.filter((h) => h !== header))}
														/>
														<Label htmlFor={id} className="font-mono text-micro font-normal">
															{header}
														</Label>
													</div>
												);
											})}
										</div>
									)}
								/>
							</fieldset>
						</div>
					</SettingRow>
				</Panel>

				<Panel aria-labelledby="pb-rs-response">
					<PanelHeader id="pb-rs-response" title={__('Responses', 'proto-blocks')} />
					<SettingRow label={__('Caching', 'proto-blocks')} help={__('Cache-Control on 301 and 308 redirects.', 'proto-blocks')}>
						<SelectField control={c} name="cache" label={__('Permanent redirect cache', 'proto-blocks')} disabled={locked} options={CACHE.map((hours) => [String(hours), cacheLabel(hours)])} />
					</SettingRow>
					<SettingRow label={__('Headers', 'proto-blocks')} help={__('One per line, as Name: value.', 'proto-blocks')}>
						<div className="grid gap-3">
							<TextField id={ids.site} label={__('Every front-end response', 'proto-blocks')} error={err('headers', 'site')}>
								<Textarea id={ids.site} rows={3} className="font-mono text-micro" {...guard} {...aria(ids.site, err('headers', 'site'))} {...form.register('headers.site')} />
							</TextField>
							<TextField id={ids.redirect} label={__('Redirects only', 'proto-blocks')} error={err('headers', 'redirect')}>
								<Textarea id={ids.redirect} rows={3} className="font-mono text-micro" {...guard} {...aria(ids.redirect, err('headers', 'redirect'))} {...form.register('headers.redirect')} />
							</TextField>
						</div>
					</SettingRow>
				</Panel>

				<Panel aria-labelledby="pb-rs-canonical">
					<PanelHeader id="pb-rs-canonical" title={__('Canonical host', 'proto-blocks')} />
					<SettingRow label={__('Host and scheme', 'proto-blocks')}>
						<div className="grid gap-3">
							<CheckField control={c} name="canonical.https" label={__('Redirect http to https', 'proto-blocks')} disabled={locked} />
							<SelectField
								control={c}
								name="canonical.www"
								label={__('www', 'proto-blocks')}
								disabled={locked}
								options={[
									['none', __('Leave as requested', 'proto-blocks')],
									['www', __('Add www', 'proto-blocks')],
									['nowww', __('Remove www', 'proto-blocks')],
								]}
							/>
							<TextField id={ids.aliases} label={__('Alias hosts', 'proto-blocks')} error={err('canonical', 'aliases')} hint={__('Other host names sent to this site, separated by commas (up to 20).', 'proto-blocks')}>
								<Input id={ids.aliases} {...guard} {...aria(ids.aliases, err('canonical', 'aliases'), true)} {...form.register('canonical.aliases')} />
							</TextField>
							<TextField id={ids.relocate} label={__('Relocate site to', 'proto-blocks')} error={err('canonical', 'relocate')} hint={__('A full URL; every front-end request is sent there. Leave empty to turn off.', 'proto-blocks')}>
								<Input id={ids.relocate} {...guard} {...aria(ids.relocate, err('canonical', 'relocate'), true)} {...form.register('canonical.relocate')} />
							</TextField>
						</div>
					</SettingRow>
				</Panel>

				<Panel aria-labelledby="pb-rs-monitor">
					<PanelHeader id="pb-rs-monitor" title={__('Permalink monitor', 'proto-blocks')} description={__('Adds a redirect when a published post’s URL changes.', 'proto-blocks')} />
					<SettingRow label={__('Watch', 'proto-blocks')}>
						<div className="grid gap-3">
							<TextField id={ids.postTypes} label={__('Post types', 'proto-blocks')} error={err('monitor', 'postTypes')} hint={__('Separated by commas, e.g. post, page. Empty turns the monitor off.', 'proto-blocks')}>
								<Input id={ids.postTypes} {...guard} {...aria(ids.postTypes, err('monitor', 'postTypes'), true)} {...form.register('monitor.postTypes')} />
							</TextField>
							<TextField id={ids.group} label={__('Group for new rules', 'proto-blocks')} error={err('monitor', 'group')}>
								<Input id={ids.group} className="font-mono" {...guard} {...aria(ids.group, err('monitor', 'group'))} {...form.register('monitor.group')} />
							</TextField>
							<CheckField control={c} name="monitor.trash" label={__('Add a disabled rule when a watched post is trashed', 'proto-blocks')} disabled={locked} />
							<TextField id={ids.associated} label={__('Associated suffix', 'proto-blocks')} error={err('monitor', 'associated')} hint={__('e.g. /feed/ adds a second rule with this suffix on both sides.', 'proto-blocks')}>
								<Input id={ids.associated} className="font-mono" {...guard} {...aria(ids.associated, err('monitor', 'associated'), true)} {...form.register('monitor.associated')} />
							</TextField>
						</div>
					</SettingRow>
				</Panel>

				<Panel aria-labelledby="pb-rs-coexist">
					<PanelHeader id="pb-rs-coexist" title={__('Redirection plugin', 'proto-blocks')} />
					<SettingRow label={__('Coexistence', 'proto-blocks')} help={__('While Redirection is active, Proto-Blocks pauses to avoid redirecting twice.', 'proto-blocks')}>
						<CheckField control={c} name="coexist" label={__('Run even while Redirection is active', 'proto-blocks')} disabled={locked} />
					</SettingRow>
				</Panel>

				<div className="flex items-center gap-3">
					<Button type="submit" {...writeGuard(locked, busy || isSubmitting)}>
						{busy || isSubmitting ? <LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" /> : null}
						{__('Save settings', 'proto-blocks')}
					</Button>
					<code className="font-mono text-micro text-muted-foreground">{file.path || 'proto-redirects/settings.jsonc'}</code>
				</div>
			</form>

			<Panel aria-labelledby="pb-rs-db">
				<PanelHeader id="pb-rs-db" title={__('Database', 'proto-blocks')} description={__('Hit counts and logs. Configuration is never stored here.', 'proto-blocks')} />
				<div className="flex flex-wrap items-center gap-3 px-6 py-4">
					{!index.db.tables ? (
						<Button disabled={dbBusy} onClick={() => void db('admin/redirects/db/install', __('Tables created.', 'proto-blocks'))}>
							<DatabaseIcon aria-hidden="true" />
							{__('Create tables', 'proto-blocks')}
						</Button>
					) : null}
					<Button variant="outline" disabled={dbBusy || index.db.orphans === 0} onClick={() => void db('admin/redirects/hits/prune', __('Orphan hit rows removed.', 'proto-blocks'))}>
						{sprintf(/* translators: %d: number of hit rows whose rule no longer exists. */ __('Prune orphan hit rows (%d)', 'proto-blocks'), index.db.orphans)}
					</Button>
				</div>
			</Panel>
			{dialogs}
		</div>
	);
}
