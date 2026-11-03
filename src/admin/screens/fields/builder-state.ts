/**
 * The builder's state: a pure reducer over the RAW group object, exactly as
 * the file holds it (ACF-shaped; every key the builder does not know is kept),
 * and the bridge back to the file text. Every change reaches the text through
 * setAtPath, key by key, so comments, key order and untouched fields survive.
 */
import { __, sprintf } from '@wordpress/i18n';
import { applyEdits, findNodeAtLocation, getNodeValue, modify, parseTree, type JSONPath } from 'jsonc-parser';
import groupSchema from '../../../../schemas/field-group.schema.json';
import { normalizeField } from '@/fields/model/normalize';
import type { FieldType } from '@/fields/model/types';
import { setAtPath } from './jsonc-edit';

export type RawField = Record<string, unknown>;
export type RawGroup = Record<string, unknown>;

/**
 * Indexes through `fields` / `sub_fields`; a flexible content layout is
 * addressed as `[fieldIdx, 'layouts', layoutIdx, ...]`. `[2]` is the third
 * top-level field, `[5, 0]` the first sub-field of the sixth.
 */
export type FieldPath = (number | 'layouts')[];

export type BuilderAction =
	| { type: 'add'; parent: FieldPath | null; layout?: number; fieldType: string; at?: number; label?: string }
	/** `to` names the destination list as the tree is now; its last index is the field's final position there. */
	| { type: 'move'; from: FieldPath; to: FieldPath }
	| { type: 'update'; path: FieldPath; patch: Record<string, unknown> }
	| { type: 'remove'; path: FieldPath }
	| { type: 'duplicate'; path: FieldPath }
	| { type: 'addLayout' | 'removeLayout' | 'updateLayout'; path: FieldPath; layout?: number; patch?: Record<string, unknown> }
	| { type: 'group'; patch: Record<string, unknown> };

/** A field whose conditional logic lost rules because the field they read was removed. */
export interface Notice {
	key: string;
	label: string;
}

/** The field name grammar, from the schema (GroupValidator::NAME_PATTERN is the same). */
export const NAME_PATTERN = new RegExp(groupSchema.$defs.name.pattern);

/** Types whose name may be empty (GroupValidator::LAYOUT_ONLY_TYPES). */
export const LAYOUT_ONLY_TYPES: readonly string[] = ['tab', 'accordion', 'message'];

export function isObject(v: unknown): v is Record<string, unknown> {
	return typeof v === 'object' && v !== null && !Array.isArray(v);
}

function clone<T>(v: T): T {
	return v === undefined ? v : (JSON.parse(JSON.stringify(v)) as T);
}

/** The canonical type of a declared one (`date_picker` → `date`); null when the server would skip it. */
export function canonicalType(declared: unknown): FieldType | null {
	return typeof declared === 'string' ? (normalizeField({ key: 'k', name: 'n', type: declared })?.type ?? null) : null;
}

export function hasSubFields(field: RawField): boolean {
	const type = canonicalType(field.type);
	return type === 'repeater' || type === 'group';
}

export function isFlexible(field: RawField): boolean {
	return canonicalType(field.type) === 'flexible_content';
}

/** The layouts of a flexible content field in order (ACF exports them as an object keyed by layout key). */
export function layoutList(field: RawField): RawField[] {
	const raw = field.layouts;
	const all = Array.isArray(raw) ? raw : isObject(raw) ? Object.values(raw) : [];
	return all.filter(isObject);
}

function listOf(owner: Record<string, unknown>, key: string): RawField[] {
	if (!Array.isArray(owner[key])) {
		owner[key] = [];
	}
	return owner[key] as RawField[];
}

/**
 * The list a path's last index points into, and that index. Walks only
 * through repeater/group sub-fields and flexible layouts; null otherwise.
 * Missing `fields` / `sub_fields` lists are created on `group` (callers pass a clone).
 */
