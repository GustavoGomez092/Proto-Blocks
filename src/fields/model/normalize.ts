/**
 * Client twin of GroupNormalizer's per-field rules
 * (includes/Modules/Fields/Definition/GroupNormalizer.php): ACF aliases from
 * schemas/field-type-aliases.json, checkbox → select multiple,
 * post_object/page_link → relationship with max 1, user → select over
 * wp:users, unknown types skipped. The result has the shape of
 * Field::toArray(), key order included.
 *
 * Structural validation is the server's job (GroupValidator); this accepts
 * any input without throwing.
 */
import aliasMap from '../../../schemas/field-type-aliases.json';
import type { ConditionRule, FieldDef, FieldType, LayoutDef } from './types';

const TYPES: readonly FieldType[] = [
	'text', 'textarea', 'number', 'email', 'url', 'password', 'wysiwyg', 'image', 'file', 'gallery', 'video', 'link',
	'select', 'radio', 'true_false', 'color', 'date', 'time', 'datetime', 'taxonomy', 'relationship',
	'group', 'repeater', 'flexible_content', 'code', 'tab', 'accordion', 'message',
];

const ALIASES = aliasMap as Record< string, FieldType >;

/** Keys modelled by FieldDef; every other key goes to `settings`. */
const FIELD_KEYS = new Set( [
	'key', 'name', 'label', 'type', 'required', 'default_value', 'instructions', 'placeholder',
	'conditional_logic', 'sub_fields', 'layouts',
] );

type Raw = Record< string, unknown >;

function isObject( v: unknown ): v is Raw {
	return typeof v === 'object' && v !== null;
}

/** PHP (string) cast of a scalar; anything else is ''. */
function str( v: unknown ): string {
	if ( v === true ) {
		return '1';
	}
	if ( typeof v === 'string' || typeof v === 'number' ) {
		return String( v );
	}
	return '';
}

/** PHP empty(). */
export function phpEmpty( v: unknown ): boolean {
	if ( v === null || v === undefined || v === false || v === 0 || v === '' || v === '0' ) {
		return true;
	}
	if ( Array.isArray( v ) ) {
		return v.length === 0;
	}
	return isObject( v ) && Object.keys( v ).length === 0;
}

function isNumeric( v: unknown ): boolean {
	return ( typeof v === 'number' && Number.isFinite( v ) ) || ( typeof v === 'string' && /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test( v ) );
}

/** Values of a PHP array (list or map) in order. */
function values( v: unknown ): unknown[] {
	if ( Array.isArray( v ) ) {
		return v;
	}
	return isObject( v ) ? Object.values( v ) : [];
}

function fields( raw: unknown ): FieldDef[] {
	return values( raw )
		.map( ( f ) => ( isObject( f ) ? normalizeField( f ) : null ) )
		.filter( ( f ): f is FieldDef => f !== null );
}

function conditions( logic: unknown ): ConditionRule[][] {
	if ( ! isObject( logic ) ) {
		return [];
	}
	const or: ConditionRule[][] = [];
	for ( const branch of values( logic ) ) {
		const and: ConditionRule[] = values( branch )
			.filter( isObject )
			.map( ( rule ) => ( {
				field: str( rule.field ),
				operator: str( rule.operator ) as ConditionRule[ 'operator' ],
				value: ( rule.value ?? null ) as string | null,
			} ) );
		if ( and.length > 0 ) {
			or.push( and );
		}
	}
	return or;
}

function layouts( raw: unknown ): LayoutDef[] {
	const entries: [ string | number, unknown ][] = Array.isArray( raw )
		? raw.map( ( l, i ) => [ i, l ] )
		: isObject( raw )
			? Object.entries( raw )
			: [];
	const out: LayoutDef[] = [];
	for ( const [ k, layout ] of entries ) {
		if ( ! isObject( layout ) ) {
			continue;
		}
		const def: LayoutDef = {
			key: typeof layout.key === 'string' ? layout.key : typeof k === 'string' ? k : str( layout.name ),
			name: str( layout.name ),
			label: str( layout.label ?? layout.name ),
			subFields: fields( layout.sub_fields ?? [] ),
		};
		for ( const bound of [ 'min', 'max' ] as const ) {
			if ( layout[ bound ] !== undefined && layout[ bound ] !== null && isNumeric( layout[ bound ] ) ) {
				def[ bound ] = Math.trunc( Number( layout[ bound ] ) );
			}
		}
		out.push( def );
	}
	return out;
}

export function normalizeField( raw: Record< string, unknown > ): FieldDef | null {
	if ( ! isObject( raw ) || Array.isArray( raw ) ) {
		return null;
	}
	const declared = str( raw.type );
	const type: FieldType | undefined = ( TYPES as readonly string[] ).includes( declared )
		? ( declared as FieldType )
		: Object.prototype.hasOwnProperty.call( ALIASES, declared )
			? ALIASES[ declared ]
			: undefined;
	if ( ! type ) {
		return null;
	}

	const settings: Record< string, unknown > = {};
	for ( const [ k, v ] of Object.entries( raw ) ) {
		if ( ! FIELD_KEYS.has( k ) ) {
			settings[ k ] = v;
		}
	}
	switch ( declared ) {
		case 'checkbox':
			settings.multiple = true;
			break;
		case 'post_object':
		case 'page_link':
			if ( phpEmpty( settings.multiple ) ) {
				settings.max = 1;
			}
			break;
		case 'user':
			settings.optionsSource = 'wp:users';
			break;
	}

	let subFields: FieldDef[] = [];
	if ( type === 'repeater' || type === 'group' ) {
		subFields = fields( raw.sub_fields );
	} else if ( 'sub_fields' in raw ) {
		settings.sub_fields = raw.sub_fields;
	}

	return {
		key: str( raw.key ),
		name: typeof raw.name === 'string' ? raw.name : '',
		label: str( raw.label ),
		type,
		declaredType: declared,
		required: ! phpEmpty( raw.required ),
		default: raw.default_value ?? null,
		instructions: str( raw.instructions ),
		placeholder: str( raw.placeholder ),
		conditions: conditions( raw.conditional_logic ),
		subFields,
		layouts: type === 'flexible_content' ? layouts( raw.layouts ) : [],
		settings,
	};
}
