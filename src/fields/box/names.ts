/**
 * Form names for field box inputs. Every input carries a real `name`, so the
 * ordinary post form submit (classic editor, the block editor's meta box
 * request, page builders) saves it without any JavaScript on the way out.
 */

export type FieldPath = ( string | number )[];

/** ['agenda', 2, 'title'] → 'proto_fields[group_x][agenda][2][title]' */
export function inputName( groupKey: string, path: FieldPath ): string {
	return `proto_fields[${ groupKey }]` + path.map( ( p ) => `[${ p }]` ).join( '' );
}

/**
 * One entry per top-level field the box actually rendered for editing. The
 * server only writes fields listed here, so a hidden or read-only field keeps
 * its stored value.
 */
export const PRESENT_NAME = ( groupKey: string ) => `proto_fields_present[${ groupKey }][]`;

/** Key under which a flexible content row submits its layout name. */
export const LAYOUT_KEY = '_layout';

/** The react-hook-form path, which is also the validation error path: 'agenda.2.title'. */
export function dottedPath( path: FieldPath ): string {
	return path.join( '.' );
}

/** A DOM id unique per box and path. */
export function domId( groupKey: string, path: FieldPath ): string {
	return `pbf-${ groupKey }-${ path.join( '-' ) }`.replace( /[^A-Za-z0-9_-]/g, '_' );
}
