/**
 * image, file, video and gallery.
 *
 * Values are attachment IDs only (video may instead hold an external URL
 * typed by the user). Thumbnails and file names come from the server's
 * pre-resolved `display` data, or from the WordPress media frame's own
 * attachment data after a pick; a URL the user typed is never rendered as
 * media. Every rendered URL must be http(s) or same-origin.
 *
 * The core media frame (`wp.media`, enqueued by the box screen) is created
 * on the first click only. The gallery's multi-select frame lives in the
 * lazy `fields-picker-gallery` chunk.
 */
import { Suspense, useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowDownIcon, ArrowUpIcon, FileIcon, ImagePlusIcon, XIcon } from 'lucide-react';
import { useController } from 'react-hook-form';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { cn } from '@/admin/lib/utils';
import type { FieldDef } from '../../model/types';
import { dottedPath, inputName, type FieldPath } from '../names';
import {
	ChunkBoundary,
	DETACHED_FORM,
	EmptyMarker,
	useAnnounce,
	useBox,
	useDisplay,
	useFieldA11y,
	useRetryableLazy,
	type FieldInputProps,
} from '../registry';

/* ---------- shared helpers ---------- */

/** What the box shows for one attachment. */
export type Attachment = { id: number; url: string; alt: string; name: string; mime: string };

/** A positive integer ID, or 0. */
export function toId( v: unknown ): number {
	const n = typeof v === 'number' ? v : typeof v === 'string' && /^\s*\d+\s*$/.test( v ) ? Number( v ) : NaN;
	return Number.isInteger( n ) && n > 0 ? n : 0;
}

/** A list of positive integer IDs, in order, without duplicates. */
export function toIds( v: unknown ): number[] {
	const list = Array.isArray( v ) ? v : v && typeof v === 'object' ? Object.values( v ) : [ v ];
	const out: number[] = [];
	for ( const item of list ) {
		const id = toId( item && typeof item === 'object' ? ( item as { id?: unknown } ).id : item );
		if ( id && ! out.includes( id ) ) {
			out.push( id );
		}
	}
	return out;
}

/** An http(s) or same-origin URL, or '' (never javascript:, data: and the like). */
export function safeUrl( v: unknown ): string {
	if ( typeof v !== 'string' || v.trim() === '' ) {
		return '';
	}
	try {
		const url = new URL( v, window.location.href );
		return url.protocol === 'http:' || url.protocol === 'https:' ? v : '';
	} catch {
		return '';
	}
}

function str( v: unknown ): string {
	return typeof v === 'string' ? v : typeof v === 'number' ? String( v ) : '';
}

/** A display entry or a media frame attachment (its toJSON()) as an Attachment; null without an ID. */
export function toAttachment( v: unknown ): Attachment | null {
	if ( ! v || typeof v !== 'object' ) {
		return null;
	}
	const o = v as Record< string, unknown >;
	const id = toId( o.id );
	if ( ! id ) {
		return null;
	}
	const sizes = o.sizes && typeof o.sizes === 'object' ? ( o.sizes as Record< string, { url?: unknown } | undefined > ) : {};
	const url = safeUrl( sizes.thumbnail?.url ) || safeUrl( sizes.medium?.url ) || safeUrl( o.url );
	return {
		id,
		url,
		alt: str( o.alt ),
		name: str( o.filename ) || str( o.title ) || str( o.name ),
		mime: str( o.mime ) || ( str( o.type ) && str( o.subtype ) ? `${ str( o.type ) }/${ str( o.subtype ) }` : '' ),
	};
}

/** The display data for a list input as Attachments (accepts a list or one entry). */
function attachmentsOf( display: unknown ): Attachment[] {
	const list = Array.isArray( display ) ? display : display ? [ display ] : [];
	return list.map( toAttachment ).filter( ( a ): a is Attachment => a !== null );
}

type MediaSelection = { first: () => { toJSON: () => unknown }; toJSON: () => unknown; reset?: () => unknown };
export type MediaFrame = {
	on: ( event: string, cb: () => void ) => unknown;
	open: () => unknown;
	state: () => { get: ( key: string ) => MediaSelection };
	off?: () => unknown;
	detach?: () => unknown;
	remove?: () => unknown;
};

