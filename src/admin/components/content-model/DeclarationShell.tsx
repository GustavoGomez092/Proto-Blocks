import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import type { ListItem } from '@/admin/content-model/types';

/**
 * Frame of a declaration edit screen: PageHeader with the file path and the
 * actions, the Phase 0 read-only banner when the file can't be written, then
 * the form. `file_mods`, `multisite` and `not_writable` get the banner; other reasons
 * (outside the theme, not a Proto-Blocks file) are explained by the screen.
 */
export function DeclarationShell({
	title,
	readOnlyReason,
	path,
	children,
	actions,
}: {
	title: string;
	readOnlyReason: ListItem['readOnlyReason'];
	/** The declaration file (or, for not_writable, the folder that can't be written). */
	path: string;
	children: ReactNode;
	actions?: ReactNode;
}) {
	const description = __(
		"You can browse everything, but changes can't be saved. Ask your developer to edit the files in the theme, or to allow file changes on this environment.",
		'proto-blocks'
	);

	return (
		<div className="grid gap-6">
			<PageHeader
				title={title}
				description={path ? <code className="font-mono text-[12.5px] break-all">{path}</code> : undefined}
				actions={actions}
			/>
			{readOnlyReason === 'file_mods' ? <ReadOnlyBanner reason="file_mods" description={description} /> : null}
			{readOnlyReason === 'multisite' ? <ReadOnlyBanner reason="multisite" description={description} /> : null}
			{readOnlyReason === 'not_writable' ? <ReadOnlyBanner reason="not_writable" path={path} description={description} /> : null}
			{children}
		</div>
	);
}
