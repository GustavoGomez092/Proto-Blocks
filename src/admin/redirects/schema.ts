/**
 * Browser-side checks for redirect group files, rules and the settings file.
 *
 * Mirrors includes/ContentModel/schemas/redirects.schema.json ($defs/group,
 * $defs/rule, $defs/settings) and Definition\RuleValidator's cross-field
 * checks, with the same error codes, so the dialog refuses what the server
 * refuses. The JSON Schema is written by Track A in parallel; this file was
 * written from spec §1–§3. TODO(integration): reconcile key by key with
 * redirects.schema.json once it lands (or build from it the way
 * content-model/schema.ts does), and keep the codes in RULE_CODES equal to
 * RuleValidator's.
 */
import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';
import { codesFor } from './codes';
import type { FileRule } from './types';

export const RULE_ID = /^r_[a-f0-9]{8}$/;
export const GROUP_SLUG = /^[a-z][a-z0-9_-]*$/;
export const GROUP_SLUG_MAX = 60;
/** Slugs a group file cannot use: the settings file and the virtual PHP group. */
export const RESERVED_SLUGS = ['settings', 'php'];
export const HEADER_NAME = /^[A-Za-z0-9-]{1,100}$/;
export const FORBIDDEN_HEADERS = ['set-cookie', 'location', 'status', 'content-length', 'transfer-encoding'];
export const FILTER_NAME = /^[a-z0-9_-]+$/;
export const REGEX_MAX = 500;
export const VALUE_MAX = 500;
export const URL_MAX = 2000;
export const TITLE_MAX = 200;

/** Error codes shared with Definition\RuleValidator (spec §1–§3, §7). */
export type RuleCode =
	| 'schema_invalid'
	| 'missing_from'
	| 'invalid_from'
	| 'use_when_server'
	| 'invalid_regex'
	| 'regex_too_long'
	| 'missing_target'
	| 'invalid_target'
	| 'invalid_code'
	| 'else_without_when'
	| 'else_not_allowed'
	| 'self_redirect'
	| 'missing_value'
	| 'missing_name'
	| 'invalid_ip'
	| 'invalid_filter'
	| 'invalid_header'
	| 'invalid_label'
	| 'invalid_id'
	| 'too_long';

export function ruleMessage(code: RuleCode, detail = ''): string {
	switch (code) {
		case 'missing_from':
			return __('Enter the source path.', 'proto-blocks');
		case 'invalid_from':
			return __('Start the source with / (or turn on Regex).', 'proto-blocks');
		case 'use_when_server':
			return __('This URL is on another host. Enter the path here and use the "Server (host)" condition for the host.', 'proto-blocks');
		case 'invalid_regex':
			return sprintf(
				/* translators: %s: the regular expression engine's error message. */
				__('The regular expression does not compile: %s', 'proto-blocks'),
				detail
			);
		case 'regex_too_long':
			return __('Keep regular expressions to 500 characters or fewer.', 'proto-blocks');
		case 'missing_target':
			return __('Enter the target.', 'proto-blocks');
		case 'invalid_target':
			return __('Use a path starting with / or a full http:// or https:// URL.', 'proto-blocks');
		case 'invalid_code':
			return __('Choose a code this action supports.', 'proto-blocks');
		case 'else_without_when':
			return __('A fallback target needs a match condition.', 'proto-blocks');
		case 'else_not_allowed':
			return __('Only "Redirect to URL" can have a fallback target.', 'proto-blocks');
		case 'self_redirect':
			return __('The target is the same as the source, so this rule would loop.', 'proto-blocks');
		case 'missing_value':
			return __('Enter a value for this condition.', 'proto-blocks');
		case 'missing_name':
			return __('Enter the header or cookie name.', 'proto-blocks');
		case 'invalid_ip':
			return sprintf(
				/* translators: %s: the entry that is not an IP address or CIDR range. */
				__('"%s" is not an IP address or CIDR range.', 'proto-blocks'),
				detail
			);
		case 'invalid_filter':
			return __('Use a hook name of lowercase letters, digits, hyphens and underscores.', 'proto-blocks');
		case 'invalid_header':
			return sprintf(
				/* translators: %s: header name. */
				__('The header "%s" is not allowed or its value has line breaks.', 'proto-blocks'),
				detail
			);
		case 'invalid_label':
			return __('Titles cannot contain < or >.', 'proto-blocks');
		case 'invalid_id':
			return __('Rule ids look like r_ followed by 8 hex digits.', 'proto-blocks');
		case 'too_long':
			return sprintf(
				/* translators: %d: maximum number of characters. */
				__('Use %d characters or fewer.', 'proto-blocks'),
				Number(detail)
			);
		default:
			return detail !== '' ? detail : __('This value is not valid.', 'proto-blocks');
	}
}

