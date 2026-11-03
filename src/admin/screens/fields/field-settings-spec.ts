/**
 * What the builder offers per field type. The type list is the client field
 * model's `FieldType` (src/fields/model/types.ts, the twin of the server's
 * TypeRegistry): `TYPE_META` is a `Record<FieldType, …>`, so the compiler
 * refuses a type the server does not know and one the builder forgot. ACF
 * aliases come from schemas/field-type-aliases.json, the file both sides read.
 */
import { __ } from '@wordpress/i18n';
import aliasMap from '../../../../schemas/field-type-aliases.json';
import type { FieldType } from '@/fields/model/types';
import { canonicalType, LAYOUT_ONLY_TYPES } from './builder-state';

export type SettingControl = 'text' | 'textarea' | 'number' | 'switch' | 'select' | 'multiselect' | 'choices' | 'postTypes' | 'taxonomies';

export type SettingSpec = {
	/** The key in the field object; `wrapper.width` is `width` inside `wrapper`. */
	key: string;
	label: string;
	control: SettingControl;
	options?: { value: string; label: string }[];
	help?: string;
	/** postTypes / taxonomies: one slug (a string) instead of a list. */
	single?: boolean;
};

export type TypeCategory = 'basic' | 'content' | 'choice' | 'relational' | 'layout';

export const CATEGORY_LABELS: Record<TypeCategory, string> = {
	basic: __('Basic', 'proto-blocks'),
	content: __('Content', 'proto-blocks'),
	choice: __('Choice', 'proto-blocks'),
	relational: __('Relational', 'proto-blocks'),
	layout: __('Layout', 'proto-blocks'),
};

export const TYPE_META: Record<FieldType, { label: string; category: TypeCategory; help?: string }> = {
	text: { label: __('Text', 'proto-blocks'), category: 'basic' },
	textarea: { label: __('Text area', 'proto-blocks'), category: 'basic' },
	number: { label: __('Number', 'proto-blocks'), category: 'basic' },
	email: { label: __('Email', 'proto-blocks'), category: 'basic' },
	url: { label: __('URL', 'proto-blocks'), category: 'basic' },
	password: { label: __('Password', 'proto-blocks'), category: 'basic' },
	wysiwyg: { label: __('Rich text', 'proto-blocks'), category: 'content' },
	image: { label: __('Image', 'proto-blocks'), category: 'content' },
	file: { label: __('File', 'proto-blocks'), category: 'content' },
	gallery: { label: __('Gallery', 'proto-blocks'), category: 'content' },
	video: { label: __('Video', 'proto-blocks'), category: 'content' },
	code: {
		label: __('Code', 'proto-blocks'),
		category: 'content',
		help: __('Anyone can add it; only users who may post unfiltered HTML can fill it.', 'proto-blocks'),
	},
	select: { label: __('Select', 'proto-blocks'), category: 'choice' },
	radio: { label: __('Radio buttons', 'proto-blocks'), category: 'choice' },
	true_false: { label: __('True / false', 'proto-blocks'), category: 'choice' },
	color: { label: __('Colour', 'proto-blocks'), category: 'choice' },
	date: { label: __('Date', 'proto-blocks'), category: 'choice' },
	time: { label: __('Time', 'proto-blocks'), category: 'choice' },
	datetime: { label: __('Date & time', 'proto-blocks'), category: 'choice' },
	link: { label: __('Link', 'proto-blocks'), category: 'relational' },
	relationship: { label: __('Relationship', 'proto-blocks'), category: 'relational' },
	taxonomy: { label: __('Taxonomy terms', 'proto-blocks'), category: 'relational' },
	group: { label: __('Group', 'proto-blocks'), category: 'layout' },
	repeater: { label: __('Repeater', 'proto-blocks'), category: 'layout' },
	flexible_content: { label: __('Flexible content', 'proto-blocks'), category: 'layout' },
	tab: { label: __('Tab', 'proto-blocks'), category: 'layout' },
	accordion: { label: __('Accordion', 'proto-blocks'), category: 'layout' },
	message: { label: __('Message', 'proto-blocks'), category: 'layout' },
};

export const FIELD_TYPES = Object.keys(TYPE_META) as FieldType[];

