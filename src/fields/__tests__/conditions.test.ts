import { isVisible } from '../validation/conditions';
import type { ConditionRule, FieldDef } from '../model/types';

function field( conditions: ConditionRule[][] ): FieldDef {
	return {
		key: 'field_x', name: 'x', label: 'X', type: 'text', declaredType: 'text', required: false,
		default: null, instructions: '', placeholder: '', conditions, subFields: [], layouts: [], settings: {},
	};
}
const rule = ( operator: ConditionRule[ 'operator' ], value: string | null = null, f = 'field_a' ): ConditionRule => ( {
	field: f,
	operator,
	value,
} );
const one = ( r: ConditionRule, actual: unknown ) => isVisible( field( [ [ r ] ] ), { field_a: actual } );

test( 'no conditions is visible', () => {
	expect( isVisible( field( [] ), {} ) ).toBe( true );
} );

test( '== and != compare as strings, arrays by membership', () => {
	expect( one( rule( '==', 'a' ), 'a' ) ).toBe( true );
	expect( one( rule( '==', '5' ), 5 ) ).toBe( true );
	expect( one( rule( '==', '1' ), true ) ).toBe( true );
	expect( one( rule( '==', '0' ), false ) ).toBe( true );
	expect( one( rule( '==', 'y' ), [ 'x', 'y' ] ) ).toBe( true );
	expect( one( rule( '!=', 'b' ), 'a' ) ).toBe( true );
	expect( one( rule( '!=', 'a' ), 'a' ) ).toBe( false );
} );

test( '==contains: substring, list membership, empty needle false', () => {
	expect( one( rule( '==contains', 'ell' ), 'hello' ) ).toBe( true );
	expect( one( rule( '==contains', 'y' ), [ 'x', 'y' ] ) ).toBe( true );
	expect( one( rule( '==contains', 'y' ), [ 'xy' ] ) ).toBe( false );
	expect( one( rule( '==contains', '' ), 'hello' ) ).toBe( false );
	expect( one( rule( '==contains', null ), 'hello' ) ).toBe( false );
} );

test( '==empty and !=empty', () => {
	for ( const empty of [ null, undefined, '', false, [], {}, { url: '', title: 'x' } ] ) {
		expect( one( rule( '==empty' ), empty ) ).toBe( true );
		expect( one( rule( '!=empty' ), empty ) ).toBe( false );
	}
	for ( const full of [ '0', 0, 'a', [ 1 ], { url: 'https://x.test' } ] ) {
		expect( one( rule( '==empty' ), full ) ).toBe( false );
		expect( one( rule( '!=empty' ), full ) ).toBe( true );
	}
} );

test( '==pattern matches with $ anchored at the very end, slashes allowed', () => {
	expect( one( rule( '==pattern', '^a/b$' ), 'a/b' ) ).toBe( true );
	expect( one( rule( '==pattern', '^a\\/b$' ), 'a/b' ) ).toBe( true );
	expect( one( rule( '==pattern', '^[A-Z]{3}$' ), 'ABC\n' ) ).toBe( false );
	expect( one( rule( '==pattern', '^\\d+$' ), [ '1' ] ) ).toBe( false );
} );

test( 'invalid, overlong or catastrophic regex rules are false', () => {
	expect( one( rule( '==pattern', '([a-' ), 'x' ) ).toBe( false );
	expect( one( rule( '==pattern', 'abc\\' ), 'abc' ) ).toBe( false );
	expect( one( rule( '==pattern', 'a'.repeat( 201 ) ), 'a'.repeat( 201 ) ) ).toBe( false );
	expect( one( rule( '==pattern', '^(a+)+$' ), 'a'.repeat( 5000 ) + 'b' ) ).toBe( false );
} );

test( 'catastrophic-looking patterns still run on short subjects, like the server', () => {
	expect( one( rule( '==pattern', '^(a+)+$' ), 'aaaa' ) ).toBe( true );
	expect( one( rule( '==pattern', '^(a+)+$' ), 'aaab' ) ).toBe( false );
	expect( one( rule( '==pattern', '^((a+)+)+$' ), 'a'.repeat( 11 ) + 'b' ) ).toBe( false );
	const started = Date.now();
	expect( one( rule( '==pattern', '^((a+)+)+$' ), 'a'.repeat( 40 ) + 'b' ) ).toBe( false );
	expect( Date.now() - started ).toBeLessThan( 500 );
} );

test( 'unknown keys and unknown operators are false', () => {
	expect( isVisible( field( [ [ rule( '==', 'a', 'field_missing' ) ] ] ), { field_a: 'a' } ) ).toBe( false );
	expect( one( { field: 'field_a', operator: '===' as ConditionRule[ 'operator' ], value: 'a' }, 'a' ) ).toBe( false );
} );

test( 'OR of AND groups', () => {
	const f = field( [
		[ rule( '==', 'a' ), { field: 'field_b', operator: '==', value: 'b' } ],
		[ rule( '==', 'z' ) ],
	] );
	expect( isVisible( f, { field_a: 'a', field_b: 'b' } ) ).toBe( true );
	expect( isVisible( f, { field_a: 'a', field_b: 'c' } ) ).toBe( false );
	expect( isVisible( f, { field_a: 'z', field_b: 'c' } ) ).toBe( true );
	expect( isVisible( field( [ [] ] ), {} ) ).toBe( false );
} );
