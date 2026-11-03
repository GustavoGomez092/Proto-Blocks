/**
 * schemas/field-group.schema.json must accept a real ACF export and reject
 * every invalid fixture that GroupValidator reports as an error (warnings,
 * like an unknown location rule, must still validate).
 */
import fs from 'fs';
import path from 'path';
import Ajv2020 from 'ajv/dist/2020';

const root = path.resolve( __dirname, '../../..' );
const fixtures = path.join( root, 'tests/fixtures/fields' );
const read = ( file: string ): unknown =>
	JSON.parse( fs.readFileSync( path.join( fixtures, file ), 'utf8' ) );

function compile() {
	const schema = JSON.parse(
		fs.readFileSync(
			path.join( root, 'schemas/field-group.schema.json' ),
			'utf8'
		)
	);
	const ajv = new Ajv2020( { allErrors: true } );
	// The schema's one non-standard keyword: no two fields at one level share
	// a non-empty name (GroupValidator's duplicate_name).
	ajv.addKeyword( {
		keyword: 'uniqueFieldNames',
		type: 'array',
		schemaType: 'boolean',
		validate: ( enabled: boolean, data: unknown[] ) => {
			if ( ! enabled ) {
				return true;
			}
			const names = data
				.map( ( f ) =>
					f && typeof f === 'object'
						? ( f as { name?: unknown } ).name
						: undefined
				)
				.filter(
					( n ): n is string => typeof n === 'string' && n !== ''
				);
			return new Set( names ).size === names.length;
		},
	} );
	return ajv.compile( schema );
}

// Mirrors GroupValidatorTest::invalidGroups().
const errorFixtures = [
	'missing-key.json',
	'bad-name.json',
	'duplicate-name.json',
	'layouts-on-text.json',
	'repeater-without-subfields.json',
];
const warningFixtures = [ 'unknown-rule.json' ];

describe( 'field-group.schema.json', () => {
	const validate = compile();

	it( 'accepts the realistic ACF export', () => {
		const ok = validate( read( 'acf-export-realistic.json' ) );
		expect( validate.errors ).toBeNull();
		expect( ok ).toBe( true );
	} );

	it( 'accepts the 50-field performance group', () => {
		expect( validate( read( 'perf-50.json' ) ) ).toBe( true );
	} );

	it( 'accepts a single group object as well as an array', () => {
		const [ group ] = read( 'acf-export-realistic.json' ) as unknown[];
		expect( validate( group ) ).toBe( true );
	} );

	it( 'lists every invalid fixture', () => {
		const files = fs
			.readdirSync( path.join( fixtures, 'groups-invalid' ) )
			.filter( ( f ) => f.endsWith( '.json' ) )
			.sort();
		expect( files ).toEqual(
			[ ...errorFixtures, ...warningFixtures ].sort()
		);
	} );

	it.each( errorFixtures )( 'rejects groups-invalid/%s', ( file ) => {
		expect( validate( read( `groups-invalid/${ file }` ) ) ).toBe( false );
	} );

	it.each( warningFixtures )(
		'accepts groups-invalid/%s (warning only)',
		( file ) => {
			expect( validate( read( `groups-invalid/${ file }` ) ) ).toBe(
				true
			);
		}
	);

	it( 'allows an empty name only on layout-only types', () => {
		const group = ( type: string ) => ( {
			key: 'group_x',
			title: 'X',
			fields: [ { key: 'field_x', name: '', label: 'X', type } ],
		} );
		expect( validate( group( 'tab' ) ) ).toBe( true );
		expect( validate( group( 'message' ) ) ).toBe( true );
		expect( validate( group( 'text' ) ) ).toBe( false );
	} );

	it( 'rejects a location operator other than == and !=', () => {
		expect(
			validate( {
				key: 'group_x',
				title: 'X',
				fields: [],
				location: [
					[ { param: 'post_type', operator: '>=', value: 'post' } ],
				],
			} )
		).toBe( false );
	} );
} );
