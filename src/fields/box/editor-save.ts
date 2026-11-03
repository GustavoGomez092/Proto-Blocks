/**
 * Block editor and term screen integration for field boxes.
 *
 * Saving needs nothing from here: in WordPress 6.5+ the block editor saves
 * meta boxes by posting `.metabox-base-form` (post_ID, the core nonce) plus
 * every meta box location form as one FormData request to post.php after
 * the REST save, and that request runs save_post. The boxes' inputs, their
 * nonce and their object marker carry real names, so SaveHandler sees them
 * exactly as in the classic editor. What the block editor lacks is the page
 * reload that shows validation errors, so after that request completes
 * (`core/edit-post` isSavingMetaBoxes() true → false) each post box's
 * errors are read once from `fields/errors` and pushed into the mounted forms.
 *
 * On the term add form core submits over admin-ajax (`add-tag`) and clears
 * only its own inputs; the boxes are remounted empty after a successful add.
 */

/** What a mounted FieldsForm exposes to this module. */
export type FormHandle = {
	groupKey: string;
	/** 'post:10', 'term:new', ... (the payload's `object`). */
	object: string;
	restRoot: string;
	restNonce: string;
	/** Replaces the server errors; submitted values (top-level name => value) become the inputs' values. */
	applyServer: ( errors: Record< string, string >, submitted: Record< string, unknown > ) => void;
};

const forms = new Set< FormHandle >();

/** Called by FieldsForm on mount; returns the unregister function. */
export function registerForm( handle: FormHandle ): () => void {
	forms.add( handle );
	return () => {
		forms.delete( handle );
	};
}

export function mountedForms(): FormHandle[] {
	return Array.from( forms );
}

type DataRegistry = {
	select: ( store: string ) => { isSavingMetaBoxes?: () => boolean } | undefined;
	subscribe: ( listener: () => void ) => () => void;
};

type Deps = {
	data?: DataRegistry;
	fetch?: typeof fetch;
};

type WpGlobal = { wp?: { data?: DataRegistry } };

/** `<restRoot>proto-blocks/v1/fields/errors?object=…`, also for `?rest_route=/` roots (plain permalinks). */
export function errorsUrl( restRoot: string, object: string ): string {
	const base = restRoot.endsWith( '/' ) ? restRoot : `${ restRoot }/`;
	const sep = base.includes( '?' ) ? '&' : '?';
	return `${ base }proto-blocks/v1/fields/errors${ sep }object=${ encodeURIComponent( object ) }`;
}

type ErrorsResponse = { errors?: unknown; submitted?: unknown };

const asRecord = ( v: unknown ): Record< string, unknown > =>
	v && typeof v === 'object' && ! Array.isArray( v ) ? ( v as Record< string, unknown > ) : {};

async function refresh( fetchFn: typeof fetch ): Promise< void > {
	const byObject = new Map< string, FormHandle[] >();
	for ( const form of forms ) {
		if ( form.object.startsWith( 'post:' ) && form.restRoot && form.restNonce ) {
			byObject.set( form.object, [ ...( byObject.get( form.object ) ?? [] ), form ] );
		}
	}
	await Promise.all(
		Array.from( byObject.entries() ).map( async ( [ object, handles ] ) => {
			const first = handles[ 0 ];
			let body: ErrorsResponse = {};
			try {
				const res = await fetchFn( errorsUrl( first.restRoot, object ), {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': first.restNonce, Accept: 'application/json' },
				} );
				if ( ! res.ok ) {
					return;
				}
				body = ( await res.json() ) as ErrorsResponse;
			} catch {
				return;
			}
			const errors: Record< string, string > = {};
			for ( const [ path, message ] of Object.entries( asRecord( body.errors ) ) ) {
				if ( typeof message === 'string' ) {
					errors[ path ] = message;
				}
			}
			const submitted = asRecord( body.submitted );
			for ( const handle of handles ) {
				handle.applyServer( errors, submitted );
			}
		} )
	);
}

/**
 * Watches the block editor's meta box save. Does nothing outside the block
 * editor (no `core/edit-post` store). Returns the unsubscribe function.
 */
export function subscribeToMetaBoxSave( onSaved: () => void, deps: Deps = {} ): () => void {
	const data = deps.data ?? ( globalThis as WpGlobal ).wp?.data;
	const fetchFn = deps.fetch ?? ( typeof fetch === 'function' ? fetch.bind( globalThis ) : undefined );
	if ( ! data || ! fetchFn ) {
		return () => undefined;
	}
	const saving = () => {
		try {
			return !! data.select( 'core/edit-post' )?.isSavingMetaBoxes?.();
		} catch {
			return false; // store not registered (not the post editor)
		}
	};
	let was = saving();
	return data.subscribe( () => {
		const now = saving();
		if ( was && ! now ) {
			if ( forms.size > 0 ) {
				refresh( fetchFn )
					.then( onSaved )
					.catch( () => undefined ); // a failing form or callback must not surface as an unhandled rejection
			}
		}
		was = now;
	} );
}

type JQueryLike = ( target: unknown ) => {
	on: ( event: string, handler: ( event: unknown, xhr: { responseText?: string }, settings: { data?: unknown } ) => void ) => unknown;
};

/** Term add form: after a successful admin-ajax `add-tag`, `remount` runs (to start the boxes empty again). */
export function subscribeToTermAdd( remount: () => void, jq?: JQueryLike ): void {
	const $ = jq ?? ( globalThis as { jQuery?: JQueryLike } ).jQuery;
	if ( ! $ || typeof document === 'undefined' || ! document.getElementById( 'addtag' ) ) {
		return;
	}
	$( document ).on( 'ajaxSuccess', ( _event, xhr, settings ) => {
		const body = typeof settings?.data === 'string' ? settings.data : '';
		if ( ! /(?:^|&)action=add-tag(?:&|$)/.test( body ) ) {
			return;
		}
		if ( ( xhr?.responseText ?? '' ).includes( '<wp_error' ) ) {
			return; // core keeps the form filled when the add failed
		}
		remount();
	} );
}
