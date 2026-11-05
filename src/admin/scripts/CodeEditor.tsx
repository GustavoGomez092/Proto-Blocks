import { useId, useRef } from 'react';
import { Label } from '@/admin/components/ui/label';

/**
 * A monospace textarea with a line-number gutter (the plugin has no code
 * field component). The gutter scrolls with the text and is hidden from
 * assistive technology.
 */
export function CodeEditor({
	label,
	hint,
	value,
	onChange,
	readOnly = false,
	error = null,
	describedBy,
}: {
	label: string;
	hint?: string;
	value: string;
	onChange: (value: string) => void;
	readOnly?: boolean;
	error?: string | null;
	describedBy?: string;
}) {
	const id = useId();
	const gutter = useRef<HTMLDivElement>(null);
	const lines = Math.max(1, value.split('\n').length);
	const errorId = `${id}-error`;
	const described = [error ? errorId : null, describedBy ?? null].filter(Boolean).join(' ') || undefined;

	return (
		<div className="grid gap-1.5">
			<div className="flex flex-wrap items-baseline justify-between gap-2">
				<Label htmlFor={id}>{label}</Label>
				{hint ? <code className="font-mono text-micro text-muted-foreground">{hint}</code> : null}
			</div>
			<div className={`flex overflow-hidden rounded-md border bg-card ${error ? 'border-destructive' : ''}`}>
				<div
					ref={gutter}
					aria-hidden="true"
					data-testid="line-gutter"
					className="select-none overflow-hidden border-e bg-muted px-2 py-2 text-right font-mono text-micro leading-5 text-muted-foreground"
				>
					{Array.from({ length: lines }, (_, i) => (
						<div key={i}>{i + 1}</div>
					))}
				</div>
				<textarea
					id={id}
					value={value}
					readOnly={readOnly}
					spellCheck={false}
					autoCapitalize="off"
					autoComplete="off"
					wrap="off"
					rows={Math.min(Math.max(lines, 6), 24)}
					aria-invalid={error ? true : undefined}
					aria-describedby={described}
					onChange={(event) => onChange(event.target.value)}
					onScroll={(event) => {
						if (gutter.current) {
							gutter.current.scrollTop = event.currentTarget.scrollTop;
						}
					}}
					className="min-h-32 w-full resize-y bg-transparent px-3 py-2 font-mono text-micro leading-5 outline-none"
				/>
			</div>
			{error ? (
				<p id={errorId} className="text-caption text-destructive">
					{error}
				</p>
			) : null}
		</div>
	);
}
