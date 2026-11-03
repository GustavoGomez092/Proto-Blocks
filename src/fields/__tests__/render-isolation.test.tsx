import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import perf from '../../../tests/fixtures/fields/perf-50.json';
import { normalizeField } from '../model/normalize';
import { FieldsForm } from '../box/FieldsForm';
import { inputFor, registerInput, type FieldInput } from '../box/registry';
import type { BoxPayload, FieldDef } from '../model/types';

const fields = ( perf as unknown as { fields: Record< string, unknown >[] } ).fields
	.map( normalizeField )
	.filter( ( f ): f is FieldDef => f !== null );

const renders: Record< string, number > = {};
const Real = inputFor( 'text' ) as FieldInput;
const Counting: FieldInput = ( props ) => {
	renders[ props.field.name ] = ( renders[ props.field.name ] ?? 0 ) + 1;
	return <Real { ...props } />;
};

beforeAll( () => registerInput( 'text', Counting ) );
afterAll( () => registerInput( 'text', Real ) );

test( 'typing into one field re-renders no other field', async () => {
	expect( fields ).toHaveLength( 50 );
	const payload: BoxPayload = {
		groupKey: 'group_perf50', fields, values: {}, display: {}, choices: {}, errors: {},
		canUnfiltered: true, maxRows: {}, restRoot: '/wp-json/', restNonce: 'n', object: 'post:1',
	};
	render( <FieldsForm payload={ payload } /> );
	const textNames = fields.filter( ( f ) => f.type === 'text' ).map( ( f ) => f.name );
	expect( textNames ).toContain( 'f25' );
	expect( Object.keys( renders ).sort() ).toEqual( [ ...textNames ].sort() );

	const before = { ...renders };
	const input = screen.getByLabelText( 'Field 25' ) as HTMLInputElement;
	await userEvent.setup().type( input, 'abcdefghij' );

	expect( input.value ).toBe( 'abcdefghij' );
	for ( const name of textNames ) {
		if ( name !== 'f25' ) {
			expect( [ name, renders[ name ] ] ).toEqual( [ name, before[ name ] ] );
		}
	}
	// Uncontrolled: field 25 itself need not re-render per keystroke either.
	expect( renders.f25 - before.f25 ).toBeLessThanOrEqual( 10 );
} );
