/**
 * Shapes of the scripts REST routes (includes/API/Admin/ScriptsRoutes.php).
 * Keep in step with that file.
 */
import type { FileResult, Issue } from '@/admin/content-model/types';

export type Location = 'head' | 'bodyOpen' | 'bodyClose';

export const LOCATIONS: Location[] = ['head', 'bodyOpen', 'bodyClose'];

/** The only file each location may be stored in, next to script.jsonc. */
export const SNIPPET_FILES: Record<Location, string> = {
	head: 'head.html',
	bodyOpen: 'body-open.html',
	bodyClose: 'body-close.html',
};

export interface Where {
	include: string[];
	exclude: string[];
	loggedIn: boolean | null;
	roles: string[];
	excludeRoles: string[];
}

export type ScriptsWriteReason = 'file_mods' | 'multisite' | 'unfiltered_html' | null;

export interface ScriptRow {
	slug: string;
	title: string;
	enabled: boolean;
	priority: number;
	priorities: Record<Location, number>;
	/** Locations that print something. */
	locations: Location[];
	files: Record<Location, string | null>;
	where: Where;
	whereSummary: string;
	origin: 'file' | 'php';
	originLabel: string;
	source: string;
	double: { file: string; php: string } | null;
	editable: boolean;
	readOnlyReason: string | null;
	issues: Issue[];
}

export interface ScriptsIndex {
	moduleEnabled: boolean;
	createTarget: { path: string; writable: boolean; reason: string | null };
	canWrite: boolean;
	writeReason: ScriptsWriteReason;
	roles: { slug: string; name: string }[];
	items: ScriptRow[];
	issues: Issue[];
}

/** GET admin/scripts/<slug>. */
export interface ScriptDetail extends FileResult {
	snippets: Record<Location, string>;
	item: ScriptRow | null;
	canWrite: boolean;
	writeReason: ScriptsWriteReason;
}

/** POST admin/scripts/<slug> body. */
export interface SavePayload {
	text: string;
	hash: string | null;
	snippets?: Partial<Record<Location, string>>;
}

export interface SaveResponse {
	hash: string;
	path: string;
	created: boolean;
	warnings: Issue[];
}
