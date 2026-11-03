/**
 * date, time, datetime with the browser's pickers. The server stores
 * Y-m-d, H:i:s and Y-m-d H:i:s exactly (DateType), which native inputs do not
 * always produce (no seconds, a T separator), so the visible control has no
 * name and no form owner, and a hidden input submits the stored format.
 */
import { useController } from 'react-hook-form';
import { Input } from '@/admin/components/ui/input';
import { dottedPath, inputName } from '../names';
import { DETACHED_FORM, useA11yProps, useBox, type FieldInputProps } from '../registry';

const NATIVE: Record< string, string > = { date: 'date', time: 'time', datetime: 'datetime-local' };

/** Stored value → native input value. */
export function toNative( type: string, v: string ): string {
	return type === 'datetime' ? v.replace( ' ', 'T' ) : v;
}

/** Native input value → stored format. */
export function toStored( type: string, v: string ): string {
	if ( v === '' || type === 'date' ) {
		return v;
	}
	const [ date, time ] = type === 'datetime' ? v.split( 'T' ) : [ '', v ];
	const full = time && /^\d{2}:\d{2}$/.test( time ) ? `${ time }:00` : time ?? '';
	return type === 'datetime' ? `${ date } ${ full }` : full;
}

export function DateInput( { field, path }: FieldInputProps ) {
	const { control, groupKey } = useBox();
	const a11y = useA11yProps();
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const value = typeof ctl.value === 'string' ? ctl.value : '';
	return (
		<>
			<input type="hidden" name={ inputName( groupKey, path ) } value={ value } />
			<Input
				{ ...a11y }
				ref={ ctl.ref }
				type={ NATIVE[ field.type ] ?? 'date' }
				form={ DETACHED_FORM }
				step={ field.type === 'date' ? undefined : 1 }
				value={ toNative( field.type, value ) }
				onChange={ ( e ) => ctl.onChange( toStored( field.type, e.target.value ) ) }
				onBlur={ ctl.onBlur }
				className="w-auto min-w-44 tabular-nums"
			/>
		</>
	);
}
