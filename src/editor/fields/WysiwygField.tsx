/**
 * WYSIWYG Field Component for Proto-Blocks
 *
 * Renders a full rich text editor with all formatting options
 */

import React from 'react';
import { createElement } from '@wordpress/element';
import { RichText } from '@wordpress/block-editor';
import { FieldProps } from '../types';

interface WysiwygFieldProps extends FieldProps<string> {
    className?: string;
    tagName?: string;
}

/**
 * All available RichText formats
 */
const ALL_FORMATS = [
    'core/bold',
    'core/italic',
    'core/link',
    'core/strikethrough',
    'core/underline',
    'core/subscript',
    'core/superscript',
    'core/code',
    'core/image',
    'core/text-color',
    'core/keyboard',
];

export function WysiwygField({
    name,
    value,
    onChange,
    config,
    className = '',
    tagName = 'div',
    isSelected,
}: WysiwygFieldProps): JSX.Element {
    // Use configured tagName or fall back to prop
    const Tag = config.tagName || tagName;

    return (
        <div className={`proto-blocks-wysiwyg-field ${className}`}>
            <RichText
                tagName={Tag}
                value={value || ''}
                onChange={onChange}
                placeholder={config.placeholder || `Enter ${config.label || name}...`}
                allowedFormats={ALL_FORMATS}
                preserveWhiteSpace
            />
        </div>
    );
}
