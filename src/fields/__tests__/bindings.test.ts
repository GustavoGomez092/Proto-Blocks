/**
 * src/fields/bindings/register.ts registers the editor side of the
 * `proto-blocks/field` Block Bindings source when the editor has the
 * client API (WordPress 6.7+), and is a no-op without it.
 */

type Source = {
	name: string;
	label: string;
	getValues: ( args: {
		bindings: Record< string, { source: string; args: Record< string, unknown > } >;
	} ) => Record< string, string >;
};

function load( blocks: Record< string, unknown > ): typeof import( '../bindings/register' ) {
	let mod: typeof import( '../bindings/register' ) | undefined;
	jest.isolateModules( () => {
		jest.doMock( '@wordpress/blocks', () => blocks, { virtual: true } );
		jest.doMock( '@wordpress/i18n', () => ( { __: ( s: string ) => s } ) );
		// eslint-disable-next-line @typescript-eslint/no-require-imports
		mod = require( '../bindings/register' );
	} );
	return mod as typeof import( '../bindings/register' );
}

describe( 'proto-blocks/field bindings source (editor)', () => {
	afterEach( () => {
		jest.resetModules();
	} );

	it( 'registers the source once with the right name on import', () => {
		const registerBlockBindingsSource = jest.fn();
		const mod = load( { registerBlockBindingsSource } );

		expect( registerBlockBindingsSource ).toHaveBeenCalledTimes( 1 );
		const source = registerBlockBindingsSource.mock.calls[ 0 ][ 0 ] as Source;
		expect( source.name ).toBe( 'proto-blocks/field' );
		expect( source.label ).toBe( 'Proto-Blocks field' );

		expect( mod.registerFieldBindingSource() ).toBe( false );
		expect( registerBlockBindingsSource ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows "{name}" placeholders for each bound attribute', () => {
		const registerBlockBindingsSource = jest.fn();
		load( { registerBlockBindingsSource } );
		const source = registerBlockBindingsSource.mock.calls[ 0 ][ 0 ] as Source;

		expect(
			source.getValues( {
				bindings: {
					content: { source: 'proto-blocks/field', args: { name: 'subtitle' } },
					url: { source: 'proto-blocks/field', args: { name: 'hero', key: 'url' } },
					alt: { source: 'proto-blocks/field', args: {} },
				},
			} )
		).toEqual( { content: '{subtitle}', url: '{hero}', alt: '{}' } );
	} );

	it( 'does nothing when the editor has no client bindings API', () => {
		let mod: typeof import( '../bindings/register' ) | undefined;
		expect( () => {
			mod = load( {} );
		} ).not.toThrow();
		expect( mod?.registerFieldBindingSource() ).toBe( false );
	} );
} );
