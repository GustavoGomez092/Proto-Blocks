import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import exportFile from '../../../tests/fixtures/fields/acf-export-realistic.json';
import { normalizeField } from '../model/normalize';
import { FieldsForm } from '../box/FieldsForm';
import type { BoxPayload, FieldDef } from '../model/types';

const raw = ( exportFile as unknown as Array< { fields: Record< string, unknown >[] } > )[ 0 ].fields;
const pick = ( ...names: string[] ) =>
	names.map( ( n ) => normalizeField( raw.find( ( f ) => f.name === n ) as Record< string, unknown > ) as FieldDef );

function payload( overrides: Partial< BoxPayload > = {} ): BoxPayload {
	return {
		groupKey: 'group_x',
		fields: pick( 'agenda', 'sections' ),
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
	const names = () => Array.from( new FormData( form ).keys() );
	return { ...utils, form, names };
}

const titleNames = ( names: string[] ) => names.filter( ( n ) => /\[agenda]\[\d+]\[title]$/.test( n ) );

test( 'repeater add, remove and reorder keep bracket names contiguous', async () => {
	const user = userEvent.setup();
	const { form, names } = renderBox(
		payload( { values: { agenda: [ { title: 'Keynote' }, { title: 'Panel' }, { title: 'Close' } ] } } )
	);
	expect( titleNames( names() ) ).toEqual( [
		'proto_fields[group_x][agenda][0][title]',
		'proto_fields[group_x][agenda][1][title]',
		'proto_fields[group_x][agenda][2][title]',
	] );

	await user.click( screen.getByRole( 'button', { name: 'Remove row 1' } ) );
	await waitFor( () => expect( titleNames( names() ) ).toHaveLength( 2 ) );
	let data = new FormData( form );
	expect( data.get( 'proto_fields[group_x][agenda][0][title]' ) ).toBe( 'Panel' );
	expect( data.get( 'proto_fields[group_x][agenda][1][title]' ) ).toBe( 'Close' );

	await user.click( screen.getByRole( 'button', { name: 'Move row 2 up' } ) );
	await waitFor( () =>
		expect( new FormData( form ).get( 'proto_fields[group_x][agenda][0][title]' ) ).toBe( 'Close' )
	);
	data = new FormData( form );
	expect( data.get( 'proto_fields[group_x][agenda][1][title]' ) ).toBe( 'Panel' );

	await user.click( screen.getByRole( 'button', { name: 'Add session' } ) );
	await waitFor( () => expect( titleNames( names() ) ).toHaveLength( 3 ) );
	expect( titleNames( names() ) ).toEqual( [
		'proto_fields[group_x][agenda][0][title]',
		'proto_fields[group_x][agenda][1][title]',
		'proto_fields[group_x][agenda][2][title]',
	] );
	expect( new FormData( form ).getAll( 'proto_fields_present[group_x][]' ) ).toContain( 'agenda' );
} );

test( 'removing every row still submits the repeater key', async () => {
	const user = userEvent.setup();
	const { form } = renderBox( payload( { values: { agenda: [ { title: 'Only' } ] } } ) );
	await user.click( screen.getByRole( 'button', { name: 'Remove row 1' } ) );
	await waitFor( () => expect( new FormData( form ).getAll( 'proto_fields[group_x][agenda]' ) ).toEqual( [ '' ] ) );
} );

test( 'add is disabled at maxRows.agenda', async () => {
	const user = userEvent.setup();
	renderBox( payload( { values: { agenda: [ { title: 'A' } ] }, maxRows: { agenda: 2 } } ) );
	const add = screen.getByRole( 'button', { name: 'Add session' } );
	expect( add ).toBeEnabled();
	await user.click( add );
	await waitFor( () => expect( add ).toBeDisabled() );
	expect( screen.getByText( '2 of max 2 rows' ) ).toBeInTheDocument();
} );

test( 'min rows render initially', async () => {
	const [ agenda ] = pick( 'agenda' );
	const withMin = { ...agenda, settings: { ...agenda.settings, min: 2 } };
	const { names } = renderBox( payload( { fields: [ withMin ] } ) );
	await waitFor( () => expect( titleNames( names() ) ).toHaveLength( 2 ) );
} );

test( 'flexible "Add section → Quote" submits the layout name', async () => {
	const user = userEvent.setup();
	const { form } = renderBox( payload() );
	expect( new FormData( form ).getAll( 'proto_fields[group_x][sections]' ) ).toEqual( [ '' ] );
	const add = screen.getByRole( 'button', { name: /Add section/ } );
	await user.click( add );
	expect( add ).toHaveAttribute( 'aria-expanded', 'true' );
	await user.click( screen.getByRole( 'button', { name: 'Quote' } ) );
	await waitFor( () =>
		expect( new FormData( form ).get( 'proto_fields[group_x][sections][0][_layout]' ) ).toBe( 'quote' )
	);
	const row = screen.getByRole( 'listitem', { name: /Quote/ } );
	expect( within( row ).getByLabelText( 'Cite' ) ).toHaveAttribute( 'name', 'proto_fields[group_x][sections][0][cite]' );
	expect( new FormData( form ).getAll( 'proto_fields[group_x][sections]' ) ).toEqual( [] );
} );

test( 'a nested required error renders under that row’s input', async () => {
	const { form } = renderBox( payload( { values: { agenda: [ { title: 'Keynote' }, { title: '' } ] } } ) );
	await act( async () => {
		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
	const inputs = screen.getAllByLabelText( /^Title/, { selector: 'input' } );
	expect( inputs ).toHaveLength( 2 );
	await waitFor( () => expect( inputs[ 1 ] ).toHaveAttribute( 'aria-invalid', 'true' ) );
	expect( inputs[ 0 ] ).not.toHaveAttribute( 'aria-invalid' );
	const ids = inputs[ 1 ].getAttribute( 'aria-describedby' )!.split( ' ' );
	const message = document.getElementById( ids[ ids.length - 1 ] )!;
	expect( message ).toHaveTextContent( 'This field is required.' );
	expect( message.closest( '[data-field]' )?.getAttribute( 'data-field' ) ).toBe( 'agenda.1.title' );
} );

test( 'a group renders its sub-fields inline with nested names', () => {
	const group: FieldDef = {
		key: 'field_author', name: 'author', label: 'Author box', type: 'group', declaredType: 'group', required: false,
		default: null, instructions: '', placeholder: '', conditions: [], layouts: [], settings: {},
		subFields: [
			{
				key: 'field_author_name', name: 'name', label: 'Name', type: 'text', declaredType: 'text', required: false,
				default: null, instructions: '', placeholder: '', conditions: [], subFields: [], layouts: [], settings: {},
			},
		],
	};
	renderBox( payload( { fields: [ group ], values: { author: { name: 'Dana' } } } ) );
	expect( screen.getByRole( 'group', { name: 'Author box' } ) ).toBeInTheDocument();
	const input = screen.getByLabelText( 'Name' ) as HTMLInputElement;
	expect( input.name ).toBe( 'proto_fields[group_x][author][name]' );
	expect( input.value ).toBe( 'Dana' );
} );

test( 'a row move is announced in the box live region', async () => {
	const user = userEvent.setup();
	renderBox( payload( { values: { agenda: [ { title: 'A' }, { title: 'B' }, { title: 'C' } ] } } ) );
	const status = screen.getByRole( 'status' );
	await user.click( screen.getByRole( 'button', { name: 'Move row 2 up' } ) );
	await waitFor( () => expect( status ).toHaveTextContent( 'Row moved to position 1 of 3.' ) );
	await user.click( screen.getByRole( 'button', { name: 'Remove row 3' } ) );
	await waitFor( () => expect( status ).toHaveTextContent( 'Row 3 removed.' ) );
	await user.click( screen.getByRole( 'button', { name: 'Add session' } ) );
	await waitFor( () => expect( status ).toHaveTextContent( 'Row 3 added.' ) );
} );

test( 'wysiwyg editors in rows keep distinct ids after remove and add', async () => {
	const user = userEvent.setup();
	const editor = { initialize: jest.fn(), remove: jest.fn() };
	( window as unknown as { wp?: unknown } ).wp = { editor };
	try {
		renderBox( payload( { values: { agenda: [ { title: 'A' }, { title: 'B' } ] } } ) );
		await user.click( screen.getByRole( 'button', { name: 'Remove row 1' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Add session' } ) );
		const edits = await screen.findAllByRole( 'button', { name: /Notes Edit/ } );
		expect( edits ).toHaveLength( 2 );
		await user.click( edits[ 0 ] );
		await user.click( edits[ 1 ] );
		await waitFor( () => expect( editor.initialize ).toHaveBeenCalledTimes( 2 ) );
		const [ first, second ] = editor.initialize.mock.calls.map( ( c ) => c[ 0 ] as string );
		expect( first ).not.toBe( second );
		expect( first ).toMatch( /^[A-Za-z0-9_-]+$/ );
	} finally {
		delete ( window as unknown as { wp?: unknown } ).wp;
	}
} );

test( 'a collapsed row (past the 20th) opens when it holds a server error', async () => {
	const rows = Array.from( { length: 22 }, ( _, i ) => ( { title: `T${ i }` } ) );
	renderBox( payload( { values: { agenda: rows }, errors: { 'agenda.21.title': 'This field is required.' } } ) );
	expect( screen.getByRole( 'button', { name: 'Expand row 21' } ) ).toBeInTheDocument();
	await waitFor( () => expect( screen.getByRole( 'button', { name: 'Collapse row 22' } ) ).toBeInTheDocument() );
	const input = document.getElementById( 'pbf-group_x-agenda-21-title' )!;
	expect( input.closest( '[hidden]' ) ).toBeNull();
} );