/** Unbinds and removes a media frame (its modal markup and listeners). */
export function disposeFrame( frame: MediaFrame | null ): void {
	if ( ! frame ) {
		return;
	}
	frame.off?.();
	frame.detach?.();
	frame.remove?.();
}

/**
 * The one gallery frame of a gallery input. The lazy picker creates it on
 * the first "Add images" and reuses it; the input disposes it on unmount.
 */
export type GalleryFrameHolder = {
	frame: MediaFrame | null;
	picked: boolean;
	onSelect: ( attachments: Attachment[] ) => void;
	onClose: () => void;
};
export type MediaFrameOptions = {
	title: string;
	button: { text: string };
	library?: { type?: string };
	multiple: boolean;
};

/** The core media frame factory, when the screen enqueued it (wp_enqueue_media). */
export function wpMedia(): ( ( opts: MediaFrameOptions ) => MediaFrame ) | undefined {
	const wp = ( window as unknown as { wp?: { media?: ( opts: MediaFrameOptions ) => MediaFrame } } ).wp;
	return typeof wp?.media === 'function' ? wp.media : undefined;
}

/** Selected attachments of a frame, as Attachments. */
export function frameSelection( frame: MediaFrame, multiple: boolean ): Attachment[] {
	const selection = frame.state().get( 'selection' );
	const raw = multiple ? selection.toJSON() : [ selection.first()?.toJSON() ];
	return ( Array.isArray( raw ) ? raw : [] ).map( toAttachment ).filter( ( a ): a is Attachment => a !== null );
}

export const MEDIA_UNAVAILABLE = __( 'The media library is not available on this screen.', 'proto-blocks' );

/** Up/down (or left/right) buttons that move one item of an ordered list by keyboard or pointer. */
export function MoveButtons( {
	index,
	count,
	label,
	onMove,
	horizontal = false,
	className,
}: {
	index: number;
	count: number;
	/** The accessible label for moving this item earlier (-1) or later (+1). */
	label: ( dir: -1 | 1 ) => string;
	onMove: ( from: number, to: number ) => void;
	horizontal?: boolean;
	className?: string;
} ) {
	const button = ( dir: -1 | 1, icon: ReactNode ) => (
		<Button
			type="button"
			variant="ghost"
			size="icon-xs"
			className="size-7 rounded-md text-muted-foreground hover:bg-background hover:text-foreground [&_svg]:size-3.5"
			data-move={ dir }
			aria-label={ label( dir ) }
			disabled={ index + dir < 0 || index + dir >= count }
			onClick={ () => onMove( index, index + dir ) }
		>
			{ icon }
		</Button>
	);
	return (
		<span className={ cn( 'flex', className ) }>
			{ button( -1, <ArrowUpIcon aria-hidden="true" className={ cn( horizontal && '-rotate-90' ) } /> ) }
			{ button( 1, <ArrowDownIcon aria-hidden="true" className={ cn( horizontal && '-rotate-90' ) } /> ) }
		</span>
	);
}

/**
 * After a move, keep focus on the moved item's same button (or the other
 * direction's, when that end is now disabled).
 */
export function refocusMoved( container: HTMLElement | null, selector: string, dir: number ): void {
	window.setTimeout( () => {
		const item = container?.querySelector( selector );
		const same = item?.querySelector< HTMLButtonElement >( `[data-move="${ dir }"]` );
		const other = item?.querySelector< HTMLButtonElement >( `[data-move="${ -dir }"]` );
		( same && ! same.disabled ? same : other )?.focus();
	}, 0 );
}

/* ---------- single attachment: image, file, video ---------- */

type Kind = 'image' | 'file' | 'video';

const SELECT_LABEL: Record< Kind, string > = {
	image: __( 'Select image', 'proto-blocks' ),
	file: __( 'Select file', 'proto-blocks' ),
	video: __( 'Select video', 'proto-blocks' ),
};

