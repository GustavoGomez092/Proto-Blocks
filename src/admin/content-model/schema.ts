/**
 * Browser-side checker for declaration files, as zod schemas for
 * react-hook-form. It mirrors includes/ContentModel/SchemaCheck.php keyword
 * for keyword (type, enum, pattern, minLength, maxLength, minimum, maximum,
 * properties, required, additionalProperties, items, minItems, maxItems,
 * uniqueItems, anyOf, allOf, local `#/$defs/<name>` references; unknown keywords
 * ignored), including which anyOf branch's errors are reported, so the form
 * and the server agree on what is valid and where the problem is.
 */
import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';
import contentModelSchema from '../../../includes/ContentModel/schemas/content-model.schema.json';

export interface JsonSchema {
	$ref?: string;
	type?: string | string[];
	enum?: unknown[];
	pattern?: string;
	minLength?: number;
	maxLength?: number;
	minimum?: number;
	maximum?: number;
	properties?: Record<string, JsonSchema>;
	required?: string[];
	additionalProperties?: boolean | JsonSchema;
	items?: JsonSchema;
	minItems?: number;
	maxItems?: number;
	uniqueItems?: boolean;
	anyOf?: JsonSchema[];
	allOf?: JsonSchema[];
	$defs?: Record<string, JsonSchema>;
	[keyword: string]: unknown;
}

type Path = (string | number)[];
interface CheckError {
	path: Path;
	message: string;
}

const isObject = (data: unknown): data is Record<string, unknown> =>
	data !== null && typeof data === 'object' && !Array.isArray(data);

function resolve(root: JsonSchema, ref: string): JsonSchema | null {
	if (ref === '#') {
		return root;
	}
	const match = /^#\/\$defs\/([A-Za-z0-9_-]+)$/.exec(ref);
	const target = match ? root.$defs?.[match[1]] : undefined;
	return isObject(target) ? (target as JsonSchema) : null;
}

function typeMatches(data: unknown, type: string | string[]): boolean {
	return (Array.isArray(type) ? type : [type]).some((t) => {
		switch (t) {
			case 'object':
				return isObject(data);
			case 'array':
				return Array.isArray(data);
			case 'string':
				return typeof data === 'string';
			case 'boolean':
				return typeof data === 'boolean';
			case 'integer':
				return typeof data === 'number' && Number.isInteger(data);
			case 'number':
				return typeof data === 'number';
			case 'null':
				return data === null;
			default:
				return false;
		}
	});
}

/** Strict equality for scalars, structural for arrays and objects (PHP in_array strict). */
function same(a: unknown, b: unknown): boolean {
	if (a === b) {
		return true;
	}
	if (Array.isArray(a) && Array.isArray(b)) {
		return a.length === b.length && a.every((item, i) => same(item, b[i]));
	}
	if (isObject(a) && isObject(b)) {
		const keys = Object.keys(a);
		return keys.length === Object.keys(b).length && keys.every((key) => key in b && same(a[key], b[key]));
	}
	return false;
}

