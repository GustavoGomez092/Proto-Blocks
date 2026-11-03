import type { ReactNode } from 'react';
import { cn } from '@/admin/lib/utils';

/** Mono uppercase overline: page eyebrows, panel kickers, group labels in tables. */
export function Eyebrow({
	children,
	tone = 'muted',
	className,
	id,
}: {
	children: ReactNode;
	/** muted (#5a6577), brand (teal ink #0b5f5a) or panel (on the dark panel). */
	tone?: 'muted' | 'brand' | 'panel';
	className?: string;
	id?: string;
}) {
	return (
		<div
			id={id}
			className={cn(
				'font-mono text-eyebrow font-medium uppercase',
				tone === 'brand' ? 'text-brand-ink' : tone === 'panel' ? 'text-faint' : 'text-muted-foreground',
				className
			)}
		>
			{children}
		</div>
	);
}

export type DotTone = 'brand' | 'primary' | 'neutral' | 'warning' | 'success' | 'danger';

const DOT: Record<DotTone, string> = {
	brand: 'bg-brand shadow-[0_0_0_2px_var(--brand-soft)]',
	primary: 'bg-primary shadow-[0_0_0_2px_var(--primary-soft)]',
	neutral: 'bg-input shadow-[0_0_0_2px_var(--muted)]',
	warning: 'bg-warning-border shadow-[0_0_0_2px_var(--warning-soft)]',
	success: 'bg-success shadow-[0_0_0_2px_var(--success-soft)]',
	danger: 'bg-destructive shadow-[0_0_0_2px_var(--destructive-soft)]',
};

/**
 * Origin / kind signal: an 8px dot with a soft halo and a label. Replaces
 * filled pills for categorical data (Tailwind vs Vanilla, file vs core).
 */
export function Dot({ tone, children, className }: { tone: DotTone; children?: ReactNode; className?: string }) {
	return (
		<span className={cn('inline-flex items-center gap-2 text-label', className)}>
			<span aria-hidden="true" className={cn('size-2 flex-none rounded-full', DOT[tone])} />
			{children}
		</span>
	);
}

/** Inline code chip: mono, link blue on the code wash. `muted` drops the wash for slugs and paths. */
export function CodeChip({
	children,
	muted = false,
	className,
}: {
	children: ReactNode;
	muted?: boolean;
	className?: string;
}) {
	return (
		<code
			className={cn(
				'font-mono text-micro',
				muted ? 'text-muted-foreground' : 'rounded-sm bg-code px-1.5 py-0.5 text-link',
				className
			)}
		>
			{children}
		</code>
	);
}
