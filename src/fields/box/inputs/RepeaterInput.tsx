/**
 * repeater: numbered row cards, each holding the sub-fields under
 * `[repeater][i][sub]`. Rows are added (up to the row cap), removed, moved
 * up and down by keyboard or pointer, and collapsed (still mounted, so
 * every value submits). Indexes are re-numbered on every change, so the
 * submitted names are always contiguous. `min` rows exist from the start;
 * an empty repeater submits its key so clearing it is saved.
 *
 * The cap is the field's `max` or the site cap from the payload
 * (`proto_blocks_repeater_max_items`), whichever is lower.
 */
import { useEffect, useId, useRef, useState, type ReactNode, type RefObject } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ChevronDownIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { get, useFieldArray, useFormState, useWatch } from 'react-hook-form';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { cn } from '@/admin/lib/utils';
import type { FieldDef } from '../../model/types';
import { FieldList } from '../FieldsForm';
import { dottedPath, inputName, type FieldPath } from '../names';
import { EmptyMarker, useAnnounce, useBox, useFieldA11y, type FieldInputProps } from '../registry';
import { MoveButtons, refocusMoved, toId } from './MediaInputs';

/** Rows past this many start collapsed. */
const OPEN_ROWS = 20;

const LAYOUT_ONLY = new Set( [ 'tab', 'accordion', 'message' ] );

/** A new row: each sub-field's declared default, when it has one. */
export function emptyRow( fields: FieldDef[] ): Record< string, unknown > {
	const row: Record< string, unknown > = {};
	for ( const f of fields ) {
		if ( f.name !== '' && ! LAYOUT_ONLY.has( f.type ) && f.default !== null && f.default !== undefined ) {
			row[ f.name ] = f.default;
		}
	}
	return row;
}

/** The lower of the field's own `max` and the site cap; 0 = none. */
export function rowCap( field: FieldDef, siteCaps: Record< string, number > | undefined ): number {
	const own = toId( field.settings?.max );
	const site = toId( siteCaps?.[ field.name ] );
	return own && site ? Math.min( own, site ) : own || site;
}

export function rowCount( n: number, max: number ): string {
	return max
		? sprintf( /* translators: 1: rows, 2: maximum rows */ __( '%1$d of max %2$d rows', 'proto-blocks' ), n, max )
		: sprintf( /* translators: %d: rows */ _n( '%d row', '%d rows', n, 'proto-blocks' ), n );
}

/** The live title of a row from the sub-field named by ACF `collapsed` (a sub-field key). */
function RowTitle( { path, field }: { path: FieldPath; field: FieldDef } ) {
	const { control } = useBox();
	const value = useWatch( { control, name: dottedPath( [ ...path, field.name ] ) } );
	const text = typeof value === 'string' || typeof value === 'number' ? String( value ) : '';
	return <>{ text }</>;
}

export type RowCardProps = {
	rowKey: string;
	/** The row's dotted path; a row holding an invalid field opens itself. */
	errorPath: string;
	index: number;
	count: number;
	/** The row's name for its buttons, e.g. "row 2" or "section 2". */
	noun: ( n: number ) => string;
	badge: ReactNode;
	summary?: ReactNode;
	defaultCollapsed: boolean;
	onMove: ( from: number, to: number ) => void;
	onRemove: ( index: number ) => void;
	children: ReactNode;
};

