/**
 * Shared data and routing for the Redirects screen tests. Import AFTER
 * '../helpers' (which mocks @wordpress/api-fetch).
 */
import { readFileSync } from 'fs';
import { join } from 'path';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { AdminApp } from '@/admin/app';
import type { FileResult } from '@/admin/content-model/types';
import type { Group, RedirectsIndex, Rule, Settings } from '@/admin/redirects/types';
import { mockBootstrap } from '../helpers';

export const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';
export const legacyText = readFileSync(join(__dirname, 'fixtures/legacy-site.jsonc'), 'utf8');
export const promoText = '{\n  "title": "Promo",\n  "rules": [\n    // Spring sale.\n    { "id": "r_0000000p", "from": "/spring", "to": "/sale" }\n  ]\n}\n';

export const SETTINGS: Settings = {
	defaults: { ignoreCase: true, ignoreTrailingSlash: true, query: 'exact' },
	log: { redirects: 7, notFound: 7, ip: 'none', headers: false, external: false, trustedProxies: [], ipHeaders: [] },
	trackHits: true,
	cache: 1,
	monitor: { postTypes: [], group: 'modified-posts', trash: false, associated: '' },
	headers: { site: {}, redirect: {} },
	canonical: { https: false, www: '', aliases: [], relocate: '' },
	coexist: false,
};

export function rule(overrides: Partial<Rule> = {}): Rule {
	return {
		id: 'r_00000001',
		title: '',
		enabled: true,
		from: '/about-us',
		regex: false,
		to: '/about',
		else: null,
		code: 301,
		action: 'url',
		when: null,
		query: 'exact',
		ignoreCase: true,
		ignoreTrailingSlash: true,
		log: true,
		headers: {},
		group: 'legacy-site',
		origin: 'file',
		source: 'wp-content/themes/demo/proto-redirects/legacy-site/redirects.jsonc',
		index: 0,
		hits: 0,
		lastHit: null,
		issues: [],
		...overrides,
	};
}

export function legacyGroup(overrides: Partial<Group> = {}): Group {
	return {
		slug: 'legacy-site',
		title: 'Legacy site',
		enabled: true,
		position: 10,
		origin: 'file',
		double: null,
		writable: true,
		hash: 'h-legacy',
		rules: [
			rule({ hits: 12, lastHit: '2026-11-04 10:00:00' }),
			rule({ id: 'r_00000002', title: 'Old blog', from: '^/blog/(\\d+)/(.*)$', regex: true, to: '/news/$2', index: 1 }),
			rule({ id: 'r_00000003', from: '/promo', to: '/sale', code: 302, query: 'pass', index: 2 }),
			rule({ id: 'r_00000004', from: '/members', to: '/dashboard', else: '/login', when: { type: 'login' }, index: 3 }),
			rule({ id: 'r_1a2b3c4d', from: '/gone-forever', to: null, action: 'error', code: 410, index: 4 }),
		],
		...overrides,
	};
}

export function promoGroup(overrides: Partial<Group> = {}): Group {
	return {
		slug: 'promo',
		title: 'Promo',
		enabled: true,
		position: 20,
		origin: 'file',
		double: null,
		writable: true,
		hash: 'h-promo',
		rules: [rule({ id: 'r_0000000p', from: '/spring', to: '/sale', group: 'promo', source: 'wp-content/themes/demo/proto-redirects/promo/redirects.jsonc' })],
		...overrides,
	};
}

export function phpGroup(): Group {
	return {
		slug: 'php',
		title: 'Declared in PHP',
		enabled: true,
		position: 100,
		origin: 'php',
		double: null,
		writable: false,
		hash: null,
		rules: [rule({ id: 'r_0000beef', from: '/php-old', to: '/php-new', group: 'php', origin: 'php', source: 'functions.php:12' })],
	};
}

export function index(overrides: Partial<RedirectsIndex> = {}): RedirectsIndex {
	return {
		moduleEnabled: true,
		paused: false,
		createTarget: { path: 'wp-content/themes/demo/proto-redirects', writable: true, reason: null },
		groups: [legacyGroup(), promoGroup(), phpGroup()],
		settings: SETTINGS,
		settingsFile: { exists: true, hash: 'h-settings', writable: true },
		issues: [],
		db: { tables: true, orphans: 2 },
		...overrides,
	};
}

