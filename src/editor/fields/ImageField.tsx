/**
 * Image Field Component for Proto-Blocks
 *
 * Renders an editable image field with media library integration.
 * Uses WordPress Popover (powered by Floating UI) to render controls
 * in a portal, preventing any parent overflow clipping issues.
 */

import React, { useRef } from 'react';
import { createElement } from '@wordpress/element';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button, Popover } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { plus, edit, trash } from '@wordpress/icons';
import { FieldProps, ImageValue } from '../types';

interface ImageFieldProps extends FieldProps<ImageValue> {
    className?: string;
}

interface MediaItem {
    id: number;
    url: string;
    alt?: string;
    caption?: string;
    sizes?: Record<string, { url: string }>;
}

export function ImageField({
    name,
    value,
    onChange,
    config,
    className = '',
    isSelected,
}: ImageFieldProps): JSX.Element {
    const imageValue = value || { url: '', id: null, alt: '' };
    const hasImage = Boolean(imageValue.url);
    const containerRef = useRef<HTMLDivElement>(null);

    // Get preferred size from config or default to 'large'
    const preferredSize = (config as { size?: string }).size || 'large';

    /**
     * Handle media selection
     */
    const onSelectMedia = (media: MediaItem) => {
        // Get the URL for the preferred size, or fall back to full URL
        let imageUrl = media.url;
        if (media.sizes && media.sizes[preferredSize]) {
            imageUrl = media.sizes[preferredSize].url;
        }

        onChange({
            id: media.id,
            url: imageUrl,
            alt: media.alt || '',
            caption: media.caption || '',
            size: preferredSize,
        });
    };

    /**
     * Handle image removal
     */
    const onRemoveImage = () => {
        onChange({
            id: null,
            url: '',
            alt: '',
            caption: '',
            size: preferredSize,
        });
    };

    return (
        <MediaUploadCheck>
            <MediaUpload
                onSelect={onSelectMedia}
                allowedTypes={['image']}
                value={imageValue.id || undefined}
                render={({ open }: { open: () => void }) => (
                    <div
                        ref={containerRef}
                        className={`proto-blocks-image-field ${className}`}
                    >
                        {hasImage ? (
                            <>
                                <img
                                    src={imageValue.url}
                                    alt={imageValue.alt || ''}
                                    onClick={open}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            open();
                                        }
                                    }}
                                    role="button"
                                    tabIndex={0}
                                />
                                {/* Floating controls - rendered in portal via Popover */}
                                {isSelected && containerRef.current && (
                                    <Popover
                                        anchor={containerRef.current}
                                        placement="top-end"
                                        offset={8}
                                        className="proto-blocks-image-field__popover"
                                        focusOnMount={false}
                                        animate={false}
                                    >
                                        <div className="proto-blocks-image-field__controls">
                                            <Button
                                                onClick={open}
                                                icon={edit}
                                                label={__('Replace image', 'proto-blocks')}
                                                size="small"
                                            />
                                            <Button
                                                onClick={onRemoveImage}
                                                icon={trash}
                                                label={__('Remove image', 'proto-blocks')}
                                                isDestructive
                                                size="small"
                                            />
                                        </div>
                                    </Popover>
                                )}
                            </>
                        ) : (
                            /* Empty state - centered button inside container */
                            <Button
                                className="proto-blocks-image-field__add-button"
                                onClick={open}
                                icon={plus}
                                label={__('Add image', 'proto-blocks')}
                            />
                        )}
                    </div>
                )}
            />
        </MediaUploadCheck>
    );
}
