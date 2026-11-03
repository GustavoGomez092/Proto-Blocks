/**
 * relationship: an ordered list of post IDs. The selected list starts from
 * the server's pre-resolved `display` titles; "Add" opens the search dialog
 * from the lazy `fields-picker-relationship` chunk (searched server-side,
 * page by page). One hidden input per ID, in order. Titles render as text.
 */
import { lazy, Suspense, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { PlusIcon, XIcon } from 'lucide-react';
import { useController } from 'react-hook-form';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { dottedPath, inputName } from '../names';
import { EmptyMarker, useBox, useDisplay, useFieldA11y, type FieldInputProps } from '../registry';
import { MoveButtons, refocusMoved, toId, toIds } from './MediaInputs';

/** A post as the box shows it. */
export type PostItem = { id: number; title: string; type: string; status?: string };

const RelationshipPicker = lazy(
	() => import( /* webpackChunkName: "fields-picker-relationship" */ '../pickers/RelationshipPicker' )
);

/** Display entries (a list, or one entry for max 1) as PostItems. */
export function toPostItems( v: unknown ): PostItem[] {
	const list = Array.isArray( v ) ? v : v && typeof v === 'object' ? [ v ] : [];
	const out: PostItem[] = [];
	for ( const item of list ) {
		if ( ! item || typeof item !== 'object' ) {
			continue;
		}
		const o = item as Record< string, unknown >;
		const id = toId( o.id );
		if ( id ) {
			out.push( {
				id,
				title: typeof o.title === 'string' ? o.title : '',
				type: typeof o.type === 'string' ? o.type : '',
				status: typeof o.status === 'string' ? o.status : undefined,
			} );
		}
	}
	return out;
}

export function RelationshipInput( { field, path }: FieldInputProps ) {
	const { control, groupKey } = useBox();
	const a11y = useFieldA11y();
	const display = useDisplay( path );
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const ids = toIds( ctl.value );
	const [ known, setKnown ] = useState< Record< number, PostItem > >( () =>
		Object.fromEntries( toPostItems( display ).map( ( p ) => [ p.id, p ] ) )
	);
	const [ open, setOpen ] = useState( false );
	const listRef = useRef< HTMLOListElement >( null );
	const addRef = useRef< HTMLButtonElement >( null );
	const groupRef = useRef< HTMLDivElement >( null );
	const max = toId( field.settings?.max );
	const single = max === 1;
	const full = max > 1 && ids.length >= max;
	const name = inputName( groupKey, path );
	const fieldLabel = field.label || field.name;

	const titleOf = ( id: number ) =>
		known[ id ]?.title || sprintf( /* translators: %d: post ID */ __( '(no title) #%d', 'proto-blocks' ), id );

	const update = ( next: number[] ) => {
		ctl.onChange( next );
		ctl.onBlur();
	};

	/** Returns whether the picker should stay open. */
	const pick = ( item: PostItem ): boolean => {
		setKnown( ( k ) => ( { ...k, [ item.id ]: item } ) );
		if ( single ) {
			update( [ item.id ] );
			return false;
		}
		if ( ids.includes( item.id ) || full ) {
			return ! full;
		}
		const next = [ ...ids, item.id ];
		update( next );
		return max === 0 || next.length < max;
	};

	const move = ( from: number, to: number ) => {
		const next = [ ...ids ];
		const [ id ] = next.splice( from, 1 );
		next.splice( to, 0, id );
		update( next );
		refocusMoved( listRef.current, `[data-item="${ id }"]`, to - from );
	};

	const remove = ( index: number ) => {
		update( ids.filter( ( _, i ) => i !== index ) );
		window.setTimeout( () => {
			const removes = listRef.current?.querySelectorAll< HTMLButtonElement >( '[data-remove]' );
			( removes?.[ Math.min( index, removes.length - 1 ) ] ?? addRef.current )?.focus();
		}, 0 );
	};

	const returnFocus = () => {
		window.setTimeout( () => {
			const add = addRef.current;
			( add && ! add.disabled ? add : groupRef.current )?.focus();
		}, 0 );
	};

	const count = max
		? sprintf( /* translators: 1: items chosen, 2: maximum */ __( 'Selected · %1$d of %2$d', 'proto-blocks' ), ids.length, max )
		: sprintf( /* translators: %d: items chosen */ _n( 'Selected · %d', 'Selected · %d', ids.length, 'proto-blocks' ), ids.length );

	return (
		<div
			ref={ groupRef }
			id={ a11y.id }
			role="group"
			tabIndex={ -1 }
			aria-labelledby={ a11y.labelId }
			aria-describedby={ a11y.describedBy }
			className="flex flex-col gap-2 outline-none"
		>
			{ ids.length === 0 && <EmptyMarker name={ name } /> }
			{ ids.map( ( id ) => (
				<input key={ id } type="hidden" name={ `${ name }[]` } value={ String( id ) } />
			) ) }
			{ ids.length > 0 && (
				<div className="rounded-md border border-border">
					<p className="border-b border-border bg-muted px-3 py-1.5 text-[13px] text-muted-foreground">{ count }</p>
					<ol
						ref={ listRef }
						aria-label={ sprintf( /* translators: %s: field label */ __( '%s, in order', 'proto-blocks' ), fieldLabel ) }
						className="flex flex-col text-sm"
					>
						{ ids.map( ( id, i ) => {
							const title = titleOf( id );
							return (
								<li key={ id } data-item={ id } className="flex items-center gap-2 border-b border-border px-3 py-1.5 last:border-b-0">
									<span className="min-w-0 flex-1 truncate">{ title }</span>
									{ known[ id ]?.type && <Badge variant="secondary">{ known[ id ].type }</Badge> }
									{ ! single && (
										<MoveButtons
											index={ i }
											count={ ids.length }
											label={ ( dir ) =>
												sprintf(
													dir < 0
														? /* translators: %s: post title */ __( 'Move %s up', 'proto-blocks' )
														: /* translators: %s: post title */ __( 'Move %s down', 'proto-blocks' ),
													title
												)
											}
											onMove={ move }
										/>
									) }
									<Button
										type="button"
										variant="ghost"
										size="icon-xs"
										data-remove=""
										aria-label={ sprintf( /* translators: %s: post title */ __( 'Remove %s', 'proto-blocks' ), title ) }
										onClick={ () => remove( i ) }
									>
										<XIcon aria-hidden="true" />
									</Button>
								</li>
							);
						} ) }
					</ol>
				</div>
			) }
			<div className="flex items-center gap-2">
				<Button
					ref={ addRef }
					type="button"
					variant="outline"
					size="sm"
					disabled={ full }
					aria-haspopup="dialog"
					onFocus={ preloadPicker }
					onClick={ () => setOpen( true ) }
				>
					<PlusIcon aria-hidden="true" />
					{ single && ids.length > 0 ? __( 'Replace', 'proto-blocks' ) : __( 'Add', 'proto-blocks' ) }
				</Button>
				{ full && (
					<span className="text-[13px] text-muted-foreground">
						{ sprintf( /* translators: %d: maximum */ __( 'Up to %d.', 'proto-blocks' ), max ) }
					</span>
				) }
			</div>
			{ open && (
				<Suspense fallback={ null }>
					<RelationshipPicker
						groupKey={ groupKey }
						fieldName={ field.name }
						title={ fieldLabel }
						max={ max }
						selected={ ids }
						onPick={ ( item ) => {
							if ( ! pick( item ) ) {
								setOpen( false );
							}
						} }
						onClose={ () => setOpen( false ) }
						onReturnFocus={ returnFocus }
					/>
				</Suspense>
			) }
		</div>
	);
}

/** Warms the picker chunk when the Add button takes focus; nothing loads before then. */
function preloadPicker(): void {
	void import( /* webpackChunkName: "fields-picker-relationship" */ '../pickers/RelationshipPicker' );
}
