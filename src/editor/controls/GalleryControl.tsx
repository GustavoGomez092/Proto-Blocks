/**
 * A control that stores an ordered list of images.
 *
 * The single `image` control's counterpart for the case where a block wants
 * several: a logo wall, a slider, a photo band. It exists because the
 * alternative was a repeater of one-image rows, and a repeater puts its own
 * add/remove/drag chrome INSIDE the block's markup -- so the canvas showed
 * editing furniture the front end does not have, and a block whose layout
 * depends on how many images there are could not be judged in the editor at
 * all. Holding the list in the inspector instead leaves the rendered markup
 * identical on both sides.
 *
 * Selection is core's own media modal in gallery mode, so choosing and
 * reordering images is the interaction authors already know from core's
 * Gallery block. The sortable strip below it is a second way to reorder, for
 * keyboards and for small changes that do not deserve a modal.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    rectSortingStrategy,
    sortableKeyboardCoordinates,
    useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    GalleryItem,
    idsOf,
    indexOfId,
    removeAt,
    reorder,
    toGalleryItems,
    toStoredItems,
} from './gallery-items';

interface GalleryControlProps {
    label: string;
    value: unknown;
    onChange: (value: GalleryItem[]) => void;
}

interface SortableThumbProps {
    item: GalleryItem;
    position: number;
    total: number;
    onRemove: (id: number) => void;
}

function SortableThumb({
    item,
    position,
    total,
    onRemove,
}: SortableThumbProps): JSX.Element {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
        useSortable({ id: item.id });

    return (
        <li
            ref={setNodeRef}
            className={`proto-blocks-gallery__item${isDragging ? ' is-dragging' : ''}`}
            style={{ transform: CSS.Transform.toString(transform), transition }}
        >
            {/* The whole thumbnail is the drag handle: at this size a separate
                grip would be most of the tile. The dnd-kit keyboard sensor
                makes it operable without a pointer, which is why it is a
                button and carries its position in its label -- "Reorder" alone
                tells a screen reader nothing about WHICH image it has hold
                of, and the images themselves are decorative here. */}
            <button
                type="button"
                className="proto-blocks-gallery__grip"
                aria-label={sprintf(
                    /* translators: 1: position of this image, 2: total images. */
                    __('Reorder image %1$d of %2$d', 'proto-blocks'),
                    position,
                    total
                )}
                {...attributes}
                {...listeners}
            >
                <img src={item.url} alt="" className="proto-blocks-gallery__image" />
            </button>

            <button
                type="button"
                className="proto-blocks-gallery__remove"
                onClick={() => onRemove(item.id)}
                aria-label={sprintf(
                    /* translators: 1: position of this image, 2: total images. */
                    __('Remove image %1$d of %2$d', 'proto-blocks'),
                    position,
                    total
                )}
            >
                ×
            </button>
        </li>
    );
}

export function GalleryControl({
    label,
    value,
    onChange,
}: GalleryControlProps): JSX.Element {
    const items = useMemo(() => toStoredItems(value), [value]);

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    const handleDragEnd = useCallback(
        (event: DragEndEvent) => {
            const { active, over } = event;

            if (!over || active.id === over.id) {
                return;
            }

            const from = indexOfId(items, Number(active.id));
            const to = indexOfId(items, Number(over.id));

            if (from === -1 || to === -1) {
                return;
            }

            onChange(reorder(items, from, to));
        },
        [items, onChange]
    );

    const handleRemove = useCallback(
        (id: number) => {
            onChange(removeAt(items, indexOfId(items, id)));
        },
        [items, onChange]
    );

    return (
        <div className="proto-blocks-gallery-control">
            <label className="components-base-control__label">{label}</label>

            {items.length > 0 && (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleDragEnd}
                >
                    <SortableContext items={idsOf(items)} strategy={rectSortingStrategy}>
                        <ul className="proto-blocks-gallery__grid">
                            {items.map((item, index) => (
                                <SortableThumb
                                    key={item.id}
                                    item={item}
                                    position={index + 1}
                                    total={items.length}
                                    onRemove={handleRemove}
                                />
                            ))}
                        </ul>
                    </SortableContext>
                </DndContext>
            )}

            <MediaUploadCheck>
                <MediaUpload
                    gallery
                    multiple
                    addToGallery
                    allowedTypes={['image']}
                    /* The modal opens on the current selection, so it is an
                       editor rather than a fresh picker -- reopening it does
                       not lose what is already chosen. */
                    value={idsOf(items)}
                    onSelect={(selection: unknown) =>
                        onChange(toGalleryItems(selection))
                    }
                    render={({ open }: { open: () => void }) => (
                        <Button variant="secondary" onClick={open}>
                            {items.length > 0
                                ? sprintf(
                                      /* translators: %d: number of images currently chosen. */
                                      __('Edit gallery (%d)', 'proto-blocks'),
                                      items.length
                                  )
                                : __('Add images', 'proto-blocks')}
                        </Button>
                    )}
                />
            </MediaUploadCheck>
        </div>
    );
}
