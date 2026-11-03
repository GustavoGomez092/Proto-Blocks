import { __, sprintf } from '@wordpress/i18n';
import { PlusIcon, TriangleAlertIcon, XIcon } from 'lucide-react';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import type { Choice } from './FieldSettings';

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

const SELECT = 'h-8 rounded-md border bg-transparent px-2 text-sm';

/**
 * "Show this field group if…": OR groups of AND rows of `param operator
 * value`, limited to what LocationMatcher accepts. An unsupported param that
 * is already in the file is shown locked, with only a remove button.
 */
export function LocationBuilder({ value, choices, onChange, implied, readOnly = false }: LocationBuilderProps) {
	if (implied !== undefined) {
		return (
			<p className="rounded-md border bg-muted px-3 py-2 text-sm">
				{sprintf(/* translators: %s: post type or taxonomy label */ __('Shown on %s — set by the file it lives in', 'proto-blocks'), implied)}
			</p>
		);
	}

	const emit = (next: LocationRule[][]) => onChange(next.filter((g) => g.length > 0));
	const setRule = (g: number, r: number, rule: LocationRule) => emit(value.map((grp, gi) => (gi === g ? grp.map((old, ri) => (ri === r ? rule : old)) : grp)));
	const removeRule = (g: number, r: number) => emit(value.map((grp, gi) => (gi === g ? grp.filter((_, ri) => ri !== r) : grp)));

	const valueControl = (rule: LocationRule, g: number, r: number) => {
		if (rule.param === 'post_taxonomy') {
			const at = rule.value.indexOf(':');
			const tax = at === -1 ? rule.value : rule.value.slice(0, at);
			const term = at === -1 ? '' : rule.value.slice(at + 1);
			return (
				<>
					<select
						aria-label={__('Taxonomy', 'proto-blocks')}
						className={SELECT}
						value={tax}
						disabled={readOnly}
						onChange={(e) => setRule(g, r, { ...rule, value: `${e.target.value}:${term}` })}
					>
						{(choices.taxonomies ?? []).some((c) => c.slug === tax) ? null : <option value={tax}>{tax}</option>}
						{(choices.taxonomies ?? []).map((c) => (
							<option key={c.slug} value={c.slug}>
								{c.label}
							</option>
						))}
					</select>
					<Input
						aria-label={__('Term slug', 'proto-blocks')}
						className="h-8 w-40 font-mono"
						value={term}
						disabled={readOnly}
						onChange={(e) => setRule(g, r, { ...rule, value: `${tax}:${e.target.value}` })}
					/>
				</>
			);
		}
		const list = valueChoices(rule.param, choices);
		if (list === null) {
			return (
				<Input
					aria-label={__('Value', 'proto-blocks')}
					className="h-8 w-32 font-mono"
					inputMode="numeric"
					value={rule.value}
					disabled={readOnly}
					onChange={(e) => setRule(g, r, { ...rule, value: e.target.value })}
				/>
			);
		}
		return (
			<select
				aria-label={__('Value', 'proto-blocks')}
				className={SELECT}
				value={rule.value}
				disabled={readOnly}
				onChange={(e) => setRule(g, r, { ...rule, value: e.target.value })}
			>
				{list.some((c) => c.slug === rule.value) ? null : <option value={rule.value}>{rule.value}</option>}
				{list.map((c) => (
					<option key={c.slug} value={c.slug}>
						{c.label}
					</option>
				))}
			</select>
		);
	};

	return (
		<div className="grid gap-3">
			{value.length === 0 ? <p className="text-sm text-muted-foreground">{__('No rules: the group is not shown anywhere yet.', 'proto-blocks')}</p> : null}
			{value.map((group, g) => {
				const kinds = new Set(group.map((rule) => PARAMS[rule.param]?.kind).filter(Boolean));
				return (
					<div
						key={g}
						role="group"
						aria-label={sprintf(/* translators: %d: rule group number */ __('Location group %d', 'proto-blocks'), g + 1)}
						className="grid gap-2 rounded-md border p-2"
					>
						{g > 0 ? <p className="text-xs font-medium text-muted-foreground uppercase">{__('or', 'proto-blocks')}</p> : null}
						{group.map((rule, r) => {
							const connector = <span className="text-xs text-muted-foreground">{r === 0 ? __('Show if', 'proto-blocks') : __('and', 'proto-blocks')}</span>;
							if (!PARAMS[rule.param]) {
								return (
									<div key={r} className="flex flex-wrap items-center gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-2 py-1.5">
										{connector}
										<span className="font-mono text-sm">{`${rule.param} ${rule.operator} ${rule.value}`}</span>
										<span className="flex-1 text-[13px] text-destructive">
											{sprintf(
												/* translators: %s: location param name */ __('Not supported by Proto-Blocks: %s. This group will not be shown.', 'proto-blocks'),
												rule.param
											)}
										</span>
										{readOnly ? null : (
											<Button
												type="button"
												variant="ghost"
												size="icon-sm"
												aria-label={__('Remove unsupported rule', 'proto-blocks')}
												onClick={() => removeRule(g, r)}
											>
												<XIcon aria-hidden="true" />
											</Button>
										)}
									</div>
								);
							}
							return (
								<div key={r} className="flex flex-wrap items-center gap-2">
									{connector}
									<select
										aria-label={__('Rule', 'proto-blocks')}
										className={SELECT}
										value={rule.param}
										disabled={readOnly}
										onChange={(e) => setRule(g, r, makeRule(e.target.value, choices, rule.operator))}
									>
										{Object.entries(PARAMS).map(([param, meta]) => (
											<option key={param} value={param}>
												{meta.label}
											</option>
										))}
									</select>
									<select
										aria-label={__('Operator', 'proto-blocks')}
										className={SELECT}
										value={rule.operator}
										disabled={readOnly}
										onChange={(e) =>
											setRule(g, r, {
												...rule,
												operator: e.target.value === '!=' ? '!=' : '==',
											})
										}
									>
										<option value="==">{__('is', 'proto-blocks')}</option>
										<option value="!=">{__('is not', 'proto-blocks')}</option>
									</select>
									{valueControl(rule, g, r)}
									{readOnly ? null : (
										<Button type="button" variant="ghost" size="icon-sm" aria-label={__('Remove rule', 'proto-blocks')} onClick={() => removeRule(g, r)}>
											<XIcon aria-hidden="true" />
										</Button>
									)}
								</div>
							);
						})}
						{kinds.size > 1 ? (
							<p role="alert" className="flex items-center gap-1.5 text-[13px] text-warning">
								<TriangleAlertIcon aria-hidden="true" className="size-4 shrink-0" />
								{__('Rules for different screens in one group never match together. Put them in separate "or" groups.', 'proto-blocks')}
							</p>
						) : null}
						{readOnly ? null : (
							<Button
								type="button"
								variant="ghost"
								size="sm"
								className="justify-self-start"
								aria-label={sprintf(/* translators: %d: rule group number */ __('Add a rule to location group %d', 'proto-blocks'), g + 1)}
								onClick={() => {
									const last = group[group.length - 1];
									emit(value.map((grp, gi) => (gi === g ? [...grp, makeRule(last && PARAMS[last.param] ? last.param : 'post_type', choices)] : grp)));
								}}
							>
								<PlusIcon aria-hidden="true" />
								{__('and', 'proto-blocks')}
							</Button>
						)}
					</div>
				);
			})}
			{readOnly ? null : (
				<div className="flex flex-wrap gap-2">
					{value.length === 0 ? (
						<Button type="button" variant="outline" size="sm" onClick={() => emit([[makeRule('post_type', choices)]])}>
							<PlusIcon aria-hidden="true" />
							{__('Add rule', 'proto-blocks')}
						</Button>
					) : (
						<Button
							type="button"
							variant="outline"
							size="sm"
							aria-label={__('Add a location group', 'proto-blocks')}
							onClick={() => emit([...value, [makeRule('post_type', choices)]])}
						>
							<PlusIcon aria-hidden="true" />
							{__('or', 'proto-blocks')}
						</Button>
					)}
				</div>
			)}
		</div>
	);
}
