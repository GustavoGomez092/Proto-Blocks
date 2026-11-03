/**
 * taxonomy: term IDs chosen from the terms the server pre-resolved into
 * `display` (no request). ACF `field_type` decides the control: `checkbox`
 * (default) a checklist with children indented, `radio` radios, `select` a
 * single select, `multi_select` a multiple select. Term names render as
 * text.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useController } from 'react-hook-form';
import { cn } from '@/admin/lib/utils';
import type { FieldDef } from '../../model/types';
import { dottedPath, inputName } from '../names';
import { EmptyMarker, useA11yProps, useBox, useDisplay, useFieldA11y, type FieldInputProps } from '../registry';
import { CONTROL_CLASS } from './TextInputs';
import { toId, toIds } from './MediaInputs';

type Term = { id: number; name: string; parent: number };

function fieldType( field: FieldDef ): string {
	const t = field.settings?.field_type;
	return typeof t === 'string' && [ 'checkbox', 'radio', 'select', 'multi_select' ].includes( t ) ? t : 'checkbox';
}

/** Checklists and radios are groups named by aria-labelledby. */
export function isTaxonomyGroup( field: FieldDef ): boolean {
	const t = fieldType( field );
	return t === 'checkbox' || t === 'radio';
}

/**
 * The term list for a field at `display[path]`: inline (`{terms: [...]}` or a
 * bare array, top-level fields), or a reference (`{terms: '<taxonomy>'}`,
 * nested fields) to the shared `display['terms:<taxonomy>']` list the server
 * sends once per taxonomy. A row added in the browser has no entry yet and
 * uses the shared list of the field's taxonomy.
 */
export function termList( field: FieldDef, own: unknown, display: Record< string, unknown > | undefined ): unknown {
	const shared = ( taxonomy: unknown ) =>
		typeof taxonomy === 'string' && taxonomy !== '' && display && Object.prototype.hasOwnProperty.call( display, `terms:${ taxonomy }` )
			? display[ `terms:${ taxonomy }` ]
			: undefined;
	if ( own && typeof own === 'object' && ! Array.isArray( own ) && typeof ( own as { terms?: unknown } ).terms === 'string' ) {
		return shared( ( own as { terms: string } ).terms );
	}
	return own === undefined ? shared( field.settings?.taxonomy ) : own;
}

/** Terms in tree order (each parent followed by its children), with their depth. */
function treeOrder( display: unknown ): Array< Term & { depth: number } > {
	const raw = Array.isArray( display )
		? display
		: display && typeof display === 'object' && Array.isArray( ( display as { terms?: unknown } ).terms )
			? ( display as { terms: unknown[] } ).terms
			: [];
	const terms: Term[] = [];
	for ( const t of raw ) {
		if ( ! t || typeof t !== 'object' ) {
			continue;
		}
		const o = t as Record< string, unknown >;
		const id = toId( o.id ?? o.term_id );
		const name = typeof o.name === 'string' ? o.name : '';
		if ( id && ! terms.some( ( x ) => x.id === id ) ) {
			terms.push( { id, name: name || String( id ), parent: toId( o.parent ) } );
		}
	}
	const ids = new Set( terms.map( ( t ) => t.id ) );
	const children = new Map< number, Term[] >();
	for ( const t of terms ) {
		const parent = ids.has( t.parent ) && t.parent !== t.id ? t.parent : 0;
		children.set( parent, [ ...( children.get( parent ) ?? [] ), t ] );
	}
	const out: Array< Term & { depth: number } > = [];
	const seen = new Set< number >();
	const walk = ( parent: number, depth: number ) => {
		for ( const t of children.get( parent ) ?? [] ) {
			if ( ! seen.has( t.id ) ) {
				seen.add( t.id );
				out.push( { ...t, depth } );
				walk( t.id, depth + 1 );
			}
		}
	};
	walk( 0, 0 );
	// Terms caught in a parent cycle still show, at the top level.
	terms.filter( ( t ) => ! seen.has( t.id ) ).forEach( ( t ) => out.push( { ...t, depth: 0 } ) );
	return out;
}

const CHECK_CLASS = 'size-4 shrink-0 accent-primary';

