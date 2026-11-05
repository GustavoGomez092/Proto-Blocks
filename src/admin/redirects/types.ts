/**
 * Shapes of the redirects module: the file keys (spec §1, §2) and the REST
 * responses of includes/API/Admin/RedirectsRoutes.php (spec §11). Keep in
 * step with docs/superpowers/plans/2026-11-05-redirects-interfaces.md.
 */
import type { Issue } from '@/admin/content-model/types';

export type RuleAction = 'url' | 'error' | 'pass' | 'nothing';
export type QueryMode = 'exact' | 'exactOrder' | 'ignore' | 'pass';
export type ConditionType = 'login' | 'role' | 'referrer' | 'agent' | 'header' | 'cookie' | 'ip' | 'language' | 'server' | 'filter' | '404';

export interface Condition {
	type: ConditionType;
	/** A list for `ip` and `language`, a string for the others; absent for `login` and `404`. */
	value?: string | string[];
	/** `header` and `cookie` only. */
	name?: string;
	/** referrer, agent, header and cookie only. */
	regex?: boolean;
}

export interface GroupDefaults {
	ignoreCase?: boolean;
	ignoreTrailingSlash?: boolean;
	query?: QueryMode;
}

/** A rule as written in a group file: every key optional except what §1 requires. */
export interface FileRule {
	id?: string;
	title?: string;
	enabled?: boolean;
	from?: string;
	regex?: boolean;
	to?: string;
	else?: string;
	code?: number;
	action?: RuleAction;
	when?: Condition;
	query?: QueryMode;
	ignoreCase?: boolean;
	ignoreTrailingSlash?: boolean;
	log?: boolean;
	headers?: Record<string, string>;
}

/** A group file's top level. */
export interface FileGroup {
	title?: string;
	enabled?: boolean;
	position?: number;
	defaults?: GroupDefaults;
	rules?: FileRule[];
}

/** A normalised rule as `GET admin/redirects` lists it. */
export interface Rule {
	id: string;
	title: string;
	enabled: boolean;
	from: string | null;
	regex: boolean;
	to: string | null;
	else: string | null;
	code: number | null;
	action: RuleAction;
	when: Condition | null;
	query: QueryMode;
	ignoreCase: boolean;
	ignoreTrailingSlash: boolean;
	log: boolean;
	headers: Record<string, string>;
	group: string;
	origin: 'file' | 'php';
	source: string;
	index: number;
	hits: number;
	lastHit: string | null;
	issues: Issue[];
}

export interface Group {
	slug: string;
	title: string;
	enabled: boolean;
	position: number;
	origin: 'file' | 'php';
	double: { file: string; php: string } | null;
	writable: boolean;
	/** sha256 of the file; null for the PHP group. */
	hash: string | null;
	defaults?: GroupDefaults;
	source?: string;
	rules: Rule[];
}

export type LogRetention = number;
export type IpPolicy = 'none' | 'masked' | 'full';
export type IpHeader = 'CF-Connecting-IP' | 'X-Forwarded-For' | 'X-Real-IP' | 'Client-IP' | 'True-Client-IP';

export interface Settings {
	defaults: Required<GroupDefaults>;
	log: {
		redirects: LogRetention;
		notFound: LogRetention;
		ip: IpPolicy;
		headers: boolean;
		external: boolean;
		trustedProxies: string[];
		ipHeaders: IpHeader[];
	};
	trackHits: boolean;
	cache: -1 | 0 | 1 | 24 | 168;
	monitor: {
		postTypes: string[];
		group: string;
		trash: boolean;
		associated: string;
	};
	headers: {
		site: Record<string, string>;
		redirect: Record<string, string>;
	};
	canonical: {
		https: boolean;
		www: '' | 'www' | 'nowww';
		aliases: string[];
		relocate: string;
	};
	coexist: boolean;
}

/** GET admin/redirects. */
export interface RedirectsIndex {
	moduleEnabled: boolean;
	/** Redirection is active and `coexist` is false. */
	paused: boolean;
	createTarget: { path: string; writable: boolean; reason: string | null };
	groups: Group[];
	settings: Settings;
	settingsFile: { exists: boolean; hash: string | null; writable: boolean };
	issues: Issue[];
	db: { tables: boolean; orphans: number };
}

export interface TraceStep {
	group: string;
	ruleId: string;
	result: 'matched' | 'skipped';
	reason: string;
}

/** GET admin/redirects/test?url=. */
export interface TestResult {
	matched: boolean;
	group: string | null;
	ruleId: string | null;
	action: RuleAction | null;
	code: number | null;
	target: string | null;
	trace: TraceStep[];
	/** Optional server note, e.g. that login/role used the admin's session. */
	note?: string;
}

export interface LogRow {
	id: number;
	created: string;
	url: string;
	target: string | null;
	code: number;
	group: string | null;
	ruleId: string | null;
	redirectBy: string;
	method: string;
	ip: string | null;
	agent: string | null;
	referrer: string | null;
}

export interface NotFoundRow {
	id: number;
	created: string;
	url: string;
	method: string;
	ip: string | null;
	agent: string | null;
	referrer: string | null;
}

export interface NotFoundGroupRow {
	url: string;
	count: number;
	lastSeen: string;
	lastReferrer: string | null;
}

export interface Paged<T> {
	rows: T[];
	total: number;
}

export interface ParsedRow {
	rule: FileRule;
	status: 'new' | 'duplicate' | 'invalid';
	reason: string | null;
	line?: number;
}

/** POST admin/redirects/import/parse. */
export interface ParseResult {
	rules: ParsedRow[];
	groups?: Record<string, FileGroup>;
}

export interface PluginImporter {
	id: string;
	label: string;
	cleanupHint: string | null;
	/** Plugin basename, when the source is a plugin that can be deactivated. */
	plugin?: string | null;
}

export type DuplicateMode = 'skip' | 'replace' | 'add';
export type ExportFormat = 'csv' | 'json' | 'apache' | 'nginx' | 'redirects';
export type ImportFormat = 'csv' | 'json' | 'apache' | 'redirects';
