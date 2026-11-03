import corpus from '../../../tests/fixtures/fields/validation-cases.json';
import { normalizeField } from '../model/normalize';
import { buildValueSchema, validateValues } from '../validation/value-schema';
import type { FieldDef } from '../model/types';

type Case = {
	id: string;
	fields: Record< string, unknown >[];
	input: Record< string, unknown >;
	maxRows?: Record< string, number >;
	choices?: Record< string, string[] >;
	valid: string[];
	errors: Record< string, string >;
	hidden: string[];
};

/**
 * Server-only per the corpus notes: PCRE's backtrack limit rejects this
 * subject, while a JS engine would hang on it. The client recognises the
 * nested quantifier and leaves the verdict to the server (asserted below).
 */
const SERVER_ONLY = new Set( [ 'pattern-engine-failure-rejected' ] );

const cases = ( corpus as unknown as { cases: Case[] } ).cases;

function setup( c: Case ) {
	const fields = c.fields.map( normalizeField ).filter( ( f ): f is FieldDef => f !== null );
	const opts = {
		maxRows: c.maxRows ? ( f: FieldDef ) => c.maxRows![ f.name ] ?? null : undefined,
		choices: c.choices ? ( f: FieldDef ) => c.choices![ f.name ] ?? null : undefined,
	};
	return { fields, opts };
}

test( 'the corpus is loaded', () => {
	expect( cases.length ).toBeGreaterThanOrEqual( 30 );
} );

test.each( cases.filter( ( c ) => ! SERVER_ONLY.has( c.id ) ).map( ( c ) => [ c.id, c ] as const ) )(
	'%s',
	( _id, c ) => {
		const { fields, opts } = setup( c );
		const result = buildValueSchema( fields, opts ).safeParse( c.input );
		const errors: Record< string, string > = {};
		for ( const issue of result.success ? [] : result.error.issues ) {
			errors[ issue.path.join( '.' ) ] = issue.message;
		}
		expect( errors ).toEqual( c.errors );
		expect( result.success ).toBe( Object.keys( c.errors ).length === 0 );

		const direct = validateValues( fields, c.input, opts );
		expect( direct.hidden ).toEqual( c.hidden );
		expect( Object.keys( direct.valid ) ).toEqual( c.valid );
		expect( direct.errors ).toEqual( c.errors );
	}
);

test( 'server-only cases do not hang and raise no client error', () => {
	for ( const c of cases.filter( ( x ) => SERVER_ONLY.has( x.id ) ) ) {
		const { fields, opts } = setup( c );
		const started = Date.now();
		expect( buildValueSchema( fields, opts ).safeParse( c.input ).success ).toBe( true );
		expect( Date.now() - started ).toBeLessThan( 1000 );
	}
} );
