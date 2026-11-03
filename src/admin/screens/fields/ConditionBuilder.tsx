import { useId } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { PlusIcon, XIcon } from 'lucide-react';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Switch } from '@/admin/components/ui/switch';
import type { ConditionOperator, ConditionRule } from '@/fields/model/types';
import { compilePattern } from '@/fields/validation/conditions';
import { canonicalType, isObject, LAYOUT_ONLY_TYPES, type RawField } from './builder-state';

export interface ConditionBuilderProps {
	/**
	 * The fields a rule may read: the edited field's siblings at its own level
	 * (the condition engine never reads another level). The edited field itself
	 * may be included; it is left out.
	 */
	fields: RawField[];
	/** The edited field's key, left out of the field picker. */
	selfKey?: string;
	/** The field's raw `conditional_logic` (0, a falsy value, or OR-list of AND-lists). */
	value: unknown;
	/** An OR-list of AND-lists of rules, or 0 (ACF's "off"). */
	onChange: (value: ConditionRule[][] | 0) => void;
	readOnly?: boolean;
}

type Rule = { field: string; operator: ConditionOperator; value?: string };

/** Every operator the condition engine (ConditionEvaluator / conditions.ts) evaluates. */
const OPERATORS: Record<ConditionOperator, string> = {
	'==': __('is', 'proto-blocks'),
	'!=': __('is not', 'proto-blocks'),
	'==contains': __('contains', 'proto-blocks'),
	'==empty': __('is empty', 'proto-blocks'),
	'!=empty': __('is not empty', 'proto-blocks'),
	'==pattern': __('matches pattern', 'proto-blocks'),
};

const NO_VALUE: ConditionOperator[] = ['==empty', '!=empty'];
const NOT_A_CONTROLLER = new Set(['repeater', 'group', 'flexible_content', ...LAYOUT_ONLY_TYPES]);

function operatorsFor(field: RawField | undefined): ConditionOperator[] {
	const type = canonicalType(field?.type);
	if (type === 'true_false') {
		return ['==', '!='];
	}
	if (type === 'select' || type === 'radio') {
		return ['==', '!=', '==empty', '!=empty'];
	}
	return Object.keys(OPERATORS) as ConditionOperator[];
}

function choicesOf(field: RawField | undefined): [string, string][] {
	const choices = field?.choices;
	if (Array.isArray(choices)) {
		return choices.map((c) => [String(c), String(c)]);
	}
	return isObject(choices) ? Object.entries(choices).map(([k, v]) => [k, String(v)]) : [];
}

function defaultValue(field: RawField | undefined): string {
	if (canonicalType(field?.type) === 'true_false') {
		return '1';
	}
	return choicesOf(field)[0]?.[0] ?? '';
}

function makeRule(field: RawField | undefined, operator?: ConditionOperator): Rule {
	const ops = operatorsFor(field);
	const op = operator && ops.includes(operator) ? operator : ops[0];
	const rule: Rule = { field: String(field?.key ?? ''), operator: op };
	if (!NO_VALUE.includes(op)) {
		rule.value = defaultValue(field);
	}
	return rule;
}

function readRules(value: unknown): Rule[][] {
	if (!Array.isArray(value)) {
		return [];
	}
	return value
		.filter(Array.isArray)
		.map((branch) =>
			(branch as unknown[]).filter(isObject).map((r) => {
				const rule: Rule = { field: String(r.field ?? ''), operator: String(r.operator ?? '==') as ConditionOperator };
				if (r.value !== undefined && r.value !== null) {
					rule.value = String(r.value);
				}
				return rule;
			})
		)
		.filter((branch) => branch.length > 0);
}

/**
 * "Show this field if…": OR groups of AND rules over the sibling fields.
 * Emits exactly what the server's condition engine evaluates: `{field,
 * operator, value}` with a string value, `{field, operator}` for the empty
 * operators, and 0 when every rule is removed.
 */