/**
 * Checks a PCRE body (no delimiters) with the browser's engine. PCRE-only
 * syntax that JavaScript lacks is translated first; the server's compile is
 * authoritative. Returns the error message, or null when it compiles.
 */
export function regexError(pattern: string): string | null {
	const translated = pattern
		.replace(/\(\?P</g, '(?<')
		.replace(/\(\?P=(\w+)\)/g, '\\k<$1>')
		.replace(/\\[AZz]/g, '')
		.replace(/^\(\?[imsx]+\)/, '')
		.replace(/([*+?}])\+/g, '$1');
	try {
		new RegExp(translated);
		return null;
	} catch (e) {
		return e instanceof Error ? e.message.replace(/^Invalid regular expression: /, '') : String(e);
	}
}

function isIpv4(ip: string): boolean {
	const parts = ip.split('.');
	return parts.length === 4 && parts.every((part) => /^\d{1,3}$/.test(part) && Number(part) <= 255);
}

function isIpv6(ip: string): boolean {
	if (!/^[0-9a-fA-F:.]+$/.test(ip) || !ip.includes(':')) {
		return false;
	}
	try {
		new URL(`http://[${ip}]/`);
		return true;
	} catch {
		return false;
	}
}

/** An IPv4/IPv6 address or a CIDR range of one. */
export function isIpOrCidr(entry: string): boolean {
	const [ip, prefix, extra] = entry.split('/');
	if (extra !== undefined) {
		return false;
	}
	const v4 = isIpv4(ip);
	const v6 = !v4 && isIpv6(ip);
	if (!v4 && !v6) {
		return false;
	}
	if (prefix === undefined) {
		return true;
	}
	return /^\d{1,3}$/.test(prefix) && Number(prefix) <= (v4 ? 32 : 128);
}

