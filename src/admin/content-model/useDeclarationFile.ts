import { useCallback, useEffect, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { adminDelete, adminGet, adminPost, ApiError } from '@/admin/api';
import { applyDeclarationEdits, parseJsonc } from '@/admin/content-model/jsonc';
import type { FileResult, Guardrail, Kind } from '@/admin/content-model/types';

export type SaveOutcome = { status: 'saved' } | { status: 'conflict' } | { status: 'confirm'; guardrails: Guardrail[] };
export type RemoveOutcome =
	| { status: 'deleted'; kept: string[] }
	| { status: 'confirm'; content: { count: number; taxonomies?: Record<string, number> } };

interface SaveResponse {
	path: string;
	hash: string;
	created: boolean;
	warnings: unknown[];
}

function asApiError(error: unknown): ApiError {
	return error instanceof ApiError ? error : new ApiError('unknown_error', error instanceof Error ? error.message : String(error), 0);
}

/**
 * Loads one declaration file (GET schema-files/<kind>/<slug>) and saves or
 * deletes it. `name` seeds the template when the file does not exist yet.
 *
 * `save(next)` takes the file's data with only the user's changes merged
 * over it (`{...file.data, ...dirtyValues}`): the text written is the loaded
 * text with just those keys edited, so comments and untouched keys stay as
 * they are. It sends the hash from load, so a file changed on disk resolves
 * `conflict` instead of being overwritten, and a server guardrail resolves
 * `confirm` (save again with the codes in `acknowledge`). Other errors reject
 * with the ApiError.
 */
export function useDeclarationFile(kind: Kind, slug: string, name?: string) {
	const [file, setFile] = useState<FileResult | null>(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<ApiError | null>(null);
	const request = useRef(0);
	const fileRef = useRef<FileResult | null>(null);
	fileRef.current = file;

	const base = `schema-files/${kind}/${slug}`;

	const load = useCallback(async () => {
		const id = ++request.current;
		setLoading(true);
		try {
			const result = await adminGet<FileResult>(base + (name ? `?name=${encodeURIComponent(name)}` : ''));
			if (id === request.current) {
				setFile(result);
				setError(null);
			}
		} catch (e) {
			if (id === request.current) {
				setError(asApiError(e));
			}
		} finally {
			if (id === request.current) {
				setLoading(false);
			}
		}
	}, [base, name]);

	useEffect(() => {
		setFile(null);
		void load();
	}, [load]);

	const save = useCallback(
		async (next: Record<string, unknown>, acknowledge: string[] = []): Promise<SaveOutcome> => {
			const current = fileRef.current;
			if (!current) {
				throw new Error(__('The file has not loaded yet.', 'proto-blocks'));
			}
			// Diff against what the text really holds, not `data`, so every
			// change the caller passes reaches the file.
			const text = applyDeclarationEdits(current.text, parseJsonc(current.text).data, next);
			try {
				const result = await adminPost<SaveResponse>(base, { text, hash: current.hash, acknowledge });
				const saved: FileResult = {
					...current,
					exists: true,
					path: result.path,
					text,
					hash: result.hash,
					data: parseJsonc(text).data,
				};
				fileRef.current = saved;
				setFile(saved);
				toast.success(
					sprintf(
						/* translators: %s: path of the declaration file that was written. */
						__('Saved to %s.', 'proto-blocks'),
						result.path
					)
				);
				return { status: 'saved' };
			} catch (e) {
				const apiError = asApiError(e);
				if (apiError.code === 'proto_blocks_conflict') {
					return { status: 'conflict' };
				}
				if (apiError.code === 'proto_blocks_needs_confirmation') {
					const guardrails = Array.isArray(apiError.data.guardrails) ? (apiError.data.guardrails as Guardrail[]) : [];
					return { status: 'confirm', guardrails };
				}
				throw apiError;
			}
		},
		[base]
	);

	const remove = useCallback(
		async (confirm?: string): Promise<RemoveOutcome> => {
			try {
				const result = await adminDelete<{ deleted: boolean; kept?: string[] }>(
					base + (confirm ? `?confirm=${encodeURIComponent(confirm)}` : '')
				);
				return { status: 'deleted', kept: Array.isArray(result?.kept) ? result.kept : [] };
			} catch (e) {
				const apiError = asApiError(e);
				if (apiError.code === 'proto_blocks_confirm_required') {
					const content = (apiError.data.content ?? { count: 0 }) as { count: number; taxonomies?: Record<string, number> };
					return { status: 'confirm', content };
				}
				throw apiError;
			}
		},
		[base]
	);

	return { file, loading, error, save, remove, reload: load };
}
