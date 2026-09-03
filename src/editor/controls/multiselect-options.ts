/**
 * Label <-> key translation for the multiselect control.
 *
 * FormTokenField is a control over label *strings*, but the block attribute
 * stores option *keys*. Two options can legitimately share a label (two
 * products with the same title), so a naive Map<label, key> would silently
 * resolve one of them to the other's key. Everything here is pure so it can be
 * unit-tested without mounting the component -- @wordpress/components is a
 * webpack external and cannot be imported under Jest.
 */

import { SelectOption } from './options-source';

export interface LabelIndex {
    /** Key -> the label shown in the UI (disambiguated where labels collide). */
    labelByKey: Map<string, string>;
    /** The reverse of labelByKey. Total, because display labels are unique. */
    keyByLabel: Map<string, string>;
}

/**
 * Union of two option pages, keyed by option key.
 *
 * Map.set on an existing key overwrites the value but keeps the original
 * insertion position, which is exactly what we want: a fresher label wins,
 * but an option does not jump around the suggestion list because it happened
 * to appear in a later search response.
 */
export function mergeOptions(
    previous: SelectOption[],
    incoming: SelectOption[]
): SelectOption[] {
    const byKey = new Map<string, SelectOption>();

    previous.forEach((option) => byKey.set(option.value, option));
    incoming.forEach((option) => byKey.set(option.value, option));

    return Array.from(byKey.values());
}

/**
 * Build the two-way index, suffixing colliding labels with their key.
 *
 * The suffix is display-only -- the stored value is always the bare key.
 */
export function buildLabelIndex(options: SelectOption[]): LabelIndex {
    const occurrences = new Map<string, number>();
    options.forEach((option) => {
        occurrences.set(option.label, (occurrences.get(option.label) ?? 0) + 1);
    });

    const labelByKey = new Map<string, string>();
    const keyByLabel = new Map<string, string>();

    options.forEach((option) => {
        const display =
            (occurrences.get(option.label) ?? 0) > 1
                ? `${option.label} (#${option.value})`
                : option.label;

        labelByKey.set(option.value, display);
        keyByLabel.set(display, option.value);
    });

    return { labelByKey, keyByLabel };
}

/**
 * Render selected keys as tokens.
 *
 * An unknown key renders as itself rather than disappearing: a selection whose
 * option is not in the current search page, or whose post was deleted, must
 * still be visible and removable.
 */
export function keysToLabels(keys: string[], index: LabelIndex): string[] {
    return keys.map((key) => index.labelByKey.get(key) ?? key);
}

/**
 * Translate the token list FormTokenField hands back into stored keys.
 *
 * `fallbackKeys` closes the round-trip opened by keysToLabels: a bare key
 * rendered as its own token comes back as that same string, and without the
 * fallback it would match no label and be silently dropped -- deleting the
 * author's selection the moment they touched an unrelated token.
 *
 * Free text matching neither is discarded: FormTokenField accepts arbitrary
 * input, and there is no key to store for it.
 */
export function labelsToKeys(
    labels: string[],
    index: LabelIndex,
    fallbackKeys: string[] = []
): string[] {
    const fallback = new Set(fallbackKeys);
    const keys: string[] = [];

    labels.forEach((label) => {
        const key =
            index.keyByLabel.get(label) ?? (fallback.has(label) ? label : undefined);

        if (key !== undefined && !keys.includes(key)) {
            keys.push(key);
        }
    });

    return keys;
}

/** Display labels for everything not already chosen. */
export function suggestionsFor(index: LabelIndex, selectedKeys: string[]): string[] {
    const selected = new Set(selectedKeys);
    const suggestions: string[] = [];

    index.labelByKey.forEach((label, key) => {
        if (!selected.has(key)) {
            suggestions.push(label);
        }
    });

    return suggestions;
}
