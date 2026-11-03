import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FieldsForm } from '../box/FieldsForm';
import type { BoxPayload, FieldDef } from '../model/types';

// The editor chunk fails to load (a network error) until the test lets it through.
let mockFail = true;
jest.mock( '../box/pickers/WysiwygEditor', () => {
	if ( mockFail ) {
		throw new Error( 'Loading chunk fields-wysiwyg failed.' );
	}
	return jest.requireActual( '../box/pickers/WysiwygEditor' );
} );

const wysiwyg: FieldDef = {
	key: 'field_fine', name: 'fine_print', label: 'Fine print', type: 'wysiwyg', declaredType: 'wysiwyg', required: false,
	default: null, instructions: '', placeholder: '', conditions: [], subFields: [], layouts: [], settings: {},
};

const payload: BoxPayload = {
	groupKey: 'group_x',
	fields: [ wysiwyg ],
	values: { fine_print: '<p>Kept</p>' },
	display: {},
	choices: {},
	errors: {},
	canUnfiltered: true,
	maxRows: {},
	restRoot: '/wp-json/',
	restNonce: 'nonce',
	object: 'post:10',
};

test( 'a chunk that fails to load shows a retry and keeps the value submitting', async () => {
	const user = userEvent.setup();
	jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	render(
		<form data-testid="post">
			<FieldsForm payload={ payload } />
		</form>
	);
	const form = screen.getByTestId( 'post' ) as HTMLFormElement;
	await user.click( screen.getByRole( 'button', { name: /Edit/ } ) );

	const alert = await screen.findByText( /Couldn’t load this control/ );
	expect( alert.closest( '[role="alert"]' ) ).not.toBeNull();
	const data = new FormData( form );
	expect( data.get( 'proto_fields[group_x][fine_print]' ) ).toBe( '<p>Kept</p>' );
	expect( data.getAll( 'proto_fields_present[group_x][]' ) ).toEqual( [ 'fine_print' ] );

	mockFail = false;
	await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );
	const area = await screen.findByRole( 'textbox', { name: /Fine print/ } );
	expect( ( area as HTMLTextAreaElement ).value ).toBe( '<p>Kept</p>' );
	expect( screen.queryByText( /Couldn’t load this control/ ) ).toBeNull();
} );
