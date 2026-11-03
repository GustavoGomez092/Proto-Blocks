import { __, sprintf } from '@wordpress/i18n';
import { BoxIcon, FileCodeIcon, FileJsonIcon, PaletteIcon, PlugIcon, TriangleAlertIcon, type LucideIcon } from 'lucide-react';
import { Badge } from '@/admin/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/admin/components/ui/tooltip';
import type { Origin } from '@/admin/content-model/types';

const ICONS: Record<Origin, LucideIcon> = {
	file: FileJsonIcon,
	php: FileCodeIcon,
	plugin: PlugIcon,
	theme: PaletteIcon,
	core: BoxIcon,
};

const VARIANTS: Record<Origin, 'default' | 'secondary' | 'outline'> = {
	file: 'default',
	php: 'secondary',
	plugin: 'outline',
	theme: 'outline',
	core: 'outline',
};

/**
 * Where a declaration comes from (design guide 2.1): icon plus the server's
 * `originLabel`. `double` adds a warning badge "Declared twice" whose tooltip
 * names the file and the PHP location; the file wins.
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
	const Icon = ICONS[origin] ?? BoxIcon;
	const twice = double
		? /* translators: 1: path of the declaration file, 2: PHP file that also declares it. */
			sprintf(__('Declared in %1$s and in %2$s. The file is used.', 'proto-blocks'), double.file, double.php)
		: '';

	return (
		<span className="inline-flex flex-wrap items-center gap-1.5">
			<Badge variant={VARIANTS[origin] ?? 'outline'}>
				<Icon aria-hidden="true" />
				{label}
			</Badge>
			{double ? (
				<Tooltip>
					<TooltipTrigger type="button" aria-label={twice} className="rounded-full">
						<Badge variant="outline" className="border-warning-border bg-warning-soft text-foreground [&>svg]:text-warning">
							<TriangleAlertIcon aria-hidden="true" />
							{__('Declared twice', 'proto-blocks')}
						</Badge>
					</TooltipTrigger>
					<TooltipContent className="max-w-[360px] break-all">{twice}</TooltipContent>
				</Tooltip>
			) : null}
		</span>
	);
}
