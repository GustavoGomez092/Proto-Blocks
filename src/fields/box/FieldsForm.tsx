/**
 * The React form inside one field box (a WordPress meta box).
 *
 * It is not a <form>: it lives inside the post form, and every input carries
 * a real name, so the ordinary submit saves it in every editor. Validation
 * runs on blur and when the surrounding form is submitted, never blocks the
 * submit, and mirrors the server exactly (value-schema.ts).
 *
 * Performance: inputs are uncontrolled (or control only themselves), each
 * row subscribes to its own error only, and conditional rows watch only
 * their controlling fields. A keystroke re-renders nothing else.
 */
import { Suspense, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CircleAlertIcon } from 'lucide-react';
import { get, useForm, useFormState, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { cn } from '@/admin/lib/utils';
import type { BoxPayload, FieldDef } from '../model/types';
import { isVisible } from '../validation/conditions';
import { buildValueSchema, type ValueSchemaOptions } from '../validation/value-schema';
import { domId, dottedPath, PRESENT_NAME, type FieldPath } from './names';
import { registerForm } from './editor-save';
import {
	AnnounceContext,
	Announcer,
	BoxContext,
	ChunkBoundary,
	FieldA11yContext,
	hasInput,
	inputFor,
	isGroupInput,
	registerInput,
	useBox,
	type BoxContextValue,
	type FieldA11y,
} from './registry';
import { TextareaInput, TextInput } from './inputs/TextInputs';
import { RadioInput, SelectInput } from './inputs/ChoiceInputs';
import { toBool, TrueFalseInput } from './inputs/TrueFalseInput';
import { ColorInput } from './inputs/ColorInput';
import { DateInput } from './inputs/DateInputs';
import { LinkInput } from './inputs/LinkInput';
import { CodeInput } from './inputs/CodeInput';
import { FieldFlow, MessageField } from './inputs/LayoutFields';

for ( const type of [ 'text', 'email', 'url', 'number', 'password' ] ) {
	registerInput( type, TextInput );
}
registerInput( 'textarea', TextareaInput );
registerInput( 'select', SelectInput, { group: ( f ) => f.declaredType === 'checkbox' } );
registerInput( 'radio', RadioInput, { group: () => true } );
registerInput( 'true_false', TrueFalseInput );
registerInput( 'color', ColorInput );
registerInput( 'date', DateInput );
registerInput( 'time', DateInput );
registerInput( 'datetime', DateInput );
registerInput( 'link', LinkInput );
registerInput( 'code', CodeInput );

const LAYOUT_ONLY = new Set( [ 'tab', 'accordion', 'message' ] );

/** Field widths (ACF wrapper.width, percent) on the 12-column grid; one column in narrow boxes. */
const SPANS: Record< number, string > = {
	1: '@min-[600px]:col-span-1',
	2: '@min-[600px]:col-span-2',
	3: '@min-[600px]:col-span-3',
	4: '@min-[600px]:col-span-4',
	5: '@min-[600px]:col-span-5',
	6: '@min-[600px]:col-span-6',
	7: '@min-[600px]:col-span-7',
	8: '@min-[600px]:col-span-8',
	9: '@min-[600px]:col-span-9',
	10: '@min-[600px]:col-span-10',
	11: '@min-[600px]:col-span-11',
	12: '',
};

function spanClass( field: FieldDef ): string {
	const wrapper = field.settings?.wrapper as { width?: unknown } | undefined;
	const width = Number( wrapper?.width );
	if ( ! Number.isFinite( width ) || width <= 0 || width >= 100 ) {
		return '';
	}
	return SPANS[ Math.min( 12, Math.max( 1, Math.round( ( width / 100 ) * 12 ) ) ) ];
}

function isValueField( f: FieldDef ): boolean {
	return f.name !== '' && ! LAYOUT_ONLY.has( f.type );
}

function isMultiple( f: FieldDef ): boolean {
	const m = f.settings?.multiple;
	return f.declaredType === 'checkbox' || ! ( m === undefined || m === null || m === false || m === 0 || m === '' || m === '0' );
}

/** A stored value in the shape its input works with. */
function initialValue( f: FieldDef, v: unknown ): unknown {
	const scalar = typeof v === 'string' || typeof v === 'number';
	switch ( f.type ) {
		case 'true_false':
			return toBool( v );
		case 'select':
		case 'radio':
			if ( f.type === 'select' && isMultiple( f ) ) {
				return Array.isArray( v ) ? v.map( String ) : scalar && v !== '' ? [ String( v ) ] : [];
			}
			return scalar ? String( v ) : '';
		case 'link': {
			const o = typeof v === 'string' ? { url: v } : v && typeof v === 'object' ? ( v as Record< string, unknown > ) : {};
			const s = ( x: unknown ) => ( typeof x === 'string' || typeof x === 'number' ? String( x ) : '' );
			return { url: s( o.url ), title: s( o.title ), target: s( o.target ) === '_blank' ? '_blank' : '' };
		}
		case 'text':
		case 'textarea':
		case 'number':
		case 'email':
		case 'url':
		case 'password':
		case 'color':
		case 'date':
		case 'time':
		case 'datetime':
		case 'code':
			return scalar ? String( v ) : '';
		default:
			return v ?? null;
	}
}

function initialValues( fields: FieldDef[], values: unknown ): Record< string, unknown > {
	const stored = values && typeof values === 'object' && ! Array.isArray( values ) ? ( values as Record< string, unknown > ) : {};
	const out: Record< string, unknown > = {};
	for ( const f of fields ) {
		if ( isValueField( f ) ) {
			out[ f.name ] = initialValue( f, Object.prototype.hasOwnProperty.call( stored, f.name ) ? stored[ f.name ] : f.default );
		}
	}
	return out;
}

/* ---------- rows ---------- */

type RowProps = { field: FieldDef; scope: FieldPath; siblings: FieldDef[] };

function RowBody( { field, scope, visible }: { field: FieldDef; scope: FieldPath; visible: boolean } ) {
	const { control, groupKey, payload } = useBox();
	const path = [ ...scope, field.name ];
	const name = dottedPath( path );
	// Code (or a container holding code) the user may not write: the server sent no value and a readOnly reason.
	const readOnly = ( field.type === 'code' && ! payload.canUnfiltered ) || ( scope.length === 0 && !! payload.readOnly?.[ field.name ] );
	const editable = hasInput( field.type ) && ! readOnly;
	const { errors } = useFormState( { control, name, exact: true } );
	const message = ( get( errors, name ) as { message?: string } | undefined )?.message;

	const id = domId( groupKey, path );
	const helpId = `${ id }-help`;
	const errorId = `${ id }-error`;
	const a11y = useMemo< FieldA11y >(
		() => ( {
			id,
			labelId: `${ id }-label`,
			describedBy: [ field.instructions ? helpId : '', message ? errorId : '' ].filter( Boolean ).join( ' ' ) || undefined,
			invalid: !! message,
			required: field.required,
		} ),
		[ id, helpId, errorId, field.instructions, message, field.required ]
	);
	const Input = inputFor( field.type );
	// A group (radios, checkbox list) is named by aria-labelledby; a `for` would point at a div.
	const Label = isGroupInput( field ) ? 'span' : 'label';

	return (
		<div
			hidden={ ! visible }
			data-field={ name }
			className={ cn( 'col-span-12', spanClass( field ), visible ? 'flex flex-col gap-1.5' : 'hidden' ) }
		>
			<Label id={ a11y.labelId } { ...( Label === 'label' ? { htmlFor: id } : {} ) } className="w-fit text-sm font-semibold text-[#1d2327]">
				{ field.label || field.name }
				{ field.required && (
					<>
						<span aria-hidden="true" className="ml-0.5 text-destructive">
							*
						</span>
						<span className="sr-only">{ __( '(required)', 'proto-blocks' ) }</span>
					</>
				) }
			</Label>
			{ field.instructions && (
				<p id={ helpId } className="text-[13px] text-muted-foreground">
					{ field.instructions }
				</p>
			) }
			<FieldA11yContext.Provider value={ a11y }>
				{ /* If the input fails to load, its present marker goes too, so the stored value is kept. */ }
				<ChunkBoundary>
					{ scope.length === 0 && visible && editable && <input type="hidden" name={ PRESENT_NAME( groupKey ) } value={ field.name } /> }
					<Suspense fallback={ <Skeleton className="h-9 w-full" /> }>
						<Input field={ field } path={ path } groupKey={ groupKey } readOnly={ readOnly } />
					</Suspense>
				</ChunkBoundary>
			</FieldA11yContext.Provider>
			{ message && (
				<p id={ errorId } role="alert" className="flex items-center gap-1.5 text-[13px] text-destructive">
					<CircleAlertIcon aria-hidden="true" className="size-4 shrink-0" />
					{ message }
				</p>
			) }
		</div>
	);
}

/** A row with conditional logic: watches only the sibling fields its rules read. */
function ConditionalRow( { field, scope, controllers }: { field: FieldDef; scope: FieldPath; controllers: FieldDef[] } ) {
	const { control } = useBox();
	const watched = useWatch( { control, name: controllers.map( ( c ) => dottedPath( [ ...scope, c.name ] ) ) } ) as unknown[];
	const byKey: Record< string, unknown > = {};
	controllers.forEach( ( c, i ) => {
		byKey[ c.key ] = watched[ i ] ?? null;
	} );
	return <RowBody field={ field } scope={ scope } visible={ isVisible( field, byKey ) } />;
}

function FieldRow( { field, scope, siblings }: RowProps ) {
	if ( field.type === 'message' ) {
		return <MessageField field={ field } />;
	}
	if ( ! isValueField( field ) ) {
		return null;
	}
	if ( field.conditions.length === 0 ) {
		return <RowBody field={ field } scope={ scope } visible />;
	}
	const keys = new Set( field.conditions.flat().map( ( r ) => String( r.field ) ) );
	const controllers = siblings.filter( ( s ) => isValueField( s ) && keys.has( s.key ) );
	if ( controllers.length === 0 ) {
		// Every rule reads a field outside this scope: never true (no outer-scope reads).
		return <RowBody field={ field } scope={ scope } visible={ isVisible( field, {} ) } />;
	}
	return <ConditionalRow field={ field } scope={ scope } controllers={ controllers } />;
}

/**
 * The fields of one scope: the box itself, or (for nested inputs) a group or
 * row, whose sibling fields are its conditions' scope.
 */
export function FieldList( { fields, scope, initialErrors }: { fields: FieldDef[]; scope: FieldPath; initialErrors?: Set< string > } ) {
	const renderField = ( f: FieldDef ): ReactNode => <FieldRow key={ f.key || f.name } field={ f } scope={ scope } siblings={ fields } />;
	return (
		<div className="grid grid-cols-12 gap-5">
			<FieldFlow fields={ fields } scope={ scope } renderField={ renderField } initialErrors={ initialErrors } />
		</div>
	);
}

/* ---------- server error summary ---------- */

function focusField( id: string ): void {
	const el = document.getElementById( id );
	if ( ! el ) {
		return;
	}
	el.closest( 'details' )?.setAttribute( 'open', '' );
	const panel = el.closest< HTMLElement >( '[role="tabpanel"]' );
	if ( panel && panel.dataset.state !== 'active' ) {
		document.getElementById( panel.getAttribute( 'aria-labelledby' ) ?? '' )?.focus();
	}
	window.setTimeout( () => el.focus(), 0 );
}

/**
 * A payload map entry for a field: by field key (BoxPayload keys choices and
 * maxRows by key), falling back to the field name.
 */
export function byField< T >( map: Record< string, T > | undefined, f: FieldDef ): T | undefined {
	if ( ! map ) {
		return undefined;
	}
	if ( f.key && Object.prototype.hasOwnProperty.call( map, f.key ) ) {
		return map[ f.key ];
	}
	return Object.prototype.hasOwnProperty.call( map, f.name ) ? map[ f.name ] : undefined;
}

/**
 * How the summary describes the save: 'saved' (posts, terms, users: the valid
 * fields were written) or 'not-saved' (options pages: a 422 writes nothing).
 */
export type SaveOutcome = 'saved' | 'not-saved';

function ServerErrorSummary( { errors, fields, outcome }: { errors: Record< string, string >; fields: FieldDef[]; outcome: SaveOutcome } ) {
	const { groupKey, payload } = useBox();
	const ref = useRef< HTMLDivElement >( null );
	useEffect( () => {
		ref.current?.focus();
	}, [] );
	const entries = Object.entries( errors );
	const count = entries.length;
	let title: string;
	if ( outcome === 'not-saved' ) {
		/* translators: %d: number of fields with errors */
		title = _n( 'Nothing was saved. %d field needs attention.', 'Nothing was saved. %d fields need attention.', count, 'proto-blocks' );
	} else if ( payload.object?.startsWith( 'post:' ) ) {
		/* translators: %d: number of fields with errors */
		title = _n( 'The post was saved, but %d field needs attention', 'The post was saved, but %d fields need attention', count, 'proto-blocks' );
	} else {
		/* translators: %d: number of fields with errors */
		title = _n( 'Saved, but %d field needs attention', 'Saved, but %d fields need attention', count, 'proto-blocks' );
	}
	const labelOf = ( path: string ) => {
		const top = fields.find( ( f ) => f.name === path.split( '.' )[ 0 ] );
		return top?.label || path;
	};
	return (
		<div ref={ ref } tabIndex={ -1 } className="mb-5 rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
		<Alert variant="destructive" className="border-destructive/40 bg-destructive-soft">
			<CircleAlertIcon aria-hidden="true" />
			<AlertTitle>{ sprintf( title, count ) }</AlertTitle>
			<AlertDescription>
				<p>{ __( 'Their previous values were kept.', 'proto-blocks' ) }</p>
				<ul className="flex flex-col gap-0.5">
					{ entries.map( ( [ path, message ] ) => {
						const id = domId( groupKey, path.split( '.' ) );
						return (
							<li key={ path }>
								<a
									href={ `#${ id }` }
									onClick={ ( e ) => {
										e.preventDefault();
										focusField( id );
									} }
									className="underline underline-offset-2"
								>
									{ labelOf( path ) }
								</a>
								{ ': ' }
								{ message }
							</li>
						);
					} ) }
				</ul>
			</AlertDescription>
		</Alert>
		</div>
	);
}

/* ---------- the form ---------- */

/**
 * `outcome` defaults from the payload's object: an options page (`option:`)
 * saves nothing when a field is invalid; every other object saves the valid
 * fields.
 */
export function FieldsForm( { payload, outcome }: { payload: BoxPayload; outcome?: SaveOutcome } ) {
	const saveOutcome: SaveOutcome = outcome ?? ( payload.object?.startsWith( 'option:' ) ? 'not-saved' : 'saved' );
	const { groupKey, fields, canUnfiltered } = payload;
	const [ serverErrors, setServerErrors ] = useState< Record< string, string > >( () =>
		payload.errors && typeof payload.errors === 'object' && ! Array.isArray( payload.errors ) ? payload.errors : {}
	);

	const resolver = useMemo( () => {
		const opts: ValueSchemaOptions = {
			maxRows: ( f ) => byField( payload.maxRows, f ) ?? null,
			choices: ( f ) => byField( payload.choices, f )?.map( ( c ) => String( c.key ) ) ?? null,
			// Never report what the user cannot change here.
			skip: ( f ) => ! hasInput( f.type ) || ( f.type === 'code' && ! canUnfiltered ) || !! payload.readOnly?.[ f.name ],
		};
		return zodResolver( buildValueSchema( fields, opts ) as never );
	}, [ fields, payload.maxRows, payload.choices, payload.readOnly, canUnfiltered ] );

	const defaultValues = useMemo( () => initialValues( fields, payload.values ), [ fields, payload.values ] );

	const { control, setError, clearErrors, setValue, trigger } = useForm< Record< string, unknown > >( {
		defaultValues,
		resolver,
		mode: 'onBlur',
		reValidateMode: 'onBlur',
		shouldFocusError: false,
	} );

	useEffect( () => {
		for ( const [ path, message ] of Object.entries( serverErrors ) ) {
			setError( path, { type: 'server', message } );
		}
	}, [ serverErrors, setError ] );

	// Errors read after a block editor meta box save (editor-save.ts).
	useEffect( () => {
		const own = new Set( fields.map( ( f ) => f.name ) );
		return registerForm( {
			groupKey,
			object: payload.object,
			restRoot: payload.restRoot,
			restNonce: payload.restNonce,
			applyServer: ( errors, submitted ) => {
				const mine: Record< string, string > = {};
				for ( const [ path, message ] of Object.entries( errors ) ) {
					if ( own.has( path.split( '.' )[ 0 ] ) ) {
						mine[ path ] = message;
					}
				}
				clearErrors();
				for ( const [ name, value ] of Object.entries( submitted ) ) {
					if ( own.has( name ) && Object.prototype.hasOwnProperty.call( mine, name ) ) {
						setValue( name, value, { shouldDirty: false, shouldValidate: false } );
					}
				}
				setServerErrors( mine );
			},
		} );
	}, [ fields, groupKey, payload.object, payload.restRoot, payload.restNonce, clearErrors, setValue ] );

	// Validate when the surrounding form is submitted, without ever blocking it.
	const root = useRef< HTMLDivElement >( null );
	useEffect( () => {
		const form = root.current?.closest( 'form' );
		if ( ! form ) {
			return undefined;
		}
		const onSubmit = () => {
			void trigger();
		};
		form.addEventListener( 'submit', onSubmit );
		return () => form.removeEventListener( 'submit', onSubmit );
	}, [ trigger ] );

	const box = useMemo< BoxContextValue >( () => ( { control, groupKey, payload } ), [ control, groupKey, payload ] );
	const initialErrors = useMemo( () => new Set( Object.keys( serverErrors ).map( ( p ) => p.split( '.' )[ 0 ] ) ), [ serverErrors ] );
	const announcer = useRef< ( message: string ) => void >( null );
	const announce = useCallback( ( message: string ) => announcer.current?.( message ), [] );

	return (
		<BoxContext.Provider value={ box }>
			<AnnounceContext.Provider value={ announce }>
				<div ref={ root } className="@container">
					{ Object.keys( serverErrors ).length > 0 && <ServerErrorSummary errors={ serverErrors } fields={ fields } outcome={ saveOutcome } /> }
					<FieldList fields={ fields } scope={ [] } initialErrors={ initialErrors } />
					{ /* One polite live region per box for row and list actions. */ }
					<Announcer ref={ announcer } />
				</div>
			</AnnounceContext.Provider>
		</BoxContext.Provider>
	);
}
