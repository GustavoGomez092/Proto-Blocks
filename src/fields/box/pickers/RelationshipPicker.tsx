/**
 * The relationship search dialog (lazy chunk `fields-picker-relationship`).
 *
 * Searches server-side through GET proto-blocks/v1/fields/relationship
 * (20 per page, `{items, page, hasMore}`), debounced 250 ms after typing;
 * never loads every post. The search box is a combobox over the results
 * listbox: arrows move the active option, Enter picks it, Escape closes.
 * The Dialog traps focus and hands it back to the field on close. Titles
 * arrive raw and render as text nodes only.
 */
import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CheckIcon, SearchIcon } from 'lucide-react';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/admin/components/ui/dialog';
import { Input } from '@/admin/components/ui/input';
import { cn } from '@/admin/lib/utils';
import { toPostItems, type PostItem } from '../inputs/RelationshipInput';

export const SEARCH_DELAY = 250;

export type RelationshipPickerProps = {
	groupKey: string;
	fieldName: string;
	/** The field label, for the dialog title. */
	title: string;
	/** 0 = no limit. */
	max: number;
	selected: number[];
	onPick: ( item: PostItem ) => void;
	onClose: () => void;
	/** Called as the dialog closes, to put focus back in the field. */
	onReturnFocus: () => void;
};

type Response = { items?: unknown; page?: unknown; hasMore?: unknown; pages?: unknown };

export function routePath( groupKey: string, fieldName: string, search: string, page: number ): string {
	const q = ( v: string ) => encodeURIComponent( v );
	return `/proto-blocks/v1/fields/relationship?group=${ q( groupKey ) }&field=${ q( fieldName ) }&search=${ q( search ) }&page=${ page }`;
}

