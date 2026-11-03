/**
 * Shapes of the content-model REST routes (includes/API/Admin/PostTypesRoutes.php,
 * TaxonomiesRoutes.php, SchemaFilesRoutes.php). Keep in step with those.
 */

export type Origin = 'file' | 'php' | 'plugin' | 'theme' | 'core';

export interface Issue {
	level: 'error' | 'warning';
	code: string;
	message: string;
	/** File or PHP location the issue comes from, relative to ABSPATH. */
	source: string;
	slug?: string;
	kind?: string;
}

export interface ListItem {
	slug: string;
	label: string;
	origin: Origin;
	originLabel: string;
	/** Relative to ABSPATH. */
	source: string | null;
	override: boolean;
	double: { file: string; php: string } | null;
	count: number;
	editable: boolean;
	/** `inline`: a taxonomy declared inside a type file, edited through that type. */
	readOnlyReason: 'file_mods' | 'not_writable' | 'not_proto' | 'outside_root' | 'inline' | null;
	issues: Issue[];
	/** Taxonomy items only: translated text for `inline` ("Declared inline in proto-types/<type>/type.jsonc; edit it there."), else null. */
	readOnlyMessage?: string | null;
	/** Taxonomy items only: the post types it is attached to. */
	objectTypes?: string[];
	/** Taxonomy items only: the type whose file declares it inline, else null. */
	parentType?: string | null;
}

export interface ListResult {
	moduleEnabled: boolean;
	createTarget: { path: string; writable: boolean; reason: string | null };
	items: ListItem[];
	issues: Issue[];
}

/** GET schema-files/<kind>/<slug>. */
export interface FileResult {
	exists: boolean;
	path: string;
	text: string;
	/** Null when the file does not exist yet. */
	hash: string | null;
	data: Record<string, unknown>;
	writable: boolean;
	reason: string | null;
}

export interface Guardrail {
	code: 'supports_removed' | 'taxonomy_removed' | 'field_removed';
	message: string;
	count: number;
	detail: string[];
}

export type Kind = 'type' | 'taxonomy' | 'options';
