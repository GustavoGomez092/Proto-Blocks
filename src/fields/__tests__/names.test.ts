import { inputName, PRESENT_NAME, LAYOUT_KEY, dottedPath, domId } from '../box/names';

test( 'top-level and nested repeater paths', () => {
	expect( inputName( 'group_x', [ 'subtitle' ] ) ).toBe( 'proto_fields[group_x][subtitle]' );
	expect( inputName( 'group_x', [ 'agenda', 2, 'title' ] ) ).toBe( 'proto_fields[group_x][agenda][2][title]' );
	expect( inputName( 'group_x', [ 'agenda', 0, 'speakers', 1, 'name' ] ) ).toBe(
		'proto_fields[group_x][agenda][0][speakers][1][name]'
	);
	expect( inputName( 'group_x', [ 'hero', 'headline' ] ) ).toBe( 'proto_fields[group_x][hero][headline]' );
} );

test( 'flexible rows carry their layout under _layout', () => {
	expect( LAYOUT_KEY ).toBe( '_layout' );
	expect( inputName( 'group_x', [ 'sections', 3, LAYOUT_KEY ] ) ).toBe( 'proto_fields[group_x][sections][3][_layout]' );
	expect( inputName( 'group_x', [ 'sections', 3, 'cite' ] ) ).toBe( 'proto_fields[group_x][sections][3][cite]' );
} );

test( 'present marker and helpers', () => {
	expect( PRESENT_NAME( 'group_x' ) ).toBe( 'proto_fields_present[group_x][]' );
	expect( dottedPath( [ 'agenda', 2, 'title' ] ) ).toBe( 'agenda.2.title' );
	expect( domId( 'group_x', [ 'agenda', 2, 'title' ] ) ).toBe( 'pbf-group_x-agenda-2-title' );
} );
