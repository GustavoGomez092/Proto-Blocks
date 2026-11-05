/**
 * Editing script.jsonc with jsonc-parser so the developer's comments, key
 * order and formatting survive a save from the screen.
 */
import { applyEdits, modify } from 'jsonc-parser';
import { LOCATIONS, SNIPPET_FILES, type Location, type Where } from './types';

const FORMATTING = { formattingOptions: { insertSpaces: true, tabSize: 2 } };

export const DEFAULT_PRIORITY = 10;

/** What the editor form holds. */
export interface ScriptForm {
	title: string;
	enabled: boolean;
	priority: number;
	where: Where;
	snippets: Record<Location, string>;
}

const WHERE_KEYS: (keyof Where)[] = ['include', 'exclude', 'loggedIn', 'roles', 'excludeRoles'];

function isObject(value: unknown): value is Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function strings(value: unknown): string[] {
	return Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : [];
}

function same(a: unknown, b: unknown): boolean {
	return JSON.stringify(a) === JSON.stringify(b);
}

/** The fully defaulted `where` block (as Targets::normalize()). */
export function normalizeWhere(raw: unknown): Where {
	const where = isObject(raw) ? raw : {};
	return {
		include: Array.isArray(where.include) ? strings(where.include) : ['all'],
		exclude: strings(where.exclude),
		loggedIn: typeof where.loggedIn === 'boolean' ? where.loggedIn : null,
		roles: strings(where.roles),
		excludeRoles: strings(where.excludeRoles),
	};
}

/** The form for a decoded script.jsonc and its snippets. */
export function formFromData(data: Record<string, unknown>, snippets: Record<Location, string>): ScriptForm {
	return {
		title: typeof data.title === 'string' ? data.title : '',
		enabled: data.enabled !== false,
		priority: typeof data.priority === 'number' && Number.isInteger(data.priority) ? data.priority : DEFAULT_PRIORITY,
		where: normalizeWhere(data.where),
		snippets: { head: snippets.head ?? '', bodyOpen: snippets.bodyOpen ?? '', bodyClose: snippets.bodyClose ?? '' },
	};
}

/**
 * Rewrites `text` (whose decoded content is `before`) so it holds `form`,
 * editing only what changed: top-level keys one by one, `where` key by key.
 * A location with a snippet becomes `{ "file": "<its file>" }`; a location
 * without one loses its key. Keys the form does not know stay untouched.
 */
export function scriptText(text: string, before: Record<string, unknown>, form: ScriptForm): string {
	const changes: { path: string[]; value: unknown }[] = [];
	const set = (path: string[], was: unknown, now: unknown) => {
		if (!same(was, now)) {
			changes.push({ path, value: now });
		}
	};

	set(['title'], before.title, form.title);
	set(['enabled'], before.enabled ?? true, form.enabled);
	set(['priority'], before.priority ?? DEFAULT_PRIORITY, form.priority);

	for (const location of LOCATIONS) {
		const now = form.snippets[location] !== '' ? { file: SNIPPET_FILES[location] } : undefined;
		const was = before[location];
		if (now === undefined) {
			if (was !== undefined) {
				changes.push({ path: [location], value: undefined });
			}
		} else {
			set([location], was, now);
		}
	}

	const was = normalizeWhere(before.where);
	for (const key of WHERE_KEYS) {
		if (!same(was[key], form.where[key])) {
			changes.push({ path: ['where', key], value: form.where[key] });
		}
	}

	return changes.reduce((current, { path, value }) => applyEdits(current, modify(current, path, value, FORMATTING)), text);
}

/** `text` with only `enabled` changed (the list's switch). */
export function setEnabledText(text: string, enabled: boolean): string {
	return applyEdits(text, modify(text, ['enabled'], enabled, FORMATTING));
}

/** The text of a new script.jsonc (mirrors ScriptTemplate.php). */
export function newScriptText(title: string): string {
	return [
		'// Proto-Blocks script: snippets printed on every front-end page in scope.',
		'// The folder name is the script slug. Snippets live next to this file in',
		'// head.html, body-open.html and body-close.html (or inline as strings).',
		'{',
		`  "title": ${JSON.stringify(title.replace(/[<>]/g, ''))},`,
		'  "enabled": true,',
		'  // Within each location; lower runs first.',
		`  "priority": ${DEFAULT_PRIORITY},`,
		'  "where": {',
		'    "include": ["all"],',
		'    "exclude": []',
		'  }',
		'}',
		'',
	].join('\n');
}
