import { useId, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { InfoIcon, PlusIcon, TriangleAlertIcon, XIcon } from 'lucide-react';
import { cn } from '@/admin/lib/utils';
import type { Choice } from './FieldSettings';
import { BTN_DASHED, BTN_ICON, BTN_LINK, ERROR, EYEBROW, HELP, INPUT, PbSelect, RULE_CARD } from './pb-ui';

/** One location rule, exactly as LocationMatcher reads it. Values are plain strings. */
export interface LocationRule {
	param: string;
	operator: '==' | '!=';
	value: string;
}

/** The choice lists of GET admin/fields/<key>. */
export interface LocationChoices {
	postTypes?: Choice[];
	taxonomies?: Choice[];
	roles?: Choice[];
	optionsPages?: Choice[];
	pageTemplates?: Choice[];
}

export interface LocationBuilderProps {
	/** The group's `location`: an OR-list of AND-lists of rules. */
	value: LocationRule[][];
	choices: LocationChoices;
	onChange: (value: LocationRule[][]) => void;
	/** A label when the location is set by the file the group lives in (a type or taxonomy file); no rows then. */
	implied?: string;
	readOnly?: boolean;
}

/**
 * The params LocationMatcher evaluates (GroupNormalizer::PARAM_KINDS), with
 * the kind of screen each one matches. Nothing else is offered: the matcher
 * refuses every other param.
 */
const PARAMS: Record<string, { label: string; kind: 'post' | 'term' | 'user' | 'option' }> = {
	post_type: { label: __('Post type', 'proto-blocks'), kind: 'post' },
	post_status: { label: __('Post status', 'proto-blocks'), kind: 'post' },
	post_taxonomy: { label: __('Post term', 'proto-blocks'), kind: 'post' },
	page_template: { label: __('Page template', 'proto-blocks'), kind: 'post' },
	post: { label: __('Post (ID)', 'proto-blocks'), kind: 'post' },
	taxonomy: { label: __('Taxonomy term form', 'proto-blocks'), kind: 'term' },
	user_role: { label: __('User role', 'proto-blocks'), kind: 'user' },
	user_form: { label: __('User form', 'proto-blocks'), kind: 'user' },
	options_page: { label: __('Settings page', 'proto-blocks'), kind: 'option' },
};

const STATUSES: Choice[] = [
	{ slug: 'publish', label: __('Published', 'proto-blocks') },
	{ slug: 'draft', label: __('Draft', 'proto-blocks') },
	{ slug: 'pending', label: __('Pending review', 'proto-blocks') },
	{ slug: 'private', label: __('Private', 'proto-blocks') },
	{ slug: 'future', label: __('Scheduled', 'proto-blocks') },
];

const ALL: Choice = { slug: 'all', label: __('All', 'proto-blocks') };

const USER_FORMS: Choice[] = [
	ALL,
	{ slug: 'add', label: __('Add user', 'proto-blocks') },
	{ slug: 'edit', label: __('Edit user and profile', 'proto-blocks') },
	{ slug: 'register', label: __('Registration', 'proto-blocks') },
];

/** The value list for a param; null when the value is typed (post ID, post_taxonomy's term). */
function valueChoices(param: string, choices: LocationChoices): Choice[] | null {
	switch (param) {
		case 'post_type':
			return choices.postTypes ?? [];
		case 'post_status':
			return STATUSES;
		case 'page_template':
			return [{ slug: 'default', label: __('Default template', 'proto-blocks') }, ...(choices.pageTemplates ?? [])];
		case 'taxonomy':
			return [ALL, ...(choices.taxonomies ?? [])];
		case 'user_role':
			return [ALL, ...(choices.roles ?? [])];
		case 'user_form':
			return USER_FORMS;
		case 'options_page':
			return choices.optionsPages ?? [];
		default:
			return null;
	}
}

function firstValue(param: string, choices: LocationChoices): string {
	if (param === 'post_taxonomy') {
		const tax = choices.taxonomies?.[0]?.slug;
		return tax ? `${tax}:` : '';
	}
	return valueChoices(param, choices)?.[0]?.slug ?? '';
}

function makeRule(param: string, choices: LocationChoices, operator: '==' | '!=' = '=='): LocationRule {
	return { param, operator, value: firstValue(param, choices) };
}

/** Coerces the raw `location` of a file into rule rows; anything that is not a rule object is dropped. */
export function readLocation(raw: unknown): LocationRule[][] {
	if (!Array.isArray(raw)) {
		return [];
	}
	return raw.filter(Array.isArray).map((branch) =>
		(branch as unknown[])
			.filter((r): r is Record<string, unknown> => r !== null && typeof r === 'object' && !Array.isArray(r))
			.map((r) => ({
				param: String(r.param ?? ''),
				operator: r.operator === '!=' ? '!=' : '==',
				value: typeof r.value === 'string' || typeof r.value === 'number' ? String(r.value) : '',
			}))
	);
}

/** LocationMatcher's post ID grammar. */
const POST_ID = /^[1-9][0-9]{0,18}$/;

/** The param labels, for summaries elsewhere (the group settings nav). */
export function locationParamLabel(param: string): string {
	return PARAMS[param]?.label ?? param;
}

const ROW = 'grid grid-cols-[minmax(0,1fr)_110px_minmax(0,1fr)_32px] items-center gap-2';

/**
 * "Show this field group if…": OR groups of AND rows of `param operator
 * value`, limited to what LocationMatcher accepts. An unsupported param that
 * is already in the file is shown locked, with only a remove button.
 */
export function LocationBuilder({ value, choices, onChange, implied, readOnly = false }: LocationBuilderProps) {
	const uid = useId();
	if (implied !== undefined) {
		return (
			<p className="flex items-center gap-2.5 rounded-lg bg-code px-3.5 py-2.5 text-label text-link">
				<InfoIcon aria-hidden="true" className="size-3.5 flex-none" />
				{sprintf(/* translators: %s: post type or taxonomy label */ __('Shown on %s — set by the file it lives in', 'proto-blocks'), implied)}
			</p>
		);
	}

	const emit = (next: LocationRule[][]) => onChange(next.filter((g) => g.length > 0));
	const setRule = (g: number, r: number, rule: LocationRule) => emit(value.map((grp, gi) => (gi === g ? grp.map((old, ri) => (ri === r ? rule : old)) : grp)));
	const removeRule = (g: number, r: number) => emit(value.map((grp, gi) => (gi === g ? grp.filter((_, ri) => ri !== r) : grp)));

	const hintId = (g: number, r: number) => `${uid}-${g}-${r}`;
	const hint = (g: number, r: number, text: string) => (
		<p id={`${hintId(g, r)}-hint`} className={ERROR}>
			{text}
		</p>
	);
	const removeButton = (g: number, r: number, label: string) =>
		readOnly ? (
			<span />
		) : (
			<button type="button" className={cn(BTN_ICON, 'size-8 rounded-md')} aria-label={label} onClick={() => removeRule(g, r)}>
				<XIcon aria-hidden="true" className="size-3.5" />
			</button>
		);

	/** The value cell, and a hint shown under the row when the value can never match. */
	const valueControl = (rule: LocationRule, g: number, r: number): [ReactNode, ReactNode] => {
		if (rule.param === 'post_taxonomy') {
			const at = rule.value.indexOf(':');
			const tax = at === -1 ? rule.value : rule.value.slice(0, at);
			const term = at === -1 ? '' : rule.value.slice(at + 1);
			return [
				<div key="v" className="grid min-w-0 grid-cols-2 gap-2">
					<PbSelect compact aria-label={__('Taxonomy', 'proto-blocks')} value={tax} disabled={readOnly} onChange={(e) => setRule(g, r, { ...rule, value: `${e.target.value}:${term}` })}>
						{(choices.taxonomies ?? []).some((c) => c.slug === tax) ? null : <option value={tax}>{tax}</option>}
						{(choices.taxonomies ?? []).map((c) => (
							<option key={c.slug} value={c.slug}>
								{c.label}
							</option>
						))}
					</PbSelect>
					<input
						type="text"
						id={hintId(g, r)}
						aria-label={__('Term slug', 'proto-blocks')}
						placeholder={__('term-slug', 'proto-blocks')}
						className={cn(INPUT, 'h-9 font-mono text-label')}
						value={term}
						disabled={readOnly}
						aria-invalid={term === '' || undefined}
						aria-describedby={term === '' ? `${hintId(g, r)}-hint` : undefined}
						onChange={(e) => setRule(g, r, { ...rule, value: `${tax}:${e.target.value}` })}
					/>
				</div>,
				term === '' ? hint(g, r, __('Enter the term slug; the rule never matches without one.', 'proto-blocks')) : null,
			];
		}
		const list = valueChoices(rule.param, choices);
		if (list === null) {
			const invalid = !POST_ID.test(rule.value);
			return [
				<input
					key="v"
					type="text"
					aria-label={__('Value', 'proto-blocks')}
					className={cn(INPUT, 'h-9 font-mono text-label')}
					inputMode="numeric"
					value={rule.value}
					disabled={readOnly}
					aria-invalid={invalid || undefined}
					aria-describedby={invalid ? `${hintId(g, r)}-hint` : undefined}
					onChange={(e) => setRule(g, r, { ...rule, value: e.target.value })}
				/>,
				invalid ? hint(g, r, __('Enter a post ID (digits only); the rule never matches otherwise.', 'proto-blocks')) : null,
			];
		}
		return [
			<PbSelect key="v" compact aria-label={__('Value', 'proto-blocks')} value={rule.value} disabled={readOnly} onChange={(e) => setRule(g, r, { ...rule, value: e.target.value })}>
				{list.some((c) => c.slug === rule.value) ? null : <option value={rule.value}>{rule.value}</option>}
				{list.map((c) => (
					<option key={c.slug} value={c.slug}>
						{c.label}
					</option>
				))}
			</PbSelect>,
			null,
		];
	};

	return (
		<div className="grid gap-3.5">
			{value.length === 0 ? <p className={HELP}>{__('No rules: the group is not shown anywhere yet.', 'proto-blocks')}</p> : null}
			{value.map((group, g) => {
				const kinds = new Set(group.map((rule) => PARAMS[rule.param]?.kind).filter(Boolean));
				return (
					<div key={g} className="grid gap-3.5">
						{g > 0 ? <p className={EYEBROW}>{__('or', 'proto-blocks')}</p> : null}
						<div role="group" aria-label={sprintf(/* translators: %d: rule group number */ __('Location group %d', 'proto-blocks'), g + 1)} className={RULE_CARD}>
							{group.map((rule, r) => {
								const and = r > 0 ? <span className={cn(EYEBROW, 'text-[10px]')}>{__('and', 'proto-blocks')}</span> : null;
								if (!PARAMS[rule.param]) {
									return (
										<div key={r} className="grid gap-1.5">
											{and}
											<div className={cn(ROW, 'rounded-md border border-solid border-destructive/40 bg-destructive-soft px-2.5 py-1.5')}>
												<span className="col-span-3 grid gap-0.5">
													<code className="font-mono text-label">{`${rule.param} ${rule.operator} ${rule.value}`}</code>
													<span className={ERROR}>
														{sprintf(
															/* translators: %s: location param name */ __('Not supported by Proto-Blocks: %s. This group will not be shown.', 'proto-blocks'),
															rule.param
														)}
													</span>
												</span>
												{removeButton(g, r, __('Remove unsupported rule', 'proto-blocks'))}
											</div>
										</div>
									);
								}
								const [control, problem] = valueControl(rule, g, r);
								return (
									<div key={r} className="grid gap-1.5">
										{and}
										<div className={ROW}>
											<PbSelect compact aria-label={__('Rule', 'proto-blocks')} value={rule.param} disabled={readOnly} onChange={(e) => setRule(g, r, makeRule(e.target.value, choices, rule.operator))}>
												{Object.entries(PARAMS).map(([param, meta]) => (
													<option key={param} value={param}>
														{meta.label}
													</option>
												))}
											</PbSelect>
											<PbSelect
												compact
												aria-label={__('Operator', 'proto-blocks')}
												value={rule.operator}
												disabled={readOnly}
												onChange={(e) => setRule(g, r, { ...rule, operator: e.target.value === '!=' ? '!=' : '==' })}
											>
												<option value="==">{__('is', 'proto-blocks')}</option>
												<option value="!=">{__('is not', 'proto-blocks')}</option>
											</PbSelect>
											{control}
											{removeButton(g, r, __('Remove rule', 'proto-blocks'))}
										</div>
										{problem}
									</div>
								);
							})}
							{kinds.size > 1 ? (
								<p role="alert" className="flex items-center gap-1.5 text-caption text-warning">
									<TriangleAlertIcon aria-hidden="true" className="size-4 shrink-0" />
									{__('Rules for different screens in one group never match together. Put them in separate "or" groups.', 'proto-blocks')}
								</p>
							) : null}
							{readOnly ? null : (
								<button
									type="button"
									className={BTN_LINK}
									aria-label={sprintf(/* translators: %d: rule group number */ __('Add a rule to location group %d', 'proto-blocks'), g + 1)}
									onClick={() => {
										const last = group[group.length - 1];
										emit(value.map((grp, gi) => (gi === g ? [...grp, makeRule(last && PARAMS[last.param] ? last.param : 'post_type', choices)] : grp)));
									}}
								>
									<PlusIcon aria-hidden="true" className="size-[13px]" strokeWidth={2.5} />
									{__('and', 'proto-blocks')}
								</button>
							)}
						</div>
					</div>
				);
			})}
			{readOnly ? null : value.length === 0 ? (
				<button type="button" className={BTN_DASHED} onClick={() => emit([[makeRule('post_type', choices)]])}>
					<PlusIcon aria-hidden="true" className="size-[13px]" strokeWidth={2.5} />
					{__('Add rule', 'proto-blocks')}
				</button>
			) : (
				<button type="button" className={BTN_DASHED} onClick={() => emit([...value, [makeRule('post_type', choices)]])}>
					<PlusIcon aria-hidden="true" className="size-[13px]" strokeWidth={2.5} />
					{__('or, another rule group', 'proto-blocks')}
				</button>
			)}
		</div>
	);
}
