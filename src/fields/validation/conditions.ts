/**
 * Conditional logic with ACF semantics, the client twin of
 * includes/Modules/Fields/Validation/ConditionEvaluator.php. Both are pinned
 * by tests/fixtures/fields/validation-cases.json.
 *
 * Also the one place user regexes are compiled on the client, so the
 * `==pattern` operator and the `pattern` field setting share one policy.
 */
import type { ConditionRule, FieldDef } from '../model/types';

/** Longest user pattern accepted, in bytes (PHP strlen). */
export const MAX_PATTERN_LENGTH = 200;

/** Longest subject a user pattern is run against, in bytes. */
export const MAX_SUBJECT_LENGTH = 10000;

/**
 * Longest subject (UTF-16 units) a catastrophic-looking pattern is still run
 * against. JS regexes have no backtrack limit or timeout, and the blow-up is
 * exponential: measured in Node, ^(a+)+$ on 24 chars + 'b' takes ~70 ms and
 * ^((a+)+)+$ on 18 chars ~1.8 s, so this stays where every such pattern
 * finishes in milliseconds (^((a+)+)+$ on 12 chars: ~2 ms). Short inputs, the
 * common case for condition controllers, therefore get the same answer as
 * the server.
 */
export const CATASTROPHIC_SUBJECT_MAX = 12;

const encoder = typeof TextEncoder !== 'undefined' ? new TextEncoder() : null;

function byteLength( s: string ): number {
	return encoder ? encoder.encode( s ).length : unescape( encodeURIComponent( s ) ).length;
}

function isObject( v: unknown ): v is Record< string, unknown > {
	return typeof v === 'object' && v !== null;
}

/** PHP is_array(): JS arrays and plain objects. */
export function isPhpArray( v: unknown ): v is unknown[] | Record< string, unknown > {
	return isObject( v );
}

/** PHP count() of an array value. */
export function phpCount( v: unknown ): number {
	if ( Array.isArray( v ) ) {
		return v.length;
	}
	return isObject( v ) ? Object.keys( v ).length : 0;
}

/** The evaluator's string view of a value: true '1', false '0', other non-scalars ''. */
export function phpStr( v: unknown ): string {
	if ( v === true ) {
		return '1';
	}
	if ( v === false ) {
		return '0';
	}
	return typeof v === 'string' || typeof v === 'number' ? String( v ) : '';
}

