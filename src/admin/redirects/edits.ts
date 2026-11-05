/**
 * Edits to redirect group files and the settings file that keep the
 * developer's comments and layout (spec §6 "comments survive", anti-goal
 * "a UI write that loses a hand-written comment").
 *
 * jsonc-parser's `modify` reformats the array element it touches onto many
 * lines and, when removing one, takes the next element's trailing comment
 * with it. Rules are therefore located with `parseTree` and spliced as text:
 * a one-line rule is rewritten on its line (the serializer's layout, spec
 * §10), a rule spread over several lines or holding comments is edited key
 * by key with `modify`. Top-level group keys and settings use `modify`.
 */
import { applyEdits, findNodeAtLocation, getNodeValue, modify, parseTree, createScanner, type Node } from 'jsonc-parser';
import type { FileRule } from './types';

const PARSE_OPTIONS = { disallowComments: false, allowTrailingComma: true, allowEmptyContent: false };
const FORMATTING = { formattingOptions: { insertSpaces: true, tabSize: 2 } };

/** Canonical key order of a rule (spec §1 table). */
export const RULE_KEY_ORDER = ['id', 'title', 'enabled', 'from', 'regex', 'to', 'else', 'code', 'action', 'when', 'query', 'ignoreCase', 'ignoreTrailingSlash', 'log', 'headers'];

/** A rule to edit: its id, and its position for a file rule written without one. */
export type RuleRef = string | { id: string; index?: number };

// jsonc-parser SyntaxKind (a const enum, so not importable as a value).
const COMMA = 5;
const LINE_COMMENT = 12;
const BLOCK_COMMENT = 13;
const LINE_BREAK = 14;
const TRIVIA = 15;
const EOF = 17;

export class RuleNotFoundError extends Error {
	constructor(id: string) {
		super(`Rule ${id} is not in the file.`);
		this.name = 'RuleNotFoundError';
	}
}

/** One-line JSON with a space after colons and commas: `{ "from": "/a", "to": "/b" }`. */
export function compact(value: unknown): string {
	if (Array.isArray(value)) {
		return `[${value.map(compact).join(', ')}]`;
	}
	if (value !== null && typeof value === 'object') {
		const entries = Object.entries(value as Record<string, unknown>).filter(([, v]) => v !== undefined);
		return entries.length === 0 ? '{}' : `{ ${entries.map(([k, v]) => `${JSON.stringify(k)}: ${compact(v)}`).join(', ')} }`;
	}
	return JSON.stringify(value);
}

function canonicalIndex(key: string): number {
	const i = RULE_KEY_ORDER.indexOf(key);
	return i === -1 ? RULE_KEY_ORDER.length : i;
}

/** `rule` with its keys in canonical order. */
export function orderRule(rule: FileRule): FileRule {
	const entries = Object.entries(rule).filter(([, v]) => v !== undefined);
	entries.sort(([a], [b]) => canonicalIndex(a) - canonicalIndex(b));
	return Object.fromEntries(entries) as FileRule;
}

/** Keeps the keys `original` already had in their order; new keys go where the canonical order puts them. */
function orderLike(original: string[], next: Record<string, unknown>): Record<string, unknown> {
	const keys = original.filter((key) => next[key] !== undefined);
	const added = Object.keys(next)
		.filter((key) => next[key] !== undefined && !keys.includes(key))
		.sort((a, b) => canonicalIndex(a) - canonicalIndex(b));
	for (const key of added) {
		const ci = canonicalIndex(key);
		let at = 0;
		keys.forEach((existing, i) => {
			if (canonicalIndex(existing) < ci) {
				at = i + 1;
			}
		});
		keys.splice(at, 0, key);
	}
	return Object.fromEntries(keys.map((key) => [key, next[key]]));
}

function tree(text: string): Node {
	const errors: unknown[] = [];
	const root = parseTree(text, errors as never, PARSE_OPTIONS);
	if (!root || errors.length > 0 || root.type !== 'object') {
		throw new SyntaxError('The file is not valid JSONC.');
	}
	return root;
}

