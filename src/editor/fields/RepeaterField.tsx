/**
 * Repeater Field Component for Proto-Blocks
 *
 * Shows rendered preview with inline editing and overlay controls
 */

import React from 'react';
import { createElement, useState, useCallback, useRef } from '@wordpress/element';
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
import { renderField } from './render';

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
    item: RepeaterItem;
    index: number;
    config: RepeaterConfig;
    canRemove: boolean;
    canAdd: boolean;
    onRemove: () => void;
    onDuplicate: () => void;
    onAddAfter: () => void;
    onFieldChange: (fieldName: string, value: unknown) => void;
    isSelected?: boolean;
    itemTemplate: Element | null;
}

/**
 * Generate a unique ID for new items
 */
function generateId(): string {
    return `item_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * Parse inline style string to React style object
 */
function parseStyleString(styleString: string): React.CSSProperties {
    const styles: Record<string, string> = {};

    styleString.split(';').forEach((declaration) => {
        const colonIndex = declaration.indexOf(':');
        if (colonIndex === -1) return;

        const property = declaration.slice(0, colonIndex).trim();
        const value = declaration.slice(colonIndex + 1).trim();

        if (property && value) {
            // Keep CSS custom properties (--var) as-is
            if (property.startsWith('--')) {
                styles[property] = value;
            } else {
                // Convert kebab-case to camelCase for regular properties
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
 * Render a field value as preview text
 */
function renderPreviewValue(value: unknown, fieldConfig: FieldConfig): string {
    if (value === null || value === undefined) {
        return fieldConfig.default?.toString() || '';
    }
    if (typeof value === 'object') {
        // Handle link fields
        if ('text' in (value as Record<string, unknown>)) {
            return (value as Record<string, unknown>).text as string || '';
        }
        // Handle image fields
        if ('url' in (value as Record<string, unknown>)) {
            return '[Image]';
        }
        return '';
    }
    return String(value);
}

/**
 * Sortable Repeater Item Component with Preview
 */
function SortableRepeaterItem({
    item,
    index,
    config,
    canRemove,
    canAdd,
    onRemove,
    onDuplicate,
    onAddAfter,
    onFieldChange,
    isSelected,
    itemTemplate,
}: SortableItemProps): JSX.Element {
    const [editingField, setEditingField] = useState<string | null>(null);
    const [showAddPopover, setShowAddPopover] = useState(false);
    const addButtonRef = useRef<HTMLButtonElement>(null);

    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: item.id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    const fields = config.fields || {};

    /**
     * Render item preview using the template structure
     */
    const renderItemPreview = () => {
        return (
            <div className="proto-blocks-repeater__item-preview">
                {Object.entries(fields).map(([fieldName, fieldConfig]) => {
                    const isEditing = editingField === fieldName;
                    const value = item[fieldName];

                    if (isEditing) {
                        return (
                            <div
                                key={fieldName}
                                className="proto-blocks-repeater__field-editing"
                                onBlur={(e) => {
                                    // Check if the focus moved outside this field
                                    if (!e.currentTarget.contains(e.relatedTarget as Node)) {
                                        setEditingField(null);
                                    }
                                }}
                            >
                                {renderField({
                                    name: fieldName,
                                    value: value,
                                    onChange: (newValue: unknown) => onFieldChange(fieldName, newValue),
                                    config: fieldConfig,
                                    isSelected: true,
                                })}
                            </div>
                        );
                    }

                    // Render as clickable preview
                    return (
                        <div
                            key={fieldName}
                            className={`proto-blocks-repeater__field-preview proto-blocks-repeater__field-preview--${fieldConfig.type || 'text'}`}
                            onClick={() => setEditingField(fieldName)}
                            role="button"
                            tabIndex={0}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    setEditingField(fieldName);
                                }
                            }}
                        >
                            {fieldConfig.type === 'image' && value && (value as Record<string, string>).url ? (
                                <img
                                    src={(value as Record<string, string>).url}
                                    alt=""
                                    className="proto-blocks-repeater__field-image"
                                />
                            ) : (
                                <span
                                    className={`proto-blocks-repeater__field-value proto-blocks-repeater__field-value--${fieldName}`}
                                    data-placeholder={fieldConfig.label || fieldName}
                                >
                                    {renderPreviewValue(value, fieldConfig) || (
                                        <span className="proto-blocks-repeater__placeholder">
                                            {fieldConfig.label || fieldName}
                                        </span>
                                    )}
                                </span>
                            )}
                        </div>
                    );
                })}
            </div>
        );
    };

    return (
        <div className="proto-blocks-repeater__item-wrapper">
            <div
                ref={setNodeRef}
                style={style}
                className={`proto-blocks-repeater__item ${isDragging ? 'is-dragging' : ''}`}
            >
                {/* Drag Handle - Left Side */}
                <div className="proto-blocks-repeater__item-drag">
                    <Button
                        icon={dragHandle}
                        className="proto-blocks-repeater__drag-handle"
                        {...attributes}
                        {...listeners}
                        label={__('Drag to reorder', 'proto-blocks')}
                    />
                </div>

                {/* Content Preview */}
                <div className="proto-blocks-repeater__item-content">
                    {renderItemPreview()}
                </div>

                {/* Actions - Right Side */}
                <div className="proto-blocks-repeater__item-actions">
                    <Button
                        icon={copy}
                        label={__('Duplicate', 'proto-blocks')}
                        onClick={onDuplicate}
                        className="proto-blocks-repeater__action"
                        disabled={!canAdd}
                    />
                    <Button
                        icon={trash}
                        label={__('Remove', 'proto-blocks')}
                        onClick={onRemove}
                        isDestructive
                        className="proto-blocks-repeater__action"
                        disabled={!canRemove}
                    />
                </div>
            </div>

            {/* Add Item Button Between Items */}
            {canAdd && (
                <div className="proto-blocks-repeater__add-between">
                    <button
                        ref={addButtonRef}
                        type="button"
                        className="proto-blocks-repeater__add-button"
                        onClick={() => setShowAddPopover(!showAddPopover)}
                        aria-label={__('Add item', 'proto-blocks')}
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M12 4v16m-8-8h16" stroke="currentColor" strokeWidth="2" fill="none" />
                        </svg>
                    </button>
                    {showAddPopover && (
                        <Popover
                            anchor={addButtonRef.current}
                            onClose={() => setShowAddPopover(false)}
                            placement="bottom"
                            className="proto-blocks-repeater__add-popover"
                        >
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    onAddAfter();
                                    setShowAddPopover(false);
                                }}
                                className="proto-blocks-repeater__add-popover-button"
                            >
                                {__('Add new item', 'proto-blocks')}
                            </Button>
                        </Popover>
                    )}
                </div>
            )}
        </div>
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

    // Extract item template from the original element if available
    const itemTemplate = element?.querySelector('[data-proto-repeater-item]') || null;

    // Configure DnD sensors
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

    // Get the original element's inline style for CSS variables (like --proto-stats-columns)
    const originalStyle = element?.getAttribute('style') || '';

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
                    {/* Apply original className to items container to inherit grid/flex layout */}
                    <div
                        className={`proto-blocks-repeater__items ${className}`}
                        style={originalStyle ? parseStyleString(originalStyle) : undefined}
                    >
                        {items.map((item, index) => (
                            <SortableRepeaterItem
                                key={item.id}
                                item={item}
                                index={index}
                                config={repeaterConfig}
                                canRemove={canRemove}
                                canAdd={canAdd}
                                onRemove={() => handleRemoveItem(index)}
                                onDuplicate={() => handleDuplicateItem(index)}
                                onAddAfter={() => handleAddItemAfter(index)}
                                onFieldChange={(fieldName, fieldValue) =>
                                    handleFieldChange(index, fieldName, fieldValue)
                                }
                                isSelected={isSelected}
                                itemTemplate={itemTemplate}
                            />
                        ))}
                    </div>
                </SortableContext>
            </DndContext>

            {/* Initial Add Button when empty or at the end */}
            {items.length === 0 && canAdd && (
                <div className="proto-blocks-repeater__empty">
                    <button
                        ref={initialAddRef}
                        type="button"
                        className="proto-blocks-repeater__add-initial"
                        onClick={() => setShowInitialAdd(!showInitialAdd)}
                    >
                        <svg viewBox="0 0 24 24" width="24" height="24">
                            <path d="M12 4v16m-8-8h16" stroke="currentColor" strokeWidth="2" fill="none" />
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