/** One row card: header (number or layout badge, title, move, remove, collapse) and its fields. */
export function RowCard( { rowKey, errorPath, index, count, noun, badge, summary, defaultCollapsed, onMove, onRemove, children }: RowCardProps ) {
	const [ collapsed, setCollapsed ] = useState( defaultCollapsed );
	const { control } = useBox();
	const { errors } = useFormState( { control, name: errorPath } );
	const invalid = !! get( errors, errorPath );
	useEffect( () => {
		// Open a collapsed row when it holds an error (server errors on load, a submit attempt), so
		// the error summary link and the user can reach the field.
		if ( invalid ) {
			setCollapsed( false );
		}
	}, [ invalid ] );
	const uid = useId();
	const headId = `${ uid }-head`;
	const bodyId = `${ uid }-body`;
	const n = index + 1;
	const what = noun( n );
	return (
		<li data-row={ rowKey } aria-labelledby={ headId } className="rounded-md border border-border bg-background">
			<div className={ cn( 'flex items-center gap-2 px-2 py-1.5', ! collapsed && 'border-b border-border' ) }>
				<span id={ headId } className="flex min-w-0 flex-1 items-center gap-2">
					{ badge }
					<span className="truncate text-sm font-medium">{ summary }</span>
				</span>
				<MoveButtons
					index={ index }
					count={ count }
					label={ ( dir ) =>
						sprintf(
							dir < 0
								? /* translators: %s: e.g. "row 2" */ __( 'Move %s up', 'proto-blocks' )
								: /* translators: %s: e.g. "row 2" */ __( 'Move %s down', 'proto-blocks' ),
							what
						)
					}
					onMove={ onMove }
				/>
				<Button
					type="button"
					variant="ghost"
					size="icon-xs"
					data-remove=""
					aria-label={ sprintf( /* translators: %s: e.g. "row 2" */ __( 'Remove %s', 'proto-blocks' ), what ) }
					onClick={ () => onRemove( index ) }
				>
					<Trash2Icon aria-hidden="true" />
				</Button>
				<Button
					type="button"
					variant="ghost"
					size="icon-xs"
					aria-expanded={ ! collapsed }
					aria-controls={ bodyId }
					aria-label={ sprintf(
						collapsed
							? /* translators: %s: e.g. "row 2" */ __( 'Expand %s', 'proto-blocks' )
							: /* translators: %s: e.g. "row 2" */ __( 'Collapse %s', 'proto-blocks' ),
						what
					) }
					onClick={ () => setCollapsed( ( c ) => ! c ) }
				>
					<ChevronDownIcon aria-hidden="true" className={ cn( 'transition-transform motion-reduce:transition-none', ! collapsed && 'rotate-180' ) } />
				</Button>
			</div>
			{ /* Collapsed rows stay mounted so their values still submit. */ }
			<div id={ bodyId } hidden={ collapsed } className={ cn( 'p-4', collapsed && 'hidden' ) }>
				{ children }
			</div>
		</li>
	);
}

/** What the live region says after a row action; `kind` is "row" (repeater) or "section" (flexible content). */
export function rowMessages( kind: 'row' | 'section' ) {
	return kind === 'row'
		? {
				/* translators: 1: new position, 2: number of rows */
				moved: ( to: number, total: number ) => sprintf( __( 'Row moved to position %1$d of %2$d.', 'proto-blocks' ), to, total ),
				/* translators: %d: row number */
				removed: ( n: number ) => sprintf( __( 'Row %d removed.', 'proto-blocks' ), n ),
				/* translators: %d: row number */
				added: ( n: number ) => sprintf( __( 'Row %d added.', 'proto-blocks' ), n ),
		  }
		: {
				/* translators: 1: new position, 2: number of sections */
				moved: ( to: number, total: number ) => sprintf( __( 'Section moved to position %1$d of %2$d.', 'proto-blocks' ), to, total ),
				/* translators: %d: section number */
				removed: ( n: number ) => sprintf( __( 'Section %d removed.', 'proto-blocks' ), n ),
				/* translators: %d: section number */
				added: ( n: number ) => sprintf( __( 'Section %d added.', 'proto-blocks' ), n ),
		  };
}

/**
 * Row list behaviour shared by repeater and flexible content: move and
 * remove with focus kept in place, each announced in the box's live region.
 */
