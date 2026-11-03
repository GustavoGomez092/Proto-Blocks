import { useEffect, useMemo, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Controller, useForm, type Resolver } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { TriangleAlertIcon, XIcon } from 'lucide-react';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Switch } from '@/admin/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/admin/components/ui/tabs';
import { compilePattern } from '@/fields/validation/conditions';
import type { ConditionRule } from '@/fields/model/types';
import { phpEmpty } from '@/fields/model/normalize';
import { canonicalType, isFlexible, isObject, layoutList, nameError, slugifyName, uniqueName, type RawField } from './builder-state';
import { ConditionBuilder } from './ConditionBuilder';
import { settingsFor, TYPE_META, type SettingSpec } from './field-settings-spec';

export interface Choice {
	slug: string;
	label: string;
}

export interface FieldSettingsProps {
	/** The selected field, raw. Remount (key it by field key) when the selection changes. */
	field: RawField;
	/** The other fields at the same level: duplicate names are refused, conditions read them. */
	siblings: RawField[];
	/** The field's name in the saved file; renaming it shows the meta key warning. Undefined for a new field. */
	savedName?: string;
	/** Post types and taxonomies from GET admin/fields/<key>. */
	choices?: { postTypes?: Choice[]; taxonomies?: Choice[] };
	/** A partial update of the field (undefined removes a key), debounced 150 ms. */
	onChange: (patch: Record<string, unknown>) => void;
	/** Flexible content layouts: `addLayout`, `removeLayout`, `updateLayout` on this field. */
	onLayout?: (action: { type: 'addLayout' | 'removeLayout' | 'updateLayout'; layout?: number; patch?: Record<string, unknown> }) => void;
	readOnly?: boolean;
	/** Prefix of every control id: `<idPrefix>-<setting>` (`wrapper.width` → `wrapper-width`). */
	idPrefix?: string;
}

type FormValue = string | boolean | string[];
type Values = Record<string, FormValue>;

const DEBOUNCE_MS = 150;

/** Form names cannot hold dots without nesting. */
const formName = (key: string) => key.replace(/\./g, '__');

function readPath(field: RawField, key: string): unknown {
	return key.split('.').reduce<unknown>((v, k) => (isObject(v) ? v[k] : undefined), field);
}

function choicesToText(v: unknown): string {
	if (Array.isArray(v)) {
		return v.map(String).join('\n');
	}
	return isObject(v)
		? Object.entries(v)
				.map(([k, label]) => (String(label) === k ? k : `${k} : ${String(label)}`))
				.join('\n')
		: '';
}

function textToChoices(text: string): Record<string, string> {
	const out: Record<string, string> = {};
	for (const line of text.split('\n')) {
		if (line.trim() === '') {
			continue;
		}
		const at = line.indexOf(':');
		const value = (at === -1 ? line : line.slice(0, at)).trim();
		const label = (at === -1 ? line : line.slice(at + 1)).trim();
		if (value !== '') {
			out[value] = label === '' ? value : label;
		}
	}
	return out;
}

function toForm(spec: SettingSpec, field: RawField): FormValue {
	const v = readPath(field, spec.key);
	switch (spec.control) {
		case 'switch':
			return !phpEmpty(v);
		case 'multiselect':
		case 'postTypes':
		case 'taxonomies':
			if (spec.single) {
				return typeof v === 'string' ? v : '';
			}
			return Array.isArray(v) ? v.map(String) : typeof v === 'string' && v !== '' ? [v] : [];
		case 'choices':
			return choicesToText(v);
		default:
			return typeof v === 'string' || typeof v === 'number' ? String(v) : '';
	}
}

/** The field value for a form value; undefined removes the key. */
function fromForm(spec: SettingSpec, value: FormValue, field: RawField): unknown {
	const had = readPath(field, spec.key) !== undefined;
	switch (spec.control) {
		case 'switch':
			// ACF writes 0/1; keep whichever the file uses.
			return typeof readPath(field, spec.key) === 'number' ? (value ? 1 : 0) : Boolean(value);
		case 'number': {
			const text = String(value).trim();
			return text === '' ? undefined : Number(text);
		}
		case 'choices':
			return textToChoices(String(value));
		case 'multiselect':
		case 'postTypes':
		case 'taxonomies':
			return spec.single ? (value === '' && !had ? undefined : value) : value;
		default:
			return value === '' && (!had || spec.key === 'optionsSource') ? undefined : value;
	}
}

