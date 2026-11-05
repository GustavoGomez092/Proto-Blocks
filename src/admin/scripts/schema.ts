/**
 * Browser-side checks for script.jsonc, built from the same
 * includes/ContentModel/schemas/scripts.schema.json the server validates
 * with (through content-model/schema.ts's SchemaCheck mirror), plus the
 * rules the schema cannot express (mirrors ScriptValidator.php).
 */
import { __ } from '@wordpress/i18n';
import scriptsSchemaJson from '../../../includes/ContentModel/schemas/scripts.schema.json';
import { toZod, type JsonSchema } from '@/admin/content-model/schema';

const ROOT = scriptsSchemaJson as unknown as JsonSchema;

/** A whole script.jsonc. */
export const scriptSchema = toZod({ $ref: '#/$defs/script' }, ROOT);

/** One placement target string. */
export const targetSchema = toZod({ $ref: '#/$defs/target' }, ROOT);

/** One role slug. */
export const roleSchema = toZod({ type: 'string', pattern: '^[A-Za-z0-9_-]{1,100}$' }, ROOT);

/** Same as SchemaFileWriter::EXTENDED_SLUG_PATTERN. */
export const SLUG_PATTERN = /^[a-z][a-z0-9_-]{0,31}$/;

/** Same as ScriptValidator::PHP_OPEN. */
export const PHP_OPEN = /<\?(?:php|=|\s)/i;

export const SNIPPET_MAX_BYTES = 262144;

export interface TargetKind {
	kind: string;
	label: string;
	/** Placeholder for the value input; null when the target takes no value. */
	placeholder: string | null;
}

export const TARGET_KINDS: TargetKind[] = [
	{ kind: 'all', label: __('All pages', 'proto-blocks'), placeholder: null },
	{ kind: 'front_page', label: __('Front page', 'proto-blocks'), placeholder: null },
	{ kind: 'home', label: __('Blog index', 'proto-blocks'), placeholder: null },
	{ kind: 'search', label: __('Search results', 'proto-blocks'), placeholder: null },
	{ kind: '404', label: __('Not found (404)', 'proto-blocks'), placeholder: null },
	{ kind: 'archive', label: __('Any archive', 'proto-blocks'), placeholder: null },
	{ kind: 'singular', label: __('Any single post or page', 'proto-blocks'), placeholder: null },
	{ kind: 'singular:', label: __('Single of a post type', 'proto-blocks'), placeholder: 'product' },
	{ kind: 'post_type:', label: __('Post type (single and archive)', 'proto-blocks'), placeholder: 'product' },
	{ kind: 'page:', label: __('Page (slug or ID)', 'proto-blocks'), placeholder: 'privacy-policy' },
	{ kind: 'post:', label: __('Post (slug or ID)', 'proto-blocks'), placeholder: 'hello-world' },
	{ kind: 'taxonomy:', label: __('Taxonomy archive', 'proto-blocks'), placeholder: 'category' },
	{ kind: 'term:', label: __('Term (archive or posts with it)', 'proto-blocks'), placeholder: 'category:news' },
	{ kind: 'url:', label: __('URL path (glob with *)', 'proto-blocks'), placeholder: '/landing/*' },
];

/** Short chip labels per kind ("page:" → "Page"); valued kinds are shown as "Page · about". */
const TARGET_SHORT: Record<string, string> = {
	all: __('All pages', 'proto-blocks'),
	front_page: __('Front page', 'proto-blocks'),
	home: __('Blog index', 'proto-blocks'),
	search: __('Search results', 'proto-blocks'),
	'404': __('Not found (404)', 'proto-blocks'),
	archive: __('Any archive', 'proto-blocks'),
	singular: __('Any single post or page', 'proto-blocks'),
	'singular:': __('Single', 'proto-blocks'),
	'post_type:': __('Post type', 'proto-blocks'),
	'page:': __('Page', 'proto-blocks'),
	'post:': __('Post', 'proto-blocks'),
	'taxonomy:': __('Taxonomy', 'proto-blocks'),
	'term:': __('Term', 'proto-blocks'),
	'url:': __('URL', 'proto-blocks'),
};

/** A readable label for a target string: "all" → "All pages", "page:about" → "Page · about". Unknown kinds stay as written. */
export function targetLabel(target: string): string {
	if (TARGET_SHORT[target] !== undefined && !target.endsWith(':')) {
		return TARGET_SHORT[target];
	}
	const colon = target.indexOf(':');
	if (colon > 0) {
		const kind = target.slice(0, colon + 1);
		if (TARGET_SHORT[kind] !== undefined) {
			return `${TARGET_SHORT[kind]} · ${target.slice(colon + 1)}`;
		}
	}
	return target;
}

/** The target string for a kind and its value ("page:" + "about"). */
export function buildTarget(kind: string, value: string): string {
	return kind.endsWith(':') ? kind + value.trim() : kind;
}

/** A message when `target` is not a valid placement target, else null. */
export function targetError(target: string): string | null {
	if (targetSchema.safeParse(target).success) {
		return null;
	}
	if (target.startsWith('url:') && !target.startsWith('url:/')) {
		return __('A URL path starts with "/".', 'proto-blocks');
	}
	return __('This is not a valid target. Check the value: lowercase slugs, IDs, or a URL path with *.', 'proto-blocks');
}

/** Bytes of `text` in UTF-8 (the server's limit is in bytes). */
export function utf8Length(text: string): number {
	let bytes = 0;
	for (const char of text) {
		const point = char.codePointAt(0) ?? 0;
		bytes += point < 0x80 ? 1 : point < 0x800 ? 2 : point < 0x10000 ? 3 : 4;
	}
	return bytes;
}

/** A message when a snippet cannot be saved, else null. */
export function snippetError(code: string): string | null {
	if (utf8Length(code) > SNIPPET_MAX_BYTES) {
		return __('A snippet may not be larger than 256 KB.', 'proto-blocks');
	}
	if (PHP_OPEN.test(code)) {
		return __('Snippets are HTML, CSS and JavaScript only; a PHP opening tag ("<?php", "<?=" or "<?") is not allowed.', 'proto-blocks');
	}
	return null;
}

/** Errors of a decoded script.jsonc as "path: message" lines (empty when valid). */
export function scriptErrors(data: unknown): { path: string; message: string }[] {
	const result = scriptSchema.safeParse(data);
	const errors = result.success ? [] : result.error.issues.map((issue) => ({ path: '/' + issue.path.join('/'), message: issue.message }));
	const where = (data as { where?: { loggedIn?: unknown; roles?: unknown } } | null)?.where;
	if (where && where.loggedIn === false && Array.isArray(where.roles) && where.roles.length > 0) {
		errors.push({ path: '/where/roles', message: __('"Roles" only match logged-in users; with "Logged out" the script would never run.', 'proto-blocks') });
	}
	return errors;
}

/** The slug a title suggests (like PhpDeclarations::slugFromTitle). */
export function slugFromTitle(title: string): string {
	return title
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^[^a-z]+/, '')
		.slice(0, 32)
		.replace(/[-_]+$/, '');
}
