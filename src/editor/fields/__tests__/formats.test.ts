import { ALL_FORMATS, FORMAT_MAP } from '../formats';

describe('FORMAT_MAP', () => {
    it('gives `full` every format, not a hand-copied subset', () => {
        // The defect this guards: `full` and the wysiwyg list were maintained
        // separately and drifted, so a `full` text field silently offered fewer
        // formats than a wysiwyg one. Identity, not "contains", because the
        // whole point is that there is one list.
        expect(FORMAT_MAP.full).toBe(ALL_FORMATS);
    });

    it('offers the text-colour format on `full`', () => {
        // Named on its own because this is the one whose absence was reported:
        // an author could colour a phrase in a wysiwyg body but not in a
        // heading declared `"format": "full"`.
        expect(FORMAT_MAP.full).toContain('core/text-color');
    });

    it('keeps the narrower presets narrow', () => {
        expect(FORMAT_MAP.plain).toEqual([]);
        expect(FORMAT_MAP.simple).toEqual(['core/bold', 'core/italic']);
        expect(FORMAT_MAP.standard).toEqual([
            'core/bold',
            'core/italic',
            'core/link',
        ]);
    });

    it('escalates: every preset is a subset of the one above it', () => {
        expect(FORMAT_MAP.simple).toEqual(
            expect.arrayContaining(FORMAT_MAP.plain)
        );
        expect(FORMAT_MAP.standard).toEqual(
            expect.arrayContaining(FORMAT_MAP.simple)
        );
        expect(FORMAT_MAP.full).toEqual(
            expect.arrayContaining(FORMAT_MAP.standard)
        );
    });

    it('lists no format twice', () => {
        expect(new Set(ALL_FORMATS).size).toBe(ALL_FORMATS.length);
    });
});
