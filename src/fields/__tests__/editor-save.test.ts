import { createElement } from 'react';
import { act, render, screen } from '@testing-library/react';
import { byField, FieldsForm } from '../box/FieldsForm';
import { layoutOf } from '../box/inputs/FlexibleInput';
import { termList } from '../box/inputs/TaxonomyInput';
import { errorsUrl, mountedForms, registerForm, subscribeToMetaBoxSave, subscribeToTermAdd, type FormHandle } from '../box/editor-save';

function fakeStore() {
	let saving = false;
	const listeners: Array< () => void > = [];
	return {
		data: {
			select: ( store: string ) => ( store === 'core/edit-post' ? { isSavingMetaBoxes: () => saving } : undefined ),
			subscribe: ( l: () => void ) => {
				listeners.push( l );
				return () => listeners.splice( listeners.indexOf( l ), 1 );
			},
		},
		set( value: boolean ) {
			saving = value;
			listeners.forEach( ( l ) => l() );
		},
	};
}

const okResponse = ( body: unknown ) => Promise.resolve( { ok: true, json: () => Promise.resolve( body ) } as Response );

function handle( overrides: Partial< FormHandle > = {} ): FormHandle & { calls: unknown[] } {
	const calls: unknown[] = [];
	return {
		groupKey: 'group_x',
		object: 'post:10',
		restRoot: 'https://example.test/wp-json/',
		restNonce: 'n0nce',
		applyServer: ( errors, submitted ) => calls.push( { errors, submitted } ),
		calls,
		...overrides,
	};
}

afterEach( () => {
	for ( const f of mountedForms() ) {
		// eslint-disable-next-line @typescript-eslint/no-empty-function
		registerForm( f )();
	}
} );

test( 'a completed meta box save reads the errors once and pushes them into every mounted form', async () => {
	const store = fakeStore();
	const fetchFn = jest.fn( () => okResponse( { errors: { start_date: 'Enter a valid date.' }, submitted: { start_date: '2026-02-30' } } ) );
	const a = handle();
	const b = handle( { groupKey: 'group_y' } );
	const offA = registerForm( a );
	const offB = registerForm( b );
	const onSaved = jest.fn();
	const off = subscribeToMetaBoxSave( onSaved, { data: store.data, fetch: fetchFn as unknown as typeof fetch } );

	store.set( true );
	expect( fetchFn ).not.toHaveBeenCalled();
	store.set( false );
	await new Promise( ( r ) => setTimeout( r, 0 ) );

	expect( fetchFn ).toHaveBeenCalledTimes( 1 );
	const [ url, init ] = fetchFn.mock.calls[ 0 ] as unknown as [ string, RequestInit ];
	expect( url ).toBe( 'https://example.test/wp-json/proto-blocks/v1/fields/errors?object=post%3A10' );
	expect( ( init.headers as Record< string, string > )[ 'X-WP-Nonce' ] ).toBe( 'n0nce' );
	const expected = { errors: { start_date: 'Enter a valid date.' }, submitted: { start_date: '2026-02-30' } };
	expect( a.calls ).toEqual( [ expected ] );
	expect( b.calls ).toEqual( [ expected ] );
	expect( onSaved ).toHaveBeenCalledTimes( 1 );

	store.set( false ); // no transition, no request
	expect( fetchFn ).toHaveBeenCalledTimes( 1 );
	off();
	offA();
	offB();
} );

test( 'no request when no box is mounted', async () => {
	const store = fakeStore();
	const fetchFn = jest.fn( () => okResponse( {} ) );
	const off = subscribeToMetaBoxSave( jest.fn(), { data: store.data, fetch: fetchFn as unknown as typeof fetch } );
	store.set( true );
	store.set( false );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	expect( fetchFn ).not.toHaveBeenCalled();
	off();
} );

test( 'a failed request leaves the forms untouched', async () => {
	const store = fakeStore();
	const fetchFn = jest.fn( () => Promise.resolve( { ok: false, json: () => Promise.resolve( {} ) } as Response ) );
	const a = handle();
	const offA = registerForm( a );
	const off = subscribeToMetaBoxSave( jest.fn(), { data: store.data, fetch: fetchFn as unknown as typeof fetch } );
	store.set( true );
	store.set( false );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	expect( a.calls ).toEqual( [] );
	off();
	offA();
} );