/** The ACF type names the server also accepts for each canonical type. */
export const TYPE_ALIASES: Partial<Record<FieldType, string[]>> = {};
for (const [alias, type] of Object.entries(aliasMap as Record<string, FieldType>)) {
	(TYPE_ALIASES[type] ??= []).push(alias);
}

/** One line per type for the type picker's tiles. */
export const TYPE_TILE: Record<FieldType, string> = {
	text: __('Single line', 'proto-blocks'),
	textarea: __('Multiple lines', 'proto-blocks'),
	number: __('Min, max, step', 'proto-blocks'),
	email: __('Validated address', 'proto-blocks'),
	url: __('Validated link', 'proto-blocks'),
	password: __('Masked input', 'proto-blocks'),
	wysiwyg: __('WYSIWYG editor', 'proto-blocks'),
	image: __('One attachment', 'proto-blocks'),
	file: __('Any attachment', 'proto-blocks'),
	gallery: __('Ordered images', 'proto-blocks'),
	video: __('Attachment or URL', 'proto-blocks'),
	code: __('Syntax-highlighted', 'proto-blocks'),
	select: __('One or many', 'proto-blocks'),
	radio: __('One of few', 'proto-blocks'),
	true_false: __('Checkbox or switch', 'proto-blocks'),
	color: __('Hex picker', 'proto-blocks'),
	date: __('ISO stored', 'proto-blocks'),
	time: __('HH:MM', 'proto-blocks'),
	datetime: __('Both', 'proto-blocks'),
	link: __('URL, title, target', 'proto-blocks'),
	relationship: __('Pick posts', 'proto-blocks'),
	taxonomy: __('Pick terms', 'proto-blocks'),
	group: __('Nested fields', 'proto-blocks'),
	repeater: __('Repeating rows', 'proto-blocks'),
	flexible_content: __('Layouts', 'proto-blocks'),
	tab: __('Split the box', 'proto-blocks'),
	accordion: __('Collapsible section', 'proto-blocks'),
	message: __('Static text', 'proto-blocks'),
};

/** A sentence per type, under the Field type select. */
export const TYPE_HELP: Record<FieldType, string> = {
	text: __('A single line of plain text.', 'proto-blocks'),
	textarea: __('Multiple lines of plain text.', 'proto-blocks'),
	number: __('A number with optional min, max and step.', 'proto-blocks'),
	email: __('An email address.', 'proto-blocks'),
	url: __('A single web address.', 'proto-blocks'),
	password: __('Text typed into a masked input.', 'proto-blocks'),
	wysiwyg: __('Formatted text from the classic editor.', 'proto-blocks'),
	image: __('An attachment id, URL or array.', 'proto-blocks'),
	file: __('Any attachment from the media library.', 'proto-blocks'),
	gallery: __('An ordered set of images.', 'proto-blocks'),
	video: __('A video attachment or URL.', 'proto-blocks'),
	code: __('Source code in a syntax-highlighted editor.', 'proto-blocks'),
	select: __('One or more choices from a list.', 'proto-blocks'),
	radio: __('One choice from a short list.', 'proto-blocks'),
	true_false: __('A checkbox, or a switch with the UI option.', 'proto-blocks'),
	color: __('A hex colour.', 'proto-blocks'),
	date: __('Stored in ISO format; display format is separate.', 'proto-blocks'),
	time: __('Stored as HH:MM:SS.', 'proto-blocks'),
	datetime: __('A date and a time, stored in ISO format.', 'proto-blocks'),
	link: __('URL, title and target.', 'proto-blocks'),
	relationship: __('Posts picked from a list.', 'proto-blocks'),
	taxonomy: __('Terms from one taxonomy.', 'proto-blocks'),
	group: __('A set of sub-fields stored together.', 'proto-blocks'),
	repeater: __('A row of sub-fields, repeated.', 'proto-blocks'),
	flexible_content: __('Rows built from the layouts you define.', 'proto-blocks'),
	tab: __('Groups the fields below it under a tab.', 'proto-blocks'),
	accordion: __('Folds the fields below it into a collapsible section.', 'proto-blocks'),
	message: __('Static text shown on the edit screen.', 'proto-blocks'),
};

