import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { LockIcon } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { interpolate } from '@/admin/lib/interpolate';
import type { FileModsConstant } from '@/admin/bootstrap';

/** Id screens point disabled controls at with aria-describedby. */
export const READ_ONLY_BANNER_ID = 'proto-blocks-read-only';

const code = (text: ReactNode) => <code className="font-mono text-[12.5px]">{text}</code>;

/**
 * Sits directly under PageHeader when the screen cannot write files. The
 * title says why; the description says what is off and what to do. Screens
 * pass `description` to name what they disable.
 */
export function ReadOnlyBanner({
	reason,
	path,
	constant,
	description,
}: {
	reason: 'file_mods' | 'multisite' | 'not_writable';
	path?: string;
	/** Constant to name for file_mods; defaults to the bootstrap's fileModsConstant. */
	constant?: FileModsConstant;
	description?: ReactNode;
}) {
	const fileModsConstant: FileModsConstant =
		constant ??
		(typeof window === 'undefined' ? undefined : window.protoBlocksAdmin?.fileModsConstant) ??
		'DISALLOW_FILE_MODS';

	const title =
		reason === 'file_mods'
			? __('File modifications are disabled on this site', 'proto-blocks')
			: reason === 'multisite'
				? __('Read-only on a multisite network', 'proto-blocks')
				: __('The theme folder is not writable', 'proto-blocks');

	const body =
		reason === 'multisite'
			? __('This is a multisite network; only network administrators can edit declaration files.', 'proto-blocks')
			: reason === 'file_mods'
			? interpolate(
					/* translators: %s: a wp-config.php constant, DISALLOW_FILE_MODS or DISALLOW_FILE_EDIT. */
					__('%s is set in wp-config.php, so controls that write files are turned off.', 'proto-blocks'),
					code(fileModsConstant)
				)
			: interpolate(
					/* translators: %s: absolute path of the theme folder. */
					__('The web server cannot write to %s, so controls that write files are turned off. Make the folder writable to turn them on.', 'proto-blocks'),
					code(path ?? '')
				);

	return (
		<Alert variant="warning" role="status" id={READ_ONLY_BANNER_ID}>
			<LockIcon aria-hidden="true" />
			<AlertTitle className="line-clamp-none">{title}</AlertTitle>
			<AlertDescription>
				<p>
					{body} {description ?? __('Everything else on this screen still works.', 'proto-blocks')}
				</p>
			</AlertDescription>
		</Alert>
	);
}