/** Opens a single-select media frame, created once per input on first use. */
function useMediaFrame( kind: Kind, onPick: ( a: Attachment ) => void ) {
	const frame = useRef< MediaFrame | null >( null );
	const pick = useRef( onPick );
	pick.current = onPick;
	const [ unavailable, setUnavailable ] = useState( false );
	const open = useCallback( () => {
		const media = wpMedia();
		if ( ! media ) {
			setUnavailable( true );
			return;
		}
		if ( ! frame.current ) {
			const f = media( {
				title: SELECT_LABEL[ kind ],
				button: { text: __( 'Use this', 'proto-blocks' ) },
				library: kind === 'file' ? undefined : { type: kind },
				multiple: false,
			} );
			f.on( 'select', () => {
				const [ chosen ] = frameSelection( f, false );
				if ( chosen ) {
					pick.current( chosen );
				}
			} );
			frame.current = f;
		}
		frame.current.open();
	}, [ kind ] );
	useEffect( () => () => disposeFrame( frame.current ), [] );
	return { open, unavailable };
}

function Thumb( { attachment, kind }: { attachment: Attachment | null; kind: Kind } ) {
	if ( kind === 'image' && attachment?.url ) {
		return (
			<img
				src={ attachment.url }
				alt={ attachment.alt || attachment.name }
				className="size-20 shrink-0 rounded-lg border border-border bg-muted object-cover"
			/>
		);
	}
	return (
		<span className="grid size-12 shrink-0 place-items-center rounded-lg border border-border bg-muted text-muted-foreground">
			<FileIcon aria-hidden="true" className="size-5" />
		</span>
	);
}

function SingleMedia( { field, path, kind }: FieldInputProps & { kind: Kind } ) {
	const { control, groupKey } = useBox();
	const a11y = useFieldA11y();
	const display = useDisplay( path );
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const raw = ctl.value;
	const id = toId( raw );
	const typedUrl = kind === 'video' && ! id && typeof raw === 'string' ? raw : '';
	const [ info, setInfo ] = useState< Attachment | null >( () => attachmentsOf( display )[ 0 ] ?? null );
	const shown = info && info.id === id ? info : null;
	const mainRef = useRef< HTMLButtonElement >( null );

	const { open, unavailable } = useMediaFrame( kind, ( a ) => {
		setInfo( a );
		ctl.onChange( a.id );
		ctl.onBlur();
	} );
	const clear = () => {
		setInfo( null );
		ctl.onChange( '' );
		ctl.onBlur();
		window.setTimeout( () => mainRef.current?.focus(), 0 );
	};
	const label = field.label || field.name;
	const ownId = a11y.id;

	return (
		<div className="flex flex-col gap-2">
			<input type="hidden" name={ inputName( groupKey, path ) } value={ id ? String( id ) : typedUrl } />
			<div
				data-media-trigger=""
				className={ cn(
					'flex gap-3 rounded-lg border p-3',
					id > 0 ? 'items-start border-border bg-background' : 'items-center border-dashed border-input bg-subtle'
				) }
			>
				{ id > 0 ? (
					<Thumb attachment={ shown } kind={ kind } />
				) : (
					<span aria-hidden="true" className="grid size-10 shrink-0 place-items-center rounded-md border border-border bg-background text-faint">
						{ kind === 'image' ? <ImagePlusIcon className="size-[18px]" /> : <FileIcon className="size-[18px]" /> }
					</span>
				) }
				<div className="flex min-w-0 flex-col gap-1.5">
					{ id > 0 && (
						<span className="truncate text-label font-medium text-foreground">
							{ shown?.name || sprintf( /* translators: %d: attachment ID */ __( 'Attachment #%d', 'proto-blocks' ), id ) }
						</span>
					) }
					<div className="flex flex-wrap gap-1.5">
						<Button
							ref={ mainRef }
							type="button"
							variant="outline"
							size="sm"
							id={ ownId }
							aria-labelledby={ `${ a11y.labelId } ${ ownId }-text` }
							aria-describedby={ a11y.describedBy }
							aria-invalid={ a11y.invalid || undefined }
							onClick={ open }
						>
							<span id={ `${ ownId }-text` }>{ id ? __( 'Replace', 'proto-blocks' ) : SELECT_LABEL[ kind ] }</span>
						</Button>
						{ id > 0 && (
							<Button
								type="button"
								variant="ghost"
								size="sm"
								aria-label={ sprintf( /* translators: %s: field label */ __( 'Remove %s', 'proto-blocks' ), label ) }
								onClick={ clear }
							>
								{ __( 'Remove', 'proto-blocks' ) }
							</Button>
						) }
					</div>
				</div>
			</div>
			{ kind === 'video' && (
				<Input
					type="text"
					inputMode="url"
					form={ DETACHED_FORM }
					autoComplete="off"
					spellCheck={ false }
					placeholder="https://"
					aria-label={ sprintf( /* translators: %s: field label */ __( '%s video URL', 'proto-blocks' ), label ) }
					aria-describedby={ a11y.describedBy }
					value={ typedUrl }
					onChange={ ( e ) => ctl.onChange( e.target.value ) }
					onBlur={ ctl.onBlur }
				/>
			) }
			{ unavailable && <p className="text-caption text-muted-foreground">{ MEDIA_UNAVAILABLE }</p> }
		</div>
	);
}

