import { __, sprintf } from '@wordpress/i18n';
import { TriangleAlertIcon } from 'lucide-react';
import { Dot, type DotTone } from '@/admin/components/shell/Signals';
import { Badge } from '@/admin/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/admin/components/ui/tooltip';
import type { Origin } from '@/admin/content-model/types';

/** Redesign origin signal: teal = Proto-Blocks file, navy = Proto-Blocks PHP, grey = core, amber = plugin or theme. */
export const ORIGIN_TONES: Record<Origin, DotTone> = {
	file: 'brand',
	php: 'primary',
	plugin: 'warning',
	theme: 'warning',
	core: 'neutral',
};

/** Warning badge "Declared twice"; its tooltip names the file and the PHP location (the file wins). */
export function DoubleBadge({ double }: { double: { file: string; php: string } }) {
	const twice = sprintf(
		/* translators: 1: path of the declaration file, 2: PHP file that also declares it. */
		__('Declared in %1$s and in %2$s. The file is used.', 'proto-blocks'),
		double.file,
		double.php
	);
	return (
		<Tooltip>
			<TooltipTrigger type="button" aria-label={twice} className="rounded-full">
				<Badge variant="warning">
					<TriangleAlertIcon aria-hidden="true" />
					{__('Declared twice', 'proto-blocks')}
				</Badge>
			</TooltipTrigger>
			<TooltipContent className="max-w-[360px] break-all">{twice}</TooltipContent>
		</Tooltip>
	);
}

/**
 * Where a declaration comes from (design guide 2.1): an origin dot plus the
 * server's `originLabel`, and DoubleBadge when it is declared twice.
 */
export function OriginBadge({
	origin,
	label,
	double = null,
}: {
	origin: Origin;
	label: string;
	double?: { file: string; php: string } | null;
}) {
	return (
		<span className="inline-flex flex-wrap items-center gap-x-2.5 gap-y-1">
			<Dot tone={ORIGIN_TONES[origin] ?? 'neutral'} className="whitespace-nowrap">
				{label}
			</Dot>
			{double ? <DoubleBadge double={double} /> : null}
		</span>
	);
}
