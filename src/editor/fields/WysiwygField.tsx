/**
 * WYSIWYG Field Component for Proto-Blocks
 *
 * Renders a full rich text editor with all formatting options
 */

import React from 'react';
import { createElement } from '@wordpress/element';
import { RichText } from '@wordpress/block-editor';
import { FieldProps } from '../types';
import { ALL_FORMATS } from './formats';

interface WysiwygFieldProps extends FieldProps<string> {
    className?: string;
    tagName?: string;
}

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
