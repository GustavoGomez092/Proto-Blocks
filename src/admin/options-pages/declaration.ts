/**
 * An options page declaration (proto-options/<slug>/page.jsonc) as the
 * Options Pages editor holds it, plus the admin route shapes
 * (includes/API/Admin/OptionsPagesRoutes.php). Keep in step with those.
 */

export interface OptionsPageForm {
	title: string;
	menu: string;
	menu_title?: string;
	position?: number;
	capability: string;
	icon?: string;
	autoload: boolean;
	storage: { mode: 'fields' } | { mode: 'array'; option: string };
	fields: unknown[];
	tabs: { title: string; fields: unknown[] }[];
	children: { slug: string; title: string; capability?: string; fields: unknown[] }[];
}

/** The capability grammar of the schema and PageDefinition. */
export const CAPABILITY_PATTERN = /^[a-z][a-z0-9_]*$/;
/** An array storage option name (schema `optionsStorage`). */
export const OPTION_PATTERN = /^[a-z0-9_]{1,191}$/;
/** A child page's slug part (schema `children[].slug`). */
export const CHILD_SLUG_PATTERN = /^[a-z][a-z0-9-]*$/;
/** A page slug (schema `slug`, OptionsPagesRoutes::SLUG_RE). */
export const PAGE_SLUG_PATTERN = /^[a-z][a-z0-9-]{0,63}$/;

const DEFAULTS = { menu: 'top', capability: 'manage_options', autoload: true } as const;

type Data = Record<string, unknown>;

function isObject(value: unknown): value is Data {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

const str = (value: unknown): string | undefined => (typeof value === 'string' ? value : undefined);
const list = (value: unknown): unknown[] => (Array.isArray(value) ? value : []);

function same(a: unknown, b: unknown): boolean {
	return JSON.stringify(a) === JSON.stringify(b);
}

/** The form for a declaration; absent keys get the runtime defaults. Extra keys of tabs and children ride along. */
export function formFromData(data: Data): OptionsPageForm {
	const storage = isObject(data.storage) && data.storage.mode === 'array'
		? { mode: 'array' as const, option: str(data.storage.option) ?? '' }
		: { mode: 'fields' as const };
	return {
		title: str(data.title) ?? '',
		menu: str(data.menu) ?? DEFAULTS.menu,
		menu_title: str(data.menuTitle) ?? str(data.menu_title),
		position: typeof data.position === 'number' ? data.position : undefined,
		capability: str(data.capability) ?? DEFAULTS.capability,
		icon: str(data.icon),
		autoload: typeof data.autoload === 'boolean' ? data.autoload : DEFAULTS.autoload,
		storage,
		fields: list(data.fields),
		tabs: list(data.tabs)
			.filter(isObject)
			.map((tab) => ({ ...tab, title: str(tab.title) ?? '', fields: list(tab.fields) })),
		children: list(data.children)
			.filter(isObject)
			.map((child) => ({ ...child, slug: str(child.slug) ?? '', title: str(child.title) ?? '', fields: list(child.fields) })),
	};
}

/**
 * The declaration with the form merged over `original`: unknown keys stay,
 * and a key the file does not have stays absent while the form holds its
 * default (or nothing), so a save writes only what the user changed.
 */
export function dataFromForm(original: Data, form: OptionsPageForm): Data {
	const next: Data = { ...original };
	const put = (key: string, value: unknown, fallback?: unknown) => {
		const empty = value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
		if (!(key in original) && (empty || (fallback !== undefined && same(value, fallback)))) {
			return;
		}
		if (value === undefined || (value === '' && key !== 'title')) {
			delete next[key];
			return;
		}
		if (!same(original[key], value)) {
			next[key] = value;
		}
	};
	// The file's storage object stays as written (key order, extra keys) while it means the same.
	const storage = same(formFromData(original).storage, form.storage) && 'storage' in original
		? original.storage
		: form.storage.mode === 'array'
			? { mode: 'array', option: form.storage.option }
			: { mode: 'fields' };

	put('title', form.title);
	put('menu', form.menu, DEFAULTS.menu);
	const menuTitleKey = 'menu_title' in original && !('menuTitle' in original) ? 'menu_title' : 'menuTitle';
	put(menuTitleKey, form.menu_title);
	put('position', form.position);
	put('capability', form.capability, DEFAULTS.capability);
	put('icon', form.icon);
	put('autoload', form.autoload, DEFAULTS.autoload);
	put('storage', storage, { mode: 'fields' });
	put('fields', form.fields);
	put('tabs', form.tabs);
	put('children', form.children.map(({ capability, ...child }) => (capability ? { ...child, capability } : child)));
	return next;
}

/** Proposed page slug for a title ("Shop Notices" → "shop-notices"). */
export function slugFromTitle(title: string): string {
	return title
		.toLowerCase()
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^[^a-z]+/, '')
		.replace(/-+$/, '')
		.slice(0, 64)
		.replace(/-+$/, '');
}

// --- admin route shapes -----------------------------------------------------

export type OptionsReadOnlyReason = 'file_mods' | 'multisite' | 'not_writable' | 'php' | 'unknown' | null;

/** One row of GET admin/options-pages `pages` (OptionsPagesRoutes::row). */
export interface OptionsPageRow {
	slug: string;
	title: string;
	menu: string;
	menuLabel: string;
	capability: string;
	/** "Array: <option>" or "Per field". */
	storage: string;
	origin: 'file' | 'php';
	originLabel: string;
	double: { file: string; php: string } | null;
	source: string | null;
	editable: boolean;
	readOnlyReason: string | null;
	/** Compound slugs of the child pages. */
	children: string[];
	warnings: string[];
	url: string;
}

/** A settings page another plugin, the theme or core added (ExternalMenus). */
export interface ExternalRow {
	slug: string;
	title: string;
	parent: string;
	origin: 'core' | 'plugin' | 'theme' | 'unknown';
	originLabel: string;
}

export interface OptionsIndex {
	moduleEnabled: boolean;
	createTarget: { path: string; writable: boolean; reason: string | null };
	pages: OptionsPageRow[];
	external: ExternalRow[];
	warnings: { slug: string; source: string | null; message: string }[];
}

/** GET admin/options-pages/<slug>. */
export interface OptionsDetail {
	page: OptionsPageRow;
	declaration: Data;
	parents: { slug: string; title: string }[];
	capabilities: string[];
}

/** The banner vocabulary for a row's readOnlyReason. */
export function readOnlyReason(reason: string | null | undefined): OptionsReadOnlyReason {
	if (reason === null || reason === undefined) {
		return null;
	}
	return reason === 'file_mods' || reason === 'multisite' || reason === 'not_writable' || reason === 'php' ? reason : 'unknown';
}
