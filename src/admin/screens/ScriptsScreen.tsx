import { __ } from '@wordpress/i18n';
import { PageHeader } from '@/admin/components/shell/PageHeader';

/**
 * Placeholder until the scripts implementation lands
 * (docs/superpowers/specs/2026-11-05-scripts-design.md).
 */
export default function ScriptsScreen() {
	return (
		<div>
			<PageHeader title={__('Scripts', 'proto-blocks')} eyebrow={__('Content model', 'proto-blocks')} />
			<p className="text-muted-foreground">{__('Coming soon.', 'proto-blocks')}</p>
		</div>
	);
}
