/**
 * Ajv set up for schemas/field-group.schema.json. The schema's one
 * non-standard keyword, `uniqueFieldNames`, is registered here: no two fields
 * at one level share a non-empty name (GroupValidator's duplicate_name).
 * Compile the schema with the instance this returns, never a bare Ajv.
 */
import Ajv2020 from 'ajv/dist/2020';

export function createGroupAjv(): Ajv2020 {
	const ajv = new Ajv2020( { allErrors: true } );
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
	return ajv;
}
