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
import {
    Button,
    Popover,
    TextControl,
    ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
    dragHandle,
    copy,
    trash,
    plus,
    link as linkIcon,
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
import { FieldProps, RepeaterItem, FieldConfig, LinkValue } from '../types';
import { processElementNode } from '../utils/html-to-react';

/**
 * Decide which side of an item its "add between" button should sit on,
 * based on the repeater's actual flow direction.
 *
 * We compare the item's center to its neighbor's center: if the next
 * (or, for the last item, previous) item is offset more horizontally
 * than vertically, the items flow in a row -> put the "+" on the right
 * edge (between this item and the next). Otherwise they stack -> put it
 * on the bottom edge. This reads the rendered geometry rather than the
 * CSS, so it works for flex rows, grids, and wrapped grids alike
 * (row-end items in a wrapped grid correctly fall back to "bottom").
 */
function computeFlowPlacement(el: HTMLElement | null): 'right' | 'bottom' {
    if (!el) return 'bottom';
    const sel = '[data-proto-repeater-item], .proto-blocks-repeater__sortable-item';
    const sibling = (node: Element, dir: 'next' | 'previous'): Element | null => {
        let n: Element | null =
            dir === 'next' ? node.nextElementSibling : node.previousElementSibling;
        while (n && !n.matches(sel)) {
            n = dir === 'next' ? n.nextElementSibling : n.previousElementSibling;
        }
        return n;
    };
    const a = el.getBoundingClientRect();
    const axisTo = (other: Element | null): 'horizontal' | 'vertical' | null => {
        if (!other) return null;
        const b = other.getBoundingClientRect();
        const dx = b.left + b.width / 2 - (a.left + a.width / 2);
        const dy = b.top + b.height / 2 - (a.top + a.height / 2);
        return Math.abs(dx) > Math.abs(dy) ? 'horizontal' : 'vertical';
    };
    const dir =
        axisTo(sibling(el, 'next')) || axisTo(sibling(el, 'previous')) || 'vertical';
    return dir === 'horizontal' ? 'right' : 'bottom';
}

/**
 * Resolve the name of the repeater item's link field (the first field of
 * type "link"), if any. Used to offer item-level link editing.
 */
function findLinkFieldName(fields: Record<string, FieldConfig>): string | null {
    for (const [fieldName, cfg] of Object.entries(fields || {})) {
        if (cfg && cfg.type === 'link') return fieldName;
    }
    return null;
}

/**
 * Item-level link control rendered inside the repeater item's floating
 * toolbar. URL-only (no rich text) so it works for items whose link is an
 * icon-only or whole-element <a> with no editable link text -- e.g. a card
 * whose entire surface is the link. Lives in the absolute overlay so it
 * never affects the item's own grid/flex layout. Edits the item's link
 * field value (url + open-in-new-tab).
 */
function RepeaterItemLinkButton({
    value,
    onChange,
}: {
    value: LinkValue | undefined;
    onChange: (value: LinkValue) => void;
}): JSX.Element {
    const [isEditing, setIsEditing] = useState(false);
    const link: LinkValue = value || { url: '', text: '', target: '', rel: '', title: '' };

    return (
        <>
            <Button
                icon={linkIcon}
                label={__('Edit link', 'proto-blocks')}
                onClick={() => setIsEditing((v) => !v)}
                isPressed={isEditing}
                className="proto-blocks-repeater__item-toolbar-btn"
            />
            {isEditing && (
                <Popover
                    onClose={() => setIsEditing(false)}
                    placement="bottom-end"
                    className="proto-blocks-link-popover"
                    onPointerDown={(e: React.PointerEvent) => e.stopPropagation()}
                >
                    <div
                        className="proto-blocks-link-popover__content"
                        style={{ padding: '8px', minWidth: '260px' }}
                    >
                        <TextControl
                            label={__('URL', 'proto-blocks')}
                            value={link.url || ''}
                            onChange={(url) => onChange({ ...link, url })}
                            placeholder="https://"
                        />
                        <ToggleControl
                            label={__('Open in new tab', 'proto-blocks')}
                            checked={link.target === '_blank'}
                            onChange={(checked) =>
                                onChange({
                                    ...link,
                                    target: checked ? '_blank' : '',
                                    rel: checked ? 'noopener noreferrer' : '',
                                })
                            }
                        />
                        <div style={{ display: 'flex', gap: '8px', marginTop: '8px' }}>
                            <Button variant="primary" onClick={() => setIsEditing(false)}>
                                {__('Done', 'proto-blocks')}
                            </Button>
                            {link.url && (
                                <Button
                                    variant="link"
                                    isDestructive
                                    onClick={() => {
                                        onChange({ ...link, url: '', target: '', rel: '' });
                                        setIsEditing(false);
                                    }}
                                >
                                    {__('Remove link', 'proto-blocks')}
                                </Button>
                            )}
                        </div>
                    </div>
                </Popover>
            )}
        </>
    );
}

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
    // Name of the item's link field (type "link"), if the repeater declares one.
    linkFieldName?: string | null;
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
    linkValue,
    onLinkChange,
}: {
    dragAttributes: Record<string, unknown>;
    dragListeners: Record<string, unknown> | undefined;
    onRemove: () => void;
    onDuplicate: () => void;
    canAdd: boolean;
    canRemove: boolean;
    // When provided, an item-level (URL-only) link control is shown in the
    // toolbar. Offered when the item has a link field that is not already
    // bound to an inline data-proto-field element (e.g. a card whose whole
    // surface is the link, or an icon-only link with no editable text).
    linkValue?: LinkValue | undefined;
    onLinkChange?: (value: LinkValue) => void;
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
            {onLinkChange && (
                <RepeaterItemLinkButton value={linkValue} onChange={onLinkChange} />
            )}
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
 * Rendered through a WordPress <Popover> anchored to the item's
 * bottom edge, so it is TELEPORTED out of the item's own DOM subtree
 * and into the editor's top-level popover slot. This is what lets it
 * sit half-outside the card without being clipped: cards commonly use
 * `overflow: hidden` (rounded corners, inner shadows), and an
 * overflow-hidden ancestor clips its descendants regardless of
 * z-index. A portal escapes that ancestor entirely, so the button is
 * always drawn on top.
 *
 * Visibility is controlled by the parent (`visible`) rather than CSS
 * :hover, because the portaled button lives outside the item and so
 * can't inherit the item's hover state. The parent keeps it shown
 * while either the item or this button is hovered (a small timeout
 * bridges the gap as the pointer travels between them).
 *
 * Placement follows the repeater's flow direction (see
 * computeFlowPlacement): a row of cards gets the "+" on the right edge
 * between items; a stacked list gets it on the bottom edge.
 * `offset={-12}` pulls the popover back toward the anchor so the round
 * button straddles that edge rather than floating in the gap.
 */