export function RelationshipPicker( {
	groupKey,
	fieldName,
	title,
	max,
	selected,
	onPick,
	onClose,
	onReturnFocus,
}: RelationshipPickerProps ) {
	const [ query, setQuery ] = useState( '' );
	/** The term the shown results are for; "Load more" pages through it, never a term still being typed. */
	const [ searched, setSearched ] = useState( '' );
	const [ items, setItems ] = useState< PostItem[] >( [] );
	const [ page, setPage ] = useState( 1 );
	const [ hasMore, setHasMore ] = useState( false );
	const [ status, setStatus ] = useState< 'loading' | 'idle' | 'error' >( 'loading' );
	const [ active, setActive ] = useState( -1 );
	const request = useRef( 0 );
	const first = useRef( true );
	const attempted = useRef( { search: '', page: 1 } );
	const searchRef = useRef< HTMLInputElement >( null );
	const listId = useId();
	const optionId = ( i: number ) => `${ listId }-option-${ i }`;

	const load = useCallback(
		( search: string, nextPage: number ) => {
			const seq = ++request.current;
			attempted.current = { search, page: nextPage };
			setStatus( 'loading' );
			apiFetch< Response >( { path: routePath( groupKey, fieldName, search, nextPage ) } )
				.then( ( res ) => {
					if ( seq !== request.current ) {
						return; // A newer search superseded this one.
					}
					const found = toPostItems( res?.items );
					const more =
						typeof res?.hasMore === 'boolean'
							? res.hasMore
							: typeof res?.pages === 'number' && nextPage < res.pages;
					setItems( ( prev ) => ( nextPage === 1 ? found : [ ...prev, ...found.filter( ( f ) => ! prev.some( ( p ) => p.id === f.id ) ) ] ) );
					setPage( nextPage );
					setSearched( search );
					setHasMore( more );
					setStatus( 'idle' );
					if ( nextPage === 1 ) {
						setActive( -1 );
					}
				} )
				.catch( () => {
					if ( seq === request.current ) {
						setStatus( 'error' );
					}
				} );
		},
		[ groupKey, fieldName ]
	);

	useEffect( () => {
		if ( first.current ) {
			first.current = false;
			load( query, 1 );
			return undefined;
		}
		const timer = window.setTimeout( () => load( query.trim(), 1 ), SEARCH_DELAY );
		return () => window.clearTimeout( timer );
	}, [ query, load ] );

	// A request still in flight when the dialog closes is ignored.
	useEffect(
		() => () => {
			request.current += 1;
		},
		[]
	);

	const choose = ( item: PostItem | undefined ) => {
		if ( item && ! selected.includes( item.id ) ) {
			onPick( item );
		}
	};

	const onKeyDown = ( e: KeyboardEvent< HTMLInputElement > ) => {
		if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			if ( items.length === 0 ) {
				return;
			}
			const next = e.key === 'ArrowDown' ? Math.min( active + 1, items.length - 1 ) : Math.max( active - 1, 0 );
			setActive( next );
			document.getElementById( optionId( next ) )?.scrollIntoView?.( { block: 'nearest' } );
		} else if ( e.key === 'Enter' ) {
			e.preventDefault();
			choose( items[ active ] );
		}
	};

	const limit =
		max > 0
			? sprintf( /* translators: %d: maximum number of items */ _n( 'Choose up to %d item.', 'Choose up to %d items.', max, 'proto-blocks' ), max )
			: __( 'Choose as many as you need, then select Done.', 'proto-blocks' );

	let announcement = '';
	if ( status === 'loading' ) {
		announcement = __( 'Loading…', 'proto-blocks' );
	} else if ( status === 'error' ) {
		announcement = __( 'The results could not be loaded.', 'proto-blocks' );
	} else if ( items.length === 0 ) {
		announcement = __( 'No results.', 'proto-blocks' );
	} else {
		announcement = sprintf( /* translators: %d: number of results shown */ _n( '%d result', '%d results', items.length, 'proto-blocks' ), items.length );
	}

	return (
		<Dialog open onOpenChange={ ( open ) => ! open && onClose() }>
			<DialogContent
				className="gap-3 sm:max-w-xl"
				onOpenAutoFocus={ ( e ) => {
					e.preventDefault();
					searchRef.current?.focus();
				} }
				onCloseAutoFocus={ ( e ) => {
					e.preventDefault();
					onReturnFocus();
				} }
			>
				<DialogHeader>
					<DialogTitle>{ sprintf( /* translators: %s: field label */ __( 'Choose %s', 'proto-blocks' ), title ) }</DialogTitle>
					<DialogDescription>{ limit }</DialogDescription>
				</DialogHeader>
				<div className="relative">
					<SearchIcon aria-hidden="true" className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
					<Input
						ref={ searchRef }
						type="text"
						role="combobox"
						aria-label={ __( 'Search', 'proto-blocks' ) }
						aria-expanded="true"
						aria-controls={ listId }
						aria-autocomplete="list"
						aria-activedescendant={ active >= 0 && items[ active ] ? optionId( active ) : undefined }
						autoComplete="off"
						placeholder={ __( 'Search by title', 'proto-blocks' ) }
						value={ query }
						onChange={ ( e ) => setQuery( e.target.value ) }
						onKeyDown={ onKeyDown }
						className="pl-8"
					/>
				</div>
				<p role="status" aria-live="polite" className="text-caption text-muted-foreground">
					{ announcement }
				</p>
				<ul
					id={ listId }
					role="listbox"
					aria-label={ __( 'Results', 'proto-blocks' ) }
					aria-multiselectable={ max !== 1 || undefined }
					className="max-h-72 overflow-auto rounded-md border border-border text-sm"
				>
					{ items.map( ( item, i ) => {
						const chosen = selected.includes( item.id );
						return (
							<li
								key={ item.id }
								id={ optionId( i ) }
								role="option"
								aria-selected={ chosen }
								aria-disabled={ chosen || undefined }
								onMouseDown={ ( e ) => e.preventDefault() }
								onMouseMove={ () => setActive( i ) }
								onClick={ () => choose( item ) }
								className={ cn(
									'flex cursor-pointer items-center gap-2 border-b border-border px-3 py-2 last:border-b-0',
									i === active && 'bg-accent text-accent-foreground',
									chosen && 'cursor-default opacity-60'
								) }
							>
								<span className="min-w-0 flex-1 truncate">
									{ item.title || sprintf( /* translators: %d: post ID */ __( '(no title) #%d', 'proto-blocks' ), item.id ) }
								</span>
								{ item.status && item.status !== 'publish' && <Badge variant="outline">{ item.status }</Badge> }
								{ item.type && <Badge variant="secondary">{ item.type }</Badge> }
								{ chosen && <CheckIcon aria-hidden="true" className="size-4 shrink-0" /> }
							</li>
						);
					} ) }
				</ul>
				<DialogFooter className="items-center sm:justify-between">
					<span>
						{ hasMore && (
							<Button
								type="button"
								variant="outline"
								size="sm"
								// Disabled while a typed search is still pending, so it never pages a stale term.
								disabled={ status === 'loading' || query.trim() !== searched }
								onClick={ () => load( searched, page + 1 ) }
							>
								{ __( 'Load more', 'proto-blocks' ) }
							</Button>
						) }
						{ status === 'error' && (
							<Button type="button" variant="outline" size="sm" onClick={ () => load( attempted.current.search, attempted.current.page ) }>
								{ __( 'Try again', 'proto-blocks' ) }
							</Button>
						) }
					</span>
					<Button type="button" size="sm" onClick={ onClose }>
						{ __( 'Done', 'proto-blocks' ) }
					</Button>
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
}

export default RelationshipPicker;