function patchFor(spec: SettingSpec, value: FormValue, field: RawField): Record<string, unknown> {
	const next = fromForm(spec, value, field);
	const dot = spec.key.indexOf('.');
	if (dot === -1) {
		return { [spec.key]: next };
	}
	const outer = spec.key.slice(0, dot);
	const inner = spec.key.slice(dot + 1);
	const current = isObject(field[outer]) ? (field[outer] as Record<string, unknown>) : {};
	const merged: Record<string, unknown> = { ...current };
	if (next === undefined || next === '') {
		delete merged[inner];
	} else {
		merged[inner] = next;
	}
	return { [outer]: Object.keys(merged).length > 0 ? merged : undefined };
}

const NUMBER = /^-?\d+(\.\d+)?$/;

function schemaFor(specs: SettingSpec[], siblings: RawField[]) {
	return z
		.record(z.string(), z.unknown())
		.superRefine((values, ctx) => {
			const name = nameError(values.name, values.type, siblings);
			if (name) {
				ctx.addIssue({ code: 'custom', path: ['name'], message: name });
			}
			for (const spec of specs) {
				const v = values[formName(spec.key)];
				if (spec.control === 'number' && typeof v === 'string' && v.trim() !== '' && !NUMBER.test(v.trim())) {
					ctx.addIssue({ code: 'custom', path: [formName(spec.key)], message: __('Enter a number.', 'proto-blocks') });
				}
				if (spec.key === 'pattern' && typeof v === 'string' && v !== '' && compilePattern(v) === null) {
					ctx.addIssue({ code: 'custom', path: ['pattern'], message: __('Enter a valid regular expression (200 bytes at most).', 'proto-blocks') });
				}
			}
		});
}

function Help({ id, text }: { id: string; text?: string }) {
	return text ? (
		<p id={id} className="text-xs text-muted-foreground">
			{text}
		</p>
	) : null;
}

/**
 * The selected field's settings, built from field-settings-spec: the common
 * settings, the type's own, flexible layouts and the conditional logic.
 * react-hook-form + zod validate as you type (the name with the server's
 * grammar, unique at its level); every change is reported through
 * `onChange` as a patch 150 ms after the last keystroke, invalid values
 * included, so the file preview always shows what Save would write.
 */
