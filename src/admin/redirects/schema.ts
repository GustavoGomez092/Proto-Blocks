/**
 * Browser-side checks for redirect group files, rules and the settings file.
 *
 * Mirrors includes/ContentModel/schemas/redirects.schema.json ($defs/group,
 * $defs/rule, $defs/when, $defs/settings) and the server validators
 * (Definition\RuleValidator, GroupValidator, SettingsValidator, Patterns)
 * with the same error codes, limits and order: a rule the schema refuses
 * (`schema_invalid`) is not cross-checked, as on the server. The server's
 * compile and checks stay authoritative; a refused save is mapped back onto
 * the form with serverFindings() / ruleFieldErrors().
 */
import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';
import type { ApiError } from '@/admin/api';
import { ERROR_CODES, REDIRECT_CODES } from './codes';
import type { FileRule, GroupDefaults } from './types';

export const RULE_ID = /^r_[a-f0-9]{8}$/;
export const GROUP_SLUG = /^[a-z][a-z0-9_-]*$/;
export const GROUP_SLUG_MAX = 60;
/** Slugs a group file cannot use: the settings file and the virtual PHP group. */
export const RESERVED_SLUGS = ['settings', 'php'];
/** Patterns::HEADER_NAME */
export const HEADER_NAME = /^[A-Za-z0-9-]{1,100}$/;
export const HEADER_VALUE_MAX = 1000;
/** Patterns::FORBIDDEN_HEADERS (lower case). */
export const FORBIDDEN_HEADERS = ['set-cookie', 'location', 'status', 'content-length', 'transfer-encoding', 'refresh'];
/** RuleValidator's condition grammars. */
export const FILTER_NAME = /^[a-z0-9_-]+$/;
export const ROLE_NAME = /^[A-Za-z0-9_-]{1,100}$/;
export const COOKIE_NAME = /^[A-Za-z0-9!#$%&'*+.^_`|~-]{1,100}$/;
export const LANGUAGE_TAG = /^(?:\*|[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*)$/;
/** Patterns::REGEX_MAX */
export const REGEX_MAX = 500;
/** $defs/when value (string or list item) and the validator's cap. */
export const VALUE_MAX = 500;
export const VALUE_ITEMS_MAX = 100;
export const NAME_MAX = 100;
/** $defs/url: from, to, else. */
export const URL_MAX = 2000;
/** $defs/label: rule and group titles. */
export const TITLE_MAX = 200;
export const POSITION_MIN = -10000;
export const POSITION_MAX = 10000;

export const RULE_KEYS = ['id', 'title', 'enabled', 'from', 'regex', 'to', 'else', 'code', 'action', 'when', 'query', 'ignoreCase', 'ignoreTrailingSlash', 'log', 'headers'] as const;
export const RULE_ACTIONS = ['url', 'error', 'pass', 'nothing'] as const;
export const WHEN_TYPES = ['login', 'role', 'referrer', 'agent', 'header', 'cookie', 'ip', 'language', 'server', 'filter', '404'] as const;
export const QUERY_MODES = ['exact', 'exactOrder', 'ignore', 'pass'] as const;
/** RuleNormalizer::LIST_TYPES, NAMED_TYPES, REGEX_TYPES */
const LIST_TYPES: string[] = ['ip', 'language'];
const NAMED_TYPES: string[] = ['header', 'cookie'];
const REGEX_TYPES: string[] = ['referrer', 'agent', 'header', 'cookie'];

/** Error codes of Definition\RuleValidator, GroupValidator and Schema (spec §1–§3, §7). */
export type RuleCode =
	| 'schema_invalid'
	| 'missing_source'
	| 'invalid_source'
	| 'use_when_server'
	| 'invalid_regex'
	| 'invalid_target'
	| 'invalid_else'
	| 'invalid_code'
	| 'self_redirect'
	| 'invalid_header'
	| 'invalid_condition'
	| 'duplicate_rule';

/** Error codes of Definition\SettingsValidator. */
export type SettingsCode = 'schema_invalid' | 'invalid_header' | 'invalid_host' | 'invalid_url' | 'invalid_ip';

/** The generic message for a code (a server finding without a message, or a code with one meaning). */
export function ruleMessage(code: RuleCode | SettingsCode | string, detail = ''): string {
	switch (code) {
		case 'missing_source':
			return __('A rule needs a source unless its condition is "Page not found (404)".', 'proto-blocks');
		case 'invalid_source':
			return __('The source must be a path starting with / (or turn on Regex).', 'proto-blocks');
		case 'use_when_server':
			return __('This URL is on another host. Enter the path here and use the "Server (host)" condition for the host.', 'proto-blocks');
		case 'invalid_regex':
			return detail !== ''
				? sprintf(
						/* translators: %s: the regular expression engine's error message. */
						__('The pattern does not compile: %s', 'proto-blocks'),
						detail
					)
				: __('The pattern does not compile.', 'proto-blocks');
		case 'invalid_target':
			return __('The target must be a path starting with / or an http:// or https:// URL.', 'proto-blocks');
		case 'invalid_else':
			return __('The fallback target needs a match condition, the "Redirect to URL" action and a valid path or URL.', 'proto-blocks');
		case 'invalid_code':
			return __('Choose a code this action supports.', 'proto-blocks');
		case 'self_redirect':
			return __('The rule redirects to its own source, so it would loop.', 'proto-blocks');
		case 'invalid_header':
			return detail !== ''
				? sprintf(
						/* translators: %s: header name. */
						__('The header "%s" cannot be sent: check its name and value.', 'proto-blocks'),
						detail
					)
				: __('A header cannot be sent: check its name and value.', 'proto-blocks');
		case 'invalid_condition':
			return __('The match condition is not valid.', 'proto-blocks');
		case 'duplicate_rule':
			return __('Another rule of this group has the same id.', 'proto-blocks');
		case 'invalid_host':
			return __('Enter host names only, without http://, a port or a path.', 'proto-blocks');
		case 'invalid_url':
			return __('Enter a full http:// or https:// URL without query or fragment, or leave empty.', 'proto-blocks');
		case 'invalid_ip':
			return __('Not an IP address or CIDR range.', 'proto-blocks');
		default:
			return detail !== '' ? detail : __('This value is not valid.', 'proto-blocks');
	}
}

/** Characters as PHP's mb_strlen counts them (code points). */
const length = (text: string) => [...text].length;
// eslint-disable-next-line no-control-regex
const CONTROL = /[\u0000-\u001f\u007f]/;
const hasControl = (text: string) => CONTROL.test(text);
/** Bytes as PHP's strlen counts them (UTF-8). */
const utf8Length = (text: string) => [...text].reduce((sum, char) => {
	const point = char.codePointAt(0) ?? 0;
	return sum + (point < 0x80 ? 1 : point < 0x800 ? 2 : point < 0x10000 ? 3 : 4);
}, 0);
const isObject = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const isString = (value: unknown): value is string => typeof value === 'string';

/**
 * Checks a PCRE body (no delimiters) with the browser's engine, with the
 * server's empty and length checks (Patterns::compile). PCRE-only syntax
 * that JavaScript lacks is translated first; the server's compile is
 * authoritative. Returns the error message, or null when it compiles.
 */
export function regexError(pattern: string): string | null {
	if (pattern === '') {
		return __('The pattern is empty.', 'proto-blocks');
	}
	if (length(pattern) > REGEX_MAX) {
		return sprintf(
			/* translators: %d: maximum pattern length. */
			__('The pattern is longer than %d characters.', 'proto-blocks'),
			REGEX_MAX
		);
	}
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

/** Patterns::anchored: the body holds an unescaped ^ or $. */
export function anchored(body: string): boolean {
	for (let i = 0; i < body.length; i++) {
		if (body[i] === '\\') {
			i++;
			continue;
		}
		if (body[i] === '^' || body[i] === '$') {
			return true;
		}
	}
	return false;
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

/** An IPv4/IPv6 address or a CIDR range of one (Patterns::validIpOrCidr). */
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
	return /^\d{1,3}$/.test(prefix) && Number(prefix) <= (ip.includes(':') ? 128 : 32);
}

const ASCII_HOST = /^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i;
const UNICODE_HOST = /^(?=.{1,253}$)[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?)*$/u;

/** Patterns::validHost: a bare host name; `unicode` also accepts IDN in Unicode form. */
export function isHost(host: string, unicode = false): boolean {
	return (unicode ? UNICODE_HOST : ASCII_HOST).test(host);
}

/**
 * Patterns::splitAbsolute: [lower-cased host or null (empty or userinfo), rest]
 * of an absolute http(s) URL; null when it is not one.
 */
export function splitAbsolute(url: string): [string | null, string] | null {
	const m = /^https?:\/\/([^/?#\\]*)(.*)$/is.exec(url);
	if (!m) {
		return null;
	}
	const authority = m[1];
	if (authority === '' || authority.includes('@')) {
		return [null, m[2]];
	}
	return [authority.replace(/:\d*$/, '').toLowerCase(), m[2]];
}

/** RuleNormalizer::reduce: an absolute URL on the site's host becomes its path. */
export function reduceToPath(url: string, siteHost?: string): string {
	const parts = splitAbsolute(url);
	if (!parts || parts[0] === null || siteHost === undefined || parts[0] !== siteHost.toLowerCase()) {
		return url;
	}
	return parts[1] === '' || parts[1][0] !== '/' ? `/${parts[1]}` : parts[1];
}

/** RuleValidator::targetError: null when `target` may be redirected to, else why not. */
export function targetError(target: string, pathOnly: boolean): string | null {
	if (target === '' || hasControl(target)) {
		return __('The target may not be empty or hold control characters.', 'proto-blocks');
	}
	if (target.startsWith('//') || target.startsWith('/\\')) {
		return __('A target path may not start with "//" (that is another host).', 'proto-blocks');
	}
	if (target[0] === '/') {
		return null;
	}
	if (pathOnly) {
		return __('A pass-through target must be a path on this site, starting with "/".', 'proto-blocks');
	}
	const parts = splitAbsolute(target);
	if (!parts) {
		return __('The target must be a path starting with "/" or an http:// or https:// URL.', 'proto-blocks');
	}
	if (parts[0] === null || parts[0] === '') {
		return __('The target URL needs a host and may not carry a user name or password.', 'proto-blocks');
	}
	return null;
}

/** Patterns::headerError: null when the header may be sent, else the reason. */
export function headerError(name: string, value: unknown): string | null {
	if (!HEADER_NAME.test(name)) {
		return __('Header names use only letters, digits and "-" (100 characters at most).', 'proto-blocks');
	}
	if (FORBIDDEN_HEADERS.includes(name.toLowerCase())) {
		return sprintf(
			/* translators: %s: header name. */
			__('The %s header cannot be set here.', 'proto-blocks'),
			name
		);
	}
	if (!isString(value)) {
		return __('A header value must be a string.', 'proto-blocks');
	}
	// PHP strlen counts bytes.
	if (utf8Length(value) > HEADER_VALUE_MAX) {
		return sprintf(
			/* translators: %d: maximum length. */
			__('A header value may not be longer than %d characters.', 'proto-blocks'),
			HEADER_VALUE_MAX
		);
	}
	if (hasControl(value)) {
		return __('A header value may not hold line breaks or control characters.', 'proto-blocks');
	}
	return null;
}

/** Settings::validRelocate */
export function isRelocateUrl(url: string): boolean {
	if (url.length > URL_MAX || hasControl(url) || url.includes('\\')) {
		return false;
	}
	const parts = splitAbsolute(url);
	if (!parts || parts[0] === null || !isHost(parts[0])) {
		return false;
	}
	return !/[?#\s]/.test(parts[1]);
}

export interface RuleCheckOptions {
	/** The site's host (lower case, no port), to reduce same-host absolute URLs. */
	siteHost?: string;
	/** The group's effective flags: a rule without ignoreCase / ignoreTrailingSlash inherits them (self_redirect). */
	defaults?: Required<GroupDefaults>;
}

export interface Problem {
	path: (string | number)[];
	code: RuleCode;
	message: string;
}

const schemaMessage = {
	unknownKey: (key: string) =>
		sprintf(
			/* translators: %s: key name. */
			__('Unknown key "%s".', 'proto-blocks'),
			key
		),
	type: (type: string) =>
		sprintf(
			/* translators: %s: JSON type such as string or boolean. */
			__('Must be a %s.', 'proto-blocks'),
			type
		),
	tooLong: (max: number) =>
		sprintf(
			/* translators: %d: maximum number of characters. */
			__('Use %d characters or fewer.', 'proto-blocks'),
			max
		),
	empty: () => __('May not be empty.', 'proto-blocks'),
};

/** `$defs/when`: problems relative to the condition. */
function whenSchemaProblems(when: unknown, add: (path: (string | number)[], message: string) => void): void {
	if (!isObject(when)) {
		add([], schemaMessage.type('object'));
		return;
	}
	for (const key of Object.keys(when)) {
		if (!['type', 'value', 'name', 'regex'].includes(key)) {
			add([key], schemaMessage.unknownKey(key));
		}
	}
	if (!(WHEN_TYPES as readonly unknown[]).includes(when.type)) {
		add(
			['type'],
			sprintf(
				/* translators: %s: allowed condition types. */
				__('The condition type must be one of %s.', 'proto-blocks'),
				WHEN_TYPES.join(', ')
			)
		);
	}
	const value = when.value;
	if (value !== undefined) {
		if (isString(value)) {
			if (length(value) > VALUE_MAX) {
				add(['value'], schemaMessage.tooLong(VALUE_MAX));
			}
		} else if (Array.isArray(value)) {
			if (value.length === 0) {
				add(['value'], __('Enter at least one value.', 'proto-blocks'));
			} else if (value.length > VALUE_ITEMS_MAX) {
				add(
					['value'],
					sprintf(
						/* translators: %d: maximum number of entries. */
						__('Enter %d values or fewer.', 'proto-blocks'),
						VALUE_ITEMS_MAX
					)
				);
			}
			value.forEach((item, i) => {
				if (!isString(item) || item === '') {
					add(['value', i], schemaMessage.empty());
				} else if (length(item) > VALUE_MAX) {
					add(['value', i], schemaMessage.tooLong(VALUE_MAX));
				}
			});
		} else {
			add(['value'], __('Must be a string or a list of strings.', 'proto-blocks'));
		}
	}
	if (when.name !== undefined) {
		if (!isString(when.name) || when.name === '') {
			add(['name'], schemaMessage.empty());
		} else if (length(when.name) > NAME_MAX) {
			add(['name'], schemaMessage.tooLong(NAME_MAX));
		}
	}
	if (when.regex !== undefined && typeof when.regex !== 'boolean') {
		add(['regex'], schemaMessage.type('boolean'));
	}
}

/** `$defs/rule`: keys, types and limits. Every problem is `schema_invalid`. */
export function ruleSchemaProblems(rule: unknown): Problem[] {
	const out: Problem[] = [];
	const add = (path: (string | number)[], message: string) => out.push({ path, code: 'schema_invalid', message });
	if (!isObject(rule)) {
		add([], schemaMessage.type('object'));
		return out;
	}
	for (const key of Object.keys(rule)) {
		if (!(RULE_KEYS as readonly string[]).includes(key)) {
			add([key], schemaMessage.unknownKey(key));
		}
	}
	if (rule.id !== undefined && (!isString(rule.id) || !RULE_ID.test(rule.id))) {
		add(['id'], __('Rule ids look like r_ followed by 8 hex digits.', 'proto-blocks'));
	}
	if (rule.title !== undefined) {
		if (!isString(rule.title)) {
			add(['title'], schemaMessage.type('string'));
		} else if (length(rule.title) > TITLE_MAX) {
			add(['title'], schemaMessage.tooLong(TITLE_MAX));
		} else if (/[<>]/.test(rule.title)) {
			add(['title'], __('Titles cannot contain < or >.', 'proto-blocks'));
		}
	}
	for (const key of ['from', 'to', 'else'] as const) {
		const value = rule[key];
		if (value === undefined) {
			continue;
		}
		if (!isString(value)) {
			add([key], schemaMessage.type('string'));
		} else if (value === '') {
			add([key], schemaMessage.empty());
		} else if (length(value) > URL_MAX) {
			add([key], schemaMessage.tooLong(URL_MAX));
		}
	}
	for (const key of ['enabled', 'regex', 'ignoreCase', 'ignoreTrailingSlash', 'log'] as const) {
		if (rule[key] !== undefined && typeof rule[key] !== 'boolean') {
			add([key], schemaMessage.type('boolean'));
		}
	}
	if (rule.code !== undefined && (typeof rule.code !== 'number' || !Number.isInteger(rule.code) || rule.code < 100 || rule.code > 599)) {
		add(['code'], __('The code must be a whole number from 100 to 599.', 'proto-blocks'));
	}
	if (rule.action !== undefined && !(RULE_ACTIONS as readonly unknown[]).includes(rule.action)) {
		add(
			['action'],
			sprintf(
				/* translators: %s: allowed actions. */
				__('The action must be one of %s.', 'proto-blocks'),
				RULE_ACTIONS.join(', ')
			)
		);
	}
	if (rule.query !== undefined && !(QUERY_MODES as readonly unknown[]).includes(rule.query)) {
		add(
			['query'],
			sprintf(
				/* translators: %s: allowed query modes. */
				__('The query mode must be one of %s.', 'proto-blocks'),
				QUERY_MODES.join(', ')
			)
		);
	}
	if (rule.when !== undefined) {
		whenSchemaProblems(rule.when, (path, message) => add(['when', ...path], message));
	}
	if (rule.headers !== undefined) {
		if (!isObject(rule.headers)) {
			add(['headers'], schemaMessage.type('object'));
		} else {
			for (const [name, value] of Object.entries(rule.headers)) {
				if (!isString(value)) {
					add(['headers', name], schemaMessage.type('string'));
				} else if (length(value) > HEADER_VALUE_MAX) {
					add(['headers', name], schemaMessage.tooLong(HEADER_VALUE_MAX));
				}
			}
		}
	}
	return out;
}

/** RuleValidator::sameTarget: the target, reduced and folded like the source, equals it. */
function sameTarget(from: string, target: string, ignoreCase: boolean, ignoreTrailingSlash: boolean, siteHost?: string): boolean {
	const reduced = reduceToPath(target, siteHost);
	if (reduced === '' || reduced[0] !== '/') {
		return false;
	}
	const fold = (path: string) => {
		let out = ignoreCase ? path.toLowerCase() : path;
		if (ignoreTrailingSlash) {
			out = out.replace(/\/+$/, '');
			out = out === '' ? '/' : out;
		}
		return out;
	};
	return fold(from) === fold(reduced);
}

/** RuleValidator::conditionErrors over a schema-valid condition. */
function conditionProblems(when: NonNullable<FileRule['when']>, add: (path: (string | number)[], code: RuleCode, message: string) => void): void {
	const bad = (path: (string | number)[], message: string) => add(['when', ...path], 'invalid_condition', message);
	const type = when.type;
	const name = when.name;
	// RuleNormalizer::when: a single string becomes a one-item list.
	const value: unknown = LIST_TYPES.includes(type) && isString(when.value) ? [when.value] : when.value;
	if (name !== undefined && !NAMED_TYPES.includes(type)) {
		bad(['name'], __('Only header and cookie conditions take a name.', 'proto-blocks'));
	}
	if (when.regex === true && !REGEX_TYPES.includes(type)) {
		bad(['regex'], __('Only referrer, user agent, header and cookie conditions take a regular expression.', 'proto-blocks'));
	}
	if (type === 'login' || type === '404') {
		if (value !== undefined) {
			bad(['value'], __('This condition takes no value.', 'proto-blocks'));
		}
		return;
	}
	if (LIST_TYPES.includes(type)) {
		if (!Array.isArray(value) || value.length === 0) {
			bad(['value'], __('This condition needs a list of values.', 'proto-blocks'));
			return;
		}
		value.forEach((item, i) => {
			const ok = isString(item) && (type === 'ip' ? isIpOrCidr(item.trim()) : LANGUAGE_TAG.test(item));
			if (ok) {
				return;
			}
			bad(
				['value', i],
				type === 'ip'
					? sprintf(
							/* translators: %s: the entry that is not an IP address. */
							__('"%s" is not an IP address or CIDR range.', 'proto-blocks'),
							String(item)
						)
					: sprintf(
							/* translators: %s: the entry that is not a language tag. */
							__('"%s" is not a language tag (for example "en" or "pt-BR").', 'proto-blocks'),
							String(item)
						)
			);
		});
		return;
	}
	if (!isString(value) || value === '') {
		bad(['value'], __('Enter a value for this condition.', 'proto-blocks'));
		return;
	}
	if (length(value) > VALUE_MAX) {
		bad(['value'], schemaMessage.tooLong(VALUE_MAX));
		return;
	}
	if (NAMED_TYPES.includes(type) && (!isString(name) || !(type === 'header' ? HEADER_NAME : COOKIE_NAME).test(name))) {
		bad(['name'], type === 'header' ? __('Enter a header name of letters, digits and "-".', 'proto-blocks') : __('Enter a valid cookie name.', 'proto-blocks'));
	}
	switch (type) {
		case 'role':
			if (!ROLE_NAME.test(value)) {
				bad(['value'], __('A role or capability uses only letters, digits, "_" and "-".', 'proto-blocks'));
			}
			break;
		case 'server':
			if (!isHost(value.toLowerCase(), true)) {
				bad(['value'], __('Enter a bare host name, without http://, a port or a path.', 'proto-blocks'));
			}
			break;
		case 'filter':
			if (!FILTER_NAME.test(value)) {
				bad(['value'], __('A filter name uses only a-z, 0-9, "_" and "-".', 'proto-blocks'));
			}
			break;
		default:
			if (when.regex === true) {
				const error = regexError(value);
				if (error !== null) {
					add(['when', 'value'], 'invalid_regex', ruleMessage('invalid_regex', error));
				}
			}
	}
}

/**
 * Every error the server would report for this rule, in its order: the
 * schema first (and nothing else when it fails), then RuleValidator.
 */
export function ruleProblems(rule: FileRule, options: RuleCheckOptions = {}): Problem[] {
	const schema = ruleSchemaProblems(rule);
	if (schema.length > 0) {
		return schema;
	}
	const out: Problem[] = [];
	const add = (path: (string | number)[], code: RuleCode, message: string) => out.push({ path, code, message });
	const defaults = options.defaults ?? { ignoreCase: true, ignoreTrailingSlash: true, query: 'exact' };
	const action = rule.action ?? 'url';
	const regex = rule.regex === true;
	const when = rule.when;
	const from = rule.from === undefined || regex ? rule.from : reduceToPath(rule.from, options.siteHost);
	const { to, else: fallback, code } = rule;

	// Source.
	if (from === undefined || from === '') {
		if (when?.type !== '404') {
			add(['from'], 'missing_source', ruleMessage('missing_source'));
		}
	} else if (regex) {
		const error = regexError(from);
		if (error !== null) {
			add(['from'], 'invalid_regex', ruleMessage('invalid_regex', error));
		}
	} else if (splitAbsolute(from) !== null) {
		add(['from'], 'use_when_server', ruleMessage('use_when_server'));
	} else if (from[0] !== '/' || hasControl(from)) {
		add(['from'], 'invalid_source', ruleMessage('invalid_source'));
	}

	// Target and code, per action.
	const checkTarget = (pathOnly: boolean, missing: string) => {
		if (to === undefined) {
			add(['to'], 'invalid_target', missing);
			return;
		}
		const error = targetError(to, pathOnly);
		if (error !== null) {
			add(['to'], 'invalid_target', error);
		}
	};
	switch (action) {
		case 'url':
			checkTarget(false, __('A redirect needs a target.', 'proto-blocks'));
			if (code !== undefined && !(REDIRECT_CODES as readonly number[]).includes(code)) {
				add(
					['code'],
					'invalid_code',
					sprintf(
						/* translators: %s: allowed codes. */
						__('A redirect code must be one of %s.', 'proto-blocks'),
						REDIRECT_CODES.join(', ')
					)
				);
			}
			break;
		case 'error':
			if (to !== undefined) {
				add(['to'], 'invalid_target', __('An error rule has no target.', 'proto-blocks'));
			}
			if (code !== undefined && !(ERROR_CODES as readonly number[]).includes(code)) {
				add(
					['code'],
					'invalid_code',
					sprintf(
						/* translators: %s: allowed codes. */
						__('An error code must be one of %s.', 'proto-blocks'),
						ERROR_CODES.join(', ')
					)
				);
			}
			break;
		case 'pass':
			checkTarget(true, __('A pass-through rule needs a target path.', 'proto-blocks'));
			if (code !== undefined) {
				add(['code'], 'invalid_code', __('A pass-through rule takes no code.', 'proto-blocks'));
			}
			break;
		default:
			if (to !== undefined) {
				add(['to'], 'invalid_target', __('A "Do nothing" rule has no target.', 'proto-blocks'));
			}
			if (code !== undefined) {
				add(['code'], 'invalid_code', __('A "Do nothing" rule takes no code.', 'proto-blocks'));
			}
	}

	if (fallback !== undefined) {
		if (!when) {
			add(['else'], 'invalid_else', __('A fallback target needs a match condition.', 'proto-blocks'));
		} else if (action !== 'url') {
			add(['else'], 'invalid_else', __('Only "Redirect to URL" can have a fallback target.', 'proto-blocks'));
		} else {
			const error = targetError(fallback, false);
			if (error !== null) {
				add(['else'], 'invalid_else', error);
			}
		}
	}

	// Self-loop: a plain redirect whose target is its own source.
	if (!regex && action === 'url' && from !== undefined && from !== '') {
		const ignoreCase = rule.ignoreCase ?? defaults.ignoreCase;
		const ignoreTrailingSlash = rule.ignoreTrailingSlash ?? defaults.ignoreTrailingSlash;
		for (const [key, target] of [
			['to', to],
			['else', fallback],
		] as const) {
			if (target !== undefined && sameTarget(from, target, ignoreCase, ignoreTrailingSlash, options.siteHost)) {
				add([key], 'self_redirect', ruleMessage('self_redirect'));
			}
		}
	}

	for (const [name, value] of Object.entries(rule.headers ?? {})) {
		const error = headerError(name, value);
		if (error !== null) {
			add(['headers', name], 'invalid_header', error);
		}
	}

	if (when) {
		conditionProblems(when, add);
	}

	return out;
}

/** Warnings that do not block saving (RuleValidator `unanchored_regex`). */
export function ruleWarnings(rule: FileRule): { code: 'unanchored_regex'; message: string }[] {
	if (rule.regex && rule.from && regexError(rule.from) === null && !anchored(rule.from)) {
		return [
			{
				code: 'unanchored_regex',
				message: __('This pattern has neither ^ nor $, so it matches anywhere in the URL.', 'proto-blocks'),
			},
		];
	}
	return [];
}

/** `$defs/rule` plus RuleValidator, as a zod schema whose issues carry the code in `params.code`. */
export function ruleSchema(options: RuleCheckOptions = {}) {
	return z.unknown().superRefine((rule, ctx) => {
		for (const problem of ruleProblems(rule as FileRule, options)) {
			ctx.addIssue({ code: 'custom', path: problem.path, message: problem.message, params: { code: problem.code } });
		}
	});
}

const queryMode = z.enum(QUERY_MODES);

export const groupDefaultsSchema = z.strictObject({
	ignoreCase: z.boolean().optional(),
	ignoreTrailingSlash: z.boolean().optional(),
	query: queryMode.optional(),
});

/** `$defs/group` plus GroupValidator's `duplicate_rule` (explicit ids). */
export function groupSchema(options: RuleCheckOptions = {}) {
	return z
		.strictObject({
			$schema: z.string().optional(),
			title: z
				.string()
				.min(1)
				.max(TITLE_MAX)
				.refine((title) => !/[<>]/.test(title), { message: __('Titles cannot contain < or >.', 'proto-blocks') })
				.optional(),
			enabled: z.boolean().optional(),
			position: z.number().int().min(POSITION_MIN).max(POSITION_MAX).optional(),
			defaults: groupDefaultsSchema.optional(),
			rules: z.array(ruleSchema(options)).optional(),
		})
		.superRefine((group, ctx) => {
			const seen = new Set<string>();
			(group.rules ?? []).forEach((rule, i) => {
				const id = isObject(rule) && isString(rule.id) ? rule.id : null;
				if (id === null) {
					return;
				}
				if (seen.has(id)) {
					ctx.addIssue({ code: 'custom', path: ['rules', i, 'id'], message: ruleMessage('duplicate_rule'), params: { code: 'duplicate_rule' } });
				}
				seen.add(id);
			});
		});
}

const coded = (code: SettingsCode, message: string) => ({ message, params: { code } });
const retention = z.number().int().min(-1).max(90);
const headerMap = z.record(z.string(), z.string().max(HEADER_VALUE_MAX)).superRefine((map, ctx) => {
	for (const [name, value] of Object.entries(map)) {
		const error = headerError(name, value);
		if (error !== null) {
			ctx.addIssue({ code: 'custom', path: [name], message: error, params: { code: 'invalid_header' } });
		}
	}
});
const unique = <T,>(list: T[]) => new Set(list).size === list.length;

/** `$defs/settings` plus SettingsValidator: every key optional, unknown keys refused. */
export const settingsSchema = z.strictObject({
	$schema: z.string().optional(),
	defaults: groupDefaultsSchema.optional(),
	log: z
		.strictObject({
			redirects: retention.optional(),
			notFound: retention.optional(),
			ip: z.enum(['none', 'masked', 'full']).optional(),
			headers: z.boolean().optional(),
			external: z.boolean().optional(),
			trustedProxies: z
				.array(z.string().min(1).max(49).refine(isIpOrCidr, coded('invalid_ip', __('Enter IP addresses or CIDR ranges.', 'proto-blocks'))))
				.max(100)
				.optional(),
			ipHeaders: z
				.array(z.enum(['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP', 'Client-IP', 'True-Client-IP']))
				.refine(unique, { message: __('List each header once.', 'proto-blocks') })
				.optional(),
		})
		.optional(),
	trackHits: z.boolean().optional(),
	cache: z.union([z.literal(-1), z.literal(0), z.literal(1), z.literal(24), z.literal(168)]).optional(),
	monitor: z
		.strictObject({
			postTypes: z
				.array(z.string().regex(/^[a-z0-9_-]{1,20}$/, { message: __('Post type names use up to 20 lowercase letters, digits, "_" or "-".', 'proto-blocks') }))
				.refine(unique, { message: __('List each post type once.', 'proto-blocks') })
				.optional(),
			group: z
				.string()
				.refine((slug) => /^(?!settings$)[a-z][a-z0-9_-]{0,59}$/.test(slug) && !RESERVED_SLUGS.includes(slug), {
					message: __('Use lowercase letters, digits, hyphens or underscores, starting with a letter.', 'proto-blocks'),
				})
				.optional(),
			trash: z.boolean().optional(),
			associated: z
				.string()
				.max(100)
				.regex(/^(|\/[^\s<>]*)$/, { message: __('Enter a path suffix starting with /, or leave empty.', 'proto-blocks') })
				.optional(),
		})
		.optional(),
	headers: z.strictObject({ site: headerMap.optional(), redirect: headerMap.optional() }).optional(),
	canonical: z
		.strictObject({
			https: z.boolean().optional(),
			www: z.enum(['', 'www', 'nowww']).optional(),
			aliases: z
				.array(z.string().min(1).max(253).refine((host) => isHost(host), coded('invalid_host', ruleMessage('invalid_host'))))
				.max(20)
				.refine(unique, { message: __('List each alias once.', 'proto-blocks') })
				.optional(),
			relocate: z
				.string()
				.max(URL_MAX)
				.refine((url) => url === '' || isRelocateUrl(url), coded('invalid_url', ruleMessage('invalid_url')))
				.optional(),
		})
		.optional(),
	coexist: z.boolean().optional(),
});

/** One entry of a refused save's `data.errors` (SchemaFileWriter: `{path, message}`; `code` when the server passes it on). */
export interface ServerFinding {
	path: string;
	code?: string;
	message: string;
}

/** The findings of a `proto_blocks_schema_invalid` refusal; [] for any other error. */
export function serverFindings(error: ApiError): ServerFinding[] {
	if (error.code !== 'proto_blocks_schema_invalid' || !Array.isArray(error.data.errors)) {
		return [];
	}
	return (error.data.errors as unknown[]).flatMap((entry) => {
		if (!isObject(entry)) {
			return [];
		}
		const code = isString(entry.code) ? entry.code : undefined;
		const message = isString(entry.message) && entry.message !== '' ? entry.message : ruleMessage(code ?? 'schema_invalid');
		return [{ path: isString(entry.path) ? entry.path : '', code, message }];
	});
}

/** A JSON pointer ("/rules/0/when/value/1") as segments. */
export function pointer(path: string): string[] {
	return path === '' || path === '/' ? [] : path.replace(/^\//, '').split('/').map((part) => part.replace(/~1/g, '/').replace(/~0/g, '~'));
}

/** Dialog fields a rule key maps to; `root` is the dialog's general error. */
export type RuleField = 'from' | 'to' | 'else' | 'code' | 'title' | 'conditionValue' | 'conditionName' | 'root';

/** The dialog field for a path inside a rule (segments after `/rules/<i>`). */
export function ruleField(segments: (string | number)[]): RuleField {
	const [key, sub] = segments.map(String);
	switch (key) {
		case 'from':
		case 'to':
		case 'else':
		case 'code':
		case 'title':
			return key;
		case 'when':
			return sub === 'name' ? 'conditionName' : sub === 'value' || sub === 'type' || sub === 'regex' || sub === undefined ? 'conditionValue' : 'root';
		default:
			return 'root';
	}
}

/**
 * Field messages for the rule at `ruleIndex` of the posted group file, from
 * a refusal's findings. Findings elsewhere in the file (another rule, the
 * group itself) and rule keys the dialog has no field for go to `root`,
 * prefixed with where they are.
 */
export function ruleFieldErrors(findings: ServerFinding[], ruleIndex: number | null): Partial<Record<RuleField, string>> {
	const out: Partial<Record<RuleField, string>> = {};
	const put = (field: RuleField, message: string) => {
		if (out[field] === undefined) {
			out[field] = message;
		} else if (field === 'root') {
			out.root = `${out.root} ${message}`;
		}
	};
	for (const finding of findings) {
		const segments = pointer(finding.path);
		const own = ruleIndex !== null && segments[0] === 'rules' && segments[1] === String(ruleIndex);
		const field = own ? ruleField(segments.slice(2)) : 'root';
		if (field !== 'root') {
			put(field, finding.message);
		} else {
			const at = own ? segments.slice(2) : segments;
			put('root', at.length > 0 ? `${at.join('.')}: ${finding.message}` : finding.message);
		}
	}
	return out;
}

/** Settings form fields ("log.trustedProxies") for a refusal's findings; the rest under `root`. */
export function settingsFieldErrors(findings: ServerFinding[]): Record<string, string> {
	const out: Record<string, string> = {};
	for (const finding of findings) {
		const [section, key] = pointer(finding.path);
		const nested = ['defaults', 'log', 'monitor', 'headers', 'canonical'].includes(section ?? '');
		const field = section === undefined ? 'root' : nested && key !== undefined ? `${section}.${key}` : nested ? 'root' : section;
		if (out[field] === undefined) {
			out[field] = field === 'root' && finding.path !== '' ? `${pointer(finding.path).join('.')}: ${finding.message}` : finding.message;
		}
	}
	return out;
}

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
