import type { ReactNode } from 'react';
import { Card } from '@/admin/components/ui/card';

/** One figure with a label, an optional hint and an optional 36px icon tile. */
export function StatCard({
	label,
	value,
	hint,
	icon,
}: {
	label: string;
	value: ReactNode;
	hint?: ReactNode;
	icon?: ReactNode;
}) {
	return (
		<Card className="gap-0 p-5 shadow-xs">
			<div className="flex items-start justify-between gap-4">
				<div className="grid min-w-0 gap-1.5">
					<p className="text-[13px] text-muted-foreground">{label}</p>
					<p className="text-[28px] leading-none font-semibold tabular-nums">{value}</p>
					{hint ? <p className="truncate text-[13px] text-muted-foreground">{hint}</p> : null}
				</div>
				{icon ? (
					<div
						aria-hidden="true"
						className="grid size-9 flex-none place-items-center rounded-lg bg-secondary text-primary [&_svg]:size-[18px]"
					>
						{icon}
					</div>
				) : null}
			</div>
		</Card>
	);
}
