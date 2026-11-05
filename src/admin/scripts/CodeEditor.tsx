import { useId, useRef } from 'react';
import { Label } from '@/admin/components/ui/label';
import { cn } from '@/admin/lib/utils';

/**
 * A monospace textarea with a line-number gutter (the plugin has no code
 * field component). The gutter scrolls with the text and is hidden from
 * assistive technology.
 *
 * `bare` drops the visible label and the frame so the editor can fill a
 * card (the script editor's snippet tabs); `label` then names the textarea.
 */
export function CodeEditor({
	label,
	hint,
	value,
	onChange,
	readOnly = false,
	error = null,
	describedBy,
	bare = false,
}: {
	label: string;
	hint?: string;
	value: string;
	onChange: (value: string) => void;
	readOnly?: boolean;
	error?: string | null;
	describedBy?: string;
	bare?: boolean;
}) {
	const id = useId();
	const gutter = useRef<HTMLDivElement>(null);
	const lines = Math.max(1, value.split('\n').length);
	const errorId = `${id}-error`;
	const described = [error ? errorId : null, describedBy ?? null].filter(Boolean).join(' ') || undefined;

	return (
		<div className={cn('grid', bare ? 'gap-0' : 'gap-1.5')}>
			{bare ? null : (
				<div className="flex flex-wrap items-baseline justify-between gap-2">
					<Label htmlFor={id}>{label}</Label>
					{hint ? <code className="font-mono text-micro text-muted-foreground">{hint}</code> : null}
				</div>
			)}
			<div
				className={cn(
					'flex overflow-hidden bg-card',
					bare ? (error ? 'shadow-[inset_0_0_0_1px_var(--destructive)]' : '') : cn('rounded-md border', error && 'border-destructive')
				)}
			>
				<div
					ref={gutter}
					aria-hidden="true"
					data-testid="line-gutter"
					className="w-10 flex-none select-none overflow-hidden border-e border-divider bg-subtle py-3.5 pe-2.5 text-right font-mono text-caption leading-[1.7] text-faint"
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
					aria-label={bare ? label : undefined}
					rows={Math.min(Math.max(lines, 8), 24)}
					aria-invalid={error ? true : undefined}
					aria-describedby={described}
					onChange={(event) => onChange(event.target.value)}
					onScroll={(event) => {
						if (gutter.current) {
							gutter.current.scrollTop = event.currentTarget.scrollTop;
						}
					}}
					className="min-h-[214px] w-full resize-y bg-transparent px-4 py-3.5 font-mono text-caption leading-[1.7] text-foreground outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
				/>
			</div>
			{error ? (
				<p id={errorId} className={cn('text-caption text-destructive', bare && 'border-t bg-destructive-soft px-5 py-2')}>
					{error}
				</p>
			) : null}
		</div>
	);
}