export function file(slug: string, text: string, hash: string, overrides: Partial<FileResult> = {}): FileResult {
	return {
		exists: true,
		path: `wp-content/themes/demo/proto-redirects/${slug}/redirects.jsonc`,
		text,
		hash,
		data: {},
		writable: true,
		reason: null,
		...overrides,
	};
}

export const saved = (slug: string) => ({ path: `wp-content/themes/demo/proto-redirects/${slug}/redirects.jsonc`, hash: `h-${slug}-2`, created: false, warnings: [] });

type Route = unknown | ((body: unknown, path: string) => unknown);

/**
 * Like helpers' mockApi, but a key without a query string also matches the
 * same path with any query. Keys: 'METHOD path[?query]'; a bare path is GET.
 */
export function routes(extra: Record<string, Route> = {}): jest.Mock {
	const table: Record<string, Route> = {
		'admin/redirects': index(),
		'admin/schema-files/redirects/legacy-site': file('legacy-site', legacyText, 'h-legacy'),
		'admin/schema-files/redirects/promo': file('promo', promoText, 'h-promo'),
		'POST admin/schema-files/redirects/legacy-site': saved('legacy-site'),
		'POST admin/schema-files/redirects/promo': saved('promo'),
		...extra,
	};
	const mock = apiFetch as unknown as jest.Mock;
	mock.mockImplementation(async (options: { url?: string; method?: string; data?: unknown }) => {
		const path = String(options.url ?? '').replace(ROOT, '');
		const bare = path.replace(/\?.*$/, '');
		const method = (options.method ?? 'GET').toUpperCase();
		const candidates = [`${method} ${path}`, `${method} ${bare}`, ...(method === 'GET' ? [path, bare] : [])];
		const key = candidates.find((k) => k in table);
		if (!key) {
			throw { code: 'rest_no_route', message: `No route for ${method} ${path}`, data: { status: 404 } };
		}
		const route = table[key];
		return typeof route === 'function' ? (route as (body: unknown, path: string) => unknown)(options.data, path) : route;
	});
	return mock;
}

export interface Call {
	url: string;
	method: string;
	data?: { text?: string; hash?: string | null; acknowledge?: string[] } & Record<string, unknown>;
}

export function calls(): Call[] {
	return (apiFetch as unknown as jest.Mock).mock.calls.map(([options]) => ({
		url: String(options.url).replace(ROOT, ''),
		method: options.method ?? 'GET',
		data: options.data,
	}));
}

export const writes = () => calls().filter((c) => c.method !== 'GET');

export function renderScreen(query = '', bootstrap = {}) {
	window.history.replaceState(null, '', `/wp-admin/admin.php?page=proto-blocks-redirects${query}`);
	const boot = mockBootstrap({ screen: 'redirects', ...bootstrap });
	render(<AdminApp bootstrap={boot} />);
}

export async function rulesTable(): Promise<HTMLElement> {
	return screen.findByRole('table', { name: 'Redirect rules' });
}

export async function rowOf(source: string): Promise<HTMLElement> {
	const table = await rulesTable();
	return within(table).getByText(source).closest('tr') as HTMLElement;
}

/** Opens a Radix select by its label and picks an option. */
export async function choose(user: ReturnType<typeof userEvent.setup>, label: string | RegExp, option: string | RegExp) {
	await user.click(screen.getByRole('combobox', { name: label }));
	await user.click(await screen.findByRole('option', { name: option }));
}

export function setup() {
	beforeAll(async () => {
		await Promise.all([
			import('@/admin/screens/RedirectsScreen'),
			import('@/admin/redirects/NotFoundTab'),
			import('@/admin/redirects/LogTab'),
			import('@/admin/redirects/ImportExportTab'),
			import('@/admin/redirects/SettingsTab'),
		]);
		if (!('ResizeObserver' in window)) {
			(window as unknown as { ResizeObserver: unknown }).ResizeObserver = class {
				observe() {}
				unobserve() {}
				disconnect() {}
			};
		}
	}, 60000);

	afterEach(() => {
		delete window.protoBlocksAdmin;
		document.body.innerHTML = '';
		window.history.replaceState(null, '', '/');
		jest.clearAllMocks();
	});
}
