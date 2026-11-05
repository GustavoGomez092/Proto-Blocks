import { __ } from '@wordpress/i18n';
import { PageHeader } from '@/admin/components/shell/PageHeader';

/**
 * Placeholder until the redirects implementation lands
 * (docs/superpowers/specs/2026-11-05-redirects-design.md).
 */
export default function RedirectsScreen() {
	return (
		<div>
			<PageHeader title={__('Redirects', 'proto-blocks')} eyebrow={__('Content model', 'proto-blocks')} />
			<p className="text-muted-foreground">{__('Coming soon.', 'proto-blocks')}</p>
		</div>
	);
}
