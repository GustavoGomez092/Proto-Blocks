import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import exportFile from '../../../tests/fixtures/fields/acf-export-realistic.json';
import { normalizeField } from '../model/normalize';
import { FieldsForm } from '../box/FieldsForm';
import type { BoxPayload, FieldDef } from '../model/types';

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ) );

const mockLoaded = jest.fn();
jest.mock( '../box/pickers/RelationshipPicker', () => {
	mockLoaded( 'relationship' );
	return jest.requireActual( '../box/pickers/RelationshipPicker' );
} );
jest.mock( '../box/pickers/GalleryPicker', () => {
	mockLoaded( 'gallery' );
	return jest.requireActual( '../box/pickers/GalleryPicker' );
} );
jest.mock( '../box/pickers/WysiwygEditor', () => {
	mockLoaded( 'wysiwyg' );
	return jest.requireActual( '../box/pickers/WysiwygEditor' );
} );

const fetchMock = apiFetch as unknown as jest.Mock;

const raw = ( exportFile as unknown as Array< { fields: Record< string, unknown >[] } > )[ 0 ].fields;
const pick = ( ...names: string[] ) =>
	names.map( ( n ) => normalizeField( raw.find( ( f ) => f.name === n ) as Record< string, unknown > ) as FieldDef );

function payload( overrides: Partial< BoxPayload > = {} ): BoxPayload {
	return {
		groupKey: 'group_x',
		fields: pick( 'speakers' ),
		values: {},
		display: {},
		choices: {},
		errors: {},
		canUnfiltered: true,
		maxRows: {},
		restRoot: '/wp-json/',
		restNonce: 'nonce',
		object: 'post:10',
		...overrides,
	};
}

function renderBox( p: BoxPayload ) {
	const utils = render(
		<form data-testid="post">
			<FieldsForm payload={ p } />
		</form>
	);
	const form = screen.getByTestId( 'post' ) as HTMLFormElement;
	return { ...utils, form, all: ( name: string ) => new FormData( form ).getAll( name ) };
}

const loaded = ( which: string ) => mockLoaded.mock.calls.filter( ( c ) => c[ 0 ] === which ).length;

type Frame = {
	on: jest.Mock;
	open: jest.Mock;
	state: () => { get: () => { first: () => { toJSON: () => unknown }; toJSON: () => unknown[] } };
	select: ( attachments: unknown[] ) => void;
};

function stubMedia() {
	const frames: Frame[] = [];
	const media = jest.fn( () => {
		const handlers: Record< string, Array< () => void > > = {};
		let chosen: unknown[] = [];
		const frame: Frame = {
			on: jest.fn( ( event: string, cb: () => void ) => {
				( handlers[ event ] ??= [] ).push( cb );
				return frame;
			} ),
			open: jest.fn(),
			state: () => ( {
				get: () => ( { first: () => ( { toJSON: () => chosen[ 0 ] } ), toJSON: () => chosen } ),
			} ),
			select: ( attachments ) => {
				chosen = attachments;
				( handlers.select ?? [] ).forEach( ( cb ) => cb() );
			},
		};
		frames.push( frame );
		return frame;
	} );
	( window as unknown as { wp: unknown } ).wp = { media };
	return { media, frames };
}

