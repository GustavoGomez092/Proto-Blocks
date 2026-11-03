/**
 * code: raw markup printed as written. Users who cannot post unfiltered
 * HTML get a read-only, focusable `pre` with no name, and the field is never
 * marked present, so the stored value can neither change nor be lost.
 */
import { __ } from '@wordpress/i18n';
import { LockIcon, ShieldAlertIcon } from 'lucide-react';
import { useWatch } from 'react-hook-form';
import { Badge } from '@/admin/components/ui/badge';
import { cn } from '@/admin/lib/utils';
import { dottedPath } from '../names';
import { useA11yProps, useBox, useFieldA11y, useRegisteredInput, type FieldInputProps } from '../registry';
import { CONTROL_CLASS } from './TextInputs';

function ReadOnlyCode( { path }: { path: FieldInputProps[ 'path' ] } ) {
	const { control } = useBox();
	const a11y = useFieldA11y();
	const value = useWatch( { control, name: dottedPath( path ) } );
	const noteId = `${ a11y.id }-readonly`;
	return (
		<div className="flex flex-col gap-2">
			<pre
				id={ a11y.id }
				tabIndex={ 0 }
				role="textbox"
				aria-readonly="true"
				aria-multiline="true"
				aria-labelledby={ a11y.labelId }
				aria-describedby={ [ noteId, a11y.describedBy ].filter( Boolean ).join( ' ' ) }
				className="max-h-72 overflow-auto rounded-md border border-border bg-code px-3 py-2.5 font-mono text-label whitespace-pre-wrap break-all outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
			>
				{ typeof value === 'string' ? value : '' }
			</pre>
			<div id={ noteId } className="flex flex-col gap-1 text-caption text-muted-foreground">
				<Badge variant="outline" className="w-fit">
					<LockIcon aria-hidden="true" />
					{ __( 'Read-only for you', 'proto-blocks' ) }
				</Badge>
				<p>{ __( 'Only administrators who can post unfiltered HTML can change this.', 'proto-blocks' ) }</p>
				<p>{ __( 'This field isn’t sent when you save, so its value can’t be changed or lost.', 'proto-blocks' ) }</p>
			</div>
		</div>
	);
}

function EditableCode( { path }: { path: FieldInputProps[ 'path' ] } ) {
	const reg = useRegisteredInput( path );
	const a11y = useA11yProps();
	return (
		<div className="flex flex-col gap-1.5">
			<textarea
				{ ...reg }
				{ ...a11y }
				rows={ 8 }
				spellCheck={ false }
				autoComplete="off"
				className={ cn( CONTROL_CLASS, 'font-mono text-label' ) }
			/>
			<p className="flex items-center gap-1.5 text-caption text-muted-foreground">
				<ShieldAlertIcon aria-hidden="true" className="size-4" />
				{ __( 'Output exactly as written on the site.', 'proto-blocks' ) }
			</p>
		</div>
	);
}

export function CodeInput( { path, readOnly }: FieldInputProps ) {
	return readOnly ? <ReadOnlyCode path={ path } /> : <EditableCode path={ path } />;
}
