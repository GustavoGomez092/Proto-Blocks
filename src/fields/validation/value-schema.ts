/**
 * Client value validation, the twin of
 * includes/Modules/Fields/Validation/ValueValidator.php: same rules, same
 * dotted error paths ('agenda.1.title'), same messages. Both are pinned by
 * tests/fixtures/fields/validation-cases.json. The server stays authoritative;
 * this only gives the editor early, identical feedback.
 */
import { __, sprintf } from '@wordpress/i18n';
import { z } from 'zod';
import type { FieldDef } from '../model/types';
import { phpEmpty } from '../model/normalize';
import { compilePattern, isEmpty, isPhpArray, isVisible, matchesPattern, phpCount } from './conditions';

/** The server's messages, word for word (ValueValidator.php). */
export const MESSAGES: Record< string, string > = {
	required: __( 'This field is required.', 'proto-blocks' ),
	format: __( 'The value does not match the required format.', 'proto-blocks' ),
	email: __( 'Enter a valid email address.', 'proto-blocks' ),
	url: __( 'Enter a valid URL.', 'proto-blocks' ),
	date: __( 'Enter a valid date.', 'proto-blocks' ),
	/* translators: %d: maximum number of characters */
	maxLength: __( 'Enter at most %d characters.', 'proto-blocks' ),
	/* translators: %d: minimum number of characters */
	minLength: __( 'Enter at least %d characters.', 'proto-blocks' ),
	invalidPattern: __( 'Invalid pattern rule.', 'proto-blocks' ),
	/* translators: %s: minimum value */
	min: __( 'Enter a number no less than %s.', 'proto-blocks' ),
	/* translators: %s: maximum value */
	max: __( 'Enter a number no greater than %s.', 'proto-blocks' ),
	invalidChoice: __( 'Invalid choice.', 'proto-blocks' ),
	choice: __( 'Choose one of the listed options.', 'proto-blocks' ),
	/* translators: %d: minimum number of rows */
	minRows: __( 'Add at least %d rows.', 'proto-blocks' ),
	/* translators: %d: maximum number of rows */
	maxRows: __( 'Add no more than %d rows.', 'proto-blocks' ),
};

export type ValueSchemaOptions = {
	/** Hard row cap for a field (proto_blocks_repeater_max_items); null = none. */
	maxRows?: ( f: FieldDef ) => number | null | undefined;
	/** Allowed values for a field with `optionsSource`; null rejects every value (fail closed). */
	choices?: ( f: FieldDef ) => string[] | null | undefined;
	/** Fields the editor cannot change (read-only code, inputs not loaded): never reported. */
	skip?: ( f: FieldDef ) => boolean;
};

export type Issue = { path: ( string | number )[]; message: string };

export type ValidationResult = {
	/** Top-level field name => value, for visible fields that passed. */
	valid: Record< string, unknown >;
	/** Dotted path => message. */
	errors: Record< string, string >;
	hidden: string[];
	issues: Issue[];
};

const LAYOUT_ONLY = new Set( [ 'tab', 'accordion', 'message' ] );
const COUNTED = new Set( [ 'repeater', 'flexible_content', 'gallery', 'relationship' ] );

type Out = { errors: Map< string, Issue >; hidden: string[] };

function isList( v: unknown ): boolean {
	return Array.isArray( v ) || ( isPhpArray( v ) && Object.keys( v ).length === 0 );
}

/** PHP is_numeric(). */
function isNumeric( v: unknown ): boolean {
	if ( typeof v === 'number' ) {
		return Number.isFinite( v );
	}
	return typeof v === 'string' && /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/.test( v );
}

function intSetting( f: FieldDef, key: string ): number | null {
	const v = f.settings?.[ key ];
	if ( ! isNumeric( v ) ) {
		return null;
	}
	const n = Math.trunc( Number( v ) );
	return n > 0 ? n : null;
}

function setting( f: FieldDef, key: string ): unknown {
	const s = f.settings;
	return s && ! Array.isArray( s ) && Object.prototype.hasOwnProperty.call( s, key ) ? s[ key ] : undefined;
}

function add( out: Out, path: ( string | number )[], message: string ): void {
	const key = path.join( '.' );
	if ( ! out.errors.has( key ) ) {
		out.errors.set( key, { path, message } );
	}
}

