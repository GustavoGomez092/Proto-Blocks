/**
 * Tests for the gallery control's selection and ordering maths.
 */

import {
    GalleryItem,
    idsOf,
    indexOfId,
    removeAt,
    reorder,
    toGalleryItems,
    toStoredItems,
} from '../gallery-items';

const item = (id: number, url = `https://example.test/${id}.jpg`): GalleryItem => ({
    id,
    url,
    alt: '',
});

describe('toGalleryItems', () => {
    it('keeps the order the media modal returned', () => {
        const result = toGalleryItems([
            { id: 3, url: 'c.jpg' },
            { id: 1, url: 'a.jpg' },
            { id: 2, url: 'b.jpg' },
        ]);

        expect(idsOf(result)).toEqual([3, 1, 2]);
    });

    it('prefers a generated size over the full-size original', () => {
        const result = toGalleryItems([
            {
                id: 1,
                url: 'https://example.test/original-8mb.jpg',
                sizes: { large: { url: 'https://example.test/large.jpg' } },
            },
        ]);

        expect(result[0].url).toBe('https://example.test/large.jpg');
    });

    it('falls back through the size list before using the original', () => {
        const result = toGalleryItems([
            {
                id: 1,
                url: 'https://example.test/original.jpg',
                sizes: { medium: { url: 'https://example.test/medium.jpg' } },
            },
        ]);

        expect(result[0].url).toBe('https://example.test/medium.jpg');
    });

    it('drops an attachment that has no usable URL yet', () => {
        // An upload still in flight: it has an id but no file to point at.
        expect(toGalleryItems([{ id: 1 }, { id: 2, url: 'b.jpg' }])).toEqual([
            { id: 2, url: 'b.jpg', alt: '' },
        ]);
    });

    it('drops entries with no valid attachment id', () => {
        expect(toGalleryItems([{ id: 0, url: 'a.jpg' }, { url: 'b.jpg' }])).toEqual([]);
    });

    it('collapses a repeated attachment to its first occurrence', () => {
        // Duplicates would give two items one identity, which is what
        // drag-to-reorder uses to tell them apart.
        const result = toGalleryItems([
            { id: 7, url: 'first.jpg' },
            { id: 7, url: 'second.jpg' },
        ]);

        expect(result).toEqual([{ id: 7, url: 'first.jpg', alt: '' }]);
    });

    it('carries alt text across, defaulting to empty', () => {
        const result = toGalleryItems([
            { id: 1, url: 'a.jpg', alt: 'A rack oven' },
            { id: 2, url: 'b.jpg' },
        ]);

        expect(result.map((i) => i.alt)).toEqual(['A rack oven', '']);
    });

    it('accepts a lone media object as well as a list', () => {
        expect(toGalleryItems({ id: 4, url: 'd.jpg' })).toEqual([
            { id: 4, url: 'd.jpg', alt: '' },
        ]);
    });

    it('survives junk without throwing', () => {
        expect(toGalleryItems(undefined)).toEqual([]);
        expect(toGalleryItems(null)).toEqual([]);
        expect(toGalleryItems('nonsense')).toEqual([]);
        expect(toGalleryItems([null, 42, 'x'])).toEqual([]);
    });
});

describe('toStoredItems', () => {
    it('reads back a value this control itself stored', () => {
        const stored = [item(1), item(2)];

        expect(toStoredItems(stored)).toEqual(stored);
    });

    it('repairs a hand-edited attribute rather than throwing', () => {
        expect(toStoredItems([{ id: 1, url: 'a.jpg' }, 'garbage'])).toEqual([
            { id: 1, url: 'a.jpg', alt: '' },
        ]);
    });
});

describe('removeAt', () => {
    it('removes the item at the index', () => {
        expect(idsOf(removeAt([item(1), item(2), item(3)], 1))).toEqual([1, 3]);
    });

    it('returns the list untouched for an out-of-range index', () => {
        const items = [item(1)];

        expect(removeAt(items, 5)).toBe(items);
        expect(removeAt(items, -1)).toBe(items);
    });
});

describe('reorder', () => {
    it('moves an item forwards', () => {
        expect(idsOf(reorder([item(1), item(2), item(3)], 0, 2))).toEqual([2, 3, 1]);
    });

    it('moves an item backwards', () => {
        expect(idsOf(reorder([item(1), item(2), item(3)], 2, 0))).toEqual([3, 1, 2]);
    });

    it('does not mutate the list it was given', () => {
        const items = [item(1), item(2)];

        reorder(items, 0, 1);

        expect(idsOf(items)).toEqual([1, 2]);
    });

    it('returns the list untouched for a no-op or out-of-range move', () => {
        const items = [item(1), item(2)];

        expect(reorder(items, 1, 1)).toBe(items);
        expect(reorder(items, 0, 9)).toBe(items);
        expect(reorder(items, -1, 0)).toBe(items);
    });
});

describe('indexOfId', () => {
    it('finds an item by attachment id', () => {
        expect(indexOfId([item(4), item(9)], 9)).toBe(1);
    });

    it('reports -1 for an id that is not present', () => {
        expect(indexOfId([item(4)], 9)).toBe(-1);
    });
});