function RepeaterItemAddBetween({
    onAddAfter,
    canAdd,
    visible,
    anchor,
    onPointerEnter,
    onPointerLeave,
}: {
    onAddAfter: () => void;
    canAdd: boolean;
    visible: boolean;
    anchor: HTMLElement | null;
    onPointerEnter: () => void;
    onPointerLeave: () => void;
}): JSX.Element | null {
    if (!canAdd || !visible || !anchor) return null;

    const placement = computeFlowPlacement(anchor);

    return (
        <Popover
            anchor={anchor}
            placement={placement}
            offset={-12}
            focusOnMount={false}
            className={`proto-blocks-repeater__add-between-popover is-${placement}`}
        >
            <div
                className="proto-blocks-repeater__add-between-wrap"
                onPointerEnter={onPointerEnter}
                onPointerLeave={onPointerLeave}
            >
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
            </div>
        </Popover>
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
    linkFieldName,
}: SortableItemProps): JSX.Element | null {
    const {
        attributes: dndAttributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    // The item's live DOM node, used as the anchor for the teleported
    // "add between" Popover. Captured via a ref callback that also feeds
    // dnd-kit's setNodeRef so the same <li> is both the sortable node and
    // the popover anchor.
    const itemNodeRef = useRef<HTMLElement | null>(null);
    const setItemRef = useCallback(
        (node: HTMLElement | null) => {
            setNodeRef(node as HTMLElement);
            itemNodeRef.current = node;
        },
        [setNodeRef]
    );

    // Hover/focus visibility for the teleported add-between button. Since
    // the button is portaled out of the item, it can't use CSS :hover --
    // we drive it from React. A short hide timeout bridges the gap as the
    // pointer travels from the card down onto the button (and back).
    const [addVisible, setAddVisible] = useState(false);
    const hideTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const showAdd = useCallback(() => {
        if (hideTimer.current) {
            clearTimeout(hideTimer.current);
            hideTimer.current = undefined;
        }
        setAddVisible(true);
    }, []);
    const hideAdd = useCallback(() => {
        if (hideTimer.current) clearTimeout(hideTimer.current);
        hideTimer.current = setTimeout(() => setAddVisible(false), 120);
    }, []);
    useEffect(() => {
        return () => {
            if (hideTimer.current) clearTimeout(hideTimer.current);
        };
    }, []);

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

    // Offer an item-level (URL-only) link control in the toolbar when:
    //   - the repeater declares a link field, AND
    //   - the item is or contains an <a> (it's a link element), AND
    //   - that link field is NOT already bound to an inline data-proto-field
    //     element (which would render its own LinkField editor inline).
    // This covers items whose whole surface is the link, or whose link is an
    // icon-only <a> with no editable text (e.g. featured cards).
    const showToolbarLink = useMemo(() => {
        if (!linkFieldName) return false;
        const isAnchor =
            itemElement.tagName === 'A' || !!itemElement.querySelector('a');
        if (!isAnchor) return false;
        const boundInline =
            itemElement.getAttribute('data-proto-field') === linkFieldName ||
            !!itemElement.querySelector(`[data-proto-field="${linkFieldName}"]`);
        return !boundInline;
    }, [itemElement, linkFieldName]);
    const setItemLink = useCallback(
        (value: LinkValue) => {
            if (linkFieldName) onFieldChange(id, linkFieldName, value);
        },
        [id, linkFieldName, onFieldChange]
    );

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
            linkValue={
                showToolbarLink && linkFieldName
                    ? (item[linkFieldName] as LinkValue | undefined)
                    : undefined
            }
            onLinkChange={showToolbarLink ? setItemLink : undefined}
        />
    );

    // Teleported "add between" button. Rendered as a SIBLING of the item
    // (not a child) so it lives outside the card's overflow-hidden subtree.
    // It portals to the editor's popover slot regardless, but keeping it a
    // sibling avoids it ever being treated as a grid/flex child of the
    // item. Hidden while dragging to avoid a stray button mid-reorder.
    const addBetween = (
        <RepeaterItemAddBetween
            onAddAfter={addAfterMe}
            canAdd={canAdd}
            visible={addVisible && !isDragging}
            anchor={itemNodeRef.current}
            onPointerEnter={showAdd}
            onPointerLeave={hideAdd}
        />
    );

    // Existing children (rendered fields, decorations) PLUS the
    // overlay chrome appended at the end. React's cloneElement
    // replaces children when extras are provided, so we re-pass the
    // originals explicitly.
    const originalChildren = React.Children.toArray(
        originalProps.children as React.ReactNode
    );

    const clonedItem = React.cloneElement(
        itemReact as React.ReactElement,
        {
            ref: setItemRef,
            className: mergedClassName,
            style: mergedStyle,
            // Drive the teleported add-between button's visibility. The
            // toolbar still uses CSS :hover (it's inside the card and not
            // clipped); only the half-outside add button needs JS hover.
            onMouseEnter: showAdd,
            onMouseLeave: hideAdd,
            onFocusCapture: showAdd,
            onBlurCapture: hideAdd,
        },
        ...originalChildren,
        toolbar,
    );

    return (
        <>
            {clonedItem}
            {addBetween}
        </>
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
    const linkFieldName = findLinkFieldName(fields);

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
                                    linkFieldName={linkFieldName}
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
