import type { ReactNode } from 'react';

/** The Proto-Blocks mark: a navy tile with four blocks, one teal. */
export function LogoMark({ size = 36 }: { size?: number }) {
	return (
		<div
			aria-hidden="true"
			className="grid flex-none place-items-center rounded-lg bg-primary"
			style={{ width: size, height: size }}
		>
			<svg width={size / 2} height={size / 2} viewBox="0 0 16 16">
				<rect x="1" y="1" width="6" height="6" rx="1.5" fill="#fff" />
				<rect x="9" y="1" width="6" height="6" rx="1.5" fill="#4ecdc4" />
				<rect x="1" y="9" width="6" height="6" rx="1.5" fill="#fff" />
				<rect x="9" y="9" width="6" height="6" rx="1.5" fill="#fff" opacity=".45" />
			</svg>
		</div>
	);
}

/** Screen header: logo, the screen's only h1, a one-line description and optional actions. */
export function PageHeader({
	title,
	description,
	actions,
}: {
	title: string;
	description?: ReactNode;
	actions?: ReactNode;
}) {
	return (
		<header className="flex flex-wrap items-center justify-between gap-4 pt-3 pb-6">
			<div className="flex min-w-0 items-center gap-3">
				<LogoMark />
				<div className="min-w-0">
					<h1 className="text-[22px] leading-tight font-semibold tracking-tight text-balance">{title}</h1>
					{description ? <p className="text-[13px] text-muted-foreground">{description}</p> : null}
				</div>
			</div>
			{actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
		</header>
	);
}
