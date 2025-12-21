/**
 * Repeater Field Component for Proto-Blocks
 *
 * Enhanced repeater with drag-drop, collapse, duplicate, and min/max support
 */

import React from 'react';
import { createElement, useState, useCallback } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
    dragHandle,
    chevronUp,
    chevronDown,
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
}

interface RepeaterConfig extends FieldConfig {
    min?: number;
    max?: number;
    collapsible?: boolean;
    itemLabel?: string;
    fields?: Record<string, FieldConfig>;
}

interface SortableItemProps {
    item: RepeaterItem;
    index: number;
    config: RepeaterConfig;
    isExpanded: boolean;
    canRemove: boolean;
    onToggle: () => void;
    onRemove: () => void;
    onDuplicate: () => void;
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
 * Sortable Repeater Item Component
 */
function SortableRepeaterItem({
    item,
    index,
    config,
    isExpanded,
    canRemove,
    onToggle,
    onRemove,
    onDuplicate,
    onFieldChange,
    isSelected,
}: SortableItemProps): JSX.Element {
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

    const itemLabel = config.itemLabel
        ? String(item[config.itemLabel] || `${config.itemLabel} ${index + 1}`)
        : `Item ${index + 1}`;

    const collapsible = config.collapsible !== false;
    const fields = config.fields || {};

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={`proto-blocks-repeater__item ${isDragging ? 'is-dragging' : ''} ${
                isExpanded ? 'is-expanded' : ''
            }`}
        >
            <div className="proto-blocks-repeater__item-header">
                <Button
                    icon={dragHandle}
                    className="proto-blocks-repeater__drag-handle"
                    {...attributes}
                    {...listeners}
                    label={__('Drag to reorder', 'proto-blocks')}
                />

                {collapsible ? (
                    <button
                        type="button"
                        className="proto-blocks-repeater__item-toggle"
                        onClick={onToggle}
                        aria-expanded={isExpanded}
                    >
                        <span className="proto-blocks-repeater__item-icon">
                            {isExpanded ? (
                                <svg viewBox="0 0 24 24" width="24" height="24">
                                    <path d="M6.5 12.4L12 8l5.5 4.4-.9 1.2L12 10l-4.5 3.6-1-1.2z" />
                                </svg>
                            ) : (
                                <svg viewBox="0 0 24 24" width="24" height="24">
                                    <path d="M17.5 11.6L12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z" />
                                </svg>
                            )}
                        </span>
                        <span className="proto-blocks-repeater__item-label">
                            {itemLabel}
                        </span>
                    </button>
                ) : (
                    <span className="proto-blocks-repeater__item-label">{itemLabel}</span>
                )}

                <div className="proto-blocks-repeater__item-actions">
                    <Button
                        icon={copy}
                        label={__('Duplicate', 'proto-blocks')}
                        onClick={onDuplicate}
                        className="proto-blocks-repeater__action"
                    />
                    {canRemove && (
                        <Button
                            icon={trash}
                            label={__('Remove', 'proto-blocks')}
                            onClick={onRemove}
                            isDestructive
                            className="proto-blocks-repeater__action"
                        />
                    )}
                </div>
            </div>

            {(!collapsible || isExpanded) && (
                <div className="proto-blocks-repeater__item-content">
                    {Object.entries(fields).map(([fieldName, fieldConfig]) => (
                        <div
                            key={fieldName}
                            className="proto-blocks-repeater__field"
                        >
                            <label className="proto-blocks-repeater__field-label">
                                {fieldConfig.label || fieldName}
                            </label>
                            {renderField({
                                name: fieldName,
                                value: item[fieldName],
                                onChange: (value: unknown) =>
                                    onFieldChange(fieldName, value),
                                config: fieldConfig,
                                isSelected,
                            })}
                        </div>
                    ))}
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
}: RepeaterFieldProps): JSX.Element {
    const repeaterConfig = config as RepeaterConfig;
    const items = Array.isArray(value) ? value : [];
    const [expandedItems, setExpandedItems] = useState<Set<string>>(new Set());

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
     * Add a new item
     */
    const handleAddItem = useCallback(() => {
        if (!canAdd) return;

        const newItem: RepeaterItem = { id: generateId() };

        // Initialize default values for each field
        const fields = repeaterConfig.fields || {};
        Object.entries(fields).forEach(([fieldName, fieldConfig]) => {
            if (fieldConfig.default !== undefined) {
                newItem[fieldName] = fieldConfig.default;
            }
        });

        const newItems = [...items, newItem];
        onChange(newItems);

        // Expand the new item
        setExpandedItems((prev) => new Set(prev).add(newItem.id));
    }, [items, canAdd, repeaterConfig.fields, onChange]);

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

            // Expand the new item
            setExpandedItems((prev) => new Set(prev).add(newItem.id));
        },
        [items, canAdd, onChange]
    );

    /**
     * Toggle item expansion
     */
    const handleToggleItem = useCallback((itemId: string) => {
        setExpandedItems((prev) => {
            const newSet = new Set(prev);
            if (newSet.has(itemId)) {
                newSet.delete(itemId);
            } else {
                newSet.add(itemId);
            }
            return newSet;
        });
    }, []);

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

    return (
        <div className={`proto-blocks-repeater ${className}`}>
            <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={handleDragEnd}
            >
                <SortableContext
                    items={items.map((item) => item.id)}
                    strategy={verticalListSortingStrategy}
                >
                    <div className="proto-blocks-repeater__items">
                        {items.map((item, index) => (
                            <SortableRepeaterItem
                                key={item.id}
                                item={item}
                                index={index}
                                config={repeaterConfig}
                                isExpanded={expandedItems.has(item.id)}
                                canRemove={canRemove}
                                onToggle={() => handleToggleItem(item.id)}
                                onRemove={() => handleRemoveItem(index)}
                                onDuplicate={() => handleDuplicateItem(index)}
                                onFieldChange={(fieldName, value) =>
                                    handleFieldChange(index, fieldName, value)
                                }
                                isSelected={isSelected}
                            />
                        ))}
                    </div>
                </SortableContext>
            </DndContext>

            {canAdd && (
                <Button
                    variant="secondary"
                    onClick={handleAddItem}
                    className="proto-blocks-repeater__add"
                    icon={plus}
                >
                    {__('Add Item', 'proto-blocks')}
                </Button>
            )}

            {items.length === 0 && (
                <div className="proto-blocks-repeater__empty">
                    {__('No items yet. Click "Add Item" to create one.', 'proto-blocks')}
                </div>
            )}
        </div>
    );
}