export function useRowActions(
	listRef: RefObject< HTMLOListElement | null >,
	addRef: RefObject< HTMLButtonElement | null >,
	ops: {
		kind: 'row' | 'section';
		count: number;
		move: ( from: number, to: number ) => void;
		remove: ( index: number ) => void;
		keyAt: ( index: number ) => string;
	}
) {
	const announce = useAnnounce();
	const messages = rowMessages( ops.kind );
	const onMove = ( from: number, to: number ) => {
		const key = ops.keyAt( from );
		ops.move( from, to );
		announce( messages.moved( to + 1, ops.count ) );
		refocusMoved( listRef.current, `[data-row="${ key }"]`, to - from );
	};
	const onRemove = ( index: number ) => {
		ops.remove( index );
		announce( messages.removed( index + 1 ) );
		window.setTimeout( () => {
			const removes = listRef.current?.querySelectorAll< HTMLButtonElement >( ':scope > li [data-remove]' );
			( removes?.[ Math.min( index, removes.length - 1 ) ] ?? addRef.current )?.focus();
		}, 0 );
	};
	const announceAdded = ( n: number ) => announce( messages.added( n ) );
	return { onMove, onRemove, announceAdded };
}

export function RepeaterInput( { field, path }: FieldInputProps ) {
	const { control, groupKey, payload } = useBox();
	const a11y = useFieldA11y();
	const { fields: rows, append, move, remove } = useFieldArray( { control, name: dottedPath( path ) as never } );
	const listRef = useRef< HTMLOListElement >( null );
	const addRef = useRef< HTMLButtonElement >( null );
	const max = rowCap( field, payload.maxRows );
	const min = toId( field.settings?.min );
	const full = max > 0 && rows.length >= max;
	const titleKey = field.settings?.collapsed;
	const titleField = typeof titleKey === 'string' && titleKey !== '' ? field.subFields.find( ( f ) => f.key === titleKey ) : undefined;
	const { onMove, onRemove, announceAdded } = useRowActions( listRef, addRef, {
		kind: 'row',
		count: rows.length,
		move,
		remove,
		keyAt: ( i ) => rows[ i ]?.id ?? '',
	} );

	useEffect( () => {
		if ( rows.length < min ) {
			const missing = ( max ? Math.min( min, max ) : min ) - rows.length;
			if ( missing > 0 ) {
				append( Array.from( { length: missing }, () => emptyRow( field.subFields ) ), { shouldFocus: false } );
			}
		}
		// Only the stored rows count, once, at mount.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const buttonLabel = typeof field.settings?.button_label === 'string' && field.settings.button_label !== '' ? field.settings.button_label : __( 'Add row', 'proto-blocks' );
	const noun = ( n: number ) => sprintf( /* translators: %d: row number */ __( 'row %d', 'proto-blocks' ), n );

	return (
		<div id={ a11y.id } role="group" aria-labelledby={ a11y.labelId } aria-describedby={ a11y.describedBy } className="flex flex-col gap-2">
			{ rows.length === 0 && <EmptyMarker name={ inputName( groupKey, path ) } /> }
			{ rows.length > 0 && (
				<ol ref={ listRef } className="flex flex-col gap-2">
					{ rows.map( ( row, i ) => (
						<RowCard
							key={ row.id }
							rowKey={ row.id }
							errorPath={ dottedPath( [ ...path, i ] ) }
							index={ i }
							count={ rows.length }
							noun={ noun }
							badge={ <Badge variant="outline">{ i + 1 }</Badge> }
							summary={ titleField ? <RowTitle path={ [ ...path, i ] } field={ titleField } /> : undefined }
							defaultCollapsed={ i >= OPEN_ROWS }
							onMove={ onMove }
							onRemove={ onRemove }
						>
							<FieldList fields={ field.subFields } scope={ [ ...path, i ] } />
						</RowCard>
					) ) }
				</ol>
			) }
			<div className="flex items-center gap-3">
				<Button
					ref={ addRef }
					type="button"
					variant="outline"
					size="sm"
					disabled={ full }
					onClick={ () => {
						append( emptyRow( field.subFields ) );
						announceAdded( rows.length + 1 );
					} }
				>
					<PlusIcon aria-hidden="true" />
					{ buttonLabel }
				</Button>
				<span className="text-[13px] text-muted-foreground">{ rowCount( rows.length, max ) }</span>
			</div>
		</div>
	);
}
