import exportFile from '../../../tests/fixtures/fields/acf-export-realistic.json';
import { normalizeField } from '../model/normalize';

const raw = ( exportFile as unknown as Array< { fields: Record< string, unknown >[] } > )[ 0 ].fields;
const byName = ( name: string ) => raw.find( ( f ) => f.name === name ) as Record< string, unknown >;

test( 'aliases map to the canonical type and keep the declared type', () => {
	const f = normalizeField( byName( 'start_date' ) );
	expect( f?.type ).toBe( 'date' );
	expect( f?.declaredType ).toBe( 'date_picker' );
	expect( f?.required ).toBe( true );
} );

test( 'a multiple select keeps settings.multiple', () => {
	const f = normalizeField( byName( 'audience' ) );
	expect( f?.type ).toBe( 'select' );
	expect( f?.settings.multiple ).toBeTruthy();
} );

test( 'layout-only tab fields are kept', () => {
	const tab = raw.find( ( f ) => f.type === 'tab' ) as Record< string, unknown >;
	expect( normalizeField( tab )?.type ).toBe( 'tab' );
} );

test( 'unsupported types are skipped', () => {
	expect( normalizeField( { key: 'field_map', name: 'map', label: 'Map', type: 'google_map' } ) ).toBeNull();
	expect( normalizeField( 'nope' as unknown as Record< string, unknown > ) ).toBeNull();
} );

test( 'the agenda repeater has exactly the keys of Field::toArray()', () => {
	const f = normalizeField( byName( 'agenda' ) )!;
	expect( Object.keys( f ) ).toEqual( [
		'key', 'name', 'label', 'type', 'declaredType', 'required', 'default',
		'instructions', 'placeholder', 'conditions', 'subFields', 'layouts', 'settings',
	] );
	expect( f.subFields.map( ( s ) => [ s.name, s.type ] ) ).toEqual( [
		[ 'time', 'time' ],
		[ 'title', 'text' ],
		[ 'notes', 'wysiwyg' ],
	] );
	expect( f.settings.sub_fields ).toBeUndefined();
	expect( f.settings.max ).toBe( 20 );
	expect( f.conditions ).toEqual( [] );
} );

test( 'flexible layouts keyed by id become a list with sub-fields', () => {
	const f = normalizeField( byName( 'sections' ) )!;
	expect( f.layouts.map( ( l ) => [ l.key, l.name, l.label, l.subFields.length ] ) ).toEqual( [
		[ 'layout_6543a1b2c3e01', 'text_block', 'Text block', 1 ],
		[ 'layout_6543a1b2c3e02', 'quote', 'Quote', 2 ],
	] );
	expect( 'min' in f.layouts[ 0 ] ).toBe( false );
} );

test( 'conditions keep the rule value; a rule without one gets null', () => {
	const f = normalizeField( byName( 'stream_url' ) )!;
	expect( f.conditions ).toEqual( [ [ { field: 'field_6543a1b2c3d50', operator: '==', value: '1' } ] ] );
	const empty = normalizeField( {
		key: 'k', name: 'n', label: 'L', type: 'text',
		conditional_logic: [ [ { field: 'a', operator: '==empty' } ] ],
	} )!;
	expect( empty.conditions ).toEqual( [ [ { field: 'a', operator: '==empty', value: null } ] ] );
} );

test( 'GroupNormalizer field rules: checkbox, post_object, page_link, user', () => {
	expect( normalizeField( { key: 'a', name: 'a', label: 'A', type: 'checkbox' } )?.settings ).toEqual( { multiple: true } );
	expect( normalizeField( { key: 'b', name: 'b', label: 'B', type: 'post_object' } )?.settings ).toEqual( { max: 1 } );
	const pl = normalizeField( { key: 'c', name: 'c', label: 'C', type: 'page_link', multiple: 1 } )!;
	expect( pl.type ).toBe( 'relationship' );
	expect( pl.settings ).toEqual( { multiple: 1 } );
	expect( normalizeField( { key: 'd', name: 'd', label: 'D', type: 'user' } )?.settings ).toEqual( {
		optionsSource: 'wp:users',
	} );
} );
