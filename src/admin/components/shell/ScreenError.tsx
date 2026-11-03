import { __, sprintf } from '@wordpress/i18n';
import { CircleAlertIcon, RotateCwIcon } from 'lucide-react';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { ApiError } from '@/admin/api';

/**
 * Shown when a screen cannot load: names the screen, gives the server's
 * message and status, and offers a retry when the caller can retry.
 */
export function ScreenError({
	screen,
	error,
	onRetry,
}: {
	/** Screen name as the user knows it, e.g. "System Status". */
	screen: string;
	error?: unknown;
	onRetry?: () => void;
}) {
	let detail: string = __('Check that the REST API is reachable, then try again.', 'proto-blocks');
	if (error instanceof ApiError && error.status > 0) {
		/* translators: 1: HTTP status code, 2: error message from the server. */
		detail = sprintf(__('The server answered %1$d: %2$s', 'proto-blocks'), error.status, error.message);
	} else if (error instanceof Error && error.message) {
		detail = error.message;
	}

	return (
		<Card role="alert" className="flex flex-col items-center gap-3 px-6 py-10 text-center shadow-xs">
			<div
				aria-hidden="true"
				className="grid size-11 place-items-center rounded-lg bg-destructive-soft text-destructive [&_svg]:size-[18px]"
			>
				<CircleAlertIcon />
			</div>
			<h2 className="text-[15px] font-semibold text-balance">
				{/* translators: %s: screen name, e.g. System Status. */}
				{sprintf(__("Couldn't load %s", 'proto-blocks'), screen)}
			</h2>
			<p className="max-w-[48ch] text-[13px] text-muted-foreground">{detail}</p>
			{onRetry ? (
				<Button variant="outline" size="sm" onClick={onRetry}>
					<RotateCwIcon aria-hidden="true" />
					{__('Try again', 'proto-blocks')}
				</Button>
			) : null}
		</Card>
	);
}
