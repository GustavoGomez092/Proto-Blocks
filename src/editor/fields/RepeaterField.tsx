/**
 * Repeater Field Component for Proto-Blocks
 *
 * Renders the actual server-rendered <li data-proto-repeater-item>
 * markup for each item directly, with field editors mounted on the
 * `data-proto-field` hooks inside. Drag/duplicate/trash chrome lives
 * in an absolutely-positioned floating toolbar that appears on hover
 * -- it never participates in the item's layout, so grid/flex
 * templates retain their direct-child `<li>` structure.
 *
 * Replaces the older abstract preview UI that synthesized a
 * `.proto-blocks-repeater__item-preview` box from `config.fields`.
 * That approach forced a hard-coded vertical stack that fought
 * non-stat templates and only worked when fields were declared in
 * the right shape in block.json. The new approach lets each block's
 * template control its own rendered appearance.
 */

import React from 'react';
import {
    createElement,
    useState,
    useCallback,
    useMemo,
    useRef,
    useEffect,
} from '@wordpress/element';
import { Button, Popover } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
    dragHandle,
    copy,
    trash,
    plus,
} from '@wordpress/icons';
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
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { FieldProps, RepeaterItem, FieldConfig } from '../types';
import { processElementNode } from '../utils/html-to-react';

interface RepeaterFieldProps extends FieldProps<RepeaterItem[]> {
    className?: string;
    element?: Element;
}

interface RepeaterConfig extends FieldConfig {
    min?: number;
    max?: number;
    itemLabel?: string;
    fields?: Record<string, FieldConfig>;
}

interface SortableItemProps {
    id: string;
    itemElement: Element;
    item: RepeaterItem;
    fields: Record<string, FieldConfig>;
    canRemove: boolean;
    canAdd: boolean;
    // All callbacks are id-based and stable. The item component
    // binds them with `useCallback(..., [id, fn])` so each operation
    // re-uses the same closure across renders, and the per-item
    // `processElementNode` useMemo only re-runs when this item's
    // own value changes -- not whenever a sibling is edited.
    onRemove: (id: string) => void;
    onDuplicate: (id: string) => void;
    onAddAfter: (id: string) => void;
    onFieldChange: (id: string, fieldName: string, value: unknown) => void;
    isSelected?: boolean;
}

/**
 * Generate a unique ID for new items
 */
