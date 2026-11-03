/**
 * Turns the settings page form into the POST options/<slug> body, reading the
 * real input names Phase 2's field inputs render (src/fields/box/names.ts):
 *
 *   proto_fields[<group>][agenda][0][title]  → fields.<group>.agenda[0].title
 *   proto_fields[<group>][tags][]            → appended to fields.<group>.tags
 *   proto_fields_present[<group>][]          → present.<group>
 *
 * A repeated scalar name keeps the last value, as PHP does, so a switch's
 * `1` wins over its hidden `0`. Numeric segments build arrays.
 */

type Tree = Record<string, unknown> | unknown[];

export interface OptionsSubmission {
	fields: Record<string, Record<string, unknown>>;
	present: Record<string, string[]>;
}

const UNSAFE = new Set(['__proto__', 'prototype', 'constructor']);

/** 'proto_fields[g][a][0][b]' → ['proto_fields', 'g', 'a', '0', 'b']; null when malformed. */
function segments(name: string): string[] | null {
	const match = /^([^[\]]+)((?:\[[^[\]]*\])*)$/.exec(name);
	if (!match) {
		return null;
	}
	const rest = match[2].match(/\[[^[\]]*\]/g) ?? [];
	return [match[1], ...rest.map((s) => s.slice(1, -1))];
}

const isIndex = (s: string) => /^\d+$/.test(s);

/** The container under `key`; a scalar there (e.g. an empty-list marker before `x[]`) is replaced. */
function child(container: Tree, key: string, next: string): Tree {
	const existing = (container as Record<string, unknown>)[key];
	if (existing !== null && typeof existing === 'object') {
		return existing as Tree;
	}
	const created: Tree = next === '' || isIndex(next) ? [] : {};
	(container as Record<string, unknown>)[key] = created;
	return created;
}

function assign(root: Record<string, unknown>, path: string[], value: string): void {
	if (path.some((p) => UNSAFE.has(p))) {
		return;
	}
	let node: Tree = root;
	for (let i = 0; i < path.length - 1; i++) {
		node = child(node, path[i], path[i + 1]);
	}
	const last = path[path.length - 1];
	if (last === '') {
		if (Array.isArray(node)) {
			node.push(value);
		}
		return;
	}
	(node as Record<string, unknown>)[last] = value;
}

/** Empty slots from sparse indices are dropped, keeping row order. */
function compact(value: unknown): unknown {
	if (Array.isArray(value)) {
		return value.filter((v) => v !== undefined).map(compact);
	}
	if (value !== null && typeof value === 'object') {
		const out: Record<string, unknown> = {};
		for (const [k, v] of Object.entries(value)) {
			out[k] = compact(v);
		}
		return out;
	}
	return value;
}

export function submissionFromForm(form: HTMLFormElement): OptionsSubmission {
	const fields: Record<string, unknown> = {};
	const present: Record<string, string[]> = {};

	for (const [name, raw] of new FormData(form).entries()) {
		if (typeof raw !== 'string') {
			continue;
		}
		const parts = segments(name);
		if (!parts || parts.length < 3 || parts[1] === '' || UNSAFE.has(parts[1])) {
			continue;
		}
		const [base, group, ...path] = parts;
		if (base === 'proto_fields_present') {
			if (path.length === 1 && path[0] === '') {
				(present[group] ??= []).push(raw);
			}
			continue;
		}
		if (base !== 'proto_fields' || path[0] === '') {
			continue;
		}
		const groupTree = (fields[group] ??= {}) as Record<string, unknown>;
		assign(groupTree, path, raw);
	}

	return { fields: compact(fields) as Record<string, Record<string, unknown>>, present };
}
