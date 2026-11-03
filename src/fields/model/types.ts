/**
 * Client-side field model. These types mirror the PHP value objects in
 * includes/Modules/Fields/Definition exactly:
 *   FieldDef  === Field::toArray()
 *   GroupDef  === FieldGroup::toArray()
 */

/** Canonical field types after ACF aliases are applied (schemas/field-type-aliases.json). */
export type FieldType =
	| 'text'
	| 'textarea'
	| 'number'
	| 'email'
	| 'url'
	| 'password'
	| 'wysiwyg'
	| 'image'
	| 'file'
	| 'gallery'
	| 'video'
	| 'link'
	| 'select'
	| 'radio'
	| 'true_false'
	| 'color'
	| 'date'
	| 'time'
	| 'datetime'
	| 'taxonomy'
	| 'relationship'
	| 'group'
	| 'repeater'
	| 'flexible_content'
	| 'code'
	| 'tab'
	| 'accordion'
	| 'message';

export type ConditionOperator =
	| '=='
	| '!='
	| '==contains'
	| '==empty'
	| '!=empty'
	| '==pattern';

export type ConditionRule = {
	field: string;
	operator: ConditionOperator;
	/** PHP always emits the key; null when the rule carries no value (e.g. `==empty`). */
	value: string | null;
};

export type LayoutDef = {
	key: string;
	name: string;
	label: string;
	subFields: FieldDef[];
	min?: number;
	max?: number;
};

export type FieldDef = {
	key: string;
	name: string;
	label: string;
	/** Canonical type. */
	type: FieldType;
	/** The type as written in the group, e.g. `date_picker`. */
	declaredType: string;
	required: boolean;
	default: unknown;
	instructions: string;
	placeholder: string;
	/** OR-list of AND-lists. */
	conditions: ConditionRule[][];
	subFields: FieldDef[];
	layouts: LayoutDef[];
	/**
	 * Every other key of the declaration, ACF-only ones included, untouched.
	 * PHP encodes an empty settings map as `[]`; reading a key yields undefined either way.
	 */
	settings: Record<string, unknown>;
};

export type GroupDef = {
	key: string;
	title: string;
	position: 'normal' | 'side' | 'acf_after_title';
	style: 'default' | 'seamless';
	fields: FieldDef[];
};

/** The JSON payload a field box mounts from (built by PHP BoxPayload, Task 12). */
export type BoxPayload = {
	groupKey: string;
	/** The group title (PHP always sends it). */
	title?: string;
	fields: FieldDef[];
	values: Record<string, unknown>;
	/**
	 * Pre-resolved data keyed by dotted value path ('hero', 'agenda.0.photo'):
	 * image {id,url,alt}; file {id,url,title,filename}; video {id,url};
	 * gallery [{id,url,alt}]; relationship [{id,title,type,status}];
	 * taxonomy {terms: [{id,name,parent}]} at a top-level path, or
	 * {terms: '<taxonomy>'} referencing the shared 'terms:<taxonomy>' list.
	 */
	display: Record<string, unknown>;
	/** optionsSource choices, by field name. */
	choices: Record<string, { key: string; label: string }[]>;
	/** True when the term lists were cut to the per-payload cap. */
	display_truncated?: boolean;
	errors: Record<string, string>;
	canUnfiltered: boolean;
	/** Top-level fields this user may not edit, with the reason; their values are not sent. */
	readOnly?: Record< string, 'unfiltered_html' >;
	maxRows: Record<string, number>;
	restRoot: string;
	restNonce: string;
	/** The edited object, e.g. 'post:10'. */
	object: string;
};