export function TaxonomyInput( { field, path }: FieldInputProps ) {
	const { control, groupKey, payload } = useBox();
	const a11y = useFieldA11y();
	const a11yProps = useA11yProps();
	const terms = treeOrder( termList( field, useDisplay( path ), payload.display as Record< string, unknown > | undefined ) );
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const value = toIds( ctl.value );
	const name = inputName( groupKey, path );
	const type = fieldType( field );
	const set = ( next: number[] ) => ctl.onChange( next );
	// Chosen terms the display list does not hold (deleted since, or past a cap): keep submitting them.
	const missing = value.filter( ( id ) => ! terms.some( ( t ) => t.id === id ) );
	const missingLabel = ( id: number ) => sprintf( /* translators: %d: term ID */ __( 'Term #%d', 'proto-blocks' ), id );

	if ( terms.length === 0 ) {
		return (
			<>
				{ value.map( ( id ) => (
					<input key={ id } type="hidden" name={ `${ name }[]` } value={ String( id ) } />
				) ) }
				{ value.length === 0 && <EmptyMarker name={ name } /> }
				<p id={ isTaxonomyGroup( field ) ? a11y.id : undefined } className="text-caption text-muted-foreground">
					{ __( 'No terms to choose from yet.', 'proto-blocks' ) }
				</p>
			</>
		);
	}

	if ( type === 'select' || type === 'multi_select' ) {
		const multiple = type === 'multi_select';
		return (
			<>
				{ multiple && value.length === 0 && <EmptyMarker name={ name } /> }
				<select
					{ ...a11yProps }
					ref={ ctl.ref }
					multiple={ multiple }
					name={ multiple ? `${ name }[]` : name }
					value={ multiple ? value.map( String ) : String( value[ 0 ] ?? '' ) }
					onChange={ ( e ) =>
						set( Array.from( e.target.selectedOptions, ( o ) => toId( o.value ) ).filter( Boolean ) )
					}
					onBlur={ ctl.onBlur }
					size={ multiple ? Math.min( Math.max( terms.length, 3 ), 8 ) : undefined }
					className={ cn( CONTROL_CLASS, ! multiple && 'h-[38px] py-1' ) }
				>
					{ ! multiple && <option value="">{ __( '— Select —', 'proto-blocks' ) }</option> }
					{ missing.map( ( id ) => (
						<option key={ `missing-${ id }` } value={ String( id ) }>
							{ missingLabel( id ) }
						</option>
					) ) }
					{ terms.map( ( t ) => (
						<option key={ t.id } value={ String( t.id ) }>
							{ '— '.repeat( t.depth ) + t.name }
						</option>
					) ) }
				</select>
			</>
		);
	}

	const radio = type === 'radio';
	return (
		<div
			id={ a11y.id }
			role={ radio ? 'radiogroup' : 'group' }
			aria-labelledby={ a11y.labelId }
			aria-describedby={ a11y.describedBy }
			aria-required={ ( radio && a11y.required ) || undefined }
			className="flex max-h-56 flex-col gap-0.5 overflow-auto rounded-lg border border-input bg-background p-1.5"
		>
			{ ( radio || value.length === 0 ) && <EmptyMarker name={ name } /> }
			{ ( radio ? missing.slice( 0, 1 ) : missing ).map( ( id ) => (
				<input key={ `missing-${ id }` } type="hidden" name={ radio ? name : `${ name }[]` } value={ String( id ) } />
			) ) }
			{ terms.map( ( t, i ) => (
				<label key={ t.id } className="flex min-h-8 cursor-pointer items-center gap-2 rounded-md pr-1.5 text-label hover:bg-subtle has-[:checked]:font-medium" style={ { paddingInlineStart: `${ 0.375 + t.depth * 1.25 }rem` } }>
					<input
						type={ radio ? 'radio' : 'checkbox' }
						ref={ i === 0 ? ctl.ref : undefined }
						name={ radio ? name : `${ name }[]` }
						value={ String( t.id ) }
						checked={ value.includes( t.id ) }
						onChange={ ( e ) => {
							if ( radio ) {
								set( [ t.id ] );
							} else {
								set( e.target.checked ? [ ...value.filter( ( v ) => v !== t.id ), t.id ] : value.filter( ( v ) => v !== t.id ) );
							}
						} }
						onBlur={ ctl.onBlur }
						aria-invalid={ a11y.invalid || undefined }
						className={ CHECK_CLASS }
					/>
					{ t.name }
				</label>
			) ) }
		</div>
	);
}