/** Which settings-panel tab a setting sits on; everything else is General. */
export const VALIDATION_KEYS = new Set(['required', 'minlength', 'maxlength', 'pattern', 'min', 'max', 'step']);
export const PRESENTATION_KEYS = new Set(['placeholder', 'wrapper.width', 'wrapper.class']);

/** "Date" for `date_picker`; the declared type itself when the server does not know it. */
export function typeLabel(declared: unknown): string {
	const type = canonicalType(declared);
	return type ? TYPE_META[type].label : String(declared ?? '');
}

const opts = (pairs: [string, string][]) => pairs.map(([value, label]) => ({ value, label }));

export const COMMON_SETTINGS: SettingSpec[] = [
	{ key: 'label', label: __('Label', 'proto-blocks'), control: 'text', help: __('What editors see.', 'proto-blocks') },
	{ key: 'name', label: __('Field name', 'proto-blocks'), control: 'text', help: __('The meta key templates read the value by.', 'proto-blocks') },
	{ key: 'type', label: __('Field type', 'proto-blocks'), control: 'select', options: FIELD_TYPES.map((t) => ({ value: t, label: TYPE_META[t].label })) },
	{ key: 'instructions', label: __('Instructions', 'proto-blocks'), control: 'textarea' },
	{ key: 'required', label: __('Required', 'proto-blocks'), control: 'switch', help: __("Editors can't save without it.", 'proto-blocks') },
	{ key: 'default_value', label: __('Default value', 'proto-blocks'), control: 'text', help: __('Stored as plain text in the file.', 'proto-blocks') },
	{ key: 'placeholder', label: __('Placeholder', 'proto-blocks'), control: 'text' },
	{ key: 'wrapper.width', label: __('Width', 'proto-blocks'), control: 'text', help: __('Share of the row on the edit screen; narrow boxes stack fields in one column.', 'proto-blocks') },
	{ key: 'wrapper.class', label: __('CSS class', 'proto-blocks'), control: 'text' },
];

const TEXT_LIKE: SettingSpec[] = [
	{ key: 'minlength', label: __('Min length', 'proto-blocks'), control: 'number' },
	{ key: 'maxlength', label: __('Max length', 'proto-blocks'), control: 'number' },
	{ key: 'pattern', label: __('Pattern', 'proto-blocks'), control: 'text', help: __('A regular expression the value must match.', 'proto-blocks') },
];

const MIN_MAX: SettingSpec[] = [
	{ key: 'min', label: __('Minimum', 'proto-blocks'), control: 'number' },
	{ key: 'max', label: __('Maximum', 'proto-blocks'), control: 'number' },
];

const RETURN_FORMAT: SettingSpec = {
	key: 'return_format',
	label: __('Return format', 'proto-blocks'),
	control: 'select',
	options: opts([
		['array', __('Array', 'proto-blocks')],
		['url', __('URL', 'proto-blocks')],
		['id', __('ID', 'proto-blocks')],
	]),
};

const DATE_FORMATS: SettingSpec[] = [
	{ key: 'display_format', label: __('Display format', 'proto-blocks'), control: 'text' },
	{ key: 'return_format', label: __('Return format', 'proto-blocks'), control: 'text', help: __('Kept as written; values are stored in ISO format.', 'proto-blocks') },
];

const CHOICES: SettingSpec = {
	key: 'choices',
	label: __('Choices', 'proto-blocks'),
	control: 'choices',
	help: __('One per line: value : label.', 'proto-blocks'),
};

const OPTIONS_SOURCE: SettingSpec = {
	key: 'optionsSource',
	label: __('Load choices from', 'proto-blocks'),
	control: 'select',
	options: opts([
		['', __('The list above', 'proto-blocks')],
		['wp:posts', __('Posts of a type (wp:posts)', 'proto-blocks')],
		['wp:terms', __('Terms of a taxonomy (wp:terms)', 'proto-blocks')],
		['wp:users', __('Users (wp:users)', 'proto-blocks')],
	]),
};

const LIST_LAYOUT: SettingSpec = {
	key: 'layout',
	label: __('Layout', 'proto-blocks'),
	control: 'select',
	options: opts([
		['table', __('Table', 'proto-blocks')],
		['block', __('Block', 'proto-blocks')],
		['row', __('Row', 'proto-blocks')],
	]),
};

