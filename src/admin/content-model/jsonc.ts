/**
 * Reading and editing declaration files (JSONC) without losing the
 * developer's comments, key order or formatting.
 */
import { applyEdits, modify, parseTree, type Node, type ParseError } from 'jsonc-parser';
import { __, sprintf } from '@wordpress/i18n';

export interface JsoncError {
	offset: number;
	message: string;
}

const PARSE_OPTIONS = { disallowComments: false, allowTrailingComma: true, allowEmptyContent: false };
const FORMATTING = { formattingOptions: { insertSpaces: true, tabSize: 2 } };

/** Objects compared key by key, so a comment beside one entry survives an edit to another. */
const PER_KEY = new Set(['labels', 'rewrite']);

/** jsonc-parser ParseErrorCode (a const enum, so not importable as a value). */
function parseErrorMessage(code: number): string {
	switch (code) {
		case 3:
			return __('Expected a property name.', 'proto-blocks');
		case 4:
			return __('Expected a value.', 'proto-blocks');
		case 5:
			return __('Expected a colon.', 'proto-blocks');
		case 6:
			return __('Expected a comma.', 'proto-blocks');
		case 7:
			return __('Expected a closing brace.', 'proto-blocks');
		case 8:
			return __('Expected a closing bracket.', 'proto-blocks');
		case 9:
			return __('Expected the end of the file.', 'proto-blocks');
		case 10:
		case 11:
			return __('A comment is not closed properly.', 'proto-blocks');
		case 12:
			return __('A string is not closed.', 'proto-blocks');
		case 2:
		case 13:
			return __('A number is not valid.', 'proto-blocks');
		case 14:
		case 15:
			return __('A string contains an invalid escape.', 'proto-blocks');
		default:
			return __('Unexpected character.', 'proto-blocks');
	}
}

function forbiddenKey(node: Node | undefined): Node | null {
	if (!node) {
		return null;
	}
	if (node.type === 'property' && node.children?.[0]?.value === '__proto__') {
		return node.children[0];
	}
	for (const child of node.children ?? []) {
		const found = forbiddenKey(child);
		if (found) {
			return found;
		}
	}
	return null;
}

/** Plain objects and arrays from a parse tree that has no __proto__ key. */
function toValue(node: Node): unknown {
	switch (node.type) {
		case 'object': {
			const out: Record<string, unknown> = {};
			for (const property of node.children ?? []) {
				const [key, value] = property.children ?? [];
				if (key && value) {
					out[String(key.value)] = toValue(value);
				}
			}
			return out;
		}
		case 'array':
			return (node.children ?? []).map(toValue);
		default:
			return node.value;
	}
}

/**
 * Parses a declaration file. Comments and trailing commas are allowed. Any
 * `__proto__` key, a syntax error, or a top level that is not an object
 * gives errors and empty data.
 */
export function parseJsonc(text: string): { data: Record<string, unknown>; errors: JsoncError[] } {
	const parseErrors: ParseError[] = [];
	const tree = parseTree(text, parseErrors, PARSE_OPTIONS);

	const proto = forbiddenKey(tree);
	if (proto) {
		return {
			data: {},
			errors: [{ offset: proto.offset, message: __('The key "__proto__" is not allowed.', 'proto-blocks') }],
		};
	}
	if (parseErrors.length > 0) {
		return { data: {}, errors: parseErrors.map((e) => ({ offset: e.offset, message: parseErrorMessage(e.error) })) };
	}
	if (!tree || tree.type !== 'object') {
		return { data: {}, errors: [{ offset: tree?.offset ?? 0, message: __('The file must contain a JSON object.', 'proto-blocks') }] };
	}
	return { data: toValue(tree) as Record<string, unknown>, errors: [] };
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function same(a: unknown, b: unknown): boolean {
	if (a === b) {
		return true;
	}
	if (Array.isArray(a) && Array.isArray(b)) {
		return a.length === b.length && a.every((item, i) => same(item, b[i]));
	}
	if (isPlainObject(a) && isPlainObject(b)) {
		const keys = Object.keys(a);
		return keys.length === Object.keys(b).length && keys.every((key) => Object.prototype.hasOwnProperty.call(b, key) && same(a[key], b[key]));
	}
	return false;
}

function unionKeys(a: Record<string, unknown>, b: Record<string, unknown>): string[] {
	return [...Object.keys(a), ...Object.keys(b).filter((key) => !Object.prototype.hasOwnProperty.call(a, key))];
}

/**
 * Rewrites `text` so it holds `after`, touching only what differs from
 * `before`. Top-level keys are compared; `labels` and `rewrite` objects key by
 * key; every other value (arrays included) is replaced whole. A key missing
 * from `after` (or undefined) is removed; a new key goes at the end.
 */
export function applyDeclarationEdits(text: string, before: Record<string, unknown>, after: Record<string, unknown>): string {
	const changes: { path: string[]; value: unknown }[] = [];

	for (const key of unionKeys(before, after)) {
		const was = before[key];
		const now = after[key];
		if (same(was, now)) {
			continue;
		}
		if (PER_KEY.has(key) && isPlainObject(was) && isPlainObject(now)) {
			for (const sub of unionKeys(was, now)) {
				if (!same(was[sub], now[sub])) {
					changes.push({ path: [key, sub], value: now[sub] });
				}
			}
			continue;
		}
		changes.push({ path: [key], value: now });
	}

	return changes.reduce((current, { path, value }) => applyEdits(current, modify(current, path, value, FORMATTING)), text);
}

/** 1-based line and column of an offset, for showing parse errors. */
export function lineAndColumn(text: string, offset: number): { line: number; column: number } {
	const head = text.slice(0, offset).split('\n');
	return { line: head.length, column: head[head.length - 1].length + 1 };
}

/** "Line 3, column 5: Expected a comma." */
export function describeJsoncError(text: string, error: JsoncError): string {
	const { line, column } = lineAndColumn(text, error.offset);
	return sprintf(
		/* translators: 1: line number, 2: column number, 3: error message. */
		__('Line %1$d, column %2$d: %3$s', 'proto-blocks'),
		line,
		column,
		error.message
	);
}
