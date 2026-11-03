import type { ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Eyebrow } from '@/admin/components/shell/Signals';

/** The Proto-Blocks mark: a navy tile with four blocks, one teal. */
export function LogoMark({ size = 44 }: { size?: number }) {
	return (
		<div
			aria-hidden="true"
			className="grid flex-none place-items-center bg-primary"
			style={{ width: size, height: size, borderRadius: Math.round(size * 0.23) }}
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

/**
 * Screen header: logo, a mono eyebrow ("Proto-Blocks · Section"), the
 * screen's only h1, a one-line description and optional actions. A hairline
 * separates it from the screen body.
 */
export function PageHeader({
	title,
	description,
	actions,
	eyebrow,
}: {
	title: string;
	description?: ReactNode;
	actions?: ReactNode;
	/** Section after "Proto-Blocks · ", e.g. "Diagnostics". Omit for the bare product name. */
	eyebrow?: string;
}) {
	return (
		<header className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-4 border-b pt-6 pb-5">
			<div className="flex min-w-0 items-center gap-4">
				<LogoMark />
				<div className="grid min-w-0 gap-0.5">
					<Eyebrow>
						{eyebrow
							? /* translators: %s: admin section or version, e.g. Diagnostics. */
								sprintf(__('Proto-Blocks · %s', 'proto-blocks'), eyebrow)
							: __('Proto-Blocks', 'proto-blocks')}
					</Eyebrow>
					<h1 className="text-title font-semibold text-balance text-foreground">{title}</h1>
					{description ? <p className="text-label text-muted-foreground">{description}</p> : null}
				</div>
			</div>
			{actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
		</header>
	);
}
