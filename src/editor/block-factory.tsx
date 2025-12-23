/**
 * Block Factory - Creates edit components for Proto-Blocks
 */

import React from 'react';
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps as WPBlockEditProps } from '@wordpress/blocks';
import { BlockData, BlockAttributes, BlockEditProps } from './types';
import { processHtmlToReact } from './utils/html-to-react';
import { renderControl } from './controls/render';
import { useDebouncedCallback } from './utils/debounce';

// Get data from PHP
const protoBlocksData = window.protoBlocksData;
const ajaxUrl = protoBlocksData?.ajaxUrl || '/wp-admin/admin-ajax.php';
const previewNonce = protoBlocksData?.previewNonce || '';
const debug = protoBlocksData?.debug || false;

/**
 * Create an edit component for a block
 */
export function createEditComponent(block: BlockData) {
    return function EditComponent(props: WPBlockEditProps<BlockAttributes>) {
        const { attributes, setAttributes, isSelected } = props;
        const controls = block.controls || {};

        // Check if this is a preview render (from block inserter)
        const isInserterPreview = attributes.__isPreview === true;
        const previewImageUrl = attributes.__previewImage as string | undefined;

        // Block props with click handler
        // Include proto-blocks-scope for Tailwind CSS support in editor
        const blockProps = useBlockProps({
            className: `proto-block proto-block-${block.name} proto-blocks-scope`,
        });

        // If this is an inserter preview with a custom preview image, render it
        if (isInserterPreview && previewImageUrl) {
            return (
                <div {...blockProps}>
                    <img
                        src={previewImageUrl}
                        alt={block.title + ' preview'}
                        style={{
                            width: '100%',
                            height: 'auto',
                            display: 'block',
                        }}
                    />
                </div>
            );
        }

        // State for preview
        const [previewHtml, setPreviewHtml] = useState<string | null>(null);
        const [isLoading, setIsLoading] = useState(true);
        const [error, setError] = useState<string | null>(null);

        // Get ALL attribute values that affect template rendering
        // Both controls and fields should trigger a preview refresh
        const attributeValues = useMemo(() => {
            const values: Record<string, unknown> = {};
            // Include all controls
            Object.entries(controls).forEach(([key]) => {
                values[key] = attributes[key];
            });
            // Include all fields (like repeaters)
            const fields = block.fields || {};
            Object.entries(fields).forEach(([key]) => {
                values[key] = attributes[key];
            });
            return values;
        }, [attributes, controls, block.fields]);

        // Debounced preview fetch
        const fetchPreview = useDebouncedCallback(
            async (attrs: BlockAttributes) => {
                try {
                    const response = await fetch(ajaxUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'proto_blocks_preview',
                            template: block.name,
                            attributes: JSON.stringify(attrs),
                            nonce: previewNonce,
                        }),
                    });

                    const data = await response.json();

                    if (data.success && data.data?.html) {
                        setPreviewHtml(data.data.html);
                        setError(null);
                    } else {
                        // Handle different error response formats
                        let errorMsg = 'Failed to load preview';
                        if (typeof data.data === 'string') {
                            errorMsg = data.data;
                        } else if (data.data?.message) {
                            errorMsg = data.data.message;
                        } else if (data.message) {
                            errorMsg = data.message;
                        }
                        throw new Error(errorMsg);
                    }
                } catch (err) {
                    const errorMessage = err instanceof Error ? err.message : String(err);
                    setError(errorMessage);
                    if (debug) {
                        console.error('Proto-Blocks: Preview error', err);
                    }
                } finally {
                    setIsLoading(false);
                }
            },
            300
        );

        // Fetch preview on mount and when any attribute value changes
        useEffect(() => {
            setIsLoading(!previewHtml);
            fetchPreview(attributes);
        }, [JSON.stringify(attributeValues)]);

        // Handle attribute change
        const handleAttributeChange = useCallback(
            (name: string, value: unknown) => {
                setAttributes({ [name]: value });
            },
            [setAttributes]
        );

        // Process HTML to React
        const content = useMemo(() => {
            if (!previewHtml) return null;

            return processHtmlToReact(previewHtml, {
                attributes,
                setAttributes: handleAttributeChange,
                fields: block.fields,
                isSelected,
            });
        }, [previewHtml, attributes, handleAttributeChange, isSelected]);

        return (
            <>
                {/* Inspector Controls */}
                <InspectorControls>
                    {Object.keys(controls).length > 0 && (
                        <PanelBody title={__('Block Settings', 'proto-blocks')}>
                            {Object.entries(controls).map(([name, config]) => {
                                // Check visibility conditions
                                if (config.conditions?.visible) {
                                    const isVisible = Object.entries(config.conditions.visible).every(
                                        ([key, expectedValue]) => {
                                            const actualValue = attributes[key];
                                            if (Array.isArray(expectedValue)) {
                                                return expectedValue.includes(actualValue);
                                            }
                                            return actualValue === expectedValue;
                                        }
                                    );
                                    if (!isVisible) return null;
                                }

                                return (
                                    <div key={name} className="proto-blocks-control">
                                        {renderControl(name, config, attributes, setAttributes)}
                                    </div>
                                );
                            })}
                        </PanelBody>
                    )}
                </InspectorControls>

                {/* Block Content */}
                <div {...blockProps}>
                    {isLoading ? (
                        <div className="proto-blocks-loading">
                            <Spinner />
                            <span>{__('Loading preview...', 'proto-blocks')}</span>
                        </div>
                    ) : error ? (
                        <div className="proto-blocks-error">
                            <strong>{__('Error:', 'proto-blocks')}</strong> {error}
                        </div>
                    ) : (
                        content
                    )}
                </div>
            </>
        );
    };
}
