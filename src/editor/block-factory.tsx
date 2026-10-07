/**
 * Block Factory - Creates edit components for Proto-Blocks
 */

import React from 'react';
import { useState, useEffect, useMemo, useCallback, useRef } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Button, PanelBody, Spinner } from '@wordpress/components';
import { chevronLeft } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import type { BlockEditProps as WPBlockEditProps } from '@wordpress/blocks';
import { BlockData, BlockAttributes, BlockEditProps } from './types';
import { processHtmlToReact } from './utils/html-to-react';
import { renderControl } from './controls/render';
import { useDebouncedCallback } from './utils/debounce';
import {
    isInsideRepeaterItem,
    RepeaterItemProvider,
    useRepeaterItem,
} from './repeater-item-context';

// Get data from PHP
const protoBlocksData = window.protoBlocksData;
const ajaxUrl = protoBlocksData?.ajaxUrl || '/wp-admin/admin-ajax.php';
const previewNonce = protoBlocksData?.previewNonce || '';
const debug = protoBlocksData?.debug || false;

/**
 * The block's own wrapper, inside the provider so it can clear the active row.
 *
 * Clicking the block but not a row means the author is addressing the block, so
 * the sidebar goes back to the block's settings. Capture runs outer-first, so a
 * click that IS on a row clears here and is re-set a moment later by the row's
 * own handler -- the net effect is the row that was clicked.
 */
function BlockCanvas({
    blockProps,
    children,
}: {
    blockProps: Record<string, unknown>;
    children: React.ReactNode;
}): JSX.Element {
    const { setActive } = useRepeaterItem();
    const ref = useRef<HTMLDivElement | null>(null);

    /* useBlockProps supplies its own ref, which the editor needs for selection and
       typing. Keep it: take it out of the spread and hand the node to both. */
    const { ref: blockRef, ...rest } = blockProps as Record<string, unknown> & {
        ref?: React.Ref<HTMLDivElement>;
    };

    const setRefs = useCallback(
        (node: HTMLDivElement | null) => {
            ref.current = node;

            if (typeof blockRef === 'function') {
                blockRef(node);
            } else if (blockRef && typeof blockRef === 'object') {
                (blockRef as React.MutableRefObject<HTMLDivElement | null>).current = node;
            }
        },
        [blockRef]
    );

    /* A native capture listener rather than React's onPointerDownCapture: the
       editor's own block handlers sit between the React root and this element and
       stop the synthetic event, so the React version never runs. Listening on the
       element itself puts us inside anything that intercepts above it. */
    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return undefined;
        }

        const onPointerDown = (event: Event) => {
            /* A click on the block but not on a row means the author is addressing
               the block, so the sidebar goes back to its settings. A click that IS
               on a row is left alone: the row reports itself. */
            if (!isInsideRepeaterItem(event.target)) {
                setActive(null);
            }
        };

        el.addEventListener('pointerdown', onPointerDown, true);

        return () => el.removeEventListener('pointerdown', onPointerDown, true);
    }, [setActive]);

    return (
        <div {...rest} ref={setRefs}>
            {children}
        </div>
    );
}

/**
 * The settings of whichever repeater row is focused on the canvas.
 *
 * Rendered above the block's own settings and only while a row is focused, so a
 * block with no repeater, or one whose repeater declares no itemControls, sees
 * the sidebar it has always seen.
 *
 * The row's values live in the same attribute the canvas edits -- the repeater's
 * array -- so typing here and typing on the canvas write to one place and cannot
 * disagree.
 */
function RepeaterItemPanel({
    block,
    attributes,
    setAttributes,
}: {
    block: BlockData;
    attributes: BlockAttributes;
    setAttributes: (attrs: Partial<BlockAttributes>) => void;
}): JSX.Element | null {
    const { active, setActive } = useRepeaterItem();

    if (!active) {
        return null;
    }

    const fieldConfig = block.fields?.[active.field] as
        | { itemControls?: Record<string, unknown> }
        | undefined;
    const itemControls = fieldConfig?.itemControls;

    if (!itemControls || Object.keys(itemControls).length === 0) {
        return null;
    }

    const rows = Array.isArray(attributes[active.field])
        ? ([...(attributes[active.field] as Record<string, unknown>[])])
        : [];
    const row = rows[active.index];

    if (!row) {
        return null;
    }

    /* Titled with the row itself, because a panel whose contents change as focus
       moves has to say which thing it is describing. */
    const title = active.label
        ? active.label
        : sprintf(
              /* translators: %d is the row's position in the list. */
              __('Item %d', 'proto-blocks'),
              active.index + 1
          );

    return (
        <PanelBody title={title} initialOpen className="proto-blocks-item-panel">
            {/* The way back. Focusing a row is easy to do by accident and the block's
                own settings are the more common destination, so leaving must not
                depend on finding somewhere neutral to click. */}
            <Button
                className="proto-blocks-item-panel__back"
                icon={chevronLeft}
                variant="tertiary"
                onClick={() => setActive(null)}
            >
                {__('Back to block settings', 'proto-blocks')}
            </Button>
            {Object.entries(itemControls).map(([name, config]) => (
                <div key={name} className="proto-blocks-control">
                    {renderControl(
                        name,
                        config as Parameters<typeof renderControl>[1],
                        { [name]: row[name] } as BlockAttributes,
                        (attrs) => {
                            const next = rows.slice();
                            next[active.index] = {
                                ...row,
                                [name]: (attrs as Record<string, unknown>)[name],
                            };
                            setAttributes({ [active.field]: next } as Partial<BlockAttributes>);
                        }
                    )}
                </div>
            ))}
        </PanelBody>
    );
}

/**
 * Create an edit component for a block
 */
export function createEditComponent(block: BlockData) {
    /* The provider sits above this component, so the part that needs to clear the
       active row is split out to sit inside it. */
    return function EditComponent(props: WPBlockEditProps<BlockAttributes>) {
        const { attributes, setAttributes, isSelected } = props;
        const controls = block.controls || {};

        // Check if this is a preview render (from block inserter)
        const isInserterPreview = attributes.__isPreview === true;
        const previewImageUrl = attributes.__previewImage as string | undefined;

        // Prevent <a> elements rendered inside the block preview from
        // navigating the editor away. Templates that mark only the
        // inner text node as a proto-field (e.g. a CTA pattern with
        // `<a href="..."><span data-proto-field="cta">…</span></a>`)
        // leave the outer anchor's href intact through the server-
        // rendered preview HTML. Without this guard, clicking the
        // styled button area triggers an actual link navigation and
        // tears down the editor's React tree. preventDefault stops the
        // navigation only; the click still bubbles so RichText/focus
        // handlers and Gutenberg's block selection continue to work.
        const handleBlockClick = useCallback((e: React.MouseEvent) => {
            const target = e.target as HTMLElement | null;
            if (target && target.closest('a')) {
                e.preventDefault();
            }
        }, []);

        // Block props with click handler
        // Include proto-blocks-scope for Tailwind CSS support in editor
        const blockProps = useBlockProps({
            className: `proto-block proto-block-${block.name} proto-blocks-scope`,
            onClick: handleBlockClick,
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
            <RepeaterItemProvider>
                {/* Inspector Controls */}
                <InspectorControls>
                    <RepeaterItemPanel block={block} attributes={attributes} setAttributes={setAttributes} />

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
                <BlockCanvas blockProps={blockProps}>
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
                </BlockCanvas>
            </RepeaterItemProvider>
        );
    };
}
