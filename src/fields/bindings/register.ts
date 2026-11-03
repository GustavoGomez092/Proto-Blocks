/**
 * Editor side of the `proto-blocks/field` Block Bindings source.
 *
 * The values themselves are resolved on the server (FieldBindingSource.php),
 * escaped per field type and subject to the viewer's read permissions; the
 * editor only shows a "{name}" placeholder so authors see what is bound.
 * The client API exists from WordPress 6.7; on 6.5–6.6 this is a no-op and
 * the server source still renders bound values on the front end.
 */
import * as blocks from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

export const SOURCE_NAME = 'proto-blocks/field';

type Binding = { source?: string; args?: Record< string, unknown > };

type BindingsApi = {
	registerBlockBindingsSource?: ( source: {
		name: string;
		label: string;
		getValues: ( props: { bindings: Record< string, Binding > } ) => Record< string, string >;
		canUserEditValue?: () => boolean;
	} ) => void;
};

let registered = false;

function getValues( { bindings }: { bindings: Record< string, Binding > } ): Record< string, string > {
	const values: Record< string, string > = {};
	for ( const [ attribute, binding ] of Object.entries( bindings ?? {} ) ) {
		const name = binding?.args?.name;
		values[ attribute ] = `{${ typeof name === 'string' ? name : '' }}`;
	}
	return values;
}

/** Registers the source once; false when already done or the API is missing. */
export function registerFieldBindingSource(): boolean {
	const api = blocks as unknown as BindingsApi;
	if ( registered || typeof api.registerBlockBindingsSource !== 'function' ) {
		return false;
	}
	api.registerBlockBindingsSource( {
		name: SOURCE_NAME,
		label: __( 'Proto-Blocks field', 'proto-blocks' ),
		getValues,
		// Values live in post meta / term meta / options, edited elsewhere.
		canUserEditValue: () => false,
	} );
	registered = true;
	return true;
}

registerFieldBindingSource();
