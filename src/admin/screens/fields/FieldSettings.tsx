import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Controller, useForm, type Resolver } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { TriangleAlertIcon, XIcon } from 'lucide-react';
import { Tabs as TabsPrimitive } from 'radix-ui';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { interpolate } from '@/admin/lib/interpolate';
import { cn } from '@/admin/lib/utils';
import { compilePattern } from '@/fields/validation/conditions';
import type { ConditionRule } from '@/fields/model/types';
import { phpEmpty } from '@/fields/model/normalize';
import { canonicalType, isFlexible, isObject, layoutList, nameError, slugifyName, uniqueName, type RawField } from './builder-state';
import { ConditionBuilder } from './ConditionBuilder';
import { PRESENTATION_KEYS, settingsFor, TYPE_HELP, TYPE_META, VALIDATION_KEYS, type SettingSpec } from './field-settings-spec';
import { BTN_ICON, CountPill, ERROR, HELP, INPUT, LABEL, MONO, PbSelect, PbSwitch, SANS, Segmented, TEXTAREA } from './pb-ui';

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

function Help({ id, text }: { id: string; text?: ReactNode }) {
	return text ? (
		<p id={id} className={HELP}>
			{text}
		</p>
	) : null;
}

type SettingsTab = 'general' | 'validation' | 'presentation' | 'logic';

/** The design's width steps; 100 is written as no `wrapper.width`. */
const WIDTHS = [
	{ value: '100', label: '100%' },
	{ value: '50', label: '50%' },
	{ value: '33', label: '33%' },
	{ value: '25', label: '25%' },
];

const PLACEHOLDERS: Record<string, string> = { minlength: '—', maxlength: '—', min: '—', max: '—', pattern: '^https://', 'wrapper.class': 'my-class' };