/* ---------- scalar checks ---------- */

/** WordPress is_email(). */
export function isEmail( email: string ): boolean {
	if ( email.length < 6 || email.indexOf( '@', 1 ) === -1 ) {
		return false;
	}
	const at = email.indexOf( '@' );
	const local = email.slice( 0, at );
	const domain = email.slice( at + 1 );
	if ( ! /^[a-zA-Z0-9!#$%&'*+/=?^_`{|}~.-]+$/.test( local ) ) {
		return false;
	}
	if ( /\.{2,}/.test( domain ) || domain.replace( /^[\s\0.]+|[\s\0.]+$/g, '' ) !== domain ) {
		return false;
	}
	const subs = domain.split( '.' );
	if ( subs.length < 2 ) {
		return false;
	}
	return subs.every( ( sub ) => sub.replace( /^[\s\0-]+|[\s\0-]+$/g, '' ) === sub && /^[a-z0-9-]+$/i.test( sub ) );
}

const PROTOCOLS = [
	'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet',
	'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn',
];

/** AttributeSanitizer::sanitizeUrl() (trim + esc_url_raw) closely enough to tell whether it would change a value. */
export function sanitizeUrl( value: string ): string {
	let url = value.trim();
	if ( url === '' ) {
		return '';
	}
	url = url.replace( / /g, '%20' ).replace( /[^a-z0-9\-~+_.?#=!&;,/:%@$|*'()[\]\u0080-￿]/gi, '' );
	if ( url === '' ) {
		return '';
	}
	if ( ! /^mailto:/i.test( url ) ) {
		let prev;
		do {
			prev = url;
			url = url.replace( /%0[da]/gi, '' );
		} while ( prev !== url );
	}
	url = url.replace( /;\/\//g, '://' );
	if ( ! url.includes( ':' ) && ! [ '/', '#', '?' ].includes( url[ 0 ] ) && ! /^[a-z0-9-]+?\.php/i.test( url ) ) {
		url = 'http://' + url;
	}
	if ( url[ 0 ] === '/' ) {
		return url;
	}
	// wp_kses_bad_protocol: strip disallowed schemes until none is left.
	let rest = url;
	for ( let i = 0; i < 6; i++ ) {
		const m = /^([\sA-Za-z0-9]*):/.exec( rest );
		if ( ! m ) {
			break;
		}
		const scheme = m[ 1 ].replace( /\s/g, '' ).toLowerCase();
		if ( PROTOCOLS.includes( scheme ) ) {
			rest = scheme + ':' + rest.slice( m[ 0 ].length );
			break;
		}
		rest = rest.slice( m[ 0 ].length );
	}
	return rest.toLowerCase() === url.toLowerCase() ? url : '';
}

function daysIn( year: number, month: number ): number {
	return new Date( Date.UTC( year, month, 0 ) ).getUTCDate();
}

/** The formats ValueValidator::dateOk() accepts, round-trip exact. */
export function isValidDate( type: string, v: string ): boolean {
	const date = '(\\d{4})-(\\d{2})-(\\d{2})';
	const time = '(\\d{2}):(\\d{2})(?::(\\d{2}))?';
	const re =
		type === 'date'
			? new RegExp( `^${ date }$` )
			: type === 'time'
				? new RegExp( `^()()()${ time }$` )
				: new RegExp( `^${ date }[ T]${ time }$` );
	const m = re.exec( v );
	if ( ! m ) {
		return false;
	}
	if ( type !== 'time' ) {
		const [ y, mo, d ] = [ Number( m[ 1 ] ), Number( m[ 2 ] ), Number( m[ 3 ] ) ];
		if ( mo < 1 || mo > 12 || d < 1 || d > daysIn( y, mo ) ) {
			return false;
		}
	}
	if ( type !== 'date' ) {
		const [ h, mi, s ] = [ Number( m[ 4 ] ), Number( m[ 5 ] ), m[ 6 ] === undefined ? 0 : Number( m[ 6 ] ) ];
		if ( h > 23 || mi > 59 || s > 59 ) {
			return false;
		}
	}
	return true;
}

function choiceError( f: FieldDef, value: unknown, opts: ValueSchemaOptions ): string | null {
	let allowed: string[] | null = null;
	const source = setting( f, 'optionsSource' );
	if ( source !== undefined && source !== null ) {
		const resolved = opts.choices ? opts.choices( f ) : null;
		if ( ! Array.isArray( resolved ) ) {
			return MESSAGES.invalidChoice;
		}
		allowed = resolved.map( String );
	} else {
		const choices = setting( f, 'choices' );
		if ( Array.isArray( choices ) && choices.length ) {
			allowed = choices.map( ( c ) => String( c ) );
		} else if ( isPhpArray( choices ) && ! Array.isArray( choices ) && Object.keys( choices ).length ) {
			allowed = Object.keys( choices );
		}
	}
	if ( allowed === null ) {
		return null;
	}
	const multiple = ! phpEmpty( setting( f, 'multiple' ) );
	if ( isPhpArray( value ) && ! multiple ) {
		return MESSAGES.choice;
	}
	const list = isPhpArray( value ) ? Object.values( value ) : [ value ];
	for ( const v of list ) {
		if ( ( typeof v !== 'string' && typeof v !== 'number' ) || ! allowed.includes( String( v ) ) ) {
			return MESSAGES.choice;
		}
	}
	return null;
}

function numberError( f: FieldDef, value: unknown ): string | null {
	if ( typeof value === 'boolean' || ! isNumeric( value ) ) {
		return MESSAGES.format;
	}
	const n = Number( value );
	const min = setting( f, 'min' );
	if ( isNumeric( min ) && n < Number( min ) ) {
		return sprintf( MESSAGES.min, String( min ) );
	}
	const max = setting( f, 'max' );
	if ( isNumeric( max ) && n > Number( max ) ) {
		return sprintf( MESSAGES.max, String( max ) );
	}
	return null;
}

function countError( f: FieldDef, n: number, opts: ValueSchemaOptions ): string | null {
	const min = intSetting( f, 'min' );
	if ( min !== null && n < min ) {
		return sprintf( MESSAGES.minRows, min );
	}
	let max = intSetting( f, 'max' );
	const cap = opts.maxRows ? opts.maxRows( f ) : null;
	if ( typeof cap === 'number' && Number.isInteger( cap ) && cap > 0 ) {
		max = max === null ? cap : Math.min( max, cap );
	}
	if ( max !== null && n > max ) {
		return sprintf( MESSAGES.maxRows, max );
	}
	return null;
}

function scalarError( f: FieldDef, value: unknown, opts: ValueSchemaOptions ): string | null {
	switch ( f.type ) {
		case 'select':
		case 'radio':
			return choiceError( f, value, opts );
		case 'number':
			return numberError( f, value );
		case 'email':
			if ( typeof value !== 'string' || ! isEmail( value ) ) {
				return MESSAGES.email;
			}
			break;
		case 'url':
			if ( typeof value !== 'string' || value.trim() === '' || sanitizeUrl( value ) !== value ) {
				return MESSAGES.url;
			}
			break;
		case 'date':
		case 'time':
		case 'datetime':
			if ( typeof value !== 'string' || ! isValidDate( f.type, value ) ) {
				return MESSAGES.date;
			}
			break;
	}

	if ( COUNTED.has( f.type ) ) {
		return countError( f, phpCount( value ), opts );
	}

	if ( typeof value === 'string' ) {
		// Code points, like PHP mb_strlen: an astral emoji is one character.
		const len = [ ...value ].length;
		const max = intSetting( f, 'maxlength' );
		if ( max !== null && len > max ) {
			return sprintf( MESSAGES.maxLength, max );
		}
		const min = intSetting( f, 'minlength' );
		if ( min !== null && len < min ) {
			return sprintf( MESSAGES.minLength, min );
		}
		const pattern = setting( f, 'pattern' );
		if ( typeof pattern === 'string' && pattern !== '' ) {
			if ( compilePattern( pattern ) === null ) {
				return MESSAGES.invalidPattern;
			}
			// null = the client cannot run this pattern safely; the server decides.
			if ( matchesPattern( pattern, value ) === false ) {
				return MESSAGES.format;
			}
		}
	}
	return null;
}

/* ---------- scopes ---------- */

function scope(
	fields: FieldDef[],
	input: Record< string, unknown >,
	prefix: ( string | number )[],
	opts: ValueSchemaOptions,
	out: Out,
	valid: Record< string, unknown >
): void {
	const byKey: Record< string, unknown > = {};
	for ( const f of fields ) {
		if ( f.name !== '' && ! LAYOUT_ONLY.has( f.type ) ) {
			byKey[ f.key ] = input[ f.name ] ?? null;
		}
	}
	for ( const f of fields ) {
		if ( f.name === '' || LAYOUT_ONLY.has( f.type ) ) {
			continue;
		}
		const path = [ ...prefix, f.name ];
		if ( ! isVisible( f, byKey ) ) {
			out.hidden.push( path.join( '.' ) );
			continue;
		}
		if ( opts.skip?.( f ) ) {
			continue;
		}
		const before = out.errors.size;
		const value = input[ f.name ] ?? null;
		field( f, value, path, opts, out );
		if ( out.errors.size === before ) {
			valid[ f.name ] = value;
		}
	}
}

function row( fields: FieldDef[], value: unknown, prefix: ( string | number )[], opts: ValueSchemaOptions, out: Out ): void {
	scope( fields, value as Record< string, unknown >, prefix, opts, out, {} );
}

function field( f: FieldDef, value: unknown, path: ( string | number )[], opts: ValueSchemaOptions, out: Out ): void {
	if ( f.type === 'group' && ( value === null || value === undefined || value === '' || ( isPhpArray( value ) && phpCount( value ) === 0 ) ) ) {
		value = {}; // a group always descends so its required sub-fields are enforced
	}
	if ( f.type !== 'group' && isEmpty( value ) ) {
		if ( f.required ) {
			add( out, path, MESSAGES.required );
		} else if ( COUNTED.has( f.type ) ) {
			const error = countError( f, 0, opts );
			if ( error !== null ) {
				add( out, path, error );
			}
		}
		return;
	}

	switch ( f.type ) {
		case 'group':
			if ( ! isPhpArray( value ) || ( Array.isArray( value ) && value.length > 0 ) ) {
				add( out, path, MESSAGES.format );
				return;
			}
			row( f.subFields, value, path, opts, out );
			return;
		case 'repeater':
		case 'flexible_content': {
			if ( ! isList( value ) ) {
				add( out, path, MESSAGES.format );
				return;
			}
			const rows = Object.values( value as object ) as unknown[];
			const error = countError( f, rows.length, opts );
			if ( error !== null ) {
				add( out, path, error );
				return;
			}
			rows.forEach( ( r, i ) => {
				if ( f.type === 'repeater' ) {
					if ( ! isPhpArray( r ) ) {
						add( out, [ ...path, i ], MESSAGES.format );
						return;
					}
					row( f.subFields, r, [ ...path, i ], opts, out );
					return;
				}
				const name = isPhpArray( r ) ? ( r as Record< string, unknown > ).layout : undefined;
				const layout = typeof name === 'string' ? f.layouts.find( ( l ) => l.name === name ) : undefined;
				if ( ! layout ) {
					add( out, [ ...path, i, 'layout' ], MESSAGES.choice );
					return;
				}
				row( layout.subFields, r, [ ...path, i ], opts, out );
			} );
			return;
		}
	}

	const error = scalarError( f, value, opts );
	if ( error !== null ) {
		add( out, path, error );
	}
}

/** Validates the values of one scope (a post's box, an options page). */
export function validateValues( fields: FieldDef[], input: unknown, opts: ValueSchemaOptions = {} ): ValidationResult {
	const out: Out = { errors: new Map(), hidden: [] };
	const valid: Record< string, unknown > = {};
	scope( fields, isPhpArray( input ) ? ( input as Record< string, unknown > ) : {}, [], opts, out, valid );
	const issues = [ ...out.errors.values() ];
	const errors: Record< string, string > = {};
	for ( const issue of issues ) {
		errors[ issue.path.join( '.' ) ] = issue.message;
	}
	return { valid, errors, hidden: out.hidden, issues };
}

/**
 * The zod schema for a box's values, for react-hook-form's zodResolver.
 * Hidden fields are skipped; issue paths and messages match the server's.
 */
export function buildValueSchema( fields: FieldDef[], opts: ValueSchemaOptions = {} ): z.ZodType {
	return z.any().superRefine( ( values, ctx ) => {
		for ( const issue of validateValues( fields, values, opts ).issues ) {
			ctx.addIssue( { code: 'custom', path: issue.path, message: issue.message } );
		}
	} );
}
