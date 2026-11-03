/**
 * color: a native swatch picker plus the mono hex input that carries the
 * name. Controlled so the two stay in sync; only this field re-renders.
 */
import { __ } from '@wordpress/i18n';
import { useController } from 'react-hook-form';
import { Input } from '@/admin/components/ui/input';
import { dottedPath, inputName } from '../names';
import { DETACHED_FORM, useA11yProps, useBox, type FieldInputProps } from '../registry';

const HEX6 = /^#[0-9a-f]{6}$/i;
const HEX3 = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i;

/** The value as #rrggbb for the native picker, which accepts nothing else. */
function swatch( v: string ): string {
	if ( HEX6.test( v ) ) {
		return v.toLowerCase();
	}
	const m = HEX3.exec( v );
	return m ? `#${ m[ 1 ] }${ m[ 1 ] }${ m[ 2 ] }${ m[ 2 ] }${ m[ 3 ] }${ m[ 3 ] }`.toLowerCase() : '#000000';
}

export function ColorInput( { path }: FieldInputProps ) {
	const { control, groupKey } = useBox();
	const a11y = useA11yProps();
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const value = typeof ctl.value === 'string' ? ctl.value : '';
	return (
		<div className="flex items-center gap-2">
			<input
				type="color"
				form={ DETACHED_FORM }
				aria-label={ __( 'Pick a colour', 'proto-blocks' ) }
				value={ swatch( value ) }
				onChange={ ( e ) => ctl.onChange( e.target.value ) }
				onBlur={ ctl.onBlur }
				className="size-9 shrink-0 cursor-pointer rounded-md border border-input bg-transparent p-1"
			/>
			<Input
				{ ...a11y }
				ref={ ctl.ref }
				name={ inputName( groupKey, path ) }
				value={ value }
				onChange={ ( e ) => ctl.onChange( e.target.value ) }
				onBlur={ ctl.onBlur }
				placeholder="#rrggbb"
				spellCheck={ false }
				autoComplete="off"
				className="max-w-40 font-mono"
			/>
		</div>
	);
}
