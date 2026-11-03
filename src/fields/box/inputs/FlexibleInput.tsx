/**
 * flexible_content: row cards labelled by their layout, each submitting a
 * hidden `[field][i][_layout]` with the layout name plus that layout's
 * sub-fields. "Add section" opens a menu of the layouts (a layout at its own
 * `max` is disabled). Rows move, collapse and are removed like repeater
 * rows; the same row cap applies.
 *
 * In the form state a row keeps its layout under `layout`, the key the
 * validator (client and server) reads.
 */
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ChevronDownIcon, PlusIcon } from 'lucide-react';
import { useFieldArray } from 'react-hook-form';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import type { LayoutDef } from '../../model/types';
import { FieldList } from '../FieldsForm';
import { dottedPath, inputName, LAYOUT_KEY } from '../names';
import { EmptyMarker, useBox, useFieldA11y, type FieldInputProps } from '../registry';
import { toId } from './MediaInputs';
import { emptyRow, RowCard, rowCap, rowCount, useRowActions } from './RepeaterInput';

function layoutOf( row: unknown ): string {
	const name = row && typeof row === 'object' ? ( row as { layout?: unknown } ).layout : undefined;
	return typeof name === 'string' ? name : '';
}

export function FlexibleInput( { field, path }: FieldInputProps ) {
	const { control, groupKey, payload } = useBox();
	const a11y = useFieldA11y();
	const { fields: rows, append, move, remove } = useFieldArray( { control, name: dottedPath( path ) as never } );
	const listRef = useRef< HTMLOListElement >( null );
	const addRef = useRef< HTMLButtonElement >( null );
	const menuRef = useRef< HTMLDivElement >( null );
	const [ menuOpen, setMenuOpen ] = useState( false );
	const menuId = useId();
	const max = rowCap( field, payload.maxRows );
	const full = max > 0 && rows.length >= max;
	const { onMove, onRemove } = useRowActions( listRef, addRef, { move, remove, keyAt: ( i ) => rows[ i ]?.id ?? '' } );

	const layouts = field.layouts;
	const used = ( l: LayoutDef ) => rows.filter( ( r ) => layoutOf( r ) === l.name ).length;
	const layoutFull = ( l: LayoutDef ) => {
		const lmax = toId( l.max );
		return lmax > 0 && used( l ) >= lmax;
	};

	useEffect( () => {
		if ( menuOpen ) {
			menuRef.current?.querySelector< HTMLButtonElement >( 'button:not(:disabled)' )?.focus();
		}
	}, [ menuOpen ] );

	const closeMenu = ( focusToggle: boolean ) => {
		setMenuOpen( false );
		if ( focusToggle ) {
			window.setTimeout( () => addRef.current?.focus(), 0 );
		}
	};

	const onMenuKey = ( e: KeyboardEvent< HTMLDivElement > ) => {
		const items = Array.from( menuRef.current?.querySelectorAll< HTMLButtonElement >( 'button:not(:disabled)' ) ?? [] );
		const at = items.indexOf( document.activeElement as HTMLButtonElement );
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			e.stopPropagation();
			closeMenu( true );
		} else if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			const next = e.key === 'ArrowDown' ? ( at + 1 ) % items.length : ( at - 1 + items.length ) % items.length;
			items[ next ]?.focus();
		}
	};

	const addLayout = ( l: LayoutDef ) => {
		closeMenu( false );
		append( { layout: l.name, ...emptyRow( l.subFields ) } );
	};

	const buttonLabel =
		typeof field.settings?.button_label === 'string' && field.settings.button_label !== ''
			? field.settings.button_label
			: __( 'Add section', 'proto-blocks' );
	const noun = ( n: number ) => sprintf( /* translators: %d: section number */ __( 'section %d', 'proto-blocks' ), n );

	return (
		<div id={ a11y.id } role="group" aria-labelledby={ a11y.labelId } aria-describedby={ a11y.describedBy } className="flex flex-col gap-2">
			{ rows.length === 0 && <EmptyMarker name={ inputName( groupKey, path ) } /> }
			{ rows.length > 0 && (
				<ol ref={ listRef } className="flex flex-col gap-2">
					{ rows.map( ( row, i ) => {
						const name = layoutOf( row );
						const layout = layouts.find( ( l ) => l.name === name );
						return (
							<RowCard
								key={ row.id }
								rowKey={ row.id }
								index={ i }
								count={ rows.length }
								noun={ noun }
								badge={ <Badge variant="secondary">{ layout?.label || name || __( 'Unknown layout', 'proto-blocks' ) }</Badge> }
								summary={ <span className="sr-only">{ noun( i + 1 ) }</span> }
								defaultCollapsed={ false }
								onMove={ onMove }
								onRemove={ onRemove }
							>
								<input type="hidden" name={ inputName( groupKey, [ ...path, i, LAYOUT_KEY ] ) } value={ name } />
								{ layout ? (
									<FieldList fields={ layout.subFields } scope={ [ ...path, i ] } />
								) : (
									<p className="text-[13px] text-muted-foreground">
										{ __( 'This section’s layout no longer exists. Remove it, or restore the layout.', 'proto-blocks' ) }
									</p>
								) }
							</RowCard>
						);
					} ) }
				</ol>
			) }
			<div className="flex flex-wrap items-start gap-3">
				<div className="relative">
					<Button
						ref={ addRef }
						type="button"
						variant="outline"
						size="sm"
						disabled={ full || layouts.length === 0 }
						aria-expanded={ menuOpen }
						aria-controls={ menuId }
						onClick={ () => setMenuOpen( ( o ) => ! o ) }
					>
						<PlusIcon aria-hidden="true" />
						{ buttonLabel }
						<ChevronDownIcon aria-hidden="true" />
					</Button>
					<div
						ref={ menuRef }
						id={ menuId }
						hidden={ ! menuOpen }
						onKeyDown={ onMenuKey }
						onBlur={ ( e ) => {
							if ( menuOpen && ! e.currentTarget.parentElement?.contains( e.relatedTarget as Node | null ) ) {
								setMenuOpen( false );
							}
						} }
						className={ menuOpen ? 'absolute top-full left-0 z-10 mt-1 flex w-56 flex-col rounded-md border border-border bg-background p-1 shadow-md' : 'hidden' }
					>
						{ layouts.map( ( l ) => (
							<button
								key={ l.key || l.name }
								type="button"
								disabled={ layoutFull( l ) }
								onClick={ () => addLayout( l ) }
								className="rounded-sm px-2 py-1.5 text-left text-sm outline-none hover:bg-accent focus-visible:bg-accent disabled:opacity-50"
							>
								{ l.label || l.name }
							</button>
						) ) }
					</div>
				</div>
				<span className="pt-1.5 text-[13px] text-muted-foreground">{ rowCount( rows.length, max ) }</span>
			</div>
		</div>
	);
}