function rulesArray(text: string): Node | null {
	const node = findNodeAtLocation(tree(text), ['rules']);
	return node && node.type === 'array' ? node : null;
}

/** Parsed `rules` of a group file text, in file order. */
export function readRules(text: string): FileRule[] {
	const node = rulesArray(text);
	return node ? (node.children ?? []).map((child) => getNodeValue(child) as FileRule) : [];
}

function find(text: string, ref: RuleRef): { node: Node; index: number; value: Record<string, unknown>; siblings: Node[] } {
	const id = typeof ref === 'string' ? ref : ref.id;
	const fallback = typeof ref === 'string' ? undefined : ref.index;
	const array = rulesArray(text);
	const siblings = array?.children ?? [];
	let index = siblings.findIndex((child) => child.type === 'object' && (getNodeValue(child) as FileRule).id === id);
	if (index === -1 && fallback !== undefined && siblings[fallback]) {
		const value = getNodeValue(siblings[fallback]) as FileRule;
		if (value !== null && typeof value === 'object' && value.id === undefined) {
			index = fallback;
		}
	}
	if (index === -1) {
		throw new RuleNotFoundError(id);
	}
	return { node: siblings[index], index, value: getNodeValue(siblings[index]) as Record<string, unknown>, siblings };
}

function splice(text: string, start: number, end: number, insert: string): string {
	return text.slice(0, start) + insert + text.slice(end);
}

function lineStart(text: string, offset: number): number {
	return text.lastIndexOf('\n', offset - 1) + 1;
}

function lineEnd(text: string, offset: number): number {
	const i = text.indexOf('\n', offset);
	return i === -1 ? text.length : i;
}

function indentOf(text: string, offset: number): string {
	const start = lineStart(text, offset);
	return /^[ \t]*/.exec(text.slice(start))?.[0] ?? '';
}

/** Whether only whitespace precedes `offset` on its line. */
function startsLine(text: string, offset: number): boolean {
	return text.slice(lineStart(text, offset), offset).trim() === '';
}

/** Offset of the comma right after `offset` (skipping whitespace and comments), or -1. */
function commaAfter(text: string, offset: number): number {
	const scanner = createScanner(text.slice(offset), false);
	for (;;) {
		const kind = scanner.scan();
		if (kind === TRIVIA || kind === LINE_BREAK || kind === LINE_COMMENT || kind === BLOCK_COMMENT) {
			continue;
		}
		return kind === COMMA ? offset + scanner.getTokenOffset() : -1;
	}
}

function hasComment(text: string, start: number, end: number): boolean {
	const scanner = createScanner(text.slice(start, end), false);
	for (let kind = scanner.scan(); kind !== EOF; kind = scanner.scan()) {
		if (kind === LINE_COMMENT || kind === BLOCK_COMMENT) {
			return true;
		}
	}
	return false;
}

function isOneLine(text: string, node: Node): boolean {
	return !text.slice(node.offset, node.offset + node.length).includes('\n') && !hasComment(text, node.offset, node.offset + node.length);
}

/** Replaces the rule at `node` with `next`, keeping the key order it had. */
function rewrite(text: string, node: Node, index: number, current: Record<string, unknown>, next: Record<string, unknown>): string {
	if (isOneLine(text, node)) {
		return splice(text, node.offset, node.offset + node.length, compact(orderLike(Object.keys(current), next)));
	}
	let out = text;
	const keys = [...Object.keys(current), ...Object.keys(next).filter((key) => !(key in current))];
	for (const key of keys) {
		if (JSON.stringify(current[key]) === JSON.stringify(next[key])) {
			continue;
		}
		out = applyEdits(out, modify(out, ['rules', index, key], next[key], FORMATTING));
	}
	return out;
}