function isTarget(target: string): boolean {
	return target.startsWith('/') || /^https?:\/\/[^\s/?#]+/i.test(target);
}

const hasAngle = (text: string) => /[<>]/.test(text);
const CONTROL = /[\u0000-\u001f\u007f]/;

const conditionSchema = z.strictObject({
	type: z.enum(['login', 'role', 'referrer', 'agent', 'header', 'cookie', 'ip', 'language', 'server', 'filter', '404']),
	value: z.union([z.string(), z.array(z.string())]).optional(),
	name: z.string().optional(),
	regex: z.boolean().optional(),
});

const queryMode = z.enum(['exact', 'exactOrder', 'ignore', 'pass']);

/** `$defs/rule` keys and types. */
const ruleShape = z.strictObject({
	id: z.string().optional(),
	title: z.string().optional(),
	enabled: z.boolean().optional(),
	from: z.string().optional(),
	regex: z.boolean().optional(),
	to: z.string().optional(),
	else: z.string().optional(),
	code: z.number().int().optional(),
	action: z.enum(['url', 'error', 'pass', 'nothing']).optional(),
	when: conditionSchema.optional(),
	query: queryMode.optional(),
	ignoreCase: z.boolean().optional(),
	ignoreTrailingSlash: z.boolean().optional(),
	log: z.boolean().optional(),
	headers: z.record(z.string(), z.string()).optional(),
});

export interface RuleCheckOptions {
	/** The site's host (lower case, no port), to reduce same-host absolute sources. */
	siteHost?: string;
}

interface Problem {
	path: (string | number)[];
	code: RuleCode;
	message: string;
}

function hostOf(url: string): string | null {
	try {
		return new URL(url).hostname.toLowerCase();
	} catch {
		return null;
	}
}

/** The cross-field rules of spec §1–§3 and §7 over a well-typed rule. */
export function ruleProblems(rule: FileRule, options: RuleCheckOptions = {}): Problem[] {
	const out: Problem[] = [];
	const add = (path: (string | number)[], code: RuleCode, detail = '') => out.push({ path, code, message: ruleMessage(code, detail) });
	const action = rule.action ?? 'url';
	const when = rule.when;
	const from = rule.from ?? '';

	if (rule.id !== undefined && !RULE_ID.test(rule.id)) {
		add(['id'], 'invalid_id');
	}
	if (rule.title !== undefined) {
		if (rule.title.length > TITLE_MAX) {
			add(['title'], 'too_long', String(TITLE_MAX));
		} else if (hasAngle(rule.title)) {
			add(['title'], 'invalid_label');
		}
	}

	// Source.
	if (from.trim() === '') {
		if (when?.type !== '404') {
			add(['from'], 'missing_from');
		}
	} else if (from.length > URL_MAX) {
		add(['from'], 'too_long', String(URL_MAX));
	} else if (rule.regex) {
		if (from.length > REGEX_MAX) {
			add(['from'], 'regex_too_long');
		} else {
			const error = regexError(from);
			if (error !== null) {
				add(['from'], 'invalid_regex', error);
			}
		}
	} else if (/^https?:\/\//i.test(from)) {
		const host = hostOf(from);
		if (host === null) {
			add(['from'], 'invalid_from');
		} else if (options.siteHost === undefined || host !== options.siteHost.toLowerCase()) {
			add(['from'], 'use_when_server');
		}
	} else if (!from.startsWith('/')) {
		add(['from'], 'invalid_from');
	}

	// Target.
	const to = rule.to ?? '';
	if (action === 'url' || action === 'pass') {
		if (to.trim() === '') {
			add(['to'], 'missing_target');
		} else if (to.length > URL_MAX) {
			add(['to'], 'too_long', String(URL_MAX));
		} else if (!isTarget(to) || (action === 'pass' && !to.startsWith('/'))) {
			add(['to'], 'invalid_target');
		} else if (!rule.regex && from !== '' && to === from) {
			add(['to'], 'self_redirect');
		}
	}

	// Fallback target.
	if (rule.else !== undefined && rule.else !== '') {
		if (!when) {
			add(['else'], 'else_without_when');
		} else if (action !== 'url') {
			add(['else'], 'else_not_allowed');
		} else if (!isTarget(rule.else)) {
			add(['else'], 'invalid_target');
		}
	}

	// Code.
	const codes = codesFor(action);
	if (rule.code !== undefined && !codes.includes(rule.code)) {
		add(['code'], 'invalid_code');
	}

	// Condition.
	if (when) {
		const value = when.value;
		const list = Array.isArray(value) ? value : [];
		const text = typeof value === 'string' ? value : '';
		switch (when.type) {
			case 'role':
			case 'server':
			case 'referrer':
			case 'agent':
			case 'filter':
			case 'header':
			case 'cookie':
				if (text.trim() === '') {
					add(['when', 'value'], 'missing_value');
				} else if (text.length > VALUE_MAX) {
					add(['when', 'value'], 'too_long', String(VALUE_MAX));
				} else if (when.type === 'filter' && !FILTER_NAME.test(text)) {
					add(['when', 'value'], 'invalid_filter');
				} else if (when.regex) {
					const error = text.length > REGEX_MAX ? null : regexError(text);
					if (error !== null) {
						add(['when', 'value'], 'invalid_regex', error);
					}
				}
				if ((when.type === 'header' || when.type === 'cookie') && (when.name ?? '').trim() === '') {
					add(['when', 'name'], 'missing_name');
				}
				break;
			case 'ip': {
				if (list.length === 0) {
					add(['when', 'value'], 'missing_value');
				}
				const bad = list.find((entry) => !isIpOrCidr(entry.trim()));
				if (bad !== undefined) {
					add(['when', 'value'], 'invalid_ip', bad);
				}
				break;
			}
			case 'language':
				if (list.length === 0) {
					add(['when', 'value'], 'missing_value');
				}
				break;
			default:
				break;
		}
	}

	// Headers (spec §7).
	for (const [name, value] of Object.entries(rule.headers ?? {})) {
		if (!HEADER_NAME.test(name) || FORBIDDEN_HEADERS.includes(name.toLowerCase()) || value.length > 1000 || CONTROL.test(value)) {
			add(['headers', name], 'invalid_header', name);
		}
	}

	return out;
}

/** Warnings that do not block saving (spec §1 `unanchored_regex`). */
export function ruleWarnings(rule: FileRule): { code: 'unanchored_regex'; message: string }[] {
	if (rule.regex && rule.from && !rule.from.includes('^') && !rule.from.includes('$')) {
		return [
			{
				code: 'unanchored_regex',
				message: __('This pattern has neither ^ nor $, so it matches anywhere in the URL.', 'proto-blocks'),
			},
		];
	}
	return [];
}

/** `$defs/rule` plus the cross-field rules. */
export function ruleSchema(options: RuleCheckOptions = {}) {
	return ruleShape.superRefine((rule, ctx) => {
		for (const problem of ruleProblems(rule as FileRule, options)) {
			ctx.addIssue({ code: 'custom', path: problem.path, message: problem.message, params: { code: problem.code } });
		}
	});
}

export const groupDefaultsSchema = z.strictObject({
	ignoreCase: z.boolean().optional(),
	ignoreTrailingSlash: z.boolean().optional(),
	query: queryMode.optional(),
});

/** `$defs/group`. */
export function groupSchema(options: RuleCheckOptions = {}) {
	return z.strictObject({
		title: z
			.string()
			.max(TITLE_MAX)
			.refine((title) => !hasAngle(title), { message: ruleMessage('invalid_label') })
			.optional(),
		enabled: z.boolean().optional(),
		position: z.number().int().optional(),
		defaults: groupDefaultsSchema.optional(),
		rules: z.array(ruleSchema(options)).optional(),
	});
}

const retention = z.number().int().min(-1).max(90);
const headerMap = z.record(z.string(), z.string()).superRefine((map, ctx) => {
	for (const [name, value] of Object.entries(map)) {
		if (!HEADER_NAME.test(name) || FORBIDDEN_HEADERS.includes(name.toLowerCase()) || value.length > 1000 || CONTROL.test(value)) {
			ctx.addIssue({ code: 'custom', path: [name], message: ruleMessage('invalid_header', name), params: { code: 'invalid_header' } });
		}
	}
});
const HOST = /^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i;

/** `$defs/settings`: every key optional, unknown keys refused. */
export const settingsSchema = z.strictObject({
	defaults: groupDefaultsSchema.optional(),
	log: z
		.strictObject({
			redirects: retention.optional(),
			notFound: retention.optional(),
			ip: z.enum(['none', 'masked', 'full']).optional(),
			headers: z.boolean().optional(),
			external: z.boolean().optional(),
			trustedProxies: z
				.array(z.string().refine(isIpOrCidr, { message: __('Enter IP addresses or CIDR ranges.', 'proto-blocks') }))
				.optional(),
			ipHeaders: z.array(z.enum(['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP', 'Client-IP', 'True-Client-IP'])).optional(),
		})
		.optional(),
	trackHits: z.boolean().optional(),
	cache: z.union([z.literal(-1), z.literal(0), z.literal(1), z.literal(24), z.literal(168)]).optional(),
	monitor: z
		.strictObject({
			postTypes: z.array(z.string()).optional(),
			group: z
				.string()
				.max(GROUP_SLUG_MAX)
				.refine((slug) => GROUP_SLUG.test(slug) && !RESERVED_SLUGS.includes(slug), {
					message: __('Use lowercase letters, digits, hyphens or underscores, starting with a letter.', 'proto-blocks'),
				})
				.optional(),
			trash: z.boolean().optional(),
			associated: z.string().max(VALUE_MAX).optional(),
		})
		.optional(),
	headers: z.strictObject({ site: headerMap.optional(), redirect: headerMap.optional() }).optional(),
	canonical: z
		.strictObject({
			https: z.boolean().optional(),
			www: z.enum(['', 'www', 'nowww']).optional(),
			aliases: z
				.array(z.string().refine((host) => HOST.test(host), { message: __('Enter host names only, without http:// or a path.', 'proto-blocks') }))
				.max(20)
				.optional(),
			relocate: z
				.string()
				.refine((url) => url === '' || /^https?:\/\/[^\s/?#]+/i.test(url), { message: __('Enter a full http:// or https:// URL, or leave empty.', 'proto-blocks') })
				.optional(),
		})
		.optional(),
	coexist: z.boolean().optional(),
});

/** A group slug from a title: "Legacy site" → "legacy-site". */
export function slugifyGroup(title: string): string {
	return title
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9_]+/g, '-')
		.replace(/^[^a-z]+/, '')
		.slice(0, GROUP_SLUG_MAX)
		.replace(/-+$/, '');
}

export function groupSlugError(slug: string, taken: string[] = []): string | null {
	if (slug === '' || !GROUP_SLUG.test(slug) || slug.length > GROUP_SLUG_MAX) {
		return __('Use up to 60 lowercase letters, digits, hyphens or underscores, starting with a letter.', 'proto-blocks');
	}
	if (RESERVED_SLUGS.includes(slug)) {
		return __('This name is reserved. Choose another.', 'proto-blocks');
	}
	if (taken.includes(slug)) {
		return __('A group with this slug already exists.', 'proto-blocks');
	}
	return null;
}
