import {
    buildLabelIndex,
    keysToLabels,
    labelsToKeys,
    mergeOptions,
    suggestionsFor,
} from '../multiselect-options';

const opts = (...pairs: Array<[string, string]>) =>
    pairs.map(([value, label]) => ({ value, label }));

describe('mergeOptions', () => {
    it('keeps previous order and appends genuinely new options', () => {
        const result = mergeOptions(opts(['1', 'One'], ['2', 'Two']), opts(['3', 'Three']));

        expect(result).toEqual(opts(['1', 'One'], ['2', 'Two'], ['3', 'Three']));
    });

    it('lets incoming options overwrite a stale label for the same key', () => {
        const result = mergeOptions(opts(['1', 'Old name']), opts(['1', 'New name']));

        expect(result).toEqual(opts(['1', 'New name']));
    });

    it('does not move a key that reappears in the incoming page', () => {
        const result = mergeOptions(
            opts(['1', 'One'], ['2', 'Two'], ['3', 'Three']),
            opts(['2', 'Two Updated'])
        );

        expect(result.map((o) => o.value)).toEqual(['1', '2', '3']);
        expect(result[1].label).toBe('Two Updated');
    });
});

describe('buildLabelIndex', () => {
    it('uses the bare label when it is unique', () => {
        const index = buildLabelIndex(opts(['1', 'One'], ['2', 'Two']));

        expect(index.labelByKey.get('1')).toBe('One');
        expect(index.keyByLabel.get('One')).toBe('1');
    });

    it('disambiguates every member of a colliding label with its key', () => {
        const index = buildLabelIndex(opts(['18277', 'Half Size Oven'], ['18282', 'Half Size Oven']));

        expect(index.labelByKey.get('18277')).toBe('Half Size Oven (#18277)');
        expect(index.labelByKey.get('18282')).toBe('Half Size Oven (#18282)');
        expect(index.keyByLabel.get('Half Size Oven (#18282)')).toBe('18282');
        expect(index.keyByLabel.has('Half Size Oven')).toBe(false);
    });

    it('leaves an unrelated unique label alone when another label collides', () => {
        const index = buildLabelIndex(opts(['1', 'Dup'], ['2', 'Dup'], ['3', 'Unique']));

        expect(index.labelByKey.get('3')).toBe('Unique');
    });
});

describe('keysToLabels', () => {
    it('maps known keys to their display labels', () => {
        const index = buildLabelIndex(opts(['1', 'One'], ['2', 'Two']));

        expect(keysToLabels(['2', '1'], index)).toEqual(['Two', 'One']);
    });

    it('falls back to the bare key when the option is gone', () => {
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(keysToLabels(['1', '999'], index)).toEqual(['One', '999']);
    });
});

describe('labelsToKeys', () => {
    it('maps display labels back to keys, preserving order', () => {
        const index = buildLabelIndex(opts(['1', 'One'], ['2', 'Two']));

        expect(labelsToKeys(['Two', 'One'], index)).toEqual(['2', '1']);
    });

    it('round-trips a disambiguated label to the bare key', () => {
        const index = buildLabelIndex(opts(['18277', 'Dup'], ['18282', 'Dup']));

        expect(labelsToKeys(['Dup (#18282)'], index)).toEqual(['18282']);
    });

    it('drops free text the author typed that matches no option', () => {
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(labelsToKeys(['One', 'not a product'], index)).toEqual(['1']);
    });

    it('keeps an unresolvable key that keysToLabels rendered bare', () => {
        // The round-trip that would otherwise silently drop a selection whose
        // option is not in the current result page.
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(labelsToKeys(['One', '999'], index, ['1', '999'])).toEqual(['1', '999']);
    });

    it('collapses duplicates', () => {
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(labelsToKeys(['One', 'One'], index)).toEqual(['1']);
    });
});

describe('suggestionsFor', () => {
    it('omits already-selected keys', () => {
        const index = buildLabelIndex(opts(['1', 'One'], ['2', 'Two'], ['3', 'Three']));

        expect(suggestionsFor(index, ['2'])).toEqual(['One', 'Three']);
    });

    it('returns everything when nothing is selected', () => {
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(suggestionsFor(index, [])).toEqual(['One']);
    });
});
