import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/admin/lib/utils';

/**
 * The redesign's card: white, 1px #dde3ea border, 10px radius, no shadow.
 * Pass aria-labelledby (the PanelHeader title id) to make it a named region.
 */
export function Panel({ className, role, ...props }: ComponentProps<'div'>) {
	return (
		<div
			role={role ?? (props['aria-labelledby'] ? 'region' : undefined)}
			data-slot="panel"
			className={cn('min-w-0 rounded-xl border bg-card text-card-foreground', className)}
			{...props}
		/>
	);
}

/** Panel title row: 15/600 title, optional count, muted description and an aside on the right. */
export function PanelHeader({
	id,
	title,
	count,
	description,
	aside,
	divider = true,
	className,
}: {
	id: string;
	title: ReactNode;
	count?: ReactNode;
	description?: ReactNode;
	aside?: ReactNode;
	/** Hairline under the header (default). Off when rows below draw their own. */
	divider?: boolean;
	className?: string;
}) {
	return (
		<div
			className={cn(
				'flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-6 py-4',
				divider && 'border-b',
				className
			)}
		>
			<div className="grid min-w-0 gap-0.5">
				<div className="flex items-baseline gap-2.5">
					<h2 id={id} className="text-section font-semibold text-balance">
						{title}
					</h2>
					{count !== undefined ? (
						<span className="text-label text-muted-foreground tabular-nums">{count}</span>
					) : null}
				</div>
				{description ? <div className="text-label text-muted-foreground">{description}</div> : null}
			</div>
			{aside}
		</div>
	);
}

/** Two-column settings row: label + help on the left (220px), control on the right. */
export function SettingRow({
	label,
	help,
	children,
	className,
	labelId,
}: {
	label: ReactNode;
	help?: ReactNode;
	children: ReactNode;
	className?: string;
	labelId?: string;
}) {
	return (
		<div className={cn('grid gap-3 p-6 md:grid-cols-[220px_minmax(0,1fr)] md:gap-6', className)}>
			<div className="grid content-start gap-1">
				<p id={labelId} className="text-label font-medium">
					{label}
				</p>
				{help ? <div className="text-label text-muted-foreground">{help}</div> : null}
			</div>
			<div className="min-w-0">{children}</div>
		</div>
	);
}