beforeEach( () => {
	fetchMock.mockReset();
	fetchMock.mockResolvedValue( {
		items: [
			{ id: 5, title: 'Ada <b>Lovelace</b>', type: 'post', status: 'publish' },
			{ id: 9, title: 'Grace Hopper', type: 'post', status: 'publish' },
		],
		page: 1,
		hasMore: false,
	} );
	delete ( window as unknown as { wp?: unknown } ).wp;
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'relationship', () => {
	test( 'the picker chunk is not requested until Add is clicked', async () => {
		const user = userEvent.setup();
		renderBox( payload() );
		expect( loaded( 'relationship' ) ).toBe( 0 );
		expect( fetchMock ).not.toHaveBeenCalled();
		await user.click( screen.getByRole( 'button', { name: 'Add' } ) );
		expect( await screen.findByRole( 'dialog' ) ).toBeInTheDocument();
		expect( loaded( 'relationship' ) ).toBe( 1 );
	} );

	test( 'search is debounced 250 ms and calls the route once', async () => {
		jest.useFakeTimers();
		const user = userEvent.setup( { advanceTimers: jest.advanceTimersByTime } );
		renderBox( payload() );
		await user.click( screen.getByRole( 'button', { name: 'Add' } ) );
		const search = await screen.findByRole( 'combobox', { name: 'Search' } );
		await user.type( search, 'ab' );
		const withAb = () => fetchMock.mock.calls.filter( ( c ) => String( c[ 0 ].path ).includes( 'search=ab' ) );
		expect( withAb() ).toHaveLength( 0 );
		await act( async () => {
			jest.advanceTimersByTime( 249 );
		} );
		expect( withAb() ).toHaveLength( 0 );
		await act( async () => {
			jest.advanceTimersByTime( 1 );
		} );
		expect( withAb() ).toHaveLength( 1 );
		expect( fetchMock ).toHaveBeenCalledWith(
			expect.objectContaining( { path: '/proto-blocks/v1/fields/relationship?group=group_x&field=speakers&search=ab&page=1' } )
		);
	} );

	test( 'selecting two results adds two hidden inputs in order; titles are text', async () => {
		const user = userEvent.setup();
		const { all } = renderBox( payload() );
		expect( all( 'proto_fields[group_x][speakers]' ) ).toEqual( [ '' ] );
		await user.click( screen.getByRole( 'button', { name: 'Add' } ) );
		const listbox = await screen.findByRole( 'listbox' );
		const grace = await within( listbox ).findByRole( 'option', { name: /Grace Hopper/ } );
		await user.click( grace );
		await user.click( within( listbox ).getByRole( 'option', { name: /Ada/ } ) );
		await waitFor( () => expect( all( 'proto_fields[group_x][speakers][]' ) ).toEqual( [ '9', '5' ] ) );
		expect( all( 'proto_fields[group_x][speakers]' ) ).toEqual( [] );
		// A raw title with markup shows as text, never as HTML.
		expect( within( listbox ).getByText( 'Ada <b>Lovelace</b>' ) ).toBeInTheDocument();
		expect( listbox.querySelector( 'b' ) ).toBeNull();
	} );

	test( 'keyboard: arrows move the active option and Enter selects it', async () => {
		const user = userEvent.setup();
		const { all } = renderBox( payload() );
		await user.click( screen.getByRole( 'button', { name: 'Add' } ) );
		const search = await screen.findByRole( 'combobox', { name: 'Search' } );
		await screen.findByRole( 'option', { name: /Grace/ } );
		expect( search ).toHaveFocus();
		await user.keyboard( '{ArrowDown}{ArrowDown}{Enter}' );
		await waitFor( () => expect( all( 'proto_fields[group_x][speakers][]' ) ).toEqual( [ '9' ] ) );
	} );

	test( 'max 1 replaces the selection instead of appending', async () => {
		const user = userEvent.setup();
		const [ speakers ] = pick( 'speakers' );
		const single = { ...speakers, settings: { ...speakers.settings, max: 1 } };
		const { all } = renderBox(
			payload( { fields: [ single ], values: { speakers: [ 3 ] }, display: { speakers: [ { id: 3, title: 'Old pick', type: 'post' } ] } } )
		);
		expect( screen.getByText( 'Old pick' ) ).toBeInTheDocument();
		expect( all( 'proto_fields[group_x][speakers][]' ) ).toEqual( [ '3' ] );
		await user.click( screen.getByRole( 'button', { name: 'Replace' } ) );
		await user.click( await screen.findByRole( 'option', { name: /Ada/ } ) );
		await waitFor( () => expect( all( 'proto_fields[group_x][speakers][]' ) ).toEqual( [ '5' ] ) );
		// Picking the only allowed item closes the dialog and returns focus.
		await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).toBeNull() );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Replace' } ) ).toHaveFocus() );
	} );

	test( 'a later page loads only when there is more', async () => {
		const user = userEvent.setup();
		fetchMock.mockResolvedValueOnce( { items: [ { id: 1, title: 'One', type: 'post', status: 'publish' } ], page: 1, hasMore: true } );
		renderBox( payload() );
		await user.click( screen.getByRole( 'button', { name: 'Add' } ) );
		await user.click( await screen.findByRole( 'button', { name: 'Load more' } ) );
		await waitFor( () =>
			expect( fetchMock ).toHaveBeenLastCalledWith( expect.objectContaining( { path: expect.stringContaining( '&page=2' ) } ) )
		);
		expect( await screen.findByRole( 'option', { name: /Grace/ } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'option', { name: /One/ } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Load more' } ) ).toBeNull();
	} );

	test( 'selected items reorder and remove', async () => {
		const user = userEvent.setup();
		const { all } = renderBox(
			payload( {
				values: { speakers: [ 3, 4 ] },
				display: { speakers: [ { id: 3, title: 'First', type: 'post' }, { id: 4, title: 'Second', type: 'post' } ] },
			} )
		);
		await user.click( screen.getByRole( 'button', { name: 'Move Second up' } ) );
		await waitFor( () => expect( all( 'proto_fields[group_x][speakers][]' ) ).toEqual( [ '4', '3' ] ) );
		await user.click( screen.getByRole( 'button', { name: 'Remove First' } ) );
		await waitFor( () => expect( all( 'proto_fields[group_x][speakers][]' ) ).toEqual( [ '4' ] ) );
	} );
} );

describe( 'wysiwyg', () => {
	const wysiwyg: FieldDef = {
		key: 'field_fine', name: 'fine_print', label: 'Fine print', type: 'wysiwyg', declaredType: 'wysiwyg', required: false,
		default: null, instructions: '', placeholder: '', conditions: [], subFields: [], layouts: [], settings: {},
	};

	test( 'wp.editor.initialize is not called until Edit is clicked', async () => {
		const user = userEvent.setup();
		const editor = { initialize: jest.fn(), remove: jest.fn(), getContent: jest.fn( () => '<p>x</p>' ) };
		( window as unknown as { wp: unknown } ).wp = { editor };
		const { form, unmount } = renderBox( payload( { fields: [ wysiwyg ], values: { fine_print: '<p>Terms apply</p>' } } ) );
		expect( new FormData( form ).get( 'proto_fields[group_x][fine_print]' ) ).toBe( '<p>Terms apply</p>' );
		const preview = form.querySelector( 'iframe' )!;
		expect( preview.getAttribute( 'sandbox' ) ).not.toMatch( /allow-scripts/ );
		expect( loaded( 'wysiwyg' ) ).toBe( 0 );
		expect( editor.initialize ).not.toHaveBeenCalled();

		await user.click( screen.getByRole( 'button', { name: /Edit/ } ) );
		await waitFor( () => expect( editor.initialize ).toHaveBeenCalledTimes( 1 ) );
		const [ id, settings ] = editor.initialize.mock.calls[ 0 ];
		expect( settings ).toEqual( { tinymce: { wpautop: true }, quicktags: true, mediaButtons: true } );
		const area = document.getElementById( id ) as HTMLTextAreaElement;
		expect( area.tagName ).toBe( 'TEXTAREA' );
		expect( area.name ).toBe( 'proto_fields[group_x][fine_print]' );
		expect( area.value ).toBe( '<p>Terms apply</p>' );
		expect( loaded( 'wysiwyg' ) ).toBe( 1 );
		unmount();
		expect( editor.remove ).toHaveBeenCalledWith( id );
	} );

	test( 'without wp.editor the editor is a plain textarea', async () => {
		const user = userEvent.setup();
		const { form } = renderBox( payload( { fields: [ wysiwyg ], values: { fine_print: '' } } ) );
		await user.click( screen.getByRole( 'button', { name: /Edit/ } ) );
		const area = await screen.findByRole( 'textbox', { name: /Fine print/ } );
		await user.type( area, 'Hi' );
		expect( new FormData( form ).get( 'proto_fields[group_x][fine_print]' ) ).toBe( 'Hi' );
	} );
} );

describe( 'media', () => {
	test( 'gallery: the picker chunk loads on "Add images" and appends the chosen IDs', async () => {
		const user = userEvent.setup();
		const { media, frames } = stubMedia();
		const { all } = renderBox(
			payload( {
				fields: pick( 'photos' ),
				values: { photos: [ 11 ] },
				display: { photos: [ { id: 11, url: 'https://example.test/a.jpg', alt: 'A' } ] },
			} )
		);
		expect( screen.getByRole( 'img', { name: 'A' } ) ).toHaveAttribute( 'src', 'https://example.test/a.jpg' );
		expect( loaded( 'gallery' ) ).toBe( 0 );
		expect( media ).not.toHaveBeenCalled();
		await user.click( screen.getByRole( 'button', { name: 'Add images' } ) );
		await waitFor( () => expect( media ).toHaveBeenCalledWith( expect.objectContaining( { multiple: true } ) ) );
		expect( loaded( 'gallery' ) ).toBe( 1 );
		act( () =>
			frames[ 0 ].select( [
				{ id: 11, url: 'https://example.test/a.jpg' },
				{ id: 12, url: 'https://example.test/b.jpg', alt: 'B' },
			] )
		);
		await waitFor( () => expect( all( 'proto_fields[group_x][photos][]' ) ).toEqual( [ '11', '12' ] ) );
		await user.click( screen.getByRole( 'button', { name: 'Remove image 1' } ) );
		await waitFor( () => expect( all( 'proto_fields[group_x][photos][]' ) ).toEqual( [ '12' ] ) );
	} );

	test( 'image: wp.media single selection stores the attachment ID only', async () => {
		const user = userEvent.setup();
		const { media, frames } = stubMedia();
		const { all } = renderBox(
			payload( { fields: pick( 'hero' ), values: { hero: 7 }, display: { hero: { id: 7, url: 'https://example.test/h.jpg', alt: 'Hall' } } } )
		);
		expect( all( 'proto_fields[group_x][hero]' ) ).toEqual( [ '7' ] );
		expect( screen.getByRole( 'img', { name: 'Hall' } ) ).toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: /Replace/ } ) );
		expect( media ).toHaveBeenCalledWith( expect.objectContaining( { multiple: false } ) );
		act( () => frames[ 0 ].select( [ { id: 8, url: 'javascript:alert(1)', filename: 'x.jpg' } ] ) );
		await waitFor( () => expect( all( 'proto_fields[group_x][hero]' ) ).toEqual( [ '8' ] ) );
		expect( document.querySelector( 'img[src^="javascript"]' ) ).toBeNull();
		await user.click( screen.getByRole( 'button', { name: /Remove/ } ) );
		await waitFor( () => expect( all( 'proto_fields[group_x][hero]' ) ).toEqual( [ '' ] ) );
	} );

	test( 'taxonomy: a checkbox list of the pre-resolved terms, no request', async () => {
		const user = userEvent.setup();
		const { all } = renderBox(
			payload( {
				fields: pick( 'topics' ),
				values: { topics: [ 2 ] },
				display: { topics: { terms: [ { id: 1, name: 'News', parent: 0 }, { id: 2, name: 'Local', parent: 1 } ] } },
			} )
		);
		expect( screen.getByRole( 'group', { name: 'Topics' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'checkbox', { name: 'Local' } ) ).toBeChecked();
		await user.click( screen.getByRole( 'checkbox', { name: 'News' } ) );
		await waitFor( () => expect( all( 'proto_fields[group_x][topics][]' ) ).toEqual( [ '1', '2' ] ) );
		expect( fetchMock ).not.toHaveBeenCalled();
	} );
} );
