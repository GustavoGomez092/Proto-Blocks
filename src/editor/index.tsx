/**
 * Proto-Blocks Editor Entry Point
 *
 * Registers all Proto-Blocks with the WordPress block editor
 */

import React from 'react';
import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';
import { BlockData, BlockAttributes, BlockSupports } from './types';
import { createEditComponent } from './block-factory';
import { InnerBlocks } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import { getBlockIcon } from './utils/icon-utils';

// Get data from PHP
const protoBlocksData = window.protoBlocksData;
const debug = protoBlocksData?.debug || false;

const blocks: BlockData[] = protoBlocksData?.blocks || [];

if (debug) {
    console.log('Proto-Blocks: Editor initialized with', blocks.length, 'blocks');
}

// Register each block
blocks.forEach((block) => {
    try {

        // Extend attributes with internal preview attributes if preview image exists
        const extendedAttributes = { ...block.attributes };
        if (block.previewImage) {
            extendedAttributes.__isPreview = {
                type: 'boolean',
                default: false,
            };
            extendedAttributes.__previewImage = {
                type: 'string',
                default: '',
            };
        }

        const blockConfig: BlockConfiguration<BlockAttributes> = {
            apiVersion: 3,
            title: block.title || block.name,
            description: block.description || '',
            category: block.category || 'proto-blocks',
            icon: getBlockIcon(block.icon),
            keywords: block.keywords || [],
            supports: normalizeSupports(block.supports),
            attributes: extendedAttributes,
            edit: createEditComponent(block),
            // Use createElement instead of JSX to avoid runtime issues
            save: () => createElement(InnerBlocks.Content),
        };

        // Add example property for block preview in inserter
        // If a preview image exists, use it; otherwise use default example attributes
        if (block.previewImage) {
            blockConfig.example = {
                attributes: {
                    __isPreview: true,
                    __previewImage: block.previewImage,
                },
                viewportWidth: 500,
            };
        } else {
            // Default example with empty attributes to trigger a preview render
            blockConfig.example = {
                attributes: {},
                viewportWidth: 400,
            };
        }

        registerBlockType(`proto-blocks/${block.name}`, blockConfig);
    } catch (error) {
        console.error(`Proto-Blocks: Failed to register block "${block.name}"`, error);
    }
});

/**
 * Normalize block supports for WordPress compatibility
 */
function normalizeSupports(supports: BlockSupports = {}): BlockSupports {
    return {
        html: supports.html ?? false,
        anchor: supports.anchor ?? true,
        customClassName: supports.customClassName ?? true,
        align: supports.align ?? false,
        color: supports.color ?? false,
        typography: supports.typography ?? false,
        spacing: supports.spacing ?? false,
        ...supports,
    };
}