/** Inserts `rule` (one line) at position `at` of the rules array; at the end when omitted. */
export function addRule(text: string, rule: FileRule, at?: number): string {
	let array = rulesArray(text);
	if (!array) {
		text = applyEdits(text, modify(text, ['rules'], [], FORMATTING));
		array = rulesArray(text) as Node;
	}
	const line = compact(orderRule(rule));
	const children = array.children ?? [];
	const open = array.offset;
	const close = array.offset + array.length - 1;
	const keyIndent = indentOf(text, open);

	if (children.length === 0) {
		const inner = text.slice(open + 1, close);
		const body = `\n${keyIndent}  ${line}`;
		return inner.trim() === '' ? splice(text, open + 1, close, `${body}\n${keyIndent}`) : splice(text, open + 1, open + 1, body);
	}

	const multiline = text.slice(open, close).includes('\n');
	const index = at === undefined ? children.length : Math.max(0, Math.min(at, children.length));

	if (index < children.length) {
		const next = children[index];
		if (multiline && startsLine(text, next.offset)) {
			const start = lineStart(text, next.offset);
			return splice(text, start, start, `${indentOf(text, next.offset)}${line},\n`);
		}
		return splice(text, next.offset, next.offset, `${line}, `);
	}

	const prev = children[children.length - 1];
	const prevEnd = prev.offset + prev.length;
	const trailing = commaAfter(text, prevEnd);
	if (!multiline) {
		return trailing !== -1 ? splice(text, trailing + 1, trailing + 1, ` ${line},`) : splice(text, prevEnd, prevEnd, `, ${line}`);
	}
	const indent = indentOf(text, prev.offset);
	const from = trailing !== -1 ? trailing + 1 : prevEnd;
	const end = Math.min(lineEnd(text, from), close);
	const tail = trailing !== -1 ? `\n${indent}${line},` : `\n${indent}${line}`;
	// The closing bracket on the same line as the last rule moves to its own line.
	const bracket = end === close ? `\n${keyIndent}` : '';
	let out = splice(text, end, end, tail + bracket);
	if (trailing === -1) {
		out = splice(out, prevEnd, prevEnd, ',');
	}
	return out;
}

/** Replaces the rule `ref` with `rule` (which should carry the id). */
export function updateRule(text: string, ref: RuleRef, rule: FileRule): string {
	const { node, index, value } = find(text, ref);
	return rewrite(text, node, index, value, rule as Record<string, unknown>);
}

/** Turns one rule on (the `enabled` key is removed, true is the default) or off. */
export function setRuleEnabled(text: string, ref: RuleRef, enabled: boolean): string {
	const { node, index, value } = find(text, ref);
	const next: Record<string, unknown> = { ...value };
	if (next.id === undefined && typeof ref !== 'string') {
		next.id = ref.id;
	}
	if (enabled) {
		delete next.enabled;
	} else {
		next.enabled = false;
	}
	return rewrite(text, node, index, value, next);
}

/**
 * Removes one rule. A rule alone on its line(s) takes the line with it,
 * including a comment after it on the same line; comments on the lines
 * above stay.
 */
export function removeRule(text: string, ref: RuleRef): string {
	const { node, index, siblings } = find(text, ref);
	const start = node.offset;
	const end = node.offset + node.length;
	const last = index === siblings.length - 1;
	const comma = commaAfter(text, end);
	const to = comma !== -1 ? comma + 1 : end;
	const rest = text.slice(to, lineEnd(text, to)).trim();
	const ownLine = startsLine(text, start) && (rest === '' || rest.startsWith('//') || (rest.startsWith('/*') && rest.endsWith('*/')));

	if (ownLine) {
		const from = lineStart(text, start);
		const out = splice(text, from, Math.min(lineEnd(text, to) + 1, text.length), '');
		if (!last || comma !== -1 || index === 0) {
			return out;
		}
		// The rule before is now last: drop the comma that followed it.
		const prev = siblings[index - 1];
		const prevComma = commaAfter(out, prev.offset + prev.length);
		return prevComma === -1 ? out : splice(out, prevComma, prevComma + 1, '');
	}
	if (!last) {
		let stop = to;
		while (text[stop] === ' ') {
			stop++;
		}
		return splice(text, start, stop, '');
	}
	if (index > 0 && comma === -1) {
		// `[{a}, {b}]` → `[{a}]`.
		const prev = siblings[index - 1];
		return splice(text, prev.offset + prev.length, end, '');
	}
	return splice(text, start, to, '');
}

