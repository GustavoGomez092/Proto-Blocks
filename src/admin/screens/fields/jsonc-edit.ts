/**
 * Path edits on a JSONC declaration file that keep the developer's comments,
 * key order and formatting (C6): the builder changes one group, one field or
 * one setting at a time and writes the file back.
 */
import { applyEdits, modify, type JSONPath } from 'jsonc-parser';

const FORMATTING = { formattingOptions: { insertSpaces: true, tabSize: 2 } };

/**
 * `text` with the value at `path` set to `value`. A `-1` as the last segment
 * appends to the array; `undefined` removes the property or array element.
 * Missing objects on the way are created.
 */
export function setAtPath(text: string, path: (string | number)[], value: unknown): string {
	return applyEdits(text, modify(text, path as JSONPath, value, FORMATTING));
}

/** 1-based line and column of `offset` in `text`. */
export function lineColumn(text: string, offset: number): { line: number; column: number } {
	const lines = text.slice(0, Math.max(0, offset)).split('\n');
	return { line: lines.length, column: lines[lines.length - 1].length + 1 };
}
