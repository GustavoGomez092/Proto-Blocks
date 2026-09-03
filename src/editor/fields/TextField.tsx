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

    return (
        <RichText
            tagName={Tag}
            value={value || ''}
            onChange={onChange}
            placeholder={`Enter ${config.label || name}...`}
            allowedFormats={allowedFormats}
            className={className}
            preserveWhiteSpace={false}
            __unstableDisableFormats={formatType === 'plain'}
        />
    );
}