function locate(group: RawGroup, path: FieldPath): { list: RawField[]; index: number } | null {
	let list = listOf(group, 'fields');
	let i = 0;
	for (;;) {
		const n = path[i];
		if (typeof n !== 'number') {
			return null;
		}
		if (i === path.length - 1) {
			return { list, index: n };
		}
		const field = list[n];
		if (!isObject(field)) {
			return null;
		}
		if (path[i + 1] === 'layouts') {
			const l = path[i + 2];
			const layout = typeof l === 'number' && isFlexible(field) ? layoutList(field)[l] : undefined;
			if (!layout || i + 3 >= path.length) {
				return null;
			}
			list = listOf(layout, 'sub_fields');
			i += 3;
		} else {
			if (!hasSubFields(field)) {
				return null;
			}
			list = listOf(field, 'sub_fields');
			i += 1;
		}
	}
}

/** The field at `path`, or null. */
export function fieldAt(group: RawGroup, path: FieldPath): RawField | null {
	const at = locate(clone(group), path);
	return at && isObject(at.list[at.index]) ? at.list[at.index] : null;
}

/** The list of fields that holds `path` (its siblings, itself included). */
export function siblingsAt(group: RawGroup, path: FieldPath): RawField[] {
	return locate(clone(group), path)?.list.filter(isObject) ?? [];
}

/** The sub-field list of the container at `parent` (and `layout`), or null when it is not one. */
function childList(group: RawGroup, parent: FieldPath | null, layout?: number): RawField[] | null {
	if (parent === null) {
		return listOf(group, 'fields');
	}
	const at = locate(group, parent);
	const field = at?.list[at.index];
	if (!isObject(field)) {
		return null;
	}
	if (layout !== undefined) {
		const entry = isFlexible(field) ? layoutList(field)[layout] : undefined;
		return entry ? listOf(entry, 'sub_fields') : null;
	}
	return hasSubFields(field) ? listOf(field, 'sub_fields') : null;
}

function hex(chars: number): string {
	const bytes = new Uint8Array(Math.ceil(chars / 2));
	crypto.getRandomValues(bytes);
	return Array.from(bytes, (b) => b.toString(16).padStart(2, '0'))
		.join('')
		.slice(0, chars);
}

/** 'field_' + 13 lowercase hex characters. */
export function newFieldKey(): string {
	return 'field_' + hex(13);
}

/** 'group_' + 13 lowercase hex characters. */
export function newGroupKey(): string {
	return 'group_' + hex(13);
}

function newLayoutKey(): string {
	return 'layout_' + hex(13);
}

/** 'Start date' → 'start_date'; always satisfies NAME_PATTERN. */
export function slugifyName(label: string): string {
	let name = label
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '_')
		.replace(/^_+|_+$/g, '');
	if (!/^[a-z]/.test(name)) {
		name = name === '' ? 'field' : 'field_' + name;
	}
	return name.slice(0, 64).replace(/_+$/, '');
}

/** `base`, or `base_2`, `base_3`… when a field in `list` (other than `except`) already has it. */
export function uniqueName(base: string, list: RawField[], except?: RawField): string {
	const taken = new Set(list.filter((f) => f !== except).map((f) => f.name));
	if (!taken.has(base)) {
		return base;
	}
	for (let n = 2; ; n++) {
		const suffix = `_${n}`;
		const candidate = base.slice(0, 64 - suffix.length) + suffix;
		if (!taken.has(candidate)) {
			return candidate;
		}
	}
}

function newLayout(existing: RawField[]): RawField {
	let name = 'layout';
	for (let n = 2; existing.some((l) => l.name === name); n++) {
		name = `layout_${n}`;
	}
	return { key: newLayoutKey(), name, label: __('Layout', 'proto-blocks'), sub_fields: [] };
}

function newField(fieldType: string, label: string, list: RawField[]): RawField {
	const field: RawField = { key: newFieldKey(), label, name: '', type: fieldType };
	const type = canonicalType(fieldType);
	if (!LAYOUT_ONLY_TYPES.includes(type ?? '')) {
		field.name = uniqueName(slugifyName(label), list);
	}
	if (type === 'repeater' || type === 'group') {
		field.sub_fields = [];
	} else if (type === 'flexible_content') {
		field.layouts = [newLayout([])];
	}
	return field;
}