test( 'outside the block editor nothing subscribes', () => {
	const off = subscribeToMetaBoxSave( jest.fn(), { data: undefined, fetch: jest.fn() as unknown as typeof fetch } );
	expect( off() ).toBeUndefined();
} );

test( 'errors URL works with plain permalinks', () => {
	expect( errorsUrl( 'https://example.test/?rest_route=/', 'post:3' ) ).toBe(
		'https://example.test/?rest_route=/proto-blocks/v1/fields/errors&object=post%3A3'
	);
} );

test( 'term add form remounts only after a successful add-tag', () => {
	document.body.innerHTML = '<form id="addtag"></form>';
	let handler: ( ( e: unknown, xhr: { responseText?: string }, s: { data?: unknown } ) => void ) | undefined;
	const jq = () => ( { on: ( _ev: string, h: typeof handler ) => ( handler = h ) } );
	const remount = jest.fn();
	subscribeToTermAdd( remount, jq as never );

	handler?.( {}, { responseText: '<wp_ajax><wp_error>x</wp_error></wp_ajax>' }, { data: 'action=add-tag&tag-name=a' } );
	handler?.( {}, { responseText: '<wp_ajax/>' }, { data: 'action=heartbeat' } );
	expect( remount ).not.toHaveBeenCalled();
	handler?.( {}, { responseText: '<wp_ajax><response/></wp_ajax>' }, { data: 'taxonomy=category&action=add-tag' } );
	expect( remount ).toHaveBeenCalledTimes( 1 );
} );

const textField = ( name: string ) => ( {
	key: `f_${ name }`, name, label: name === 'subtitle' ? 'Subtitle' : name, type: 'text' as const, declaredType: 'text', required: false,
	default: null, instructions: '', placeholder: '', conditions: [], subFields: [], layouts: [], settings: {},
} );

test( 'a mounted FieldsForm registers and shows pushed errors with the submitted value', async () => {
	const payload = {
		groupKey: 'group_x', fields: [ textField( 'subtitle' ) ], values: { subtitle: 'stored' }, display: {}, choices: {}, errors: {},
		canUnfiltered: true, maxRows: {}, restRoot: '/wp-json/', restNonce: 'n', object: 'post:10',
	};
	const { unmount } = render( createElement( 'form', null, createElement( FieldsForm, { payload } ) ) );
	const forms = mountedForms().filter( ( f ) => f.groupKey === 'group_x' && f.object === 'post:10' );
	expect( forms ).toHaveLength( 1 );

	await act( async () => {
		forms[ 0 ].applyServer( { subtitle: 'Too long.', 'other.0': 'not mine' }, { subtitle: 'typed' } );
	} );
	expect( screen.getAllByText( 'Too long.' ).length ).toBeGreaterThan( 0 );
	expect( screen.queryByText( 'not mine' ) ).toBeNull();
	expect( ( screen.getByLabelText( 'Subtitle' ) as HTMLInputElement ).value ).toBe( 'typed' );

	unmount();
	expect( mountedForms().filter( ( f ) => f.groupKey === 'group_x' ) ).toHaveLength( 0 );
} );

test( 'a readOnly field gets no present marker', () => {
	const payload = {
		groupKey: 'group_r', fields: [ textField( 'rows' ) ], values: {}, display: {}, choices: {}, errors: {},
		readOnly: { rows: 'unfiltered_html' as const },
		canUnfiltered: false, maxRows: {}, restRoot: '/wp-json/', restNonce: 'n', object: 'post:10',
	};
	const { container, unmount } = render( createElement( 'form', null, createElement( FieldsForm, { payload } ) ) );
	const form = container.querySelector( 'form' ) as HTMLFormElement;
	expect( new FormData( form ).getAll( 'proto_fields_present[group_r][]' ) ).toEqual( [] );
	unmount();
} );

test( 'an options page summary says nothing was saved; a post summary says it was', () => {
	const base = {
		groupKey: 'group_o', fields: [ textField( 'subtitle' ) ], values: {}, display: {}, choices: {},
		errors: { subtitle: 'Too long.' }, canUnfiltered: true, maxRows: {}, restRoot: '/wp-json/', restNonce: 'n',
	};
	const option = render( createElement( FieldsForm, { payload: { ...base, object: 'option:site' } } ) );
	expect( screen.getByText( 'Nothing was saved. 1 field needs attention.' ) ).toBeTruthy();
	expect( screen.queryByText( /Saved, but/ ) ).toBeNull();
	option.unmount();

	const forced = render( createElement( FieldsForm, { payload: { ...base, object: 'post:10' }, outcome: 'not-saved' } ) );
	expect( screen.getByText( 'Nothing was saved. 1 field needs attention.' ) ).toBeTruthy();
	forced.unmount();

	const post = render( createElement( FieldsForm, { payload: { ...base, object: 'post:10' } } ) );
	expect( screen.getByText( 'The post was saved, but 1 field needs attention' ) ).toBeTruthy();
	post.unmount();
} );

