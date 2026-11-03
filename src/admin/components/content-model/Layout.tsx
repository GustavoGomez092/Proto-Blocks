import { useId, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { TriangleAlertIcon, XIcon } from 'lucide-react';
import { Panel } from '@/admin/components/shell/Panel';
import { Eyebrow } from '@/admin/components/shell/Signals';
import { Button } from '@/admin/components/ui/button';
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

/** Sticky aside: "What gets written", the file text a save would produce, and the commit reminder. */
export function FilePreview({
	name,
	text,
	label,
	panelLabel,
	onClose,
	note,
	className,
}: {
	/** File name, e.g. type.jsonc. */
	name: string;
	text: string;
	/** Accessible name of the <pre>. */
	label: string;
	/** Accessible name of the card. */
	panelLabel?: string;
	onClose?: () => void;
	note?: ReactNode;
	className?: string;
}) {
	return (
		<div className={cn('grid min-w-0 gap-3 xl:sticky xl:top-10', className)}>
			<Panel className="grid gap-3 px-5 py-4" aria-label={panelLabel}>
				<div className="flex items-center justify-between gap-2">
					<div className="flex min-w-0 items-baseline gap-2">
						<Eyebrow>{__('What gets written', 'proto-blocks')}</Eyebrow>
						<code className="truncate font-mono text-micro text-link">{name}</code>
					</div>
					{onClose ? (
						<Button
							type="button"
							variant="ghost"
							size="icon"
							className="-my-1 -me-2 size-7 text-muted-foreground"
							aria-label={__('Close the file preview', 'proto-blocks')}
							onClick={onClose}
						>
							<XIcon aria-hidden="true" />
						</Button>
					) : null}
				</div>
				<pre
					tabIndex={0}
					aria-label={label}
					className="m-0 max-h-[70vh] max-w-full overflow-auto rounded-md bg-muted px-3.5 py-3 font-mono text-micro leading-[1.65] break-all whitespace-pre-wrap text-foreground"
				>
					{text}
				</pre>
				{note ? <p className="text-caption text-muted-foreground">{note}</p> : null}
			</Panel>
			<p className="flex items-center gap-2.5 rounded-lg border border-warning-border bg-warning-soft px-4 py-3 text-caption text-warning">
				<TriangleAlertIcon aria-hidden="true" className="size-4 flex-none" />
				{__("Commit the file to your theme repo — it isn't in the database.", 'proto-blocks')}
			</p>
		</div>
	);
}