function merge(target: Record<string, unknown>, patch: Record<string, unknown>): Record<string, unknown> {
	const next = { ...target };
	for (const [k, v] of Object.entries(patch)) {
		if (v === undefined) {
			delete next[k];
		} else {
			next[k] = v;
		}
	}
	return next;
}

function subFieldsOf(owner: Record<string, unknown>): RawField[] {
	return (Array.isArray(owner.sub_fields) ? owner.sub_fields : []).filter(isObject);
}

/** Every field key in `field` and below it. */
function keysIn(field: RawField, into: Set<string> = new Set()): Set<string> {
	if (typeof field.key === 'string') {
		into.add(field.key);
	}
	subFieldsOf(field).forEach((sub) => keysIn(sub, into));
	layoutList(field).forEach((layout) => subFieldsOf(layout).forEach((sub) => keysIn(sub, into)));
	return into;
}

/** Calls `visit` on every field of `list`, depth first. */
export function walkFields(list: unknown, visit: (field: RawField, path: FieldPath) => void, prefix: FieldPath = []): void {
	(Array.isArray(list) ? list : []).forEach((field, i) => {
		if (!isObject(field)) {
			return;
		}
		const path = [...prefix, i];
		visit(field, path);
		if (hasSubFields(field)) {
			walkFields(field.sub_fields, visit, path);
		}
		if (isFlexible(field)) {
			layoutList(field).forEach((layout, l) => walkFields(layout.sub_fields, visit, [...path, 'layouts', l]));
		}
	});
}

/** Drops every condition rule that reads one of `keys`; a field left without rules gets 0 (ACF's off value). */
function dropRules(group: RawGroup, keys: Set<string>): Notice[] {
	const notices: Notice[] = [];
	walkFields(group.fields, (field) => {
		const logic = field.conditional_logic;
		if (!Array.isArray(logic)) {
			return;
		}
		let changed = false;
		const next = logic
			.map((branch) => {
				if (!Array.isArray(branch)) {
					return branch;
				}
				const kept = branch.filter((rule) => !(isObject(rule) && typeof rule.field === 'string' && keys.has(rule.field)));
				changed ||= kept.length !== branch.length;
				return kept;
			})
			.filter((branch) => !Array.isArray(branch) || branch.length > 0);
		if (changed) {
			field.conditional_logic = next.length > 0 ? next : 0;
			notices.push({ key: String(field.key ?? ''), label: String(field.label || field.name || field.key || '') });
		}
	});
	return notices;
}

function rekey(field: RawField, map: Map<string, string>): void {
	if (typeof field.key === 'string') {
		const key = newFieldKey();
		map.set(field.key, key);
		field.key = key;
	}
	subFieldsOf(field).forEach((sub) => rekey(sub, map));
	if (Array.isArray(field.layouts)) {
		field.layouts.forEach((layout) => {
			if (isObject(layout)) {
				layout.key = newLayoutKey();
			}
		});
	} else if (isObject(field.layouts)) {
		// An object keyed by layout key: keep the shape, with new keys.
		const next: Record<string, unknown> = {};
		for (const layout of Object.values(field.layouts)) {
			if (isObject(layout)) {
				layout.key = newLayoutKey();
				next[layout.key as string] = layout;
			}
		}
		field.layouts = next;
	}
	layoutList(field).forEach((layout) => subFieldsOf(layout).forEach((sub) => rekey(sub, map)));
}

/** Points rules inside a duplicated subtree at the copies. */
function remapRules(field: RawField, map: Map<string, string>): void {
	walkFields([field], (f) => {
		if (!Array.isArray(f.conditional_logic)) {
			return;
		}
		for (const branch of f.conditional_logic) {
			for (const rule of Array.isArray(branch) ? branch : []) {
				if (isObject(rule) && typeof rule.field === 'string' && map.has(rule.field)) {
					rule.field = map.get(rule.field);
				}
			}
		}
	});
}

function startsWith(path: FieldPath, prefix: FieldPath): boolean {
	return prefix.length <= path.length && prefix.every((s, i) => path[i] === s);
}

