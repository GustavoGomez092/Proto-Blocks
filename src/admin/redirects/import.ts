/**
 * Client side of import (spec §9): format detection from the file name and
 * the duplicate-mode merge into a group file's text (the client writes the
 * file, so the merge happens here, over the text, keeping its comments).
 */
import { addRule, newGroupText, newRuleId, readRules, setGroupField, updateRule } from './edits';
import { RULE_ID } from './schema';
import type { DuplicateMode, FileGroup, FileRule, ImportFormat } from './types';

export function detectFormat(filename: string): ImportFormat | null {
	const name = filename.toLowerCase().replace(/^.*[\\/]/, '');
	if (name === '_redirects') {
		return 'redirects';
	}
	if (name.endsWith('.csv')) {
		return 'csv';
	}
	if (name.endsWith('.json')) {
		return 'json';
	}
	if (name === '.htaccess' || name.endsWith('.htaccess') || name.endsWith('.txt') || name.endsWith('.conf')) {
		return 'apache';
	}
	return null;
}

/** Same source in the sense of the duplicate modes: from, regex flag and condition. */
export function sameSource(a: FileRule, b: FileRule): boolean {
	return (a.from ?? '') === (b.from ?? '') && Boolean(a.regex) === Boolean(b.regex) && JSON.stringify(a.when ?? null) === JSON.stringify(b.when ?? null);
}

export interface MergeResult {
	text: string;
	added: number;
	replaced: number;
	skipped: number;
}

/**
 * Merges `incoming` into a group file: `skip` keeps an existing rule with
 * the same source, `replace` overwrites it (keeping its id and, when the
 * incoming rule has none, its title), `add` always appends. New rules keep a
 * valid unused id or get a fresh one.
 */
export function mergeRules(text: string, incoming: FileRule[], mode: DuplicateMode, taken: string[] = []): MergeResult {
	const ids = [...taken];
	const existing = readRules(text);
	let out = text;
	let added = 0;
	let replaced = 0;
	let skipped = 0;
	for (const rule of incoming) {
		const match = mode === 'add' ? -1 : existing.findIndex((current) => sameSource(current, rule));
		if (match !== -1 && mode === 'skip') {
			skipped++;
			continue;
		}
		if (match !== -1) {
			const current = existing[match];
			const id = current.id ?? newRuleId(ids);
			ids.push(id);
			const next: FileRule = { ...rule, id, ...(rule.title === undefined && current.title !== undefined ? { title: current.title } : {}) };
			out = updateRule(out, { id, index: match }, next);
			existing[match] = next;
			replaced++;
			continue;
		}
		const id = rule.id !== undefined && RULE_ID.test(rule.id) && !ids.includes(rule.id) && !existing.some((current) => current.id === rule.id) ? rule.id : newRuleId([...ids, ...existing.map((current) => current.id ?? '')]);
		ids.push(id);
		const next = { ...rule, id };
		out = addRule(out, next);
		existing.push(next);
		added++;
	}
	return { text: out, added, replaced, skipped };
}

/** A new group file from an imported group (title, enabled, position, defaults; rules merged separately). */
export function groupTextFrom(slug: string, group: FileGroup): string {
	let text = newGroupText(slug, group.title ?? slug, typeof group.position === 'number' ? group.position : 10);
	if (group.enabled === false) {
		text = setGroupField(text, 'enabled', false);
	}
	if (group.defaults && Object.keys(group.defaults).length > 0) {
		text = setGroupField(text, 'defaults', group.defaults);
	}
	return text;
}
