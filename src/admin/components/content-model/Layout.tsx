import { useEffect, useId, useRef, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { TriangleAlertIcon } from 'lucide-react';
import { Panel } from '@/admin/components/shell/Panel';
import { Eyebrow } from '@/admin/components/shell/Signals';
import { TableCell, TableRow } from '@/admin/components/ui/table';
import { cn } from '@/admin/lib/utils';

/** "wp-content/themes/demo/proto-types/x/type.jsonc" → "proto-types/x/type.jsonc"; other paths stay as they are. */
export function themeRelative(path: string): string {
	return path.replace(/^wp-content\/themes\/[^/]+\//, '');
}

/** List-table name cell: the label over its mono slug. */
export function NameCell({ label, slug, children }: { label: ReactNode; slug: ReactNode; children?: ReactNode }) {
	return (
		<div className="grid gap-0.5">
			<span className="font-medium">{label}</span>
			<code className="font-mono text-micro break-all text-muted-foreground">{slug}</code>
			{children}
		</div>
	);
}

/** Group label row inside a list table ("Yours · 1", "WordPress core · 5"). */
export function TableGroupRow({
	label,
	colSpan,
	brand = false,
	first = false,
}: {
	label: string;
	colSpan: number;
	brand?: boolean;
	first?: boolean;
}) {
	return (
		<TableRow className="border-0 hover:bg-transparent">
			<TableCell colSpan={colSpan} className={cn('pt-3.5 pb-1.5', first ? 'pt-2.5' : 'border-t border-divider')}>
				<Eyebrow tone={brand ? 'brand' : 'muted'}>{label}</Eyebrow>
			</TableCell>
		</TableRow>
	);
}

/** Soft link-blue pill for attached types and location values. */
export function ValuePill({ children }: { children: ReactNode }) {
	return <span className="inline-flex rounded-full bg-code px-2 py-0.5 text-micro font-medium text-link">{children}</span>;
}

/** Dashed "nothing of your own yet" callout: plus tile, title, help and an optional action. */
export function EmptyCallout({ icon, title, children, action }: { icon: ReactNode; title: string; children?: ReactNode; action?: ReactNode }) {
	return (
		<div className="flex flex-wrap items-center gap-4 rounded-xl border border-dashed border-input bg-card px-5 py-4">
			<div aria-hidden="true" className="grid size-9 flex-none place-items-center rounded-lg bg-brand-soft text-brand-ink [&_svg]:size-4">
				{icon}
			</div>
			<div className="grid min-w-0 flex-1 gap-px">
				<h2 className="text-body font-medium">{title}</h2>
				{children ? <div className="text-label text-muted-foreground">{children}</div> : null}
			</div>
			{action}
		</div>
	);
}

/** One row of a declaration form: title and help on the left (220px), the controls on the right. */
export function FormSection({ id, title, description, children }: { id?: string; title: string; description?: ReactNode; children: ReactNode }) {
	const auto = useId();
	const headingId = id ? `${id}-title` : auto;
	return (
		<section id={id} aria-labelledby={headingId} className="grid gap-4 p-6 md:grid-cols-[220px_minmax(0,1fr)] md:gap-6">
			<div className="grid content-start gap-1">
				<h2 id={headingId} className="text-section font-semibold text-balance">
					{title}
				</h2>
				{description ? <div className="text-label text-muted-foreground">{description}</div> : null}
			</div>
			<div className="grid min-w-0 content-start gap-4">{children}</div>
		</section>
	);
}

/** The card the FormSections of one declaration sit in, divided by hairlines. */
export function FormPanel({ children, className }: { children: ReactNode; className?: string }) {
	return <Panel className={cn('divide-y', className)}>{children}</Panel>;
}

export type EditorView = 'editor' | 'jsonc';

/** Header segmented control shared by the declaration editors: "Editor | { } JSONC". */
export function ViewToggle({ view, onChange }: { view: EditorView; onChange: (view: EditorView) => void }) {
	const segment = (on: boolean) =>
		cn(
			'h-7 cursor-pointer rounded-sm border-0 px-3 text-label font-medium whitespace-nowrap outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
			on ? 'bg-primary text-primary-foreground' : 'bg-transparent text-muted-foreground hover:text-foreground'
		);
	return (
		<div role="group" aria-label={__('View', 'proto-blocks')} className="inline-flex rounded-md border border-solid border-input bg-background p-[3px]">
			<button type="button" className={segment(view === 'editor')} aria-pressed={view === 'editor'} onClick={() => onChange('editor')}>
				{__('Editor', 'proto-blocks')}
			</button>
			<button type="button" className={cn(segment(view === 'jsonc'), 'font-mono')} aria-pressed={view === 'jsonc'} onClick={() => onChange('jsonc')}>
				<span aria-hidden="true">{'{ } '}</span>
				{__('JSONC', 'proto-blocks')}
			</button>
		</div>
	);
}

/** The full-width JSONC view: the file path as eyebrow, the text unwrapped with a horizontal scroll. */
export function JsoncView({ path, text, label, note }: { path: string; text: string; label: string; note?: ReactNode }) {
	return (
		<section aria-label={__('JSONC', 'proto-blocks')} className="grid min-w-0 gap-3 overflow-hidden rounded-xl border border-solid border-border bg-card px-6 py-5">
			<code className="font-mono text-eyebrow leading-none font-medium tracking-[.08em] text-muted-foreground uppercase">{themeRelative(path)}</code>
			<pre
				tabIndex={0}
				aria-label={label}
				className="m-0 max-h-[70vh] max-w-full overflow-auto rounded-md bg-muted px-4 py-3 font-mono text-[13px] leading-[1.65] whitespace-pre text-foreground"
			>
				{text}
			</pre>
			{note ? <p className="text-caption text-muted-foreground">{note}</p> : null}
		</section>
	);
}

/** Cmd/Ctrl+S runs `onSave` (when given) in either view. */
export function useSaveShortcut(onSave: (() => void) | null) {
	const ref = useRef(onSave);
	ref.current = onSave;
	useEffect(() => {
		const onKey = (event: KeyboardEvent) => {
			if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 's') {
				event.preventDefault();
				ref.current?.();
			}
		};
		window.addEventListener('keydown', onKey);
		return () => window.removeEventListener('keydown', onKey);
	}, []);
}

/** Compact reminder above the form: the file belongs in the theme repo. */
export function CommitNotice() {
	return (
		<p className="flex items-center gap-2.5 rounded-lg border border-warning-border bg-warning-soft px-4 py-2.5 text-caption text-warning">
			<TriangleAlertIcon aria-hidden="true" className="size-4 flex-none" />
			{__("Commit the file to your theme repo — it isn't in the database.", 'proto-blocks')}
		</p>
	);
}