/** The reducer, with the notices a removal produces. Returns `group` itself when the action does nothing. */
export function reduceWithNotices(group: RawGroup, action: BuilderAction): { group: RawGroup; notices: Notice[] } {
	const same = { group, notices: [] as Notice[] };
	const g = clone(group);

	switch (action.type) {
		case 'add': {
			const list = childList(g, action.parent, action.layout);
			if (!list) {
				return same;
			}
			const at = action.at === undefined ? list.length : Math.max(0, Math.min(action.at, list.length));
			list.splice(at, 0, newField(action.fieldType, action.label ?? __('New field', 'proto-blocks'), list));
			return { group: g, notices: [] };
		}
		case 'move': {
			const { from, to } = action;
			// A field cannot move into itself or below itself.
			if (from.length === 0 || to.length === 0 || startsWith(to.slice(0, -1), from)) {
				return same;
			}
			const source = locate(g, from);
			const target = locate(g, to);
			if (!source || !target || !isObject(source.list[source.index])) {
				return same;
			}
			const [field] = source.list.splice(source.index, 1);
			target.list.splice(Math.max(0, Math.min(target.index, target.list.length)), 0, field);
			return JSON.stringify(g) === JSON.stringify(group) ? same : { group: g, notices: [] };
		}
		case 'update': {
			const at = locate(g, action.path);
			if (!at || !isObject(at.list[at.index])) {
				return same;
			}
			at.list[at.index] = merge(at.list[at.index], action.patch);
			return { group: g, notices: [] };
		}
		case 'remove': {
			const at = locate(g, action.path);
			const field = at?.list[at.index];
			if (!at || !isObject(field)) {
				return same;
			}
			at.list.splice(at.index, 1);
			return { group: g, notices: dropRules(g, keysIn(field)) };
		}
		case 'duplicate': {
			const at = locate(g, action.path);
			const field = at?.list[at.index];
			if (!at || !isObject(field)) {
				return same;
			}
			const copy = clone(field);
			const map = new Map<string, string>();
			rekey(copy, map);
			remapRules(copy, map);
			if (typeof copy.name === 'string' && copy.name !== '') {
				copy.name = uniqueName(copy.name.slice(0, 59) + '_copy', at.list);
			}
			at.list.splice(at.index + 1, 0, copy);
			return { group: g, notices: [] };
		}
		case 'addLayout':
		case 'removeLayout':
		case 'updateLayout': {
			const at = locate(g, action.path);
			const field = at?.list[at.index];
			if (!at || !isObject(field) || !isFlexible(field)) {
				return same;
			}
			const layouts = layoutList(field);
			if (action.type === 'addLayout') {
				const layout = newLayout(layouts);
				field.layouts = isObject(field.layouts) ? { ...field.layouts, [String(layout.key)]: layout } : [...layouts, layout];
				return { group: g, notices: [] };
			}
			const layout = layouts[action.layout ?? -1];
			if (!layout) {
				return same;
			}
			const entries: [string | number, unknown][] = Array.isArray(field.layouts)
				? field.layouts.map((l, i) => [i, l])
				: Object.entries(isObject(field.layouts) ? field.layouts : {});
			const slot = entries.find(([, v]) => v === layout)?.[0];
			if (action.type === 'updateLayout') {
				(field.layouts as Record<string | number, unknown>)[slot as string | number] = merge(layout, action.patch ?? {});
				return { group: g, notices: [] };
			}
			field.layouts = Array.isArray(field.layouts)
				? field.layouts.filter((l) => l !== layout)
				: Object.fromEntries(entries.filter(([, v]) => v !== layout));
			const removed = new Set<string>();
			subFieldsOf(layout).forEach((sub) => keysIn(sub, removed));
			return { group: g, notices: dropRules(g, removed) };
		}
		case 'group':
			return { group: merge(g, action.patch), notices: [] };
		default:
			return same;
	}
}

export function reduce(group: RawGroup, action: BuilderAction): RawGroup {
	return reduceWithNotices(group, action).group;
}

// --- Validation (GroupValidator's rules for what the builder edits) --------

