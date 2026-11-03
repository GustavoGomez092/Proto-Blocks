/**
 * link: URL · link text · "Open in a new tab" in one row, submitted as
 * [url], [title] and [target] (LinkType). Uncontrolled.
 */
import { __ } from '@wordpress/i18n';
import { Input } from '@/admin/components/ui/input';
import { useA11yProps, useRegisteredInput, type FieldInputProps } from '../registry';

export function LinkInput( { path }: FieldInputProps ) {
	const url = useRegisteredInput( path, [ 'url' ] );
	const title = useRegisteredInput( path, [ 'title' ] );
	const target = useRegisteredInput( path, [ 'target' ] );
	const a11y = useA11yProps();
	return (
		<div className="flex flex-wrap items-center gap-2">
			<Input
				{ ...url }
				{ ...a11y }
				type="text"
				inputMode="url"
				autoComplete="off"
				spellCheck={ false }
				placeholder="https://"
				className="min-w-56 flex-[2]"
			/>
			<Input
				{ ...title }
				type="text"
				aria-label={ __( 'Link text', 'proto-blocks' ) }
				placeholder={ __( 'Link text', 'proto-blocks' ) }
				className="min-w-40 flex-1"
			/>
			<label className="flex items-center gap-2 text-sm whitespace-nowrap">
				{ /* Submits an empty target when unchecked; the checkbox (later in the form) wins. */ }
				<input type="hidden" name={ target.name } value="" />
				<input type="checkbox" { ...target } value="_blank" className="size-4 accent-primary" />
				{ __( 'Open in a new tab', 'proto-blocks' ) }
			</label>
		</div>
	);
}
