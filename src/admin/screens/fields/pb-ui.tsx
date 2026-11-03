/**
 * Builder-local class sets and small parts for the field-group editor,
 * on the wave A tokens (styles/tokens.css): navy `primary`, teal `ring`,
 * `border` / `input` / `divider` lines, `subtle` / `code` washes and the
 * text-* type scale. Controls that the shared kit already draws (Switch,
 * the segmented radio) are reused from components/ui.
 */
import { forwardRef, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { ChevronDownIcon } from 'lucide-react';
import { RadioGroup, SegmentedRadioItem, segmentedGroupClassName } from '@/admin/components/ui/radio-group';
import { cn } from '@/admin/lib/utils';

export { Switch as PbSwitch } from '@/admin/components/ui/switch';

export const SANS = 'font-sans';
export const MONO = 'font-mono';

const FOCUS = 'outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring';

/** 38 px text control, as ui/input draws it (for native selects and textareas). */
export const INPUT = cn(
	'h-[38px] min-h-0 w-full min-w-0 rounded-md border border-solid border-input bg-background px-3 py-0 text-body leading-normal text-foreground shadow-none outline-none placeholder:text-faint hover:border-[#b5bfcc] disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60',
	'focus:shadow-none focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-brand-soft',
	'aria-invalid:border-destructive aria-invalid:ring-[3px] aria-invalid:ring-destructive-soft'
);
export const TEXTAREA = cn(INPUT, 'h-auto min-h-16 resize-y py-2');
export const LABEL = 'text-label font-medium text-foreground';
export const HELP = 'text-caption leading-normal text-muted-foreground';
export const ERROR = 'text-caption leading-normal text-destructive';
export const EYEBROW = 'font-mono text-eyebrow leading-none font-medium tracking-[.08em] text-muted-foreground uppercase';
export const CODE = 'font-mono text-caption text-link';

const BTN = cn(
	'inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap no-underline disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:opacity-50',
	FOCUS
);
/** Primary navy button, 36 px (header / card footer). */
export const BTN_PRIMARY = cn(BTN, 'h-9 border-0 bg-primary px-3.5 text-body text-primary-foreground hover:bg-[#0b3a73] hover:text-primary-foreground');
/** Outlined secondary button, 32 px. */
export const BTN_SECONDARY = cn(BTN, 'h-8 border border-solid border-input bg-background px-3 text-label text-foreground hover:border-[#b5bfcc] hover:bg-subtle');
/** Quiet grey text button, 36 px (Discard). */
export const BTN_GHOST = cn(BTN, 'h-9 border-0 bg-transparent px-3 text-body text-muted-foreground hover:bg-primary-soft hover:text-primary');
/** Link-blue "+ and" button, 30 px. */
export const BTN_LINK = cn(BTN, 'h-[30px] w-fit border-0 bg-transparent px-2.5 text-label text-link hover:bg-code hover:text-link-hover');
/** Dashed "+ or, another rule group" button. */
export const BTN_DASHED = cn(BTN, 'h-8 w-fit border border-dashed border-input bg-background px-3 text-label text-muted-foreground hover:border-[#b5bfcc] hover:text-foreground');
/** 28 px square icon button. */
export const BTN_ICON = cn(BTN, 'size-7 rounded-[5px] border-0 bg-transparent p-0 text-muted-foreground hover:bg-code hover:text-primary');
export const BTN_ICON_DANGER = cn(BTN_ICON, 'hover:bg-destructive-soft hover:text-destructive');
export const BTN_DANGER = cn(BTN, 'h-[34px] border border-solid border-destructive/40 bg-background px-3 text-label text-destructive hover:bg-destructive-soft');

/** A rule card (conditions, location). */
export const RULE_CARD = 'grid gap-2.5 rounded-lg border border-solid border-border bg-background px-3.5 py-3';

/** Native select with the design's chevron: 38 px, or 36 px in rule rows. */
export const PbSelect = forwardRef<HTMLSelectElement, ComponentPropsWithoutRef<'select'> & { compact?: boolean }>(function PbSelect(
	{ className, compact = false, ...props },
	ref
) {
	return (
		<div className="relative min-w-0">
			<select ref={ref} {...props} className={cn(INPUT, 'max-w-none cursor-pointer appearance-none bg-none pr-9', compact && 'h-9 pr-8', className)} />
			<ChevronDownIcon aria-hidden="true" className={cn('pointer-events-none absolute size-3.5 text-muted-foreground', compact ? 'top-[11px] right-2.5' : 'top-3 right-3')} />
		</div>
	);
});

export interface SegmentedOption {
	value: string;
	label: ReactNode;
}

/** The wave A segmented radio ("Box | Seamless"): radio semantics, arrow keys move. */
export function Segmented({
	value,
	options,
	onChange,
	label,
	labelledBy,
	describedBy,
	disabled = false,
}: {
	value: string;
	options: SegmentedOption[];
	onChange: (value: string) => void;
	label?: string;
	labelledBy?: string;
	describedBy?: string;
	disabled?: boolean;
}) {
	return (
		<RadioGroup
			value={value}
			onValueChange={onChange}
			disabled={disabled}
			aria-label={label}
			aria-labelledby={labelledBy}
			aria-describedby={describedBy}
			className={segmentedGroupClassName}
		>
			{options.map((o) => (
				<SegmentedRadioItem key={o.value} value={o.value}>
					{o.label}
				</SegmentedRadioItem>
			))}
		</RadioGroup>
	);
}

/** A small count pill (primary-soft on navy). */
export function CountPill({ children }: { children: ReactNode }) {
	return <span className="rounded-full bg-primary-soft px-1.5 text-eyebrow leading-[18px] font-semibold text-primary">{children}</span>;
}