export function ConditionBuilder({ fields, selfKey, value, onChange, readOnly = false }: ConditionBuilderProps) {
	const id = useId();
	const candidates = fields.filter((f) => f.key !== selfKey && typeof f.key === 'string' && f.key !== '' && !NOT_A_CONTROLLER.has(canonicalType(f.type) ?? ''));
	const byKey = new Map(candidates.map((f) => [String(f.key), f]));
	const groups = readRules(value);

	const emit = (next: Rule[][]) => {
		const cleaned = next.filter((g) => g.length > 0);
		onChange(cleaned.length > 0 ? (cleaned as ConditionRule[][]) : 0);
	};
	const setRule = (g: number, r: number, rule: Rule) => emit(groups.map((group, gi) => (gi === g ? group.map((old, ri) => (ri === r ? rule : old)) : group)));

	if (candidates.length === 0 && groups.length === 0) {
		return <p className="text-sm text-muted-foreground">{__('Conditions read other fields at the same level; this level has none to read yet.', 'proto-blocks')}</p>;
	}

	return (
		<div className="grid gap-3">
			{groups.length === 0 ? (
				<p className="text-sm text-muted-foreground">{__('Off: the field is always shown.', 'proto-blocks')}</p>
			) : null}
			{groups.map((group, g) => (
				<div
					key={g}
					role="group"
					aria-label={sprintf(/* translators: %d: rule group number */ __('Rule group %d', 'proto-blocks'), g + 1)}
					className="grid gap-2 rounded-md border p-2"
				>
					{g > 0 ? <p className="text-xs font-medium text-muted-foreground uppercase">{__('or', 'proto-blocks')}</p> : null}
					{group.map((rule, r) => {
						const target = byKey.get(rule.field);
						const ops = operatorsFor(target);
						const choices = choicesOf(target);
						const isBool = canonicalType(target?.type) === 'true_false';
						const errorId = `${id}-${g}-${r}-error`;
						const error = !target
							? __('This rule reads a field that is not at this level, so it is never true. Pick another field.', 'proto-blocks')
							: rule.operator === '==pattern' && compilePattern(rule.value ?? '') === null
								? __('Enter a valid regular expression (200 bytes at most).', 'proto-blocks')
								: null;
						return (
							<div key={r} className="grid gap-1">
								<div className="flex flex-wrap items-center gap-2">
									<span className="text-xs text-muted-foreground">{r === 0 ? __('If', 'proto-blocks') : __('and', 'proto-blocks')}</span>
									<select
										aria-label={__('Field', 'proto-blocks')}
										className="h-8 rounded-md border bg-transparent px-2 text-sm"
										value={rule.field}
										disabled={readOnly}
										aria-invalid={!target || undefined}
										aria-describedby={error ? errorId : undefined}
										onChange={(e) => setRule(g, r, makeRule(byKey.get(e.target.value), rule.operator))}
									>
										{!target ? <option value={rule.field}>{rule.field}</option> : null}
										{candidates.map((f) => (
											<option key={String(f.key)} value={String(f.key)}>
												{String(f.label || f.name || f.key)}
											</option>
										))}
									</select>
									<select
										aria-label={__('Operator', 'proto-blocks')}
										className="h-8 rounded-md border bg-transparent px-2 text-sm"
										value={rule.operator}
										disabled={readOnly}
										onChange={(e) => {
											const operator = e.target.value as ConditionOperator;
											const next: Rule = { field: rule.field, operator };
											if (!NO_VALUE.includes(operator)) {
												next.value = rule.value ?? defaultValue(target);
											}
											setRule(g, r, next);
										}}
									>
										{(ops.includes(rule.operator) ? ops : [rule.operator, ...ops]).map((op) => (
											<option key={op} value={op}>
												{OPERATORS[op] ?? op}
											</option>
										))}
									</select>
									{NO_VALUE.includes(rule.operator) ? null : isBool ? (
										<Switch
											aria-label={__('Value', 'proto-blocks')}
											checked={rule.value === '1'}
											disabled={readOnly}
											onCheckedChange={(on) => setRule(g, r, { ...rule, value: on ? '1' : '0' })}
										/>
									) : choices.length > 0 && rule.operator !== '==pattern' && rule.operator !== '==contains' ? (
										<select
											aria-label={__('Value', 'proto-blocks')}
											className="h-8 rounded-md border bg-transparent px-2 text-sm"
											value={rule.value ?? ''}
											disabled={readOnly}
											onChange={(e) => setRule(g, r, { ...rule, value: e.target.value })}
										>
											{choices.map(([k, label]) => (
												<option key={k} value={k}>
													{label}
												</option>
											))}
										</select>
									) : (
										<Input
											aria-label={__('Value', 'proto-blocks')}
											className="h-8 w-48"
											value={rule.value ?? ''}
											disabled={readOnly}
											aria-invalid={error && target ? true : undefined}
											aria-describedby={error ? errorId : undefined}
											onChange={(e) => setRule(g, r, { ...rule, value: e.target.value })}
										/>
									)}
									{readOnly ? null : (
										<Button
											type="button"
											variant="ghost"
											size="icon-sm"
											aria-label={__('Remove rule', 'proto-blocks')}
											onClick={() => emit(groups.map((grp, gi) => (gi === g ? grp.filter((_, ri) => ri !== r) : grp)))}
										>
											<XIcon aria-hidden="true" />
										</Button>
									)}
								</div>
								{error ? (
									<p id={errorId} role="alert" className="text-[13px] text-destructive">
										{error}
									</p>
								) : null}
							</div>
						);
					})}
					{readOnly || candidates.length === 0 ? null : (
						<Button
							type="button"
							variant="ghost"
							size="sm"
							className="justify-self-start"
							aria-label={sprintf(/* translators: %d: rule group number */ __('Add a rule to group %d', 'proto-blocks'), g + 1)}
							onClick={() => emit(groups.map((grp, gi) => (gi === g ? [...grp, makeRule(candidates[0])] : grp)))}
						>
							<PlusIcon aria-hidden="true" />
							{__('and', 'proto-blocks')}
						</Button>
					)}
				</div>
			))}
			{readOnly || candidates.length === 0 ? null : (
				<div className="flex flex-wrap gap-2">
					{groups.length === 0 ? (
						<Button type="button" variant="outline" size="sm" onClick={() => emit([[makeRule(candidates[0])]])}>
							<PlusIcon aria-hidden="true" />
							{__('Add rule', 'proto-blocks')}
						</Button>
					) : (
						<>
							<Button type="button" variant="outline" size="sm" aria-label={__('Add a rule group', 'proto-blocks')} onClick={() => emit([...groups, [makeRule(candidates[0])]])}>
								<PlusIcon aria-hidden="true" />
								{__('or', 'proto-blocks')}
							</Button>
							<Button type="button" variant="ghost" size="sm" aria-label={__('Remove all rules', 'proto-blocks')} onClick={() => onChange(0)}>
								{__('Remove all', 'proto-blocks')}
							</Button>
						</>
					)}
				</div>
			)}
		</div>
	);
}