export interface BuilderError {
	path: FieldPath;
	/** The setting the error is about, e.g. 'name'. */
	setting: string;
	message: string;
}

export function invalidNameMessage(name: string): string {
	return sprintf(
		/* translators: %s: field name */
		__('Field name "%s" must start with a letter and contain only letters, digits, "_" or "-" (64 characters at most).', 'proto-blocks'),
		name
	);
}

export function duplicateNameMessage(name: string): string {
	return sprintf(
		/* translators: %s: field name */
		__('The name "%s" is used by more than one field at this level.', 'proto-blocks'),
		name
	);
}

/** The name error for `name` on a field of `type` among `siblings` (the other fields at its level), or null. */
export function nameError(name: unknown, type: unknown, siblings: RawField[]): string | null {
	if (LAYOUT_ONLY_TYPES.includes(canonicalType(type) ?? '') && (name === undefined || name === '')) {
		return null;
	}
	if (typeof name !== 'string' || !NAME_PATTERN.test(name)) {
		return invalidNameMessage(typeof name === 'string' ? name : '');
	}
	return siblings.some((s) => s.name === name) ? duplicateNameMessage(name) : null;
}

/** Every problem the server would refuse the save for, in tree order. */
export function validateGroup(group: RawGroup): BuilderError[] {
	const errors: BuilderError[] = [];
	const check = (list: unknown, prefix: FieldPath) => {
		const fields = (Array.isArray(list) ? list : []).filter(isObject);
		fields.forEach((field, i) => {
			const path = [...prefix, i];
			const name = nameError(
				field.name,
				field.type,
				fields.filter((f) => f !== field)
			);
			if (name) {
				errors.push({ path, setting: 'name', message: name });
			}
			if (hasSubFields(field)) {
				if (!Array.isArray(field.sub_fields) || field.sub_fields.length === 0) {
					errors.push({
						path,
						setting: 'sub_fields',
						/* translators: %s: field type */
						message: sprintf(__('A %s field needs at least one sub-field.', 'proto-blocks'), String(canonicalType(field.type))),
					});
				}
				check(field.sub_fields, path);
			}
			if (isFlexible(field)) {
				const layouts = layoutList(field);
				if (layouts.length === 0) {
					errors.push({ path, setting: 'layouts', message: __('A flexible_content field needs at least one layout.', 'proto-blocks') });
				}
				const seen = new Set<string>();
				layouts.forEach((layout, l) => {
					if (typeof layout.name !== 'string' || !NAME_PATTERN.test(layout.name)) {
						errors.push({ path, setting: 'layouts', message: invalidNameMessage(String(layout.name ?? '')) });
					} else if (seen.has(layout.name)) {
						errors.push({
							path,
							setting: 'layouts',
							/* translators: %s: layout name */
							message: sprintf(__('The layout name "%s" is used more than once.', 'proto-blocks'), layout.name),
						});
					} else {
						seen.add(layout.name);
					}
					check(layout.sub_fields, [...path, 'layouts', l]);
				});
			}
		});
	};
	check(group.fields, []);
	return errors;
}

/**
 * A server error path (`fields[0].sub_fields[1].name`, prefixed by the
 * group's place in the file) as a field path and setting; null when it is
 * not about a field.
 */
export function parseErrorPath(path: string, pointer: (string | number)[]): { path: FieldPath; setting: string } | null {
	const tokens: (string | number)[] = [];
	for (const m of path.matchAll(/\[(\d+)\]|\.?([A-Za-z0-9_-]+)/g)) {
		tokens.push(m[1] !== undefined ? Number(m[1]) : m[2]);
	}
	if (pointer.length > 0 && pointer.every((p, i) => tokens[i] === p)) {
		tokens.splice(0, pointer.length);
	}
	if (tokens[0] !== 'fields' || typeof tokens[1] !== 'number') {
		return null;
	}
	const out: FieldPath = [tokens[1]];
	let i = 2;
	while (i < tokens.length) {
		if (tokens[i] === 'sub_fields' && typeof tokens[i + 1] === 'number') {
			out.push(tokens[i + 1] as number);
			i += 2;
		} else if (tokens[i] === 'layouts' && typeof tokens[i + 1] === 'number' && tokens[i + 2] === 'sub_fields' && typeof tokens[i + 3] === 'number') {
			out.push('layouts', tokens[i + 1] as number, tokens[i + 3] as number);
			i += 4;
		} else {
			break;
		}
	}
	return { path: out, setting: typeof tokens[i] === 'string' ? (tokens[i] as string) : 'name' };
}