export function FieldSettings({ field, siblings, savedName, choices = {}, onChange, onLayout, readOnly = false, idPrefix = 'pb-field' }: FieldSettingsProps) {
	const type = typeof field.type === 'string' ? field.type : '';
	const specs = useMemo(() => settingsFor(type), [type]);
	const specByName = useMemo(() => new Map(specs.map((s) => [formName(s.key), s])), [specs]);
	const defaults = useMemo(() => Object.fromEntries(specs.map((s) => [formName(s.key), toForm(s, field)])) as Values, [specs, field]);
	const resolver = useMemo(() => zodResolver(schemaFor(specs, siblings) as never) as unknown as Resolver<Values>, [specs, siblings]);
	const form = useForm<Values>({ defaultValues: defaults, resolver, mode: 'onChange' });
	const { register, control, formState, watch, setValue, trigger } = form;

	// The name follows the label for a field that is not in the file yet, until the name is typed in.
	const [linked, setLinked] = useState(savedName === undefined && (field.name === '' || field.name === uniqueName(slugifyName(String(field.label ?? '')), siblings)));
	const linkedRef = useRef(linked);
	linkedRef.current = linked;

	const fieldRef = useRef(field);
	fieldRef.current = field;
	const onChangeRef = useRef(onChange);
	onChangeRef.current = onChange;
	const pending = useRef<Set<string>>(new Set());
	const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
	const flushRef = useRef<() => void>(() => {});

	useEffect(() => {
		const flush = () => {
			if (timer.current) {
				clearTimeout(timer.current);
			}
			timer.current = null;
			const values = form.getValues();
			let patch: Record<string, unknown> = {};
			let merged = fieldRef.current;
			for (const name of pending.current) {
				const spec = specByName.get(name);
				if (spec) {
					const part = patchFor(spec, values[name], merged);
					patch = { ...patch, ...part };
					merged = { ...merged, ...part };
				}
			}
			pending.current.clear();
			if (Object.keys(patch).length > 0) {
				onChangeRef.current(patch);
			}
		};
		flushRef.current = flush;
		const sub = watch((values, { name }) => {
			if (!name) {
				return;
			}
			pending.current.add(name);
			if (name === 'label' && linkedRef.current) {
				const next = uniqueName(slugifyName(String(values.label ?? '')), siblings);
				if (values.name !== next) {
					setValue('name', next, { shouldValidate: true });
				}
			}
			if (timer.current) {
				clearTimeout(timer.current);
			}
			timer.current = setTimeout(flush, DEBOUNCE_MS);
		});
		return () => {
			sub.unsubscribe();
			if (timer.current) {
				flush();
			}
		};
	}, [watch, setValue, form, specByName, siblings]);

	// Show a problem the file already has (e.g. an imported name) without waiting for an edit.
	useEffect(() => {
		void trigger('name');
	}, [trigger]);

	const nameValue = watch('name');
	const renamed = savedName !== undefined && typeof nameValue === 'string' && nameValue !== savedName;
	const conditionScope = siblings;

	const renderControl = (spec: SettingSpec) => {
		const name = formName(spec.key);
		const id = `${idPrefix}-${spec.key.replace(/\./g, '-')}`;
		const helpId = `${id}-help`;
		const errorId = `${id}-error`;
		const error = formState.errors[name]?.message;
		const describedBy = [spec.help ? helpId : null, error ? errorId : null, spec.key === 'name' && renamed ? `${id}-rename` : null].filter(Boolean).join(' ') || undefined;
		const common = { id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined, disabled: readOnly };
		const errorNode = error ? (
			<p id={errorId} role="alert" className="text-[13px] text-destructive">
				{String(error)}
			</p>
		) : null;

		if (spec.control === 'switch') {
			return (
				<div key={spec.key} className="grid gap-1">
					<div className="flex items-center gap-2">
						<Controller
							control={control}
							name={name}
							render={({ field: f }) => <Switch {...common} checked={Boolean(f.value)} onCheckedChange={(on) => f.onChange(on)} onBlur={f.onBlur} />}
						/>
						<Label htmlFor={id}>{spec.label}</Label>
					</div>
					<Help id={helpId} text={spec.help} />
				</div>
			);
		}

		if (spec.control === 'multiselect' || ((spec.control === 'postTypes' || spec.control === 'taxonomies') && !spec.single)) {
			const options =
				spec.control === 'multiselect'
					? (spec.options ?? [])
					: (spec.control === 'postTypes' ? (choices.postTypes ?? []) : (choices.taxonomies ?? [])).map((c) => ({ value: c.slug, label: c.label }));
			return (
				<fieldset key={spec.key} className="grid gap-1.5" aria-describedby={describedBy}>
					<legend className="mb-1 text-sm font-medium">{spec.label}</legend>
					<Controller
						control={control}
						name={name}
						render={({ field: f }) => {
							const selected = Array.isArray(f.value) ? f.value : [];
							return (
								<div className="flex flex-wrap gap-x-4 gap-y-1.5">
									{options.map((o) => (
										<div key={o.value} className="flex items-center gap-1.5">
											<Checkbox
												id={`${id}-${o.value}`}
												checked={selected.includes(o.value)}
												disabled={readOnly}
												onCheckedChange={(on) => f.onChange(on ? [...selected, o.value] : selected.filter((s) => s !== o.value))}
											/>
											<Label htmlFor={`${id}-${o.value}`} className="font-normal">
												{o.label}
											</Label>
										</div>
									))}
									{options.length === 0 ? <p className="text-xs text-muted-foreground">{__('None registered.', 'proto-blocks')}</p> : null}
								</div>
							);
						}}
					/>
					<Help id={helpId} text={spec.help} />
				</fieldset>
			);
		}

		let input;
		if (spec.control === 'textarea' || spec.control === 'choices') {
			input = (
				<textarea
					{...common}
					{...register(name)}
					rows={spec.control === 'choices' ? 5 : 3}
					className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive"
				/>
			);
		} else if (spec.control === 'select' || spec.control === 'postTypes' || spec.control === 'taxonomies') {
			let options = spec.options ?? (spec.control === 'postTypes' ? choices.postTypes : choices.taxonomies)?.map((c) => ({ value: c.slug, label: c.label })) ?? [];
			const current = String(defaults[name] ?? '');
			if (spec.key === 'type' && current !== '' && !options.some((o) => o.value === current)) {
				// An ACF alias (date_picker): keep it as written.
				const canonical = canonicalType(current);
				options = [{ value: current, label: `${canonical ? TYPE_META[canonical].label : current} (${current})` }, ...options];
			} else if (!options.some((o) => o.value === '') && spec.key !== 'type') {
				options = [{ value: '', label: __('Default', 'proto-blocks') }, ...options];
			}
			input = (
				<select
					{...common}
					{...register(name)}
					className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
				>
					{options.map((o) => (
						<option key={o.value} value={o.value}>
							{o.label}
						</option>
					))}
				</select>
			);
		} else {
			input = (
				<Input
					{...common}
					{...register(name, spec.key === 'name' ? { onChange: () => setLinked(false) } : undefined)}
					inputMode={spec.control === 'number' ? 'decimal' : undefined}
					className={spec.key === 'name' ? 'font-mono' : undefined}
				/>
			);
		}

		return (
			<div key={spec.key} className="grid gap-1.5">
				<Label htmlFor={id}>{spec.label}</Label>
				{input}
				<Help id={helpId} text={spec.help} />
				{errorNode}
				{spec.key === 'name' && renamed ? (
					<p id={`${id}-rename`} className="flex items-center gap-1.5 text-[13px] text-warning">
						<TriangleAlertIcon aria-hidden="true" className="size-4 shrink-0" />
						{sprintf(
							/* translators: %s: the field's previous name (its meta key) */
							__('Stored values stay under the old meta key "%s".', 'proto-blocks'),
							savedName
						)}
					</p>
				) : null}
			</div>
		);
	};

	const typeHelp = canonicalType(type) ? TYPE_META[canonicalType(type)!].help : undefined;

	return (
		<Tabs defaultValue="settings">
			<TabsList aria-label={__('Field settings sections', 'proto-blocks')}>
				<TabsTrigger value="settings">{__('Settings', 'proto-blocks')}</TabsTrigger>
				<TabsTrigger value="logic">
					{__('Conditional logic', 'proto-blocks')}
					{Array.isArray(field.conditional_logic) && field.conditional_logic.length > 0 ? (
						<span className="rounded-full bg-muted px-1.5 text-xs">{field.conditional_logic.length}</span>
					) : null}
				</TabsTrigger>
			</TabsList>
			<form className="grid gap-4" onSubmit={(e) => e.preventDefault()} onBlur={() => timer.current && flushRef.current()} noValidate>
				{/* Mounted while hidden: react-hook-form keeps every registered control. */}
				<TabsContent value="settings" forceMount className="grid gap-4 data-[state=inactive]:hidden">
					{specs.map(renderControl)}
					{typeHelp ? <p className="text-xs text-muted-foreground">{typeHelp}</p> : null}

					{isFlexible(field) && onLayout ? (
						<fieldset className="grid gap-2">
							<legend className="mb-1 text-sm font-medium">{__('Layouts', 'proto-blocks')}</legend>
							{layoutList(field).map((layout, l) => (
								<div key={String(layout.key ?? l)} className="flex items-end gap-2">
									<div className="grid flex-1 gap-1">
										<Label htmlFor={`${idPrefix}-layout-${l}-label`}>{__('Layout label', 'proto-blocks')}</Label>
										<Input
											id={`${idPrefix}-layout-${l}-label`}
											defaultValue={String(layout.label ?? '')}
											disabled={readOnly}
											onBlur={(e) => e.target.value !== layout.label && onLayout({ type: 'updateLayout', layout: l, patch: { label: e.target.value } })}
										/>
									</div>
									<div className="grid flex-1 gap-1">
										<Label htmlFor={`${idPrefix}-layout-${l}-name`}>{__('Layout name', 'proto-blocks')}</Label>
										<Input
											id={`${idPrefix}-layout-${l}-name`}
											className="font-mono"
											defaultValue={String(layout.name ?? '')}
											disabled={readOnly}
											onBlur={(e) => e.target.value !== layout.name && onLayout({ type: 'updateLayout', layout: l, patch: { name: e.target.value } })}
										/>
									</div>
									{readOnly ? null : (
										<Button
											type="button"
											variant="ghost"
											size="icon"
											aria-label={sprintf(/* translators: %s: layout label */ __('Remove layout %s', 'proto-blocks'), String(layout.label || layout.name || ''))}
											onClick={() => onLayout({ type: 'removeLayout', layout: l })}
										>
											<XIcon aria-hidden="true" />
										</Button>
									)}
								</div>
							))}
						</fieldset>
					) : null}
				</TabsContent>
				<TabsContent value="logic">
					<section className="grid gap-2" aria-labelledby={`${idPrefix}-logic`}>
						<h3 id={`${idPrefix}-logic`} className="text-sm font-medium">
							{__('Show this field if', 'proto-blocks')}
						</h3>
						<ConditionBuilder
							fields={conditionScope}
							selfKey={typeof field.key === 'string' ? field.key : undefined}
							value={field.conditional_logic}
							readOnly={readOnly}
							onChange={(v: ConditionRule[][] | 0) => onChange({ conditional_logic: v })}
						/>
					</section>
				</TabsContent>
			</form>
		</Tabs>
	);
}
