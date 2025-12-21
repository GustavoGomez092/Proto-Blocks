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

// Log immediately to confirm script is loading
console.log('Proto-Blocks: Editor script loaded');

// Get data from PHP
const protoBlocksData = window.protoBlocksData;
const debug = protoBlocksData?.debug || false;

// Log data availability
console.log('Proto-Blocks: protoBlocksData exists:', !!protoBlocksData);
console.log('Proto-Blocks: protoBlocksData value:', protoBlocksData);

const blocks: BlockData[] = protoBlocksData?.blocks || [];

console.log('Proto-Blocks: Found', blocks.length, 'blocks to register');

if (debug) {
    console.log('Proto-Blocks: Debug mode enabled');
    console.log('Proto-Blocks: Block data:', blocks);
}

// Register each block
if (blocks.length === 0) {
    console.warn('Proto-Blocks: No blocks to register. Check that protoBlocksData.blocks is properly populated.');
}

blocks.forEach((block) => {
    try {
        console.log(`Proto-Blocks: Registering block "${block.name}"...`);
        console.log('Proto-Blocks: Block config:', {
            name: block.name,
            title: block.title,
            category: block.category,
            attributes: block.attributes,
        });

        const blockConfig: BlockConfiguration<BlockAttributes> = {
            apiVersion: 3,
            title: block.title || block.name,
            description: block.description || '',
            category: block.category || 'proto-blocks',
            icon: getBlockIcon(block.icon),
            keywords: block.keywords || [],
            supports: normalizeSupports(block.supports),
            attributes: block.attributes,
            edit: createEditComponent(block),
            // Use createElement instead of JSX to avoid runtime issues
            save: () => createElement(InnerBlocks.Content),
        };

        registerBlockType(`proto-blocks/${block.name}`, blockConfig);

        console.log(`Proto-Blocks: Successfully registered "proto-blocks/${block.name}"`);
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

// Log completion
if (debug) {
    console.log('Proto-Blocks: Editor initialization complete');
}