/** Moves a rule `delta` places up (negative) or down; the two rules swap their text. */
export function moveRule(text: string, ref: RuleRef, delta: number): string {
	const { index, siblings } = find(text, ref);
	const target = index + delta;
	if (delta === 0 || target < 0 || target >= siblings.length) {
		return text;
	}
	let out = text;
	// Bubble one place at a time so every rule in between keeps its order.
	for (let i = index; i !== target; i += delta > 0 ? 1 : -1) {
		const array = rulesArray(out) as Node;
		const nodes = array.children ?? [];
		const a = nodes[Math.min(i, i + Math.sign(delta))];
		const b = nodes[Math.max(i, i + Math.sign(delta))];
		const aText = out.slice(a.offset, a.offset + a.length);
		const bText = out.slice(b.offset, b.offset + b.length);
		out = out.slice(0, a.offset) + bText + out.slice(a.offset + a.length, b.offset) + aText + out.slice(b.offset + b.length);
	}
	return out;
}

/** Sets (or, with undefined, removes) a top-level group key: title, enabled, position, defaults. */
export function setGroupField(text: string, key: 'title' | 'enabled' | 'position' | 'defaults', value: unknown): string {
	return applyEdits(text, modify(text, [key], value, FORMATTING));
}

export const GROUP_HEADER =
	'// Managed by Proto-Blocks. Edit by hand or in wp-admin → Proto-Blocks → Redirects. Hand-written comments inside the rules array survive UI edits but not CLI or monitor writes.';

/** A new, empty group file. */
export function newGroupText(slug: string, title: string, position = 10): string {
	return `${GROUP_HEADER}\n// proto-redirects/${slug}/redirects.jsonc\n{\n  "title": ${JSON.stringify(title)},\n  "enabled": true,\n  "position": ${position},\n  "rules": []\n}\n`;
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function same(a: unknown, b: unknown): boolean {
	return JSON.stringify(a) === JSON.stringify(b);
}

function valueAt(data: unknown, path: string[]): unknown {
	return path.reduce<unknown>((node, key) => (isPlainObject(node) ? node[key] : undefined), data);
}

/** Settings values replaced whole rather than key by key (maps of header name → value). */
const WHOLE = new Set(['headers.site', 'headers.redirect']);

/**
 * Writes the settings form's `values` into the settings file `text`, one key
 * at a time. A key is written only when it differs from what the file holds
 * or, when the file does not set it, from the module default; so saving an
 * untouched form leaves the file as it was.
 */
export function applySettingsEdits(text: string, defaults: Record<string, unknown>, values: Record<string, unknown>): string {
	const fileData = getNodeValue(tree(text)) as Record<string, unknown>;
	const changes: { path: string[]; value: unknown }[] = [];
	const walk = (node: Record<string, unknown>, path: string[]) => {
		for (const [key, value] of Object.entries(node)) {
			const next = [...path, key];
			if (isPlainObject(value) && !WHOLE.has(next.join('.'))) {
				walk(value, next);
				continue;
			}
			const inFile = valueAt(fileData, next);
			const current = inFile !== undefined ? inFile : valueAt(defaults, next);
			if (!same(current, value)) {
				changes.push({ path: next, value });
			}
		}
	};
	walk(values, []);
	return changes.reduce((out, { path, value }) => applyEdits(out, modify(out, path, value, FORMATTING)), text);
}

/** A fresh `r_` + 8 hex id, unique among `taken`. */
export function newRuleId(taken: string[] = []): string {
	for (;;) {
		const bytes = new Uint8Array(4);
		if (typeof globalThis.crypto?.getRandomValues === 'function') {
			globalThis.crypto.getRandomValues(bytes);
		} else {
			for (let i = 0; i < bytes.length; i++) {
				bytes[i] = Math.floor(Math.random() * 256);
			}
		}
		const id = `r_${Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')}`;
		if (!taken.includes(id)) {
			return id;
		}
	}
}