export function ImageInput( props: FieldInputProps ) {
	return <SingleMedia { ...props } kind="image" />;
}

export function FileInput( props: FieldInputProps ) {
	return <SingleMedia { ...props } kind="file" />;
}

export function VideoInput( props: FieldInputProps ) {
	return <SingleMedia { ...props } kind="video" />;
}

/* ---------- gallery ---------- */

const loadGalleryPicker = () => import( /* webpackChunkName: "fields-picker-gallery" */ '../pickers/GalleryPicker' );

function maxOf( field: FieldDef ): number {
	const max = toId( field.settings?.max );
	return max > 0 ? max : 0;
}

export function GalleryInput( { field, path }: FieldInputProps ) {
	const { control, groupKey } = useBox();
	const a11y = useFieldA11y();
	const display = useDisplay( path );
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const ids = toIds( ctl.value );
	const [ known, setKnown ] = useState< Record< number, Attachment > >( () =>
		Object.fromEntries( attachmentsOf( display ).map( ( a ) => [ a.id, a ] ) )
	);
	const [ picking, setPicking ] = useState( 0 );
	const [ GalleryPicker, retryPicker ] = useRetryableLazy( loadGalleryPicker );
	const holder = useRef< GalleryFrameHolder >( { frame: null, picked: false, onSelect: () => {}, onClose: () => {} } );
	useEffect( () => () => disposeFrame( holder.current.frame ), [] );
	const announce = useAnnounce();
	const [ unavailable, setUnavailable ] = useState( false );
	const listRef = useRef< HTMLUListElement >( null );
	const addRef = useRef< HTMLButtonElement >( null );
	const max = maxOf( field );
	const name = inputName( groupKey, path );
	const full = max > 0 && ids.length >= max;

	const update = ( next: number[] ) => {
		ctl.onChange( next );
		ctl.onBlur();
	};
	const add = ( chosen: Attachment[] ) => {
		setPicking( 0 );
		setKnown( ( k ) => ( { ...k, ...Object.fromEntries( chosen.map( ( a ) => [ a.id, a ] ) ) } ) );
		const next = [ ...ids ];
		for ( const a of chosen ) {
			if ( ! next.includes( a.id ) && ( max === 0 || next.length < max ) ) {
				next.push( a.id );
			}
		}
		update( next );
		const added = next.length - ids.length;
		if ( added > 0 ) {
			/* translators: %d: number of images added */
			announce( sprintf( _n( '%d image added.', '%d images added.', added, 'proto-blocks' ), added ) );
		}
	};
	const move = ( from: number, to: number ) => {
		const next = [ ...ids ];
		const [ id ] = next.splice( from, 1 );
		next.splice( to, 0, id );
		update( next );
		refocusMoved( listRef.current, `[data-item="${ id }"]`, to - from );
		/* translators: 1: new position, 2: number of images */
		announce( sprintf( __( 'Image moved to position %1$d of %2$d.', 'proto-blocks' ), to + 1, next.length ) );
	};
	const remove = ( index: number ) => {
		update( ids.filter( ( _, i ) => i !== index ) );
		/* translators: %d: image position */
		announce( sprintf( __( 'Image %d removed.', 'proto-blocks' ), index + 1 ) );
		window.setTimeout( () => {
			const removes = listRef.current?.querySelectorAll< HTMLButtonElement >( '[data-remove]' );
			( removes?.[ Math.min( index, removes.length - 1 ) ] ?? addRef.current )?.focus();
		}, 0 );
	};
	const openPicker = () => {
		if ( ! wpMedia() ) {
			setUnavailable( true );
			return;
		}
		setPicking( ( n ) => n + 1 );
	};

	const count = max
		? sprintf( /* translators: 1: images chosen, 2: maximum */ __( '%1$d of max %2$d', 'proto-blocks' ), ids.length, max )
		: sprintf( /* translators: %d: images chosen */ _n( '%d image', '%d images', ids.length, 'proto-blocks' ), ids.length );

	return (
		<div id={ a11y.id } role="group" aria-labelledby={ a11y.labelId } aria-describedby={ a11y.describedBy } className="flex flex-col gap-2">
			{ ids.length === 0 && <EmptyMarker name={ name } /> }
			{ ids.map( ( id ) => (
				<input key={ id } type="hidden" name={ `${ name }[]` } value={ String( id ) } />
			) ) }
			<p className="text-caption text-muted-foreground">{ count }</p>
			<ul ref={ listRef } className="grid grid-cols-[repeat(auto-fill,minmax(6rem,1fr))] gap-2">
				{ ids.map( ( id, i ) => {
					const a = known[ id ];
					const alt = a?.alt || a?.name || sprintf( /* translators: %d: position */ __( 'Image %d', 'proto-blocks' ), i + 1 );
					return (
						<li key={ id } data-item={ id } className="group/thumb relative aspect-square overflow-hidden rounded-lg border border-border bg-muted">
							{ a?.url ? (
								<img src={ a.url } alt={ alt } className="size-full object-cover" />
							) : (
								<span className="grid size-full place-items-center p-1 text-center text-xs text-muted-foreground">{ alt }</span>
							) }
							<span className="absolute inset-x-1 bottom-1 flex justify-between rounded-md bg-background/90 shadow-sm">
								<MoveButtons
									index={ i }
									count={ ids.length }
									horizontal
									label={ ( dir ) =>
										sprintf(
											dir < 0
												? /* translators: %d: image position */ __( 'Move image %d left', 'proto-blocks' )
												: /* translators: %d: image position */ __( 'Move image %d right', 'proto-blocks' ),
											i + 1
										)
									}
									onMove={ move }
								/>
								<Button
									type="button"
									variant="ghost"
									size="icon-xs"
									data-remove=""
									aria-label={ sprintf( /* translators: %d: image position */ __( 'Remove image %d', 'proto-blocks' ), i + 1 ) }
									onClick={ () => remove( i ) }
								>
									<XIcon aria-hidden="true" />
								</Button>
							</span>
						</li>
					);
				} ) }
				<li>
					<button
						ref={ addRef }
						type="button"
						disabled={ full }
						aria-label={ __( 'Add images', 'proto-blocks' ) }
						onClick={ openPicker }
						onFocus={ preloadGallery }
						className="grid aspect-square w-full cursor-pointer place-items-center rounded-lg border border-dashed border-input bg-subtle text-faint outline-none hover:border-[#b5bfcc] hover:text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50"
					>
						<ImagePlusIcon aria-hidden="true" className="size-6" />
					</button>
				</li>
			</ul>
			{ unavailable && <p className="text-caption text-muted-foreground">{ MEDIA_UNAVAILABLE }</p> }
			{ picking > 0 && (
				// The hidden inputs above stay mounted if the picker chunk fails to load.
				<ChunkBoundary onRetry={ retryPicker }>
					<Suspense fallback={ null }>
						<GalleryPicker
							key={ picking }
							holder={ holder.current }
							title={ field.label || field.name }
							onSelect={ add }
							onClose={ () => {
								setPicking( 0 );
								window.setTimeout( () => addRef.current?.focus(), 0 );
							} }
						/>
					</Suspense>
				</ChunkBoundary>
			) }
		</div>
	);
}

/** Warms the gallery chunk when its button takes focus; nothing loads before then. */
function preloadGallery(): void {
	loadGalleryPicker().catch( () => {} );
}
