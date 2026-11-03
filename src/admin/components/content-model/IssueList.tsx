import { __ } from '@wordpress/i18n';
import { CircleXIcon, TriangleAlertIcon } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import type { Issue } from '@/admin/content-model/types';

/**
 * Declaration problems reported by the server (broken files skipped,
 * declared twice, …): errors first in a danger alert, then warnings. Renders
 * nothing when there are none.
 */
export function IssueList({ issues }: { issues: Issue[] }) {
	const errors = issues.filter((issue) => issue.level === 'error');
	const warnings = issues.filter((issue) => issue.level !== 'error');

	if (issues.length === 0) {
		return null;
	}

	const list = (items: Issue[]) => (
		<ul className="grid w-full gap-1.5">
			{items.map((issue, i) => (
				<li key={`${issue.code}-${issue.source}-${i}`} className="grid gap-0.5">
					<span className="text-foreground">{issue.message}</span>
					{issue.source ? <code className="font-mono text-caption break-all text-muted-foreground">{issue.source}</code> : null}
				</li>
			))}
		</ul>
	);

	return (
		<div className="grid gap-3">
			{errors.length > 0 ? (
				<Alert variant="destructive">
					<CircleXIcon aria-hidden="true" />
					<AlertTitle className="line-clamp-none">
						{errors.length === 1
							? __('A declaration has a problem and was skipped', 'proto-blocks')
							: __('Some declarations have problems and were skipped', 'proto-blocks')}
					</AlertTitle>
					<AlertDescription>{list(errors)}</AlertDescription>
				</Alert>
			) : null}
			{warnings.length > 0 ? (
				<Alert variant="warning" role="status">
					<TriangleAlertIcon aria-hidden="true" />
					<AlertTitle className="line-clamp-none">{__('Check these declarations', 'proto-blocks')}</AlertTitle>
					<AlertDescription>{list(warnings)}</AlertDescription>
				</Alert>
			) : null}
		</div>
	);
}
