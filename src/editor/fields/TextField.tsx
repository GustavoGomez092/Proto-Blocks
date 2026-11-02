/**
 * Text Field Component for Proto-Blocks
 *
 * Renders an editable RichText field for text content
 */

import React from 'react';
import { createElement } from '@wordpress/element';
import { RichText } from '@wordpress/block-editor';
import { FieldProps } from '../types';
import { FORMAT_MAP } from './formats';
import { sanitizeRichTextValue } from '../utils/sanitize-rich-text';

interface TextFieldProps extends FieldProps<string> {
    tagName?: string;
    className?: string;
}

export function TextField({
    name,
    value,
    onChange,
    config,
    tagName = 'p',
    className = '',
    isSelected,
}: TextFieldProps): JSX.Element {
    // Determine allowed formats from config
    const formatType = (config as { format?: string }).format || 'standard';
    const allowedFormats = FORMAT_MAP[formatType] || FORMAT_MAP.standard;

    // Use the configured tagName or fall back to prop
    const Tag = config.tagName || tagName;

    // The stored attribute is contributor-controlled comment JSON that the
    // server never kses'd, and RichText keeps unknown tags. Launder it on the
    // way in and on every change so a stored payload is dropped on next save.
    const safeValue = sanitizeRichTextValue(value || '');
    const handleChange = (next: string) => onChange(sanitizeRichTextValue(next));

    return (
        <RichText
            tagName={Tag}
            value={safeValue}
            onChange={handleChange}
            placeholder={`Enter ${config.label || name}...`}
            allowedFormats={allowedFormats}
            className={className}
            preserveWhiteSpace={false}
            __unstableDisableFormats={formatType === 'plain'}
        />
    );
}