function generateId(): string {
    return `item_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * Parse inline style string to React style object.
 *
 * Preserves CSS custom properties (--var) as-is and converts the
 * rest of kebab-case property names to camelCase for React.
 */
function parseStyleString(styleString: string): React.CSSProperties {
    const styles: Record<string, string> = {};

    styleString.split(';').forEach((declaration) => {
        const colonIndex = declaration.indexOf(':');
        if (colonIndex === -1) return;

        const property = declaration.slice(0, colonIndex).trim();
        const value = declaration.slice(colonIndex + 1).trim();

        if (property && value) {
            if (property.startsWith('--')) {
                styles[property] = value;
            } else {
                const camelProperty = property.replace(/-([a-z])/g, (_, letter) =>
                    letter.toUpperCase()
                );
                styles[camelProperty] = value;
            }
        }
    });

    return styles as React.CSSProperties;
}

/**
 * Floating overlay toolbar for a single repeater item.
 *
 * Drag handle (left), duplicate, remove. Absolutely-positioned at
 * the item's top-right corner, hidden by default, fades in when the
 * item is hovered, focused, or being dragged. Pointer events stay
 * off while hidden so the toolbar doesn't block clicks on fields
 * underneath.
 */
function RepeaterItemToolbar({
    dragAttributes,
    dragListeners,
    onRemove,
    onDuplicate,
    canAdd,
    canRemove,
}: {
    dragAttributes: Record<string, unknown>;
    dragListeners: Record<string, unknown> | undefined;
    onRemove: () => void;
    onDuplicate: () => void;
    canAdd: boolean;
    canRemove: boolean;
}): JSX.Element {
    return (
        <div
            className="proto-blocks-repeater__item-toolbar"
            // Stop pointer events on the toolbar from bubbling to the
            // item's contenteditable / RichText handlers underneath.
            onPointerDown={(e) => e.stopPropagation()}
        >
            <Button
                icon={dragHandle}
                label={__('Drag to reorder', 'proto-blocks')}
                className="proto-blocks-repeater__item-toolbar-btn proto-blocks-repeater__drag-handle"
                {...dragAttributes}
                {...(dragListeners || {})}
            />
            <Button
                icon={copy}
                label={__('Duplicate', 'proto-blocks')}
                onClick={onDuplicate}
                className="proto-blocks-repeater__item-toolbar-btn"
                disabled={!canAdd}
            />
            <Button
                icon={trash}
                label={__('Remove', 'proto-blocks')}
                onClick={onRemove}
                isDestructive
                className="proto-blocks-repeater__item-toolbar-btn"
                disabled={!canRemove}
            />
        </div>
    );
}

/**
 * Floating "add item between" button for each item.
 *
 * Absolutely-positioned at the item's bottom-center. Hidden until
 * the item (or its wrapper hover area) is hovered. Rendered as a
 * sibling of the toolbar inside the item itself so it inherits the
 * same hover state.
 */
function RepeaterItemAddBetween({
    onAddAfter,
    canAdd,
}: {
    onAddAfter: () => void;
    canAdd: boolean;
}): JSX.Element | null {
    if (!canAdd) return null;

    return (
        <button
            type="button"
            className="proto-blocks-repeater__add-between-btn"
            onClick={(e) => {
                // Don't let the click bubble into the item's own
                // handlers (RichText focus, link nav, etc.).
                e.stopPropagation();
                onAddAfter();
            }}
            onPointerDown={(e) => e.stopPropagation()}
            aria-label={__('Add item after this one', 'proto-blocks')}
        >
            <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                <path
                    d="M12 4v16m-8-8h16"
                    stroke="currentColor"
                    strokeWidth="2"
                    fill="none"
                />
            </svg>
        </button>
    );
}

/**
 * One repeater item -- the actual server-rendered <li> markup with
 * field editors mounted on its data-proto-field children and the
 * floating toolbar appended as a child overlay.
 *
 * The DND ref + transform style are injected directly onto the
 * cloned root element so the <li> itself is the sortable item and
 * any grid/flex template that targets direct-child <li> selectors
 * still applies. Drag LISTENERS go on the drag-handle button only,
 * never on the whole item -- otherwise clicks on inputs inside the
 * item would start drags.
 */
function SortableRepeaterItem({
    id,
    itemElement,
    item,
    fields,
    canRemove,
    canAdd,
    onRemove,
    onDuplicate,
    onAddAfter,
    onFieldChange,
    isSelected,
}: SortableItemProps): JSX.Element | null {
    const {
        attributes: dndAttributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    // Id-bound callbacks. Each one is stable for the lifetime of this
    // item because `id` is stable per item and the parent passes the
    // same `onFieldChange` / `onRemove` / etc. function reference on
    // every render. That stability is what makes the per-item useMemo
    // below only re-run when THIS item's value changes -- not when a
    // sibling is edited.
    const setItemField = useCallback(
        (fieldName: string, value: unknown) => {
            onFieldChange(id, fieldName, value);
        },
        [id, onFieldChange]
    );
    const removeMe = useCallback(() => onRemove(id), [id, onRemove]);
    const duplicateMe = useCallback(() => onDuplicate(id), [id, onDuplicate]);
    const addAfterMe = useCallback(() => onAddAfter(id), [id, onAddAfter]);

    // Process the server-rendered <li> with this item's scoped
    // attributes. Memoized on the item's value so field edits inside
    // the item trigger a re-render without re-processing every other
    // item's HTML.
    const itemReact = useMemo(() => {
        return processElementNode(itemElement, {
            attributes: item as Record<string, unknown>,
            setAttributes: setItemField,
            fields,
            isSelected: Boolean(isSelected),
        });
    }, [itemElement, item, fields, isSelected, setItemField]);

    if (!React.isValidElement(itemReact)) {
        return null;
    }

    // Compose the DND transform with whatever inline transform the
    // template may have declared. Position defaults to relative so
    // the floating toolbar (position:absolute) has a containing
    // block; if the template explicitly sets a position, we honor it.
    const originalProps = (itemReact as React.ReactElement<Record<string, unknown>>).props || {};
    const originalStyle: React.CSSProperties =
        (originalProps.style as React.CSSProperties | undefined) || {};

    const dndStyle: React.CSSProperties = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    const mergedStyle: React.CSSProperties = {
        ...originalStyle,
        ...dndStyle,
        position: originalStyle.position ?? 'relative',
    };

    const mergedClassName = [
        (originalProps.className as string | undefined) || '',
        'proto-blocks-repeater__sortable-item',
        isDragging ? 'is-dragging' : '',
    ]
        .filter(Boolean)
        .join(' ');

    const toolbar = (
        <RepeaterItemToolbar
            key="__proto_toolbar"
            dragAttributes={dndAttributes as Record<string, unknown>}
            dragListeners={listeners}
            onRemove={removeMe}
            onDuplicate={duplicateMe}
            canAdd={canAdd}
            canRemove={canRemove}
        />
    );

    const addBetween = (
        <RepeaterItemAddBetween
            key="__proto_add_between"
            onAddAfter={addAfterMe}
            canAdd={canAdd}
        />
    );

    // Existing children (rendered fields, decorations) PLUS the
    // overlay chrome appended at the end. React's cloneElement
    // replaces children when extras are provided, so we re-pass the
    // originals explicitly.
    const originalChildren = React.Children.toArray(
        originalProps.children as React.ReactNode
    );

    return React.cloneElement(
        itemReact as React.ReactElement,
        {
            ref: setNodeRef,
            className: mergedClassName,
            style: mergedStyle,
        },
        ...originalChildren,
        toolbar,
        addBetween,
    );
}

/**
 * Main Repeater Field Component
 */
export function RepeaterField({
    name,
    value,
    onChange,
    config,
    className = '',
    isSelected,
    element,
}: RepeaterFieldProps): JSX.Element {
    const repeaterConfig = config as RepeaterConfig;
    const items = Array.isArray(value) ? value : [];
    const [showInitialAdd, setShowInitialAdd] = useState(false);
    const initialAddRef = useRef<HTMLButtonElement>(null);

    // Resolve each item's matching server-rendered <li> from the
    // original repeater element. The server iterates the attribute
    // array in order, so positional matching is correct as long as
    // the array length matches.
    const renderedItemElements = useMemo(() => {
        if (!element) return [];
        return Array.from(
            element.querySelectorAll(':scope > [data-proto-repeater-item]')
        );
    }, [element]);

    // First-item template, used as the fallback element when a freshly-
    // added item shows up in the attribute array before the debounced
    // server preview returns its matching <li>. Without this fallback
    // newly-added items vanish for ~300ms until the next preview fetch.
    // With it, the new item renders an instant stub using the same
    // markup shape as the existing items, then is replaced by the
    // real server-rendered <li> on the next refresh.
    const itemTemplate = useMemo(() => {
        return renderedItemElements[0] || null;
    }, [renderedItemElements]);

    // Refs that always hold the latest items array and onChange
    // callback. The handlers below close over the refs (not over
    // `items` / `onChange` directly), so they stay reference-stable
    // for the lifetime of the component. Stable handlers + id-based
    // operations + per-item useMemo on `item` = only the edited item
    // re-processes its HTML; sibling items stay memoized.
    const itemsRef = useRef(items);
    const onChangeRef = useRef(onChange);
    useEffect(() => {
        itemsRef.current = items;
        onChangeRef.current = onChange;
    });

    // Configure DnD sensors. PointerSensor's `distance` activation
    // constraint means a small click on the drag handle doesn't
    // accidentally start a drag -- only a real pointer drag does.
    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: {
                distance: 5,
            },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    // Min/max constraints
    const minItems = repeaterConfig.min || 0;
    const maxItems = repeaterConfig.max || Infinity;
    const canAdd = items.length < maxItems;
    const canRemove = items.length > minItems;

    /**
     * Handle drag end. Reads the latest items from the ref so the
     * callback can be stable across renders.
     */
    const handleDragEnd = useCallback((event: DragEndEvent) => {
        const { active, over } = event;
        if (!over || active.id === over.id) return;
        const current = itemsRef.current;
        const oldIndex = current.findIndex((it) => it.id === active.id);
        const newIndex = current.findIndex((it) => it.id === over.id);
        if (oldIndex === -1 || newIndex === -1) return;
        onChangeRef.current(arrayMove(current, oldIndex, newIndex));
    }, []);

    /**
     * Create a new item with default values. Reads field defaults
     * from `repeaterConfig.fields` (the config object reference is
     * stable for the block's lifetime).
     */
    const fieldsConfig = repeaterConfig.fields;
    const createNewItem = useCallback((): RepeaterItem => {
        const newItem: RepeaterItem = { id: generateId() };
        const localFields = fieldsConfig || {};
        Object.entries(localFields).forEach(([fieldName, fieldConfig]) => {
            if (fieldConfig.default !== undefined) {
                newItem[fieldName] = fieldConfig.default;
            }
        });
        return newItem;
    }, [fieldsConfig]);

    /**
     * Add a new item at the end
     */
    const handleAddItem = useCallback(() => {
        const current = itemsRef.current;
        if (current.length >= maxItems) return;
        onChangeRef.current([...current, createNewItem()]);
    }, [maxItems, createNewItem]);

    /**
     * Add a new item after the item with the given id.
     */
    const handleAddItemAfter = useCallback(
        (itemId: string) => {
            const current = itemsRef.current;
            if (current.length >= maxItems) return;
            const index = current.findIndex((it) => it.id === itemId);
            if (index === -1) return;
            const newItem = createNewItem();
            onChangeRef.current([
                ...current.slice(0, index + 1),
                newItem,
                ...current.slice(index + 1),
            ]);
        },
        [maxItems, createNewItem]
    );

    /**
     * Remove an item by id
     */
    const handleRemoveItem = useCallback(
        (itemId: string) => {
            const current = itemsRef.current;
            if (current.length <= minItems) return;
            onChangeRef.current(current.filter((it) => it.id !== itemId));
        },
        [minItems]
    );

    /**
     * Duplicate an item by id
     */
    const handleDuplicateItem = useCallback(
        (itemId: string) => {
            const current = itemsRef.current;
            if (current.length >= maxItems) return;
            const index = current.findIndex((it) => it.id === itemId);
            if (index === -1) return;
            const newItem: RepeaterItem = {
                ...current[index],
                id: generateId(),
            };
            onChangeRef.current([
                ...current.slice(0, index + 1),
                newItem,
                ...current.slice(index + 1),
            ]);
        },
        [maxItems]
    );

    /**
     * Update a field within an item, by item id. Stable closure --
     * reads items from the ref so per-item useMemo doesn't have to
     * re-run when this function "changes" on parent re-renders.
     */
    const handleFieldChange = useCallback(
        (itemId: string, fieldName: string, fieldValue: unknown) => {
            const current = itemsRef.current;
            const newItems = current.map((it) =>
                it.id === itemId
                    ? { ...it, [fieldName]: fieldValue }
                    : it
            );
            onChangeRef.current(newItems);
        },
        []
    );

    // The container element inherits the original <ul>/<div>'s
    // className and any inline style (CSS custom properties used by
    // grid templates, etc.) so the editor layout matches the front-
    // end exactly. Tag matches whatever the template used (ul/div).
    const ContainerTag = (element?.tagName?.toLowerCase() || 'div') as keyof JSX.IntrinsicElements;
    const originalContainerStyle = element?.getAttribute('style') || '';
    const containerStyle = originalContainerStyle
        ? parseStyleString(originalContainerStyle)
        : undefined;
    const fields = repeaterConfig.fields || {};

    return (
        <div className="proto-blocks-repeater proto-blocks-repeater--inline">
            <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={handleDragEnd}
            >
                <SortableContext
                    items={items.map((item) => item.id)}
                    strategy={verticalListSortingStrategy}
                >
                    {createElement(
                        ContainerTag,
                        {
                            className: `proto-blocks-repeater__items ${className}`,
                            style: containerStyle,
                        },
                        ...items.map((item, index) => {
                            // Use the server-rendered <li> for this
                            // index when available; otherwise fall
                            // back to the first item's template so a
                            // freshly-added item renders an instant
                            // stub instead of vanishing for ~300ms.
                            // The fallback gets replaced by the real
                            // server-rendered <li> on the next
                            // preview refresh -- transparent to the
                            // author.
                            const itemEl =
                                renderedItemElements[index] || itemTemplate;
                            if (!itemEl) return null;

                            return (
                                <SortableRepeaterItem
                                    key={item.id}
                                    id={item.id}
                                    itemElement={itemEl}
                                    item={item}
                                    fields={fields}
                                    canRemove={canRemove}
                                    canAdd={canAdd}
                                    onRemove={handleRemoveItem}
                                    onDuplicate={handleDuplicateItem}
                                    onAddAfter={handleAddItemAfter}
                                    onFieldChange={handleFieldChange}
                                    isSelected={isSelected}
                                />
                            );
                        })
                    )}
                </SortableContext>
            </DndContext>

            {/* Initial Add Button when empty */}
            {items.length === 0 && canAdd && (
                <div className="proto-blocks-repeater__empty">
                    <button
                        ref={initialAddRef}
                        type="button"
                        className="proto-blocks-repeater__add-initial"
                        onClick={() => setShowInitialAdd(!showInitialAdd)}
                    >
                        <svg viewBox="0 0 24 24" width="24" height="24">
                            <path
                                d="M12 4v16m-8-8h16"
                                stroke="currentColor"
                                strokeWidth="2"
                                fill="none"
                            />
                        </svg>
                        <span>{__('Add item', 'proto-blocks')}</span>
                    </button>
                    {showInitialAdd && (
                        <Popover
                            anchor={initialAddRef.current}
                            onClose={() => setShowInitialAdd(false)}
                            placement="bottom"
                            className="proto-blocks-repeater__add-popover"
                        >
                            <Button
                                variant="primary"
                                onClick={() => {
                                    handleAddItem();
                                    setShowInitialAdd(false);
                                }}
                                className="proto-blocks-repeater__add-popover-button"
                            >
                                {__('Add first item', 'proto-blocks')}
                            </Button>
                        </Popover>
                    )}
                </div>
            )}
        </div>
    );
}
