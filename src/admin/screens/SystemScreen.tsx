import { __ } from '@wordpress/i18n';
import { PageHeader } from '@/admin/components/shell/PageHeader';

/** Placeholder; Task 3 builds the System Status screen. */
export default function SystemScreen() {
	return (
		<PageHeader
			title={__('System Status', 'proto-blocks')}
			description={__('Plugin settings, wp-config constants and server details.', 'proto-blocks')}
		/>
	);
}
