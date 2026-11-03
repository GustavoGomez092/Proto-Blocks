/**
 * The field box's input registry and the contexts inputs read.
 *
 * FieldsForm registers the scalar inputs; later phases add theirs with
 * registerInput() (media, relational and nested inputs, possibly as
 * React.lazy components; FieldsForm renders them inside Suspense).
 * A type with no registered input renders a note and is never marked
 * present, so its stored value is kept on save.
 */
import { createContext, useContext, type ChangeEvent, type ComponentType, type FocusEvent, type LazyExoticComponent } from 'react';
import { __ } from '@wordpress/i18n';
import type { Control } from 'react-hook-form';
import type { BoxPayload, FieldDef } from '../model/types';
import { dottedPath, inputName, type FieldPath } from './names';

export type FieldInputProps = { field: FieldDef; path: FieldPath; groupKey: string; readOnly?: boolean };
export type FieldInput = ComponentType< FieldInputProps >;

export type InputOptions = {
	/**
	 * True when the input is a group of controls (radios, a checkbox list)
	 * labelled through aria-labelledby; the row label then has no `for`.
	 */
	group?: ( field: FieldDef ) => boolean;
};

const inputs = new Map< string, FieldInput | LazyExoticComponent< FieldInput > >();
const options = new Map< string, InputOptions >();

/** Registers (or replaces) the input component for a field type. */
export function registerInput(
	type: string,
	component: FieldInput | LazyExoticComponent< FieldInput >,
	opts: InputOptions = {}
): void {
	inputs.set( type, component );
	options.set( type, opts );
}

/** Whether the field's input is a labelled group rather than one labelable control. */
export function isGroupInput( field: FieldDef ): boolean {
	return options.get( field.type )?.group?.( field ) ?? false;
}

export function hasInput( type: string ): boolean {
	return inputs.has( type );
}

function UnsupportedInput() {
	return <p className="text-[13px] text-muted-foreground">{ __( 'This field can’t be edited here yet.', 'proto-blocks' ) }</p>;
}

export function inputFor( type: string ): FieldInput | LazyExoticComponent< FieldInput > {
	return inputs.get( type ) ?? UnsupportedInput;
}

/* ---------- contexts ---------- */

export type BoxContextValue = {
	control: Control< Record< string, unknown > >;
	groupKey: string;
	payload: BoxPayload;
};

export const BoxContext = createContext< BoxContextValue | null >( null );

export function useBox(): BoxContextValue {
	const box = useContext( BoxContext );
	if ( ! box ) {
		throw new Error( 'Field inputs must render inside FieldsForm.' );
	}
	return box;
}

/** Ids and state the field row hands its input, for labels and messages. */
export type FieldA11y = { id: string; labelId: string; describedBy: string | undefined; invalid: boolean; required: boolean };

export const FieldA11yContext = createContext< FieldA11y >( {
	id: '',
	labelId: '',
	describedBy: undefined,
	invalid: false,
	required: false,
} );

export function useFieldA11y(): FieldA11y {
	return useContext( FieldA11yContext );
}

/** Props that wire an input's ARIA state to its row. */
export function useA11yProps() {
	const a11y = useFieldA11y();
	return {
		id: a11y.id,
		'aria-invalid': a11y.invalid || undefined,
		'aria-required': a11y.required || undefined,
		'aria-describedby': a11y.describedBy,
	};
}

/**
 * An uncontrolled native input registered with react-hook-form under its
 * dotted path while its DOM `name` is the real form name. RHF finds a field
 * by `event.target.name`, so events are re-addressed to the dotted path; it
 * then reads the value from the registered element itself.
 */
export function useRegisteredInput( path: FieldPath, sub: ( string | number )[] = [] ) {
	const { control, groupKey } = useBox();
	const full = [ ...path, ...sub ];
	const name = dottedPath( full );
	const reg = control.register( name );
	const forward =
		( handler: ( e: unknown ) => Promise< void | boolean > ) =>
		( e: ChangeEvent< HTMLElement > | FocusEvent< HTMLElement > ) => {
			const target = e.target as HTMLInputElement;
			return handler( { type: e.type, target: { name, type: target.type, value: target.value, checked: target.checked } } );
		};
	return {
		ref: reg.ref,
		name: inputName( groupKey, full ),
		onChange: forward( reg.onChange ),
		onBlur: forward( reg.onBlur ),
	};
}

/** Associates a control with no form owner, so browser constraint validation can never block the post form. */
export const DETACHED_FORM = 'proto-blocks-fields-detached';
