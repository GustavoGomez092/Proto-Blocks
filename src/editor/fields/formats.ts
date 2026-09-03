/**
 * The inline formats a RichText field may offer.
 *
 * Kept here rather than beside either field because both a `text` field with
 * `format: "full"` and a `wysiwyg` field mean the same thing by "everything",
 * and when the two owned separate lists they drifted: `full` was missing
 * underline, image, text-color and keyboard, so an author could colour a word
 * in a wysiwyg body but not in a heading -- with nothing in the block.json to
 * explain why, because both fields were configured to allow everything.
 *
 * Adding a format here turns it on for both. That is the point.
 */
export const ALL_FORMATS = [
    'core/bold',
    'core/italic',
    'core/link',
    'core/strikethrough',
    'core/underline',
    'core/subscript',
    'core/superscript',
    'core/code',
    'core/image',
    'core/text-color',
    'core/keyboard',
];

/**
 * What each `format` value on a text field allows.
 *
 * `full` is the whole list by definition -- spelling it out again is how the
 * two fell out of step in the first place.
 */
export const FORMAT_MAP: Record<string, string[]> = {
    plain: [],
    simple: ['core/bold', 'core/italic'],
    standard: ['core/bold', 'core/italic', 'core/link'],
    full: ALL_FORMATS,
};
