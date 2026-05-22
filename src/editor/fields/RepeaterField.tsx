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
import { createElement, useState, useCallback, useMemo, useRef } from '@wordpress/element';
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
    onRemove: () => void;
    onDuplicate: () => void;
    onAddAfter: () => void;
    onFieldChange: (fieldName: string, value: unknown) => void;
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

    // Process the server-rendered <li> with this item's scoped
    // attributes. Memoized on the item's value so field edits inside
    // the item trigger a re-render without re-processing every other
    // item's HTML.
    const itemReact = useMemo(() => {
        return processElementNode(itemElement, {
            attributes: item as Record<string, unknown>,
            setAttributes: (fieldName: string, value: unknown) =>
                onFieldChange(fieldName, value),
            fields,
            isSelected: Boolean(isSelected),
        });
    }, [itemElement, item, fields, isSelected, onFieldChange]);

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
            onRemove={onRemove}
            onDuplicate={onDuplicate}
            canAdd={canAdd}
            canRemove={canRemove}
        />
    );

    const addBetween = (
        <RepeaterItemAddBetween
            key="__proto_add_between"
            onAddAfter={onAddAfter}
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
    // the array length matches. If the server renders fewer items
    // than the attribute array (e.g. after a fresh add before the
    // debounced preview returns), trailing items skip rendering
    // until the preview catches up.
    const renderedItemElements = useMemo(() => {
        if (!element) return [];
        return Array.from(
            element.querySelectorAll(':scope > [data-proto-repeater-item]')
        );
    }, [element]);

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
     * Handle drag end
     */
    const handleDragEnd = useCallback(
        (event: DragEndEvent) => {
            const { active, over } = event;

            if (over && active.id !== over.id) {
                const oldIndex = items.findIndex((item) => item.id === active.id);
                const newIndex = items.findIndex((item) => item.id === over.id);

                onChange(arrayMove(items, oldIndex, newIndex));
            }
        },
        [items, onChange]
    );

    /**
     * Create a new item with default values
     */
    const createNewItem = useCallback((): RepeaterItem => {
        const newItem: RepeaterItem = { id: generateId() };

        // Initialize default values for each field
        const fields = repeaterConfig.fields || {};
        Object.entries(fields).forEach(([fieldName, fieldConfig]) => {
            if (fieldConfig.default !== undefined) {
                newItem[fieldName] = fieldConfig.default;
            }
        });

        return newItem;
    }, [repeaterConfig.fields]);

    /**
     * Add a new item at the end
     */
    const handleAddItem = useCallback(() => {
        if (!canAdd) return;
        const newItem = createNewItem();
        onChange([...items, newItem]);
    }, [items, canAdd, createNewItem, onChange]);

    /**
     * Add a new item after a specific index
     */
    const handleAddItemAfter = useCallback(
        (index: number) => {
            if (!canAdd) return;
            const newItem = createNewItem();
            const newItems = [
                ...items.slice(0, index + 1),
                newItem,
                ...items.slice(index + 1),
            ];
            onChange(newItems);
        },
        [items, canAdd, createNewItem, onChange]
    );

    /**
     * Remove an item
     */
    const handleRemoveItem = useCallback(
        (index: number) => {
            if (!canRemove) return;
            const newItems = items.filter((_, i) => i !== index);
            onChange(newItems);
        },
        [items, canRemove, onChange]
    );

    /**
     * Duplicate an item
     */
    const handleDuplicateItem = useCallback(
        (index: number) => {
            if (!canAdd) return;

            const itemToDuplicate = items[index];
            const newItem: RepeaterItem = {
                ...itemToDuplicate,
                id: generateId(),
            };

            const newItems = [
                ...items.slice(0, index + 1),
                newItem,
                ...items.slice(index + 1),
            ];
            onChange(newItems);
        },
        [items, canAdd, onChange]
    );

    /**
     * Update a field within an item
     */
    const handleFieldChange = useCallback(
        (itemIndex: number, fieldName: string, fieldValue: unknown) => {
            const newItems = items.map((item, index) => {
                if (index === itemIndex) {
                    return { ...item, [fieldName]: fieldValue };
                }
                return item;
            });
            onChange(newItems);
        },
        [items, onChange]
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
                            const itemEl = renderedItemElements[index];
                            // The server-rendered HTML lags one
                            // preview-fetch behind a fresh add; skip
                            // until the matching <li> is available
                            // (the next preview refresh will populate
                            // it within ~300ms).
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
                                    onRemove={() => handleRemoveItem(index)}
                                    onDuplicate={() => handleDuplicateItem(index)}
                                    onAddAfter={() => handleAddItemAfter(index)}
                                    onFieldChange={(fieldName, fieldValue) =>
                                        handleFieldChange(index, fieldName, fieldValue)
                                    }
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