// --- Back to the file ------------------------------------------------------

const FORMATTING = { formattingOptions: { insertSpaces: true, tabSize: 2 } };

/** The value at `pointer` in a JSONC text; undefined when it does not parse or is missing. */
export function valueAt(text: string, pointer: (string | number)[]): unknown {
	const tree = parseTree(text, [], { allowTrailingComma: true, disallowComments: false });
	const node = tree ? (pointer.length === 0 ? tree : findNodeAtLocation(tree, pointer as JSONPath)) : undefined;
	return node ? getNodeValue(node) : undefined;
}

function identity(v: unknown): string | null {
	return isObject(v) && typeof v.key === 'string' && v.key !== '' ? v.key : null;
}

function diffInto(text: string, path: (string | number)[], before: unknown, after: unknown): string {
	if (JSON.stringify(before) === JSON.stringify(after)) {
		return text;
	}
	if (isObject(before) && isObject(after)) {
		let out = text;
		for (const key of Object.keys(before)) {
			if (!(key in after)) {
				out = setAtPath(out, [...path, key], undefined);
			}
		}
		for (const key of Object.keys(after)) {
			out = key in before ? diffInto(out, [...path, key], before[key], after[key]) : setAtPath(out, [...path, key], after[key]);
		}
		return out;
	}
	if (Array.isArray(before) && Array.isArray(after)) {
		const beforeIds = before.map(identity);
		const afterIds = after.map(identity);
		const keyed =
			[...beforeIds, ...afterIds].every((id) => id !== null) && new Set(beforeIds).size === before.length && new Set(afterIds).size === after.length;
		let out = text;
		const current = [...before];
		if (keyed) {
			// Removals, then insertions, element by element; what is left lines up index for index.
			for (let i = current.length - 1; i >= 0; i--) {
				if (!afterIds.includes(identity(current[i]))) {
					out = setAtPath(out, [...path, i], undefined);
					current.splice(i, 1);
				}
			}
			after.forEach((item, j) => {
				if (!current.some((c) => identity(c) === identity(item))) {
					const at = Math.min(j, current.length);
					// setAtPath only appends (-1); an insertion at `at` needs jsonc-parser's own flag.
					out =
						at === current.length
							? setAtPath(out, [...path, -1], item)
							: applyEdits(out, modify(out, [...path, at] as JSONPath, item, { ...FORMATTING, isArrayInsertion: true }));
					current.splice(at, 0, item);
				}
			});
		}
		if (current.length !== after.length) {
			return setAtPath(out, path, after);
		}
		after.forEach((item, i) => {
			out = diffInto(out, [...path, i], current[i], item);
		});
		return out;
	}
	return setAtPath(text, path, after);
}

/**
 * `original` with the group at `pointer` ([] for a one-group file, [i] in a
 * list file, ['fields', i] in a type or taxonomy file) rewritten to `group`.
 * Only what differs is edited, through setAtPath, so comments survive
 * wherever the content did not change.
 */
export function toFileText(original: string, group: RawGroup, pointer: (string | number)[]): string {
	if (original.trim() === '' && pointer.length === 0) {
		return JSON.stringify(group, null, 2) + '\n';
	}
	const before = valueAt(original, pointer);
	return before === undefined ? setAtPath(original, pointer, group) : diffInto(original, pointer, before, group);
}

/** Where the group sits in its file, from the detail payload's `file`. */
export function groupPointer(file: { kind: string; index?: number | null }): (string | number)[] {
	if (file.kind === 'field-group') {
		return typeof file.index === 'number' ? [file.index] : [];
	}
	return ['fields', typeof file.index === 'number' ? file.index : 0];
}
