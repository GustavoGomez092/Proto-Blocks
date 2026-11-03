/**
 * true_false as a Switch. The Switch is a button, so the value travels in
 * hidden inputs: a `0` always, then a `1` while on (the last value wins on
 * the server), so turning it off is saved too.
 */
import { useController } from 'react-hook-form';
import { Switch } from '@/admin/components/ui/switch';
import { dottedPath, inputName } from '../names';
import { useBox, useFieldA11y, type FieldInputProps } from '../registry';

/** TrueFalseType::normalize(). */
export function toBool( v: unknown ): boolean {
	if ( typeof v === 'boolean' ) {
		return v;
	}
	if ( typeof v === 'number' ) {
		return ! Number.isNaN( v ) && v !== 0;
	}
	return typeof v === 'string' && [ '1', 'true', 'on', 'yes' ].includes( v.trim().toLowerCase() );
}

export function TrueFalseInput( { field, path }: FieldInputProps ) {
	const { control, groupKey } = useBox();
	const a11y = useFieldA11y();
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const on = toBool( ctl.value );
	const name = inputName( groupKey, path );
	const message = typeof field.settings?.message === 'string' ? field.settings.message : '';
	const messageId = `${ a11y.id }-message`;
	const describedBy = [ message ? messageId : '', a11y.describedBy ?? '' ].filter( Boolean ).join( ' ' ) || undefined;
	return (
		<div className="flex items-center gap-3">
			<input type="hidden" name={ name } value="0" />
			{ on && <input type="hidden" name={ name } value="1" /> }
			<Switch
				ref={ ctl.ref }
				id={ a11y.id }
				checked={ on }
				onCheckedChange={ ( checked ) => ctl.onChange( checked ) }
				onBlur={ ctl.onBlur }
				aria-invalid={ a11y.invalid || undefined }
				aria-required={ a11y.required || undefined }
				aria-describedby={ describedBy }
			/>
			{ message && (
				<span id={ messageId } className="text-sm text-foreground">
					{ message }
				</span>
			) }
		</div>
	);
}
