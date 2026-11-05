import { __ } from '@wordpress/i18n';
import { LockIcon } from 'lucide-react';
import { ReadOnlyBanner, READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import type { ScriptsWriteReason } from './types';

/**
 * The read-only banner of the Scripts screen: the shared banner for file
 * mods, multisite and an unwritable theme, plus the scripts-only
 * `unfiltered_html` reason (spec §5).
 */
export function ScriptsReadOnlyBanner({ reason, path }: { reason: Exclude<ScriptsWriteReason, null> | 'not_writable'; path?: string }) {
	if (reason !== 'unfiltered_html') {
		return (
			<ReadOnlyBanner
				reason={reason}
				path={path}
				description={__('Scripts are listed but cannot be created, edited or deleted.', 'proto-blocks')}
			/>
		);
	}
	return (
		<Alert variant="warning" role="status" id={READ_ONLY_BANNER_ID}>
			<LockIcon aria-hidden="true" />
			<AlertTitle className="line-clamp-none">{__('Scripts are read-only for you', 'proto-blocks')}</AlertTitle>
			<AlertDescription>
				<p>
					{__('Only users who may post unfiltered HTML can edit scripts.', 'proto-blocks')}{' '}
					{__('Snippets run as-is on every page, so this needs the unfiltered_html capability (off on multisite for site admins and whenever DISALLOW_UNFILTERED_HTML is set).', 'proto-blocks')}
				</p>
			</AlertDescription>
		</Alert>
	);
}
