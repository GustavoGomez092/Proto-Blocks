/**
 * Inner Blocks Field Component for Proto-Blocks
 *
 * Renders a slot for nested WordPress blocks
 */

import React from 'react';
import { createElement } from '@wordpress/element';
import {
    InnerBlocks,
    useInnerBlocksProps,
    useBlockProps,
} from '@wordpress/block-editor';
import { FieldProps, FieldConfig } from '../types';

interface InnerBlocksConfig extends FieldConfig {
    allowedBlocks?: string[];
    template?: Array<[string, Record<string, unknown>?]>;
    templateLock?: 'all' | 'insert' | 'contentOnly' | false;
    orientation?: 'horizontal' | 'vertical';
    renderAppender?: 'default' | 'button' | false;
}

interface InnerBlocksFieldProps extends FieldProps<null> {
    className?: string;
}

export function InnerBlocksField({
    name,
    config,
    className = '',
}: InnerBlocksFieldProps): JSX.Element {
    const innerBlocksConfig = config as InnerBlocksConfig;

    // Determine which appender to use
    let renderAppender: (() => JSX.Element | null) | undefined;
    switch (innerBlocksConfig.renderAppender) {
        case 'button':
            renderAppender = InnerBlocks.ButtonBlockAppender;
            break;
        case false:
            renderAppender = () => null;
            break;
        default:
            renderAppender = InnerBlocks.DefaultBlockAppender;
    }

    // Build InnerBlocks props
    const innerBlocksProps = {
        allowedBlocks: innerBlocksConfig.allowedBlocks,
        template: innerBlocksConfig.template,
        templateLock: innerBlocksConfig.templateLock,
        orientation: innerBlocksConfig.orientation || 'vertical',
        renderAppender,
    };

    return (
        <div className={`proto-blocks-inner-blocks ${className}`}>
            <InnerBlocks {...innerBlocksProps} />
        </div>
    );
}

/**
 * Inner Blocks Content for save function
 */
export function InnerBlocksContent(): JSX.Element {
    return <InnerBlocks.Content />;
}