/** General's order: type, label, name, default, instructions, then the type's own settings. */
const GENERAL_ORDER = ['type', 'label', 'name', 'default_value', 'instructions'];
const generalRank = (key: string) => {
	const at = GENERAL_ORDER.indexOf(key);
	return at === -1 ? GENERAL_ORDER.length : at;
};

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
	const [tab, setTab] = useState<SettingsTab>('general');

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

	const nameText = typeof nameValue === 'string' ? nameValue : '';

	const renderControl = (spec: SettingSpec) => {
		const name = formName(spec.key);
		const id = `${idPrefix}-${spec.key.replace(/\./g, '-')}`;
		const helpId = `${id}-help`;
		const errorId = `${id}-error`;
		const error = formState.errors[name]?.message;
		const canonical = canonicalType(type);
		const help: ReactNode =
			spec.key === 'name'
				? interpolate(
						/* translators: %s: PHP call that reads the field, e.g. get_field('subtitle') */
						__('The meta key templates read: %s', 'proto-blocks'),
						<code className={cn(MONO, 'text-micro text-link')}>{`get_field('${nameText}')`}</code>
					)
				: spec.key === 'type'
					? canonical
						? TYPE_HELP[canonical]
						: undefined
					: spec.help;
		const describedBy = [help ? helpId : null, error ? errorId : null, spec.key === 'name' && renamed ? `${id}-rename` : null].filter(Boolean).join(' ') || undefined;
		const common = { id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined, disabled: readOnly };
		const errorNode = error ? (
			<p id={errorId} role="alert" className={ERROR}>
				{String(error)}
			</p>
		) : null;
		const wide = spec.control === 'textarea' || spec.control === 'choices' || spec.key === 'pattern' || spec.key === 'instructions';

		if (spec.control === 'switch') {
			const toggle = (
				<Controller
					control={control}
					name={name}
					render={({ field: f }) => <PbSwitch {...common} checked={Boolean(f.value)} onCheckedChange={(on) => f.onChange(on)} onBlur={f.onBlur} />}
				/>
			);
			if (spec.key === 'required') {
				return (
					<div key={spec.key} className="col-span-full flex items-center justify-between gap-4 rounded-[8px] border border-solid border-border bg-background px-4 py-3.5">
						<div className="grid gap-0.5">
							<label htmlFor={id} className={LABEL}>
								{spec.label}
							</label>
							<Help id={helpId} text={help} />
						</div>
						{toggle}
					</div>
				);
			}
			return (
				<div key={spec.key} className="grid content-start gap-1.5">
					<label htmlFor={id} className={LABEL}>
						{spec.label}
					</label>
					<div className="flex min-h-[38px] items-center gap-2.5">
						{toggle}
						<Help id={helpId} text={help} />
					</div>
				</div>
			);
		}

		if (spec.key === 'wrapper.width') {
			const labelId = `${id}-label`;
			return (
				<div key={spec.key} className="grid content-start gap-1.5">
					<span id={labelId} className={LABEL}>
						{spec.label}
					</span>
					<Controller
						control={control}
						name={name}
						render={({ field: f }) => {
							const current = String(f.value ?? '').replace(/%$/, '');
							const shown = current === '' ? '100' : current;
							const options = WIDTHS.some((w) => w.value === shown) ? WIDTHS : [...WIDTHS, { value: shown, label: `${shown}%` }];
							return <Segmented value={shown} options={options} labelledBy={labelId} describedBy={helpId} disabled={readOnly} onChange={(v) => f.onChange(v === '100' ? '' : v)} />;
						}}
					/>
					<Help id={helpId} text={help} />
				</div>
			);
		}

		if (spec.control === 'multiselect' || ((spec.control === 'postTypes' || spec.control === 'taxonomies') && !spec.single)) {
			const options =
				spec.control === 'multiselect'
					? (spec.options ?? [])
					: (spec.control === 'postTypes' ? (choices.postTypes ?? []) : (choices.taxonomies ?? [])).map((c) => ({ value: c.slug, label: c.label }));
			return (
				<fieldset key={spec.key} className="m-0 grid min-w-0 content-start gap-1.5 border-0 p-0" aria-describedby={describedBy}>
					<legend className={cn(LABEL, 'mb-1.5 p-0')}>{spec.label}</legend>
					<Controller
						control={control}
						name={name}
						render={({ field: f }) => {
							const selected = Array.isArray(f.value) ? f.value : [];
							return (
								<div className="flex min-h-[38px] flex-wrap items-center gap-x-4 gap-y-1.5">
									{options.map((o) => (
										<div key={o.value} className="flex items-center gap-1.5">
											<Checkbox
												id={`${id}-${o.value}`}
												checked={selected.includes(o.value)}
												disabled={readOnly}
												onCheckedChange={(on) => f.onChange(on ? [...selected, o.value] : selected.filter((s) => s !== o.value))}
											/>
											<label htmlFor={`${id}-${o.value}`} className="text-label">
												{o.label}
											</label>
										</div>
									))}
									{options.length === 0 ? <p className={HELP}>{__('None registered.', 'proto-blocks')}</p> : null}
								</div>
							);
						}}
					/>
					<Help id={helpId} text={help} />
				</fieldset>
			);
		}

		let input;
		const mono = spec.key === 'name' || spec.key === 'pattern' || spec.key === 'wrapper.class';
		if (spec.control === 'textarea' || spec.control === 'choices') {
			input = (
				<textarea
					{...common}
					{...register(name)}
					rows={spec.control === 'choices' ? 4 : 2}
					placeholder={spec.key === 'instructions' ? __('Shown under the label on the edit screen.', 'proto-blocks') : undefined}
					className={cn(TEXTAREA, spec.control === 'choices' && cn(MONO, 'text-label'))}
				/>
			);
		} else if (spec.control === 'select' || spec.control === 'postTypes' || spec.control === 'taxonomies') {
			let options = spec.options ?? (spec.control === 'postTypes' ? choices.postTypes : choices.taxonomies)?.map((c) => ({ value: c.slug, label: c.label })) ?? [];
			const current = String(defaults[name] ?? '');
			if (spec.key === 'type' && current !== '' && !options.some((o) => o.value === current)) {
				// An ACF alias (date_picker): keep it as written.
				const aliasOf = canonicalType(current);
				options = [{ value: current, label: `${aliasOf ? TYPE_META[aliasOf].label : current} (${current})` }, ...options];
			} else if (!options.some((o) => o.value === '') && spec.key !== 'type') {
				options = [{ value: '', label: __('Default', 'proto-blocks') }, ...options];
			}
			input = (
				<PbSelect {...common} {...register(name)}>
					{options.map((o) => (
						<option key={o.value} value={o.value}>
							{o.label}
						</option>
					))}
				</PbSelect>
			);
		} else {
			input = (
				<input
					{...common}
					type="text"
					{...register(name, spec.key === 'name' ? { onChange: () => setLinked(false) } : undefined)}
					inputMode={spec.control === 'number' ? 'decimal' : undefined}
					placeholder={PLACEHOLDERS[spec.key]}
					className={cn(INPUT, mono && cn(MONO, 'text-label'))}
				/>
			);
		}

		return (
			<div key={spec.key} className={cn('grid min-w-0 content-start gap-1.5', wide && 'col-span-full')}>
				<label htmlFor={id} className={LABEL}>
					{spec.label}
				</label>
				{input}
				<Help id={helpId} text={help} />
				{errorNode}
				{spec.key === 'name' && renamed ? (
					<p id={`${id}-rename`} className="flex items-center gap-1.5 text-[12.5px] text-warning">
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

	const general = specs.filter((s) => !VALIDATION_KEYS.has(s.key) && !PRESENTATION_KEYS.has(s.key)).sort((a, b) => generalRank(a.key) - generalRank(b.key));
	const validation = specs.filter((s) => VALIDATION_KEYS.has(s.key));
	const presentation = specs.filter((s) => PRESENTATION_KEYS.has(s.key));
	const typeNote = canonicalType(type) ? TYPE_META[canonicalType(type)!].help : undefined;
	const logicCount = Array.isArray(field.conditional_logic) ? field.conditional_logic.length : 0;
	const tabs: { value: SettingsTab; label: string; show: boolean }[] = [
		{ value: 'general', label: __('General', 'proto-blocks'), show: true },
		{ value: 'validation', label: __('Validation', 'proto-blocks'), show: validation.length > 0 },
		{ value: 'presentation', label: __('Presentation', 'proto-blocks'), show: presentation.length > 0 },
		{ value: 'logic', label: __('Conditional logic', 'proto-blocks'), show: true },
	];
	// Every settings panel stays mounted while hidden: react-hook-form keeps every registered control.
	const panel = (value: SettingsTab) => ({ value, forceMount: true as const, hidden: tab !== value, className: 'outline-none' });
	const grid = 'grid max-w-[860px] grid-cols-1 gap-x-6 gap-y-[18px] @[560px]:grid-cols-2';

	return (
		<TabsPrimitive.Root value={tab} onValueChange={(v) => setTab(v as SettingsTab)} className={cn('@container text-body text-foreground', SANS)}>
			<TabsPrimitive.List
				aria-label={__('Field settings sections', 'proto-blocks')}
				className="mb-5 flex flex-wrap gap-0.5 border-0 border-b border-solid border-border"
			>
				{tabs
					.filter((t) => t.show)
					.map((t) => (
						<TabsPrimitive.Trigger
							key={t.value}
							value={t.value}
							className="-mb-px inline-flex cursor-pointer items-center gap-1.5 border-0 border-b-2 border-solid border-transparent bg-transparent px-3.5 py-3 text-body font-medium whitespace-nowrap text-muted-foreground outline-none hover:text-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring data-[state=active]:border-primary data-[state=active]:text-foreground"
						>
							{t.label}
							{t.value === 'logic' ? <CountPill>{logicCount}</CountPill> : null}
						</TabsPrimitive.Trigger>
					))}
			</TabsPrimitive.List>
			<form onSubmit={(e) => e.preventDefault()} onBlur={() => timer.current && flushRef.current()} noValidate>
				<TabsPrimitive.Content {...panel('general')}>
					<div className={grid}>
						{general.map(renderControl)}
						{typeNote ? <p className={cn(HELP, 'col-span-full')}>{typeNote}</p> : null}
						{isFlexible(field) && onLayout ? (
							<fieldset className="col-span-full m-0 grid gap-2 border-0 p-0">
								<legend className={cn(LABEL, 'mb-1.5 p-0')}>{__('Layouts', 'proto-blocks')}</legend>
								{layoutList(field).map((layout, l) => (
									<div key={String(layout.key ?? l)} className="flex items-end gap-2">
										<div className="grid flex-1 gap-1.5">
											<label htmlFor={`${idPrefix}-layout-${l}-label`} className={LABEL}>
												{__('Layout label', 'proto-blocks')}
											</label>
											<input
												id={`${idPrefix}-layout-${l}-label`}
												type="text"
												className={INPUT}
												defaultValue={String(layout.label ?? '')}
												disabled={readOnly}
												onBlur={(e) => e.target.value !== layout.label && onLayout({ type: 'updateLayout', layout: l, patch: { label: e.target.value } })}
											/>
										</div>
										<div className="grid flex-1 gap-1.5">
											<label htmlFor={`${idPrefix}-layout-${l}-name`} className={LABEL}>
												{__('Layout name', 'proto-blocks')}
											</label>
											<input
												id={`${idPrefix}-layout-${l}-name`}
												type="text"
												className={cn(INPUT, MONO, 'text-label')}
												defaultValue={String(layout.name ?? '')}
												disabled={readOnly}
												onBlur={(e) => e.target.value !== layout.name && onLayout({ type: 'updateLayout', layout: l, patch: { name: e.target.value } })}
											/>
										</div>
										{readOnly ? null : (
											<button
												type="button"
												className={cn(BTN_ICON, 'size-[38px]')}
												aria-label={sprintf(/* translators: %s: layout label */ __('Remove layout %s', 'proto-blocks'), String(layout.label || layout.name || ''))}
												onClick={() => onLayout({ type: 'removeLayout', layout: l })}
											>
												<XIcon aria-hidden="true" className="size-3.5" />
											</button>
										)}
									</div>
								))}
							</fieldset>
						) : null}
					</div>
				</TabsPrimitive.Content>
				<TabsPrimitive.Content {...panel('validation')}>
					<div className={grid}>{validation.map(renderControl)}</div>
				</TabsPrimitive.Content>
				<TabsPrimitive.Content {...panel('presentation')}>
					<div className={grid}>{presentation.map(renderControl)}</div>
				</TabsPrimitive.Content>
				<TabsPrimitive.Content value="logic" className="outline-none">
					<ConditionBuilder
						fields={conditionScope}
						selfKey={typeof field.key === 'string' ? field.key : undefined}
						value={field.conditional_logic}
						readOnly={readOnly}
						onChange={(v: ConditionRule[][] | 0) => onChange({ conditional_logic: v })}
					/>
				</TabsPrimitive.Content>
			</form>
		</TabsPrimitive.Root>
	);
}
