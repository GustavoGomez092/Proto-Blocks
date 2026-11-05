import apiFetch from '@wordpress/api-fetch';
import type { ScriptDetail, ScriptRow, ScriptsIndex } from '@/admin/scripts/types';

export const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';

export function row(overrides: Partial<ScriptRow> = {}): ScriptRow {
	return {
		slug: 'gtm',
		title: 'Google Tag Manager',
		enabled: true,
		priority: 10,
		priorities: { head: 10, bodyOpen: 10, bodyClose: 10 },
		locations: ['head', 'bodyOpen'],
		files: { head: 'head.html', bodyOpen: 'body-open.html', bodyClose: null },
		where: { include: ['all'], exclude: ['page:privacy-policy'], loggedIn: null, roles: [], excludeRoles: ['administrator'] },
		whereSummary: 'All pages; except page:privacy-policy; not roles: administrator',
		origin: 'file',
		originLabel: 'Proto-Blocks file',
		source: 'wp-content/themes/demo/proto-scripts/gtm/script.jsonc',
		double: null,
		editable: true,
		readOnlyReason: null,
		issues: [],
		...overrides,
	};
}

export function index(overrides: Partial<ScriptsIndex> = {}): ScriptsIndex {
	return {
		moduleEnabled: true,
		createTarget: { path: 'wp-content/themes/demo/proto-scripts', writable: true, reason: null },
		canWrite: true,
		writeReason: null,
		roles: [
			{ slug: 'administrator', name: 'Administrator' },
			{ slug: 'editor', name: 'Editor' },
		],
		items: [
			row(),
			row({
				slug: 'verify',
				title: 'Search Console',
				locations: ['head'],
				files: { head: null, bodyOpen: null, bodyClose: null },
				where: { include: ['front_page'], exclude: [], loggedIn: null, roles: [], excludeRoles: [] },
				whereSummary: 'front_page',
				origin: 'php',
				originLabel: 'Proto-Blocks PHP',
				source: 'wp-content/themes/demo/functions.php:12',
				editable: false,
				readOnlyReason: 'php',
			}),
		],
		issues: [],
		...overrides,
	};
}

export const GTM_TEXT = [
	'// GTM container for the marketing site',
	'{',
	'  "title": "Google Tag Manager",',
	'  "enabled": true,',
	'  "priority": 10, // keep first',
	'  "head": { "file": "head.html" },',
	'  "bodyOpen": { "file": "body-open.html" },',
	'  "where": {',
	'    "include": ["all"],',
	'    // legal pages stay clean',
	'    "exclude": ["page:privacy-policy"],',
	'    "excludeRoles": ["administrator"]',
	'  }',
	'}',
	'',
].join('\n');

export function detail(overrides: Partial<ScriptDetail> = {}): ScriptDetail {
	return {
		exists: true,
		path: 'wp-content/themes/demo/proto-scripts/gtm/script.jsonc',
		text: GTM_TEXT,
		hash: 'h1',
		data: {
			title: 'Google Tag Manager',
			enabled: true,
			priority: 10,
			head: { file: 'head.html' },
			bodyOpen: { file: 'body-open.html' },
			where: { include: ['all'], exclude: ['page:privacy-policy'], excludeRoles: ['administrator'] },
		},
		writable: true,
		reason: null,
		snippets: { head: '<script>gtm()</script>', bodyOpen: '<noscript>gtm</noscript>', bodyClose: '' },
		item: row(),
		canWrite: true,
		writeReason: null,
		...overrides,
	};
}

export function calls(): { url: string; method: string; data?: Record<string, unknown> }[] {
	return (apiFetch as unknown as jest.Mock).mock.calls.map(([options]) => ({
		url: String(options.url).replace(ROOT, ''),
		method: options.method ?? 'GET',
		data: options.data,
	}));
}

export const posts = () => calls().filter((c) => c.method === 'POST');