const BUTTON_LABEL: SettingSpec = { key: 'button_label', label: __('Button label', 'proto-blocks'), control: 'text' };

export const TYPE_SETTINGS: Partial<Record<FieldType, SettingSpec[]>> = {
	text: TEXT_LIKE,
	textarea: TEXT_LIKE,
	email: TEXT_LIKE,
	url: TEXT_LIKE,
	password: TEXT_LIKE,
	number: [...MIN_MAX, { key: 'step', label: __('Step', 'proto-blocks'), control: 'number' }],
	select: [CHOICES, { key: 'multiple', label: __('Allow multiple', 'proto-blocks'), control: 'switch' }, OPTIONS_SOURCE],
	radio: [CHOICES, OPTIONS_SOURCE],
	true_false: [
		{ key: 'ui', label: __('Show as a switch', 'proto-blocks'), control: 'switch' },
		{ key: 'message', label: __('Message', 'proto-blocks'), control: 'text', help: __('Text shown next to the checkbox.', 'proto-blocks') },
	],
	date: DATE_FORMATS,
	time: DATE_FORMATS,
	datetime: DATE_FORMATS,
	image: [RETURN_FORMAT, { key: 'mime_types', label: __('Allowed file types', 'proto-blocks'), control: 'text', help: __('Comma separated, e.g. jpg, png.', 'proto-blocks') }],
	file: [RETURN_FORMAT, { key: 'mime_types', label: __('Allowed file types', 'proto-blocks'), control: 'text', help: __('Comma separated, e.g. pdf, zip.', 'proto-blocks') }],
	gallery: MIN_MAX,
	relationship: [
		{ key: 'post_type', label: __('Post types', 'proto-blocks'), control: 'postTypes' },
		{ key: 'taxonomy', label: __('Taxonomies', 'proto-blocks'), control: 'taxonomies' },
		{
			key: 'filters',
			label: __('Filters', 'proto-blocks'),
			control: 'multiselect',
			options: opts([
				['search', __('Search', 'proto-blocks')],
				['post_type', __('Post type', 'proto-blocks')],
				['taxonomy', __('Taxonomy', 'proto-blocks')],
			]),
		},
		...MIN_MAX,
	],
	taxonomy: [
		{ key: 'taxonomy', label: __('Taxonomy', 'proto-blocks'), control: 'taxonomies', single: true },
		{
			key: 'field_type',
			label: __('Appearance', 'proto-blocks'),
			control: 'select',
			options: opts([
				['checkbox', __('Checkboxes', 'proto-blocks')],
				['multi_select', __('Multi select', 'proto-blocks')],
				['radio', __('Radio buttons', 'proto-blocks')],
				['select', __('Select', 'proto-blocks')],
			]),
		},
		{ key: 'save_terms', label: __('Save terms to the post', 'proto-blocks'), control: 'switch' },
	],
	repeater: [...MIN_MAX, LIST_LAYOUT, BUTTON_LABEL],
	group: [LIST_LAYOUT],
	flexible_content: [...MIN_MAX, BUTTON_LABEL],
	code: [
		{
			key: 'language',
			label: __('Language', 'proto-blocks'),
			control: 'select',
			options: opts([
				['html', 'HTML'],
				['css', 'CSS'],
				['javascript', 'JavaScript'],
				['json', 'JSON'],
				['php', 'PHP'],
			]),
		},
	],
	message: [{ key: 'message', label: __('Message', 'proto-blocks'), control: 'textarea' }],
};

const NO_VALUE = new Set(['repeater', 'group', 'flexible_content', ...LAYOUT_ONLY_TYPES]);

/** The settings the panel shows for a field of `declared` type, in order. */
export function settingsFor(declared: unknown): SettingSpec[] {
	const type = canonicalType(declared) ?? '';
	const layoutOnly = LAYOUT_ONLY_TYPES.includes(type);
	const common = COMMON_SETTINGS.filter((s) => {
		if (layoutOnly) {
			return ['label', 'name', 'type', 'instructions'].includes(s.key);
		}
		return !(NO_VALUE.has(type) && (s.key === 'default_value' || s.key === 'placeholder'));
	});
	return [...common, ...((type !== '' && TYPE_SETTINGS[type]) || [])];
}
