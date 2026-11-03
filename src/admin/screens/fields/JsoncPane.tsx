import { useEffect, useId, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { adminPost, ApiError } from '@/admin/api';
import { Badge } from '@/admin/components/ui/badge';
import { parseJsonc } from '@/admin/content-model/jsonc';
import { lineColumn } from './jsonc-edit';
import type { SchemaKind } from './schema-files';

export interface JsoncProblem {
	line: number;
	column: number;
	message: string;
}

/** Prefix that lets parseJsonc (objects only) check a list file; offsets are shifted back by its length. */
const WRAP = '{"":';

/**
 * Syntax errors of a group file as 1-based line and column, through the same
 * parseJsonc the declaration screens use (`__proto__` refused). A list file
 * (`pointer` starting with an index) may have an array at the top level.
 * The text is only parsed, never evaluated.
 */
export function jsoncErrors(text: string, pointer: (string | number)[]): JsoncProblem[] {
	const list = typeof pointer[0] === 'number';
	const shift = list ? WRAP.length : 0;
	const { errors } = parseJsonc(list ? `${WRAP}${text}\n}` : text);
	return errors.map((e) => ({ ...lineColumn(text, Math.min(Math.max(0, e.offset - shift), text.length)), message: e.message }));
}

interface Finding {
	path: string;
	message: string;
}

interface ServerResult {
	text: string;
	errors: Finding[];
	warnings: Finding[];
	jsonc: JsoncProblem | null;
}

const DEBOUNCE_MS = 400;

export interface JsoncPaneProps {
	/** The whole file text: the same draft the builder edits and Save writes. */
	text: string;
	/** Where the group sits in the file (groupPointer). */
	pointer: (string | number)[];
	/** The file's kind and the group's index in a type or taxonomy file, for POST admin/fields/validate. */
	kind: SchemaKind;
	index?: number | null;
	readOnly?: boolean;
	/** Every edit; the caller re-parses valid text into the builder. */
	onChange: (text: string) => void;
}

/**
 * The file as text in a plain monospace textarea. Parse errors show at once
 * as "Line l, column c"; the server's verdict (POST admin/fields/validate,
 * debounced 400 ms) follows, and its own line and column win for a JSONC
 * error. Nothing is ever fixed automatically.
 */
export function JsoncPane({ text, pointer, kind, index = null, readOnly = false, onChange }: JsoncPaneProps) {
	const id = useId();
	const [server, setServer] = useState<ServerResult | null>(null);
	const local = jsoncErrors(text, pointer);

	useEffect(() => {
		let live = true;
		const timer = setTimeout(() => {
			const body: Record<string, unknown> = { text, kind };
			if (kind !== 'field-group' && typeof index === 'number') {
				body.index = index;
			}
			adminPost<{ errors?: Finding[]; warnings?: Finding[] }>('admin/fields/validate', body).then(
				(result) => live && setServer({ text, errors: result.errors ?? [], warnings: result.warnings ?? [], jsonc: null }),
				(e: unknown) => {
					if (!live) {
						return;
					}
					const error = e instanceof ApiError ? e : null;
					if (error?.code === 'proto_blocks_invalid_jsonc' && typeof error.data.line === 'number' && typeof error.data.column === 'number') {
						setServer({ text, errors: [], warnings: [], jsonc: { line: error.data.line, column: error.data.column, message: error.message } });
					} else {
						setServer(null);
					}
				}
			);
		}, DEBOUNCE_MS);
		return () => {
			live = false;
			clearTimeout(timer);
		};
	}, [text, kind, index]);

	const current = server && server.text === text ? server : null;
	const syntax = current?.jsonc ? [current.jsonc] : local;
	const findings = syntax.length > 0 ? [] : (current?.errors ?? []);
	const warnings = syntax.length > 0 ? [] : (current?.warnings ?? []);
	const problems = syntax.length + findings.length;

	return (
		<div className="grid gap-2">
			<div className="flex items-center justify-between gap-2">
				<label htmlFor={id} className="text-sm font-semibold">
					{__('File text', 'proto-blocks')}
				</label>
				{problems > 0 ? (
					<Badge variant="destructive">
						{sprintf(/* translators: %d: number of problems */ _n('%d problem', '%d problems', problems, 'proto-blocks'), problems)}
					</Badge>
				) : current ? (
					<Badge variant="secondary">{__('Valid', 'proto-blocks')}</Badge>
				) : null}
			</div>
			<textarea
				id={id}
				aria-invalid={syntax.length > 0 || undefined}
				aria-describedby={`${id}-problems`}
				className="min-h-[480px] w-full rounded-md border bg-[#0f1d2e] p-3 font-mono text-xs leading-5 whitespace-pre text-[#d6e2f0] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
				spellCheck={false}
				autoCapitalize="off"
				autoCorrect="off"
				wrap="off"
				readOnly={readOnly}
				value={text}
				onChange={(e) => onChange(e.target.value)}
			/>
			<div id={`${id}-problems`} aria-live="polite" className="grid gap-1 text-[13px]">
				{syntax.map((p, i) => (
					<p key={`s${i}`} className="text-destructive">
						{sprintf(/* translators: 1: line number, 2: column number */ __('Line %1$d, column %2$d', 'proto-blocks'), p.line, p.column)}
						{`: ${p.message}`}
					</p>
				))}
				{findings.map((f, i) => (
					<p key={`e${i}`} className="text-destructive">
						<code className="font-mono">{f.path || '/'}</code> {f.message}
					</p>
				))}
				{warnings.map((f, i) => (
					<p key={`w${i}`} className="text-warning">
						<code className="font-mono">{f.path || '/'}</code> {f.message}
					</p>
				))}
				<p className="text-xs text-muted-foreground">{__('Comments and key order are kept. Valid edits update the builder.', 'proto-blocks')}</p>
			</div>
		</div>
	);
}
