/**
 * The add/edit dialog's form values and their mapping to and from a file
 * rule. The resolver validates the rule the form would write with the zod
 * mirror in schema.ts, so the dialog shows the server's error codes.
 */
import type { FieldErrors, Resolver } from 'react-hook-form';
import { defaultCode } from './codes';
import { ruleField, ruleSchema, type RuleCheckOptions } from './schema';
import type { ConditionType, FileRule, GroupDefaults, QueryMode, Rule, RuleAction } from './types';

export const NEW_GROUP = '__new__';

export interface RuleFormValues {
	from: string;
	regex: boolean;
	to: string;
	else: string;
	/** The code as a select value; '' for actions without a code. */
	code: string;
	action: RuleAction;
	condition: ConditionType | 'none';
	/** One entry per line for `ip` and `language`. */
	conditionValue: string;
	conditionName: string;
	conditionRegex: boolean;
	query: QueryMode;
	ignoreCase: boolean;
	ignoreTrailingSlash: boolean;
	title: string;
	/** Group slug, or NEW_GROUP to create one named `newGroupTitle`. */
	group: string;
	newGroupTitle: string;
	log: boolean;
	enabled: boolean;
}

export const SITE_DEFAULTS: Required<GroupDefaults> = { ignoreCase: true, ignoreTrailingSlash: true, query: 'exact' };

export function effectiveDefaults(site: GroupDefaults | undefined, group: GroupDefaults | undefined): Required<GroupDefaults> {
	return { ...SITE_DEFAULTS, ...(site ?? {}), ...(group ?? {}) };
}

type AnyRule = Partial<Rule> | FileRule;

export function formFromRule(rule: AnyRule | null, defaults: Required<GroupDefaults>, group: string): RuleFormValues {
	const action = (rule?.action ?? 'url') as RuleAction;
	const when = rule?.when ?? null;
	const code = rule?.code ?? defaultCode(action);
	return {
		from: rule?.from ?? '',
		regex: rule?.regex ?? false,
		to: rule?.to ?? '',
		else: rule?.else ?? '',
		code: code === null || code === undefined ? '' : String(code),
		action,
		condition: when?.type ?? 'none',
		conditionValue: Array.isArray(when?.value) ? when.value.join('\n') : (when?.value ?? ''),
		conditionName: when?.name ?? '',
		conditionRegex: when?.regex ?? false,
		query: rule?.query ?? defaults.query,
		ignoreCase: rule?.ignoreCase ?? defaults.ignoreCase,
		ignoreTrailingSlash: rule?.ignoreTrailingSlash ?? defaults.ignoreTrailingSlash,
		title: rule?.title ?? '',
		group,
		newGroupTitle: '',
		log: rule?.log ?? true,
		enabled: rule?.enabled ?? true,
	};
}

const LIST_TYPES: ConditionType[] = ['ip', 'language'];
const VALUE_TYPES: ConditionType[] = ['role', 'referrer', 'agent', 'header', 'cookie', 'server', 'filter'];
const NAME_TYPES: ConditionType[] = ['header', 'cookie'];
const REGEX_TYPES: ConditionType[] = ['referrer', 'agent', 'header', 'cookie'];

/**
 * The rule the form writes: keys in canonical order, defaults left out
 * (enabled/log true, regex false, action url, the action's default code,
 * flags equal to the group's defaults). `keep` carries keys the dialog does
 * not edit (headers).
 */
export function ruleFromForm(values: RuleFormValues, defaults: Required<GroupDefaults>, id: string, keep: Pick<FileRule, 'headers'> = {}): FileRule {
	const rule: FileRule = { id };
	const title = values.title.trim();
	if (title !== '') {
		rule.title = title;
	}
	if (!values.enabled) {
		rule.enabled = false;
	}
	if (values.from.trim() !== '') {
		rule.from = values.from.trim();
	}
	if (values.regex) {
		rule.regex = true;
	}
	if ((values.action === 'url' || values.action === 'pass') && values.to.trim() !== '') {
		rule.to = values.to.trim();
	}
	if (values.action === 'url' && values.else.trim() !== '') {
		rule.else = values.else.trim();
	}
	if (values.action === 'url' || values.action === 'error') {
		const code = Number(values.code);
		if (values.code !== '' && Number.isFinite(code) && code !== defaultCode(values.action)) {
			rule.code = code;
		}
	}
	if (values.action !== 'url') {
		rule.action = values.action;
	}
	if (values.condition !== 'none') {
		const type = values.condition;
		const when: NonNullable<FileRule['when']> = { type };
		if (LIST_TYPES.includes(type)) {
			when.value = values.conditionValue
				.split(/[\n,]/)
				.map((entry) => entry.trim())
				.filter((entry) => entry !== '');
		} else if (VALUE_TYPES.includes(type)) {
			when.value = values.conditionValue.trim();
		}
		if (NAME_TYPES.includes(type) && values.conditionName.trim() !== '') {
			when.name = values.conditionName.trim();
		}
		if (REGEX_TYPES.includes(type) && values.conditionRegex) {
			when.regex = true;
		}
		rule.when = when;
	}
	if (values.query !== defaults.query) {
		rule.query = values.query;
	}
	if (values.ignoreCase !== defaults.ignoreCase) {
		rule.ignoreCase = values.ignoreCase;
	}
	if (values.ignoreTrailingSlash !== defaults.ignoreTrailingSlash) {
		rule.ignoreTrailingSlash = values.ignoreTrailingSlash;
	}
	if (!values.log) {
		rule.log = false;
	}
	if (keep.headers && Object.keys(keep.headers).length > 0) {
		rule.headers = keep.headers;
	}
	return rule;
}

/** react-hook-form resolver: the rule the form would write, checked by ruleSchema. */
export function ruleResolver(defaultsFor: (group: string) => Required<GroupDefaults>, options: RuleCheckOptions & { groupError?: (values: RuleFormValues) => string | null } = {}): Resolver<RuleFormValues> {
	return async (values) => {
		const defaults = defaultsFor(values.group);
		const rule = ruleFromForm(values, defaults, 'r_00000000');
		const result = ruleSchema({ ...options, defaults }).safeParse(rule);
		const errors: FieldErrors<RuleFormValues> = {};
		if (!result.success) {
			for (const issue of result.error.issues) {
				const field = ruleField(issue.path as (string | number)[]);
				const code = (issue as { params?: { code?: string } }).params?.code ?? 'schema_invalid';
				if (!(field in errors)) {
					(errors as Record<string, unknown>)[field] = { type: code, message: issue.message };
				}
			}
		}
		const groupError = options.groupError?.(values) ?? null;
		if (groupError !== null) {
			(errors as Record<string, unknown>)[values.group === NEW_GROUP ? 'newGroupTitle' : 'group'] = { type: 'invalid_group', message: groupError };
		}
		return Object.keys(errors).length > 0 ? { values: {}, errors } : { values, errors: {} };
	};
}