test( 'payload maps are read by field key, then by name', () => {
	const f = textField( 'agenda' );
	expect( byField( { f_agenda: 3, agenda: 9 }, f ) ).toBe( 3 );
	expect( byField( { agenda: 9 }, f ) ).toBe( 9 );
	expect( byField( {}, f ) ).toBeUndefined();
	expect( byField( undefined, f ) ).toBeUndefined();
} );

const taxField = ( name: string, taxonomy: string ) => ( {
	...textField( name ), type: 'taxonomy' as const, declaredType: 'taxonomy', settings: { taxonomy, field_type: 'checkbox' },
} );

test( 'term lists: inline at a top-level path, a shared reference in rows, the shared list for a new row', () => {
	const list = [ { id: 1, name: 'Jazz', parent: 0 } ];
	const display = { top: { terms: list }, 'terms:genre': list, 'rows.0.genre': { terms: 'genre' } };
	const f = taxField( 'genre', 'genre' );
	expect( termList( f, display.top, display ) ).toEqual( { terms: list } );
	expect( termList( f, display[ 'rows.0.genre' ], display ) ).toBe( list );
	expect( termList( f, undefined, display ) ).toBe( list );
	expect( termList( taxField( 'mood', 'mood' ), { terms: 'mood' }, display ) ).toBeUndefined();
} );

test( 'a row sub-field renders the terms its shared reference names', () => {
	const rows = {
		...textField( 'rows' ), type: 'repeater' as const, declaredType: 'repeater', subFields: [ taxField( 'genre', 'genre' ) ],
	};
	const payload = {
		groupKey: 'group_t', fields: [ rows ], values: { rows: [ { genre: [ 2 ] } ] },
		display: { 'terms:genre': [ { id: 1, name: 'Jazz', parent: 0 }, { id: 2, name: 'Rock', parent: 0 } ], 'rows.0.genre': { terms: 'genre' } },
		choices: {}, errors: {}, canUnfiltered: true, maxRows: {}, restRoot: '/wp-json/', restNonce: 'n', object: 'post:10',
	};
	const { unmount } = render( createElement( 'form', null, createElement( FieldsForm, { payload } ) ) );
	expect( ( screen.getByRole( 'checkbox', { name: 'Rock' } ) as HTMLInputElement ).checked ).toBe( true );
	expect( ( screen.getByRole( 'checkbox', { name: 'Jazz' } ) as HTMLInputElement ).checked ).toBe( false );
	unmount();
} );

test( 'flexible rows read their layout from `layout` (stored, or sent back after a failed save) and `_layout`', () => {
	expect( layoutOf( { layout: 'quote', cite: 'x' } ) ).toBe( 'quote' );
	expect( layoutOf( { _layout: 'quote' } ) ).toBe( 'quote' );
	expect( layoutOf( { layout: '', _layout: 'text_block' } ) ).toBe( 'text_block' );
	expect( layoutOf( null ) ).toBe( '' );

	const quote = {
		...textField( 'sections' ), type: 'flexible_content' as const, declaredType: 'flexible_content',
		layouts: [ { key: 'l_q', name: 'quote', label: 'Quote', subFields: [ textField( 'cite' ) ] } ],
	};
	const payload = {
		groupKey: 'group_f', fields: [ quote ], values: { sections: [ { layout: 'quote', cite: 'Ada' } ] }, display: {},
		choices: {}, errors: { 'sections.0.cite': 'Too short.' }, canUnfiltered: true, maxRows: {}, restRoot: '/wp-json/', restNonce: 'n', object: 'post:10',
	};
	const { container, unmount } = render( createElement( 'form', null, createElement( FieldsForm, { payload } ) ) );
	const data = new FormData( container.querySelector( 'form' ) as HTMLFormElement );
	expect( data.get( 'proto_fields[group_f][sections][0][_layout]' ) ).toBe( 'quote' );
	expect( data.get( 'proto_fields[group_f][sections][0][cite]' ) ).toBe( 'Ada' );
	unmount();
} );
