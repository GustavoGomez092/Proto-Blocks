/**
 * Selection and ordering maths for the gallery control.
 *
 * Kept apart from the component so it can be unit-tested: @wordpress/components
 * and @wordpress/block-editor are webpack externals and cannot be imported
 * under Jest, so anything that touches them is untestable by construction.
 * Everything here is pure.
 */

/**
 * One chosen image, as stored in the block attribute.
 *
 * Deliberately the same shape the single `image` control stores, so a template
 * reads one item of a gallery exactly the way it reads an image control's
 * value and neither the docs nor the reader need a second mental model.
 */
export interface GalleryItem {
    id: number;
    url: string;
    alt: string;
}

/** A media object as the WordPress media modal hands it back. */
interface MediaLike {
    id?: unknown;
    url?: unknown;
    alt?: unknown;
    sizes?: Record<string, { url?: unknown }>;
}

function isMediaLike(value: unknown): value is MediaLike {
    return typeof value === 'object' && value !== null;
}

/**
 * Best URL for a chosen attachment.
 *
 * `media.url` on a gallery selection is the FULL-size file, which for a
 * photograph straight off a camera can be several megabytes -- and the sidebar
 * renders it as a thumbnail a hundred pixels wide. Preferring a generated size
 * keeps the inspector from downloading the originals.
 */
function bestUrl(media: MediaLike): string {
    const sizes = media.sizes ?? {};

    for (const name of ['large', 'medium_large', 'medium', 'full']) {
        const candidate = sizes[name]?.url;

        if (typeof candidate === 'string' && candidate !== '') {
            return candidate;
        }
    }

    return typeof media.url === 'string' ? media.url : '';
}

/**
 * Normalise a media-modal selection into stored items, in the chosen order.
 *
 * Anything without both an id and a URL is dropped: an attachment still
 * uploading has no URL yet, and storing it would render a broken image that
 * never repairs itself.
 *
 * Repeats of one attachment collapse to the first occurrence. The gallery
 * frame does not offer the same image twice, so a duplicate only arrives from
 * a malformed value -- and duplicates would give two items the same identity,
 * which is what drag-to-reorder uses to tell them apart.
 */
export function toGalleryItems(selection: unknown): GalleryItem[] {
    const list = Array.isArray(selection) ? selection : [selection];
    const seen = new Set<number>();
    const items: GalleryItem[] = [];

    list.forEach((entry) => {
        if (!isMediaLike(entry)) {
            return;
        }

        const id = Number(entry.id);
        const url = bestUrl(entry);

        if (!Number.isInteger(id) || id <= 0 || url === '' || seen.has(id)) {
            return;
        }

        seen.add(id);
        items.push({ id, url, alt: typeof entry.alt === 'string' ? entry.alt : '' });
    });

    return items;
}

/**
 * Coerce a stored attribute back to items.
 *
 * A block saved before this control existed, or hand-edited, can hold anything
 * at all; the control has to render rather than throw.
 */
export function toStoredItems(value: unknown): GalleryItem[] {
    return toGalleryItems(value);
}

/** Attachment ids, which is what MediaUpload wants for `value`. */
export function idsOf(items: GalleryItem[]): number[] {
    return items.map((item) => item.id);
}

/** The list without the item at `index`; unchanged if there is none. */
export function removeAt(items: GalleryItem[], index: number): GalleryItem[] {
    if (index < 0 || index >= items.length) {
        return items;
    }

    return items.filter((_, i) => i !== index);
}

/**
 * Move one item to another position.
 *
 * Written out rather than taken from dnd-kit's arrayMove so the ordering rule
 * is testable without the drag library, and so a caller that reorders for some
 * other reason does not have to reach for a drag dependency to do it.
 */
export function reorder(items: GalleryItem[], from: number, to: number): GalleryItem[] {
    if (
        from === to ||
        from < 0 ||
        to < 0 ||
        from >= items.length ||
        to >= items.length
    ) {
        return items;
    }

    const next = items.slice();
    const [moved] = next.splice(from, 1);

    next.splice(to, 0, moved);

    return next;
}

/** Position of the item with this id, or -1. */
export function indexOfId(items: GalleryItem[], id: number): number {
    return items.findIndex((item) => item.id === id);
}