function check(data: unknown, node: JsonSchema, root: JsonSchema, path: Path): CheckError[] {
	if (typeof node.$ref === 'string') {
		const target = resolve(root, node.$ref);
		if (!target) {
			return [{ path, message: sprintf(/* translators: %s: a JSON Schema reference such as #/$defs/type. */ __('Unresolvable schema reference %s.', 'proto-blocks'), node.$ref) }];
		}
		return check(data, target, root, path);
	}

	if (node.type !== undefined && !typeMatches(data, node.type)) {
		const want = (Array.isArray(node.type) ? node.type : [node.type]).join(__(' or ', 'proto-blocks'));
		return [{ path, message: sprintf(/* translators: %s: JSON type names, e.g. "boolean" or "string or array". */ __('Must be of type %s.', 'proto-blocks'), want) }];
	}

	const errors: CheckError[] = [];

	if (Array.isArray(node.enum) && !node.enum.some((allowed) => same(allowed, data))) {
		errors.push({ path, message: sprintf(/* translators: %s: the allowed values as JSON. */ __('Must be one of: %s.', 'proto-blocks'), JSON.stringify(node.enum)) });
	}

	if (typeof data === 'string') {
		const length = [...data].length;
		if (typeof node.minLength === 'number' && length < node.minLength) {
			errors.push({ path, message: sprintf(/* translators: %d: minimum number of characters. */ __('Must be at least %d characters.', 'proto-blocks'), node.minLength) });
		}
		if (typeof node.maxLength === 'number' && length > node.maxLength) {
			errors.push({ path, message: sprintf(/* translators: %d: maximum number of characters. */ __('Must be at most %d characters.', 'proto-blocks'), node.maxLength) });
		}
		if (typeof node.pattern === 'string' && !new RegExp(node.pattern, 'u').test(data)) {
			errors.push({ path, message: sprintf(/* translators: %s: a regular expression. */ __('Must match pattern %s.', 'proto-blocks'), node.pattern) });
		}
	}

	if (typeof data === 'number') {
		if (typeof node.minimum === 'number' && data < node.minimum) {
			errors.push({ path, message: sprintf(/* translators: %s: the minimum value. */ __('Must be at least %s.', 'proto-blocks'), String(node.minimum)) });
		}
		if (typeof node.maximum === 'number' && data > node.maximum) {
			errors.push({ path, message: sprintf(/* translators: %s: the maximum value. */ __('Must be at most %s.', 'proto-blocks'), String(node.maximum)) });
		}
	}

	if (isObject(data)) {
		for (const key of node.required ?? []) {
			if (!Object.prototype.hasOwnProperty.call(data, key)) {
				errors.push({ path: [...path, key], message: __('Required.', 'proto-blocks') });
			}
		}
		const properties = isObject(node.properties) ? node.properties : {};
		const additional = node.additionalProperties ?? true;
		for (const [key, value] of Object.entries(data)) {
			const childPath = [...path, key];
			const property = Object.prototype.hasOwnProperty.call(properties, key) ? properties[key] : undefined;
			if (isObject(property)) {
				errors.push(...check(value, property as JsonSchema, root, childPath));
			} else if (additional === false) {
				errors.push({ path: childPath, message: __('Unknown property.', 'proto-blocks') });
			} else if (isObject(additional)) {
				errors.push(...check(value, additional as JsonSchema, root, childPath));
			}
		}
	}

	if (Array.isArray(data)) {
		if (isObject(node.items)) {
			data.forEach((value, i) => errors.push(...check(value, node.items as JsonSchema, root, [...path, i])));
		}
		if (typeof node.minItems === 'number' && data.length < node.minItems) {
			errors.push({ path, message: sprintf(/* translators: %d: minimum number of items. */ __('Must have at least %d items.', 'proto-blocks'), node.minItems) });
		}
		if (typeof node.maxItems === 'number' && data.length > node.maxItems) {
			errors.push({ path, message: sprintf(/* translators: %d: maximum number of items. */ __('Must have at most %d items.', 'proto-blocks'), node.maxItems) });
		}
		if (node.uniqueItems) {
			const seen = new Set<string>();
			data.forEach((value, i) => {
				const fingerprint = JSON.stringify(value);
				if (seen.has(fingerprint)) {
					errors.push({ path: [...path, i], message: __('Items must be unique.', 'proto-blocks') });
				}
				seen.add(fingerprint);
			});
		}
	}

	if (Array.isArray(node.anyOf)) {
		errors.push(...checkAnyOf(data, node.anyOf, root, path));
	}

	if (Array.isArray(node.allOf)) {
		for (const branch of node.allOf) {
			if (isObject(branch)) {
				errors.push(...check(data, branch as JsonSchema, root, path));
			}
		}
	}

	return errors;
}

function checkAnyOf(data: unknown, branches: JsonSchema[], root: JsonSchema, path: Path): CheckError[] {
	let best: CheckError[] | null = null;
	for (const branch of branches) {
		const errors = check(data, branch, root, path);
		if (errors.length === 0) {
			return [];
		}
		// Prefer the branch whose declared type fits the data: its errors
		// are the most specific ones to show.
		const resolved = typeof branch.$ref === 'string' ? (resolve(root, branch.$ref) ?? {}) : branch;
		const fits = resolved.type === undefined || typeMatches(data, resolved.type);
		if (fits && (best === null || errors.length < best.length)) {
			best = errors;
		}
	}
	return best ?? [{ path, message: __('Does not match any allowed form.', 'proto-blocks') }];
}

/**
 * A zod schema that accepts exactly what SchemaCheck accepts for `schema`
 * (with `$ref`s resolved against `root`) and reports the same paths. The
 * parsed value is the input unchanged, unknown keys included.
 */
export function toZod(schema: JsonSchema, root: JsonSchema = schema): z.ZodTypeAny {
	return z.unknown().superRefine((data, ctx) => {
		for (const error of check(data, schema, root, [])) {
			ctx.addIssue({ code: 'custom', message: error.message, path: error.path });
		}
	});
}

const ROOT = contentModelSchema as unknown as JsonSchema;

/** A post type declaration file (proto-types/<slug>/type.jsonc). */
export const typeSchema = toZod({ $ref: '#/$defs/type' }, ROOT);

/** A taxonomy declaration file, standalone or inline in a type's `taxonomies`. */
export const taxonomySchema = toZod({ $ref: '#/$defs/taxonomy' }, ROOT);

/** An options page declaration file (proto-options/<slug>/page.jsonc). */
export const optionsSchema = toZod({ $ref: '#/$defs/options' }, ROOT);
