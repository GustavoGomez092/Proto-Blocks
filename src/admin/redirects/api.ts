/**
 * REST calls of the Redirects screen (spec §11) and the group/settings file
 * writes. Files go through the existing schema-files route with the hash, the
 * way useDeclarationFile does; the text is edited here with edits.ts so the
 * developer's comments survive (useDeclarationFile's top-level diff would
 * rewrite the whole rules array).
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { adminDelete, adminGet, adminPost, ApiError, restUrl } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import type { FileResult, Guardrail } from '@/admin/content-model/types';
import { newGroupText } from './edits';
import { RULE_ID } from './schema';
import type { Paged, RedirectsIndex } from './types';

export type FileKind = 'redirects' | 'redirect-settings';

export type WriteOutcome =
	| { status: 'saved'; path: string; hash: string }
	| { status: 'conflict'; path: string }
	| { status: 'confirm'; guardrails: Guardrail[] };

interface SaveResponse {
	path: string;
	hash: string;
	created: boolean;
	warnings: unknown[];
}

export function asApiError(error: unknown): ApiError {
	return error instanceof ApiError ? error : new ApiError('unknown_error', error instanceof Error ? error.message : String(error), 0);
}

/** `?a=1&ids[]=2…` from a record; empty values are left out. */
export function query(params: Record<string, string | number | boolean | (string | number)[] | null | undefined>): string {
	const parts: string[] = [];
	for (const [key, value] of Object.entries(params)) {
		if (value === null || value === undefined || value === '' || value === false) {
			continue;
		}
		if (Array.isArray(value)) {
			for (const item of value) {
				parts.push(`${encodeURIComponent(key)}[]=${encodeURIComponent(String(item))}`);
			}
		} else {
			parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(value === true ? '1' : String(value))}`);
		}
	}
	return parts.length > 0 ? `?${parts.join('&')}` : '';
}

export function filePath(kind: FileKind, slug: string): string {
	return `admin/schema-files/${kind}/${slug}`;
}

export function loadFile(kind: FileKind, slug: string): Promise<FileResult> {
	return adminGet<FileResult>(filePath(kind, slug));
}

/** POSTs `text` with `hash`; resolves conflict/confirm like useDeclarationFile.save. */
export async function postFile(kind: FileKind, slug: string, text: string, hash: string | null, acknowledge: string[] = []): Promise<WriteOutcome> {
	try {
		const result = await adminPost<SaveResponse>(filePath(kind, slug), { text, hash, acknowledge });
		return { status: 'saved', path: result.path, hash: result.hash };
	} catch (e) {
		const error = asApiError(e);
		if (error.code === 'proto_blocks_conflict') {
			return { status: 'conflict', path: filePath(kind, slug) };
		}
		if (error.code === 'proto_blocks_needs_confirmation') {
			return { status: 'confirm', guardrails: Array.isArray(error.data.guardrails) ? (error.data.guardrails as Guardrail[]) : [] };
		}
		throw error;
	}
}

export interface GroupWrite {
	slug: string;
	/** The hash the screen listed; a different hash on disk is a conflict. Undefined skips the check. */
	expectedHash?: string | null;
	/** Title for a group file that does not exist yet. */
	title?: string;
	/** Starting text for a group file that does not exist yet (default newGroupText). */
	initial?: string;
	edit: (text: string) => string;
}

/**
 * Reads the group file, applies `edit` to its text and writes it back with
 * the hash it read. A group file that does not exist starts from
 * newGroupText and is written with hash null (never overwrites).
 */
export async function writeGroup({ slug, expectedHash, title, initial, edit }: GroupWrite, acknowledge: string[] = []): Promise<WriteOutcome> {
	const file = await loadFile('redirects', slug);
	if (file.exists && typeof expectedHash === 'string' && file.hash !== expectedHash) {
		return { status: 'conflict', path: file.path };
	}
	if (!file.exists && typeof expectedHash === 'string') {
		// Listed, but gone from disk since.
		return { status: 'conflict', path: file.path };
	}
	const text = edit(file.exists ? file.text : (initial ?? newGroupText(slug, title ?? slug)));
	return postFile('redirects', slug, text, file.exists ? file.hash : null, acknowledge);
}

/** Runs writes one after another and stops at the first that does not save. */
export async function writeInOrder(writes: GroupWrite[], acknowledge: string[] = []): Promise<WriteOutcome> {
	let last: WriteOutcome = { status: 'saved', path: '', hash: '' };
	for (const write of writes) {
		last = await writeGroup(write, acknowledge);
		if (last.status !== 'saved') {
			return last;
		}
	}
	return last;
}

export function deleteGroupFile(slug: string): Promise<unknown> {
	return adminDelete(`${filePath('redirects', slug)}?confirm=${encodeURIComponent(slug)}`);
}

/**
 * Drops the hit rows of rules deleted from their files
 * (`DELETE admin/redirects/hits?group=&ruleId=`; adminDelete sends no body,
 * WP reads the query). A 404 (no row) is fine. Resolves to the rules whose
 * rows could not be dropped for another reason.
 */
export async function deleteHitRows(rules: { group: string; id: string }[]): Promise<{ failed: number; error: ApiError | null }> {
	let failed = 0;
	let last: ApiError | null = null;
	for (const rule of rules) {
		if (!RULE_ID.test(rule.id)) {
			continue;
		}
		try {
			await adminDelete(`admin/redirects/hits${query({ group: rule.group, ruleId: rule.id })}`);
		} catch (e) {
			const error = asApiError(e);
			if (error.status !== 404) {
				failed++;
				last = error;
			}
		}
	}
	return { failed, error: last };
}

/** Deletes every logged 404 of exactly `url` (`DELETE admin/redirects/404?url=`); resolves to the count. */
export async function deleteNotFoundUrl(url: string): Promise<number> {
	const result = await adminDelete<{ deleted?: number }>(`admin/redirects/404${query({ url })}`);
	return typeof result?.deleted === 'number' ? result.deleted : 0;
}

/** GET admin/redirects, reloadable. */
export function useRedirectsIndex() {
	const [index, setIndex] = useState<RedirectsIndex | null>(null);
	const [error, setError] = useState<unknown>(null);
	const [loading, setLoading] = useState(true);
	const request = useRef(0);

	const load = useCallback(async () => {
		const id = ++request.current;
		setLoading(true);
		try {
			const result = await adminGet<RedirectsIndex>('admin/redirects');
			if (id === request.current) {
				setIndex(result);
				setError(null);
			}
		} catch (e) {
			if (id === request.current) {
				setError(e);
			}
		} finally {
			if (id === request.current) {
				setLoading(false);
			}
		}
	}, []);

	useEffect(() => {
		void load();
	}, [load]);

	return { index, error, loading, reload: load };
}

/** A paged GET that reloads when `path` changes. */
export function usePaged<T>(path: string) {
	const [data, setData] = useState<Paged<T> | null>(null);
	const [error, setError] = useState<unknown>(null);
	const [loading, setLoading] = useState(true);
	const request = useRef(0);

	const load = useCallback(async () => {
		const id = ++request.current;
		setLoading(true);
		try {
			const result = await adminGet<Partial<Paged<T>>>(path);
			if (id === request.current) {
				const total = typeof result?.total === 'number' ? result.total : 0;
				setData({
					rows: Array.isArray(result?.rows) ? result.rows : [],
					total,
					page: typeof result?.page === 'number' ? result.page : undefined,
					perPage: typeof result?.perPage === 'number' ? result.perPage : undefined,
					pages: typeof result?.pages === 'number' ? result.pages : undefined,
					groupBy: result?.groupBy ?? null,
				});
				setError(null);
			}
		} catch (e) {
			if (id === request.current) {
				setError(e);
			}
		} finally {
			if (id === request.current) {
				setLoading(false);
			}
		}
	}, [path]);

	useEffect(() => {
		void load();
	}, [load]);

	return { data, error, loading, reload: load };
}

/** A header-supplied name as a bare file name: no folders, no control characters; null when nothing usable is left. */
function safeName(name: string): string | null {
	// eslint-disable-next-line no-control-regex
	const base = name.replace(/[\u0000-\u001f\u007f]/g, '').split(/[\\/]/).pop()?.trim() ?? '';
	return base === '' || base === '.' || base === '..' ? null : base;
}

/** The file name of a `Content-Disposition` header (`filename*=` first, then `filename=`). */
export function filenameFrom(disposition: string | null | undefined): string | null {
	if (!disposition) {
		return null;
	}
	const star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(disposition);
	if (star) {
		try {
			const name = safeName(decodeURIComponent(star[1].trim().replace(/^"|"$/g, '')));
			if (name) {
				return name;
			}
		} catch {
			// Fall through to the plain filename.
		}
	}
	const plain = /filename="?([^";]+)"?/i.exec(disposition);
	return plain ? safeName(plain[1]) : null;
}

/**
 * Downloads a file route (exports): `fetch` with the REST nonce, then a Blob
 * link. apiFetch would parse the body as JSON.
 */
export async function download(path: string, fallbackName: string): Promise<string> {
	const { restRoot, restNonce } = getBootstrap();
	let response: Response;
	try {
		response = await fetch(restUrl(path, restRoot), { headers: { 'X-WP-Nonce': restNonce }, credentials: 'same-origin' });
	} catch (e) {
		throw new ApiError('proto_blocks_export_failed', e instanceof Error ? e.message : String(e), 0);
	}
	if (!response.ok) {
		let code = 'proto_blocks_export_failed';
		let message = `HTTP ${response.status}`;
		try {
			const body = (await response.json()) as { code?: string; message?: string };
			code = body.code ?? code;
			message = body.message ?? message;
		} catch {
			// Not JSON.
		}
		throw new ApiError(code, message, response.status);
	}
	const blob = await response.blob();
	const name = filenameFrom(response.headers?.get('Content-Disposition')) ?? fallbackName;
	const url = URL.createObjectURL(blob);
	const link = document.createElement('a');
	link.href = url;
	link.download = name;
	link.rel = 'noopener';
	document.body.appendChild(link);
	link.click();
	link.remove();
	window.setTimeout(() => URL.revokeObjectURL(url), 0);
	return name;
}
