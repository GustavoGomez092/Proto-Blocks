/**
 * select (single, multiple, and ACF checkbox lists) and radio.
 *
 * Single values are uncontrolled native controls. Multi-value controls are
 * controlled through useController so that, when nothing is chosen, they can
 * render the hidden empty input that still submits the key (otherwise
 * clearing every choice would look like "not sent" and keep the old value).
 * Each re-renders only itself.
 */
import { __ } from '@wordpress/i18n';
import { useController } from 'react-hook-form';
import { cn } from '@/admin/lib/utils';
import type { FieldDef } from '../../model/types';
import { dottedPath, inputName } from '../names';
import { useA11yProps, useBox, useFieldA11y, useRegisteredInput, type FieldInputProps } from '../registry';
import { CONTROL_CLASS } from './TextInputs';

type Option = { key: string; label: string };

function truthy( v: unknown ): boolean {
	return ! ( v === undefined || v === null || v === false || v === 0 || v === '' || v === '0' );
}

/** Static choices (ACF map or list), or the server-resolved ones for an optionsSource field. */
export function optionsFor( field: FieldDef, resolved: Record< string, Option[] > ): Option[] {
	const source = field.settings?.optionsSource;
	if ( source !== undefined && source !== null ) {
		return resolved?.[ field.name ] ?? [];
	}
	const choices = field.settings?.choices;
	if ( Array.isArray( choices ) ) {
		return choices.map( ( c ) => ( { key: String( c ), label: String( c ) } ) );
	}
	if ( choices && typeof choices === 'object' ) {
		return Object.entries( choices as Record< string, unknown > ).map( ( [ key, label ] ) => ( {
			key,
			label: typeof label === 'string' || typeof label === 'number' ? String( label ) : key,
		} ) );
	}
	return [];
}

function useOptions( field: FieldDef ): Option[] {
	return optionsFor( field, useBox().payload.choices );
}

export function SelectInput( props: FieldInputProps ) {
	const { field } = props;
	if ( field.declaredType === 'checkbox' ) {
		return <CheckboxList { ...props } />;
	}
	return truthy( field.settings?.multiple ) ? <MultiSelect { ...props } /> : <SingleSelect { ...props } />;
}

function SingleSelect( { field, path }: FieldInputProps ) {
	const reg = useRegisteredInput( path );
	const a11y = useA11yProps();
	const options = useOptions( field );
	return (
		<select { ...reg } { ...a11y } className={ cn( CONTROL_CLASS, 'h-9 py-1' ) }>
			<option value="">{ field.placeholder || __( '— Select —', 'proto-blocks' ) }</option>
			{ options.map( ( o ) => (
				<option key={ o.key } value={ o.key }>
					{ o.label }
				</option>
			) ) }
		</select>
	);
}

function asList( v: unknown ): string[] {
	if ( Array.isArray( v ) ) {
		return v.map( String );
	}
	return v === undefined || v === null || v === '' ? [] : [ String( v ) ];
}

/** The hidden input that submits an empty multi-value field's key. */
function EmptyMarker( { name }: { name: string } ) {
	return <input type="hidden" name={ name } value="" />;
}

function MultiSelect( { field, path }: FieldInputProps ) {
	const { control, groupKey } = useBox();
	const a11y = useA11yProps();
	const options = useOptions( field );
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const value = asList( ctl.value );
	const name = inputName( groupKey, path );
	return (
		<>
			{ value.length === 0 && <EmptyMarker name={ name } /> }
			<select
				{ ...a11y }
				ref={ ctl.ref }
				multiple
				name={ `${ name }[]` }
				value={ value }
				onChange={ ( e ) => ctl.onChange( Array.from( e.target.selectedOptions, ( o ) => o.value ) ) }
				onBlur={ ctl.onBlur }
				size={ Math.min( Math.max( options.length, 3 ), 8 ) }
				className={ CONTROL_CLASS }
			>
				{ options.map( ( o ) => (
					<option key={ o.key } value={ o.key }>
						{ o.label }
					</option>
				) ) }
			</select>
		</>
	);
}

const CHECK_CLASS = 'size-4 shrink-0 accent-primary';

function CheckboxList( { field, path }: FieldInputProps ) {
	const { control, groupKey } = useBox();
	const a11y = useFieldA11y();
	const options = useOptions( field );
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const value = asList( ctl.value );
	const name = inputName( groupKey, path );
	const toggle = ( key: string, on: boolean ) =>
		ctl.onChange( on ? [ ...value.filter( ( v ) => v !== key ), key ] : value.filter( ( v ) => v !== key ) );
	return (
		<div
			id={ a11y.id }
			role="group"
			aria-labelledby={ a11y.labelId }
			aria-describedby={ a11y.describedBy }
			className="flex flex-col gap-2"
		>
			{ value.length === 0 && <EmptyMarker name={ name } /> }
			{ options.map( ( o, i ) => (
				<label key={ o.key } className="flex items-center gap-2 text-sm">
					<input
						type="checkbox"
						ref={ i === 0 ? ctl.ref : undefined }
						name={ `${ name }[]` }
						value={ o.key }
						checked={ value.includes( o.key ) }
						onChange={ ( e ) => toggle( o.key, e.target.checked ) }
						onBlur={ ctl.onBlur }
						aria-invalid={ a11y.invalid || undefined }
						className={ CHECK_CLASS }
					/>
					{ o.label }
				</label>
			) ) }
		</div>
	);
}

export function RadioInput( { field, path }: FieldInputProps ) {
	const reg = useRegisteredInput( path );
	const a11y = useFieldA11y();
	const options = useOptions( field );
	const vertical = field.settings?.layout === 'vertical';
	return (
		<div
			id={ a11y.id }
			role="radiogroup"
			aria-labelledby={ a11y.labelId }
			aria-describedby={ a11y.describedBy }
			aria-invalid={ a11y.invalid || undefined }
			className={ cn( 'flex gap-x-5 gap-y-2', vertical ? 'flex-col' : 'flex-wrap' ) }
		>
			{ /* Submits the key when no option is checked; a checked radio (later in the form) wins. */ }
			<EmptyMarker name={ reg.name } />
			{ options.map( ( o ) => (
				<label key={ o.key } className="flex items-center gap-2 text-sm">
					<input type="radio" { ...reg } value={ o.key } className={ CHECK_CLASS } />
					{ o.label }
				</label>
			) ) }
		</div>
	);
}
