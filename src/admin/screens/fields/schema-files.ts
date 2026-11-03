/**
 * The builder's file access (C7): every read and write goes through
 * admin/schema-files/<kind>/<slug>, where the server validates the group,
 * checks the hash, confines the path to the theme and swaps the file in
 * atomically. A group in `proto-fields/<slug>/` is kind `field-group`; a group
 * inside a post type or taxonomy file is saved by writing that whole file.
 */
import { __ } from '@wordpress/i18n';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';
import type { FileResult } from '@/admin/content-model/types';

export type SchemaKind = 'type' | 'taxonomy' | 'field-group';

/** The route's slug characters; the server applies each kind's own grammar. */
const SLUG = /^[a-z][a-z0-9_-]{0,63}$/;

function filePath(kind: SchemaKind, slug: string): string {
	if (!SLUG.test(slug)) {
		throw new ApiError('proto_blocks_invalid_slug', __('Invalid slug.', 'proto-blocks'), 400);
	}
	return `admin/schema-files/${kind}/${slug}`;
}

export async function getSchemaFile(kind: SchemaKind, slug: string): Promise<FileResult> {
	return adminGet<FileResult>(filePath(kind, slug));
}

/**
 * Writes `text`. `hash` is the hash the text was loaded with (null for a new
 * file); a file changed on disk since rejects with `proto_blocks_conflict`.
 * `acknowledge` lists guardrail codes the user confirmed (type files).
 */
export async function putSchemaFile(
	kind: SchemaKind,
	slug: string,
	text: string,
	hash: string | null,
	acknowledge: string[] = []
): Promise<{ path: string; hash: string; created: boolean }> {
	const result = await adminPost<{ path: string; hash: string; created: boolean }>(filePath(kind, slug), { text, hash, acknowledge });
	return { path: result.path, hash: result.hash, created: result.created };
}

/** Deletes the file; `confirm` is the slug the user typed (the server asks for it). */
export async function deleteSchemaFile(kind: SchemaKind, slug: string, confirm?: string): Promise<{ deleted: string; kept: string[] }> {
	const query = confirm === undefined ? '' : `?confirm=${encodeURIComponent(confirm)}`;
	return adminDelete<{ deleted: string; kept: string[] }>(filePath(kind, slug) + query);
}
