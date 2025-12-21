/**
 * Field Renderer for Proto-Blocks
 *
 * Renders editable fields in the block preview based on field configuration
 */

import React from 'react';
import { createElement } from '@wordpress/element';
import { FieldProps, FieldConfig } from '../types';
import { TextField } from './TextField';
import { ImageField } from './ImageField';
import { LinkField } from './LinkField';
import { WysiwygField } from './WysiwygField';
import { RepeaterField } from './RepeaterField';
import { InnerBlocksField } from './InnerBlocksField';

// Extended props for special cases
interface RenderFieldProps extends FieldProps {
    element?: Element; // For repeater template extraction
}

/**
 * Field component registry
 */
const fieldComponents: Record<
    string,
    React.ComponentType<FieldProps<unknown>>
> = {
    text: TextField as React.ComponentType<FieldProps<unknown>>,
    'rich-text': TextField as React.ComponentType<FieldProps<unknown>>,
    image: ImageField as React.ComponentType<FieldProps<unknown>>,
    link: LinkField as React.ComponentType<FieldProps<unknown>>,
    wysiwyg: WysiwygField as React.ComponentType<FieldProps<unknown>>,
    repeater: RepeaterField as React.ComponentType<FieldProps<unknown>>,
    'inner-blocks': InnerBlocksField as React.ComponentType<FieldProps<unknown>>,
};

/**
 * Register a custom field component
 */
export function registerFieldComponent(
    type: string,
    component: React.ComponentType<FieldProps<unknown>>
): void {
    fieldComponents[type] = component;
}

/**
 * Render a field based on its configuration
 */
export function renderField(props: RenderFieldProps): JSX.Element | null {
    const { config, ...restProps } = props;
    const fieldType = config.type || 'text';

    const Component = fieldComponents[fieldType];

    if (!Component) {
        console.warn(`Proto-Blocks: Unknown field type "${fieldType}"`);
        return null;
    }

    return createElement(Component, {
        ...restProps,
        config,
    } as FieldProps);
}

/**
 * Get all registered field types
 */
export function getRegisteredFieldTypes(): string[] {
    return Object.keys(fieldComponents);
}
