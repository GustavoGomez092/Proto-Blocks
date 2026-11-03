import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import exportFile from '../../../tests/fixtures/fields/acf-export-realistic.json';
import { normalizeField } from '../model/normalize';
import { FieldsForm } from '../box/FieldsForm';
import type { BoxPayload, FieldDef } from '../model/types';

// jsdom lacks ResizeObserver; Radix's Switch measures itself with it inside a form.
( globalThis as { ResizeObserver?: unknown } ).ResizeObserver ??= class {
	observe() {}
	unobserve() {}
	disconnect() {}
};

const raw = ( exportFile as unknown as Array< { fields: Record< string, unknown >[] } > )[ 0 ].fields;
const pick = ( ...names: string[] ) =>
	names.map( ( n ) => normalizeField( raw.find( ( f ) => f.name === n ) as Record< string, unknown > ) as FieldDef );

function payload( overrides: Partial< BoxPayload > = {} ): BoxPayload {
	return {
		groupKey: 'group_x',
		fields: pick( 'subtitle', 'start_date', 'is_online', 'stream_url' ),
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
	return { ...utils, form, present: () => new FormData( form ).getAll( 'proto_fields_present[group_x][]' ) };
}

const codeField: FieldDef = {
	key: 'field_embed', name: 'embed_code', label: 'Embed code', type: 'code', declaredType: 'code', required: false,
	default: null, instructions: '', placeholder: '', conditions: [], subFields: [], layouts: [], settings: {},
};

test( 'a text field carries its real name and a present marker', () => {
	const { container, present } = renderBox( payload( { values: { subtitle: 'Hello' } } ) );
	const input = screen.getByLabelText( 'Subtitle' ) as HTMLInputElement;
	expect( input.name ).toBe( 'proto_fields[group_x][subtitle]' );
	expect( input.value ).toBe( 'Hello' );
	expect( present() ).toContain( 'subtitle' );
	expect(
		container.querySelector( 'input[type="hidden"][name="proto_fields_present[group_x][]"][value="subtitle"]' )
	).not.toBeNull();
} );

test( 'toggling is_online shows stream_url and adds its present entry', async () => {
	const user = userEvent.setup();
	const { present } = renderBox( payload() );
	const url = screen.getByLabelText( 'Stream URL', { selector: 'input' } );
	expect( url.closest( '[hidden]' ) ).not.toBeNull();
	expect( present() ).not.toContain( 'stream_url' );

	await user.click( screen.getByRole( 'switch', { name: 'Online event' } ) );

	await waitFor( () => expect( url.closest( '[hidden]' ) ).toBeNull() );
	expect( present() ).toContain( 'stream_url' );
	expect( ( url as HTMLInputElement ).name ).toBe( 'proto_fields[group_x][stream_url]' );
} );

test( 'blur on an empty required date shows the required message', async () => {
	const user = userEvent.setup();
	renderBox( payload() );
	const date = screen.getByLabelText( /Start date/ );
	await user.click( date );
	await user.tab();
	const alert = await screen.findByText( 'This field is required.' );
	expect( alert.closest( '[role="alert"]' ) ).not.toBeNull();
	expect( date ).toHaveAttribute( 'aria-invalid', 'true' );
	expect( date.getAttribute( 'aria-describedby' ) ).toContain( alert.closest( '[id]' )!.id );
} );

test( 'server errors from the payload show on mount', async () => {
	renderBox( payload( { errors: { start_date: 'Enter a valid date.' } } ) );
	const messages = await screen.findAllByText( 'Enter a valid date.' );
	expect( messages.length ).toBeGreaterThan( 0 );
	expect( screen.getByLabelText( /Start date/ ) ).toHaveAttribute( 'aria-invalid', 'true' );
	// The summary at the top of the box is focused so the user notices it.
	await waitFor( () => expect( document.activeElement ).toHaveTextContent( /needs attention/ ) );
} );

test( 'a code field is read-only text without a name for untrusted users', () => {
	const { container, present } = renderBox(
		payload( { fields: [ codeField ], values: { embed_code: '<script>x()</script>' }, canUnfiltered: false } )
	);
	const pre = container.querySelector( 'pre' )!;
	expect( pre ).toHaveTextContent( '<script>x()</script>' );
	expect( pre ).toHaveAttribute( 'aria-readonly', 'true' );
	expect( pre ).toHaveAttribute( 'tabindex', '0' );
	expect( container.querySelector( '[name*="code"]' ) ).toBeNull();
	expect( present() ).toEqual( [] );
} );

test( 'a code field is editable for trusted users', () => {
	const { container, present } = renderBox(
		payload( { fields: [ codeField ], values: { embed_code: '<b>x</b>' }, canUnfiltered: true } )
	);
	const area = container.querySelector( 'textarea[name="proto_fields[group_x][embed_code]"]' ) as HTMLTextAreaElement;
	expect( area.value ).toBe( '<b>x</b>' );
	expect( present() ).toEqual( [ 'embed_code' ] );
} );

test( 'true_false submits 0 when unchecked and 1 when checked', async () => {
	const user = userEvent.setup();
	const { form } = renderBox( payload() );
	expect( new FormData( form ).getAll( 'proto_fields[group_x][is_online]' ) ).toEqual( [ '0' ] );
	await user.click( screen.getByRole( 'switch', { name: 'Online event' } ) );
	await waitFor( () =>
		expect( new FormData( form ).getAll( 'proto_fields[group_x][is_online]' ) ).toEqual( [ '0', '1' ] )
	);
} );

test( 'empty multi-value fields still submit their key', async () => {
	const user = userEvent.setup();
	const { form } = renderBox( payload( { fields: pick( 'audience' ), values: { audience: [] } } ) );
	expect( new FormData( form ).getAll( 'proto_fields[group_x][audience]' ) ).toEqual( [ '' ] );
	await user.selectOptions( screen.getByLabelText( 'Audience' ), [ 'students' ] );
	await waitFor( () => expect( new FormData( form ).getAll( 'proto_fields[group_x][audience][]' ) ).toEqual( [ 'students' ] ) );
	expect( new FormData( form ).getAll( 'proto_fields[group_x][audience]' ) ).toEqual( [] );
} );

test( 'a submit attempt validates but never blocks the form', async () => {
	const { form } = renderBox( payload() );
	const submitted = jest.fn( ( e: Event ) => e.defaultPrevented );
	form.addEventListener( 'submit', submitted );
	await act( async () => {
		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
	expect( submitted ).toHaveReturnedWith( false );
	expect( await screen.findByText( 'This field is required.' ) ).toBeInTheDocument();
} );

test( 'tabs keep every panel mounted so all values submit', () => {
	const fields = raw
		.slice( 0, 13 )
		.map( ( f ) => normalizeField( f ) )
		.filter( ( f ): f is FieldDef => f !== null );
	const { form, present } = renderBox( payload( { fields, values: { subtitle: 'S', accent: '#112233' } } ) );
	expect( screen.getByRole( 'tab', { name: 'Basics' } ) ).toBeInTheDocument();
	const data = new FormData( form );
	expect( data.get( 'proto_fields[group_x][subtitle]' ) ).toBe( 'S' );
	expect( data.get( 'proto_fields[group_x][accent]' ) ).toBe( '#112233' );
	// Media inputs in later tabs are mounted and present too (Task 11 registered them).
	expect( present() ).toEqual( expect.arrayContaining( [ 'subtitle', 'accent', 'register_link', 'hero', 'photos' ] ) );
	expect( data.getAll( 'proto_fields[group_x][photos]' ) ).toEqual( [ '' ] );
} );

test( 'required inputs carry aria-required; optional ones do not', () => {
	renderBox( payload() );
	expect( screen.getByLabelText( /Start date/ ) ).toHaveAttribute( 'aria-required', 'true' );
	expect( screen.getByLabelText( 'Subtitle' ) ).not.toHaveAttribute( 'aria-required' );
} );

test( 'radio and checkbox groups are named by aria-labelledby, not a label pointing at a div', () => {
	const choice = ( name: string, type: 'radio' | 'checkbox', required = false ): Record< string, unknown > => ( {
		key: `field_${ name }`,
		name,
		label: name.toUpperCase(),
		type,
		required: required ? 1 : 0,
		choices: { a: 'Option A', b: 'Option B' },
	} );
	const fields = [ choice( 'level', 'radio', true ), choice( 'tags', 'checkbox' ) ].map(
		( f ) => normalizeField( f ) as FieldDef
	);
	const { container } = renderBox( payload( { fields } ) );
	expect( screen.getByRole( 'radiogroup', { name: /LEVEL/ } ) ).toHaveAttribute( 'aria-required', 'true' );
	expect( screen.getByRole( 'group', { name: 'TAGS' } ) ).toBeInTheDocument();
	for ( const label of Array.from( container.querySelectorAll( 'label[for]' ) ) ) {
		expect( document.getElementById( label.getAttribute( 'for' )! )?.tagName ).not.toBe( 'DIV' );
	}
	expect( screen.getAllByRole( 'radio' ) ).toHaveLength( 2 );
} );