/** Empty means null, '', [], or false (a link without a url counts too). */
export function isEmpty( v: unknown ): boolean {
	if ( v === null || v === undefined || v === '' || v === false ) {
		return true;
	}
	if ( isObject( v ) ) {
		const keys = Object.keys( v );
		if ( keys.length === 0 ) {
			return true;
		}
		const url = ( v as Record< string, unknown > ).url;
		if ( ! Array.isArray( v ) && 'url' in v && keys.length <= 3 && ( url === '' || url === null ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Nested unbounded quantifiers ((a+)+, (a*)*, (a|b+){2,}) backtrack
 * exponentially. PCRE gives up and the server rejects the value; a JS engine
 * would hang the editor instead, so the client does not run them.
 */
export function isCatastrophicPattern( pattern: string ): boolean {
	const stack: boolean[] = [];
	let inClass = false;
	for ( let i = 0; i < pattern.length; i++ ) {
		const c = pattern[ i ];
		if ( c === '\\' ) {
			i++;
			continue;
		}
		if ( inClass ) {
			inClass = c !== ']';
			continue;
		}
		if ( c === '[' ) {
			inClass = true;
		} else if ( c === '(' ) {
			stack.push( false );
		} else if ( c === ')' ) {
			const innerQuantified = stack.pop() ?? false;
			const next = pattern[ i + 1 ];
			const quantified = next === '+' || next === '*' || ( next === '{' && /^\{\d+,\d*\}/.test( pattern.slice( i + 1 ) ) );
			if ( innerQuantified && quantified ) {
				return true;
			}
			if ( stack.length && ( innerQuantified || quantified ) ) {
				stack[ stack.length - 1 ] = true;
			}
		} else if ( ( c === '+' || c === '*' || ( c === '{' && /^\{\d+,\}/.test( pattern.slice( i ) ) ) ) && stack.length ) {
			stack[ stack.length - 1 ] = true;
		}
	}
	return false;
}

const compiled = new Map< string, RegExp | null >();

/**
 * The pattern as a RegExp, or null when empty, too long, or invalid. PHP
 * compiles '/' + pattern + '/Du': JS needs no delimiter, and without the `m`
 * flag `$` already matches only at the very end (PCRE's D). Patterns valid in
 * PCRE but not in JS's strict unicode mode are retried without `u`.
 */
export function compilePattern( pattern: string ): RegExp | null {
	if ( pattern === '' || byteLength( pattern ) > MAX_PATTERN_LENGTH ) {
		return null;
	}
	if ( compiled.has( pattern ) ) {
		return compiled.get( pattern )!;
	}
	let re: RegExp | null = null;
	for ( const flags of [ 'u', '' ] ) {
		try {
			re = new RegExp( pattern, flags );
			break;
		} catch {
			re = null;
		}
	}
	compiled.set( pattern, re );
	return re;
}

/**
 * True/false for a match; null when the pattern is unusable or the engine
 * cannot run it safely (a catastrophic-looking pattern on a longer subject
 * is left to the server: a condition reads that as false, validation
 * reports nothing).
 */
export function matchesPattern( pattern: string, subject: string ): boolean | null {
	const re = compilePattern( pattern );
	if ( re === null ) {
		return null;
	}
	if ( byteLength( subject ) > MAX_SUBJECT_LENGTH ) {
		return false;
	}
	if ( subject.length > CATASTROPHIC_SUBJECT_MAX && isCatastrophicPattern( pattern ) ) {
		return null;
	}
	try {
		return re.test( subject );
	} catch {
		return null;
	}
}

function inList( needle: string, list: unknown ): boolean {
	return Object.values( list as object ).some( ( item ) => ! isObject( item ) && phpStr( item ) === needle );
}

function equals( actual: unknown, expected: unknown ): boolean {
	const e = phpStr( expected );
	return isObject( actual ) ? inList( e, actual ) : phpStr( actual ) === e;
}

export function ruleMatches( operator: string, actual: unknown, expected: unknown ): boolean {
	switch ( operator ) {
		case '==':
			return equals( actual, expected );
		case '!=':
			return ! equals( actual, expected );
		case '==contains': {
			const needle = phpStr( expected );
			if ( needle === '' ) {
				return false;
			}
			return isObject( actual ) ? inList( needle, actual ) : phpStr( actual ).includes( needle );
		}
		case '==empty':
			return isEmpty( actual );
		case '!=empty':
			return ! isEmpty( actual );
		case '==pattern':
			return ! isObject( actual ) && matchesPattern( phpStr( expected ), phpStr( actual ) ) === true;
		default:
			return false;
	}
}

/**
 * @param siblingsByKey field key => raw value, for the fields sharing a scope
 *   with `field` (no chaining: a hidden controller still reports its value)
 */
export function isVisible( field: FieldDef, siblingsByKey: Record< string, unknown > ): boolean {
	if ( field.conditions.length === 0 ) {
		return true;
	}
	return field.conditions.some(
		( group ) =>
			group.length > 0 &&
			group.every(
				( rule: ConditionRule ) =>
					Object.prototype.hasOwnProperty.call( siblingsByKey, String( rule.field ?? '' ) ) &&
					ruleMatches( String( rule.operator ?? '' ), siblingsByKey[ String( rule.field ) ], rule.value ?? null )
			)
	);
}

/** Keys of the sibling fields any of `fields`' conditions read. */
export function controllerKeys( fields: FieldDef[] ): Set< string > {
	const keys = new Set< string >();
	for ( const f of fields ) {
		for ( const group of f.conditions ) {
			for ( const rule of group ) {
				keys.add( String( rule.field ) );
			}
		}
	}
	return keys;
}
