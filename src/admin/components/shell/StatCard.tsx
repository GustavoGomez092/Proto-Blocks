import type { ReactNode } from 'react';
import { cn } from '@/admin/lib/utils';

/**
 * One figure in a status strip: 12.5px label, 30/600 tabular value and an
 * inline muted hint. Unboxed; the strip (a Panel with a grid and dividers)
 * draws the frame. `icon` is kept for API compatibility and shown muted.
 */
export function StatCard({
	label,
	value,
	hint,
	icon,
	className,
}: {
	label: string;
	value: ReactNode;
	hint?: ReactNode;
	icon?: ReactNode;
	className?: string;
}) {
	return (
		<div className={cn('min-w-0 px-6 py-[18px]', className)}>
			<div className="flex items-center gap-2 text-caption text-muted-foreground">
				{icon ? (
					<span aria-hidden="true" className="contents [&_svg]:size-3.5 [&_svg]:text-faint">
						{icon}
					</span>
				) : null}
				<p>{label}</p>
			</div>
			<div className="mt-1 flex min-w-0 items-baseline gap-2.5">
				<p className="text-stat font-semibold tabular-nums">{value}</p>
				{hint ? <p className="truncate text-label text-muted-foreground">{hint}</p> : null}
			</div>
		</div>
	);
}
