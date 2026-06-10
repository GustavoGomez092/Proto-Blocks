/**
 * Video Field Component for Proto-Blocks
 *
 * Renders an editable video field with media library integration, filtered
 * to video attachments. Mirrors ImageField (Popover-based floating controls)
 * but previews the selected file with a muted <video> element.
 */

import React, { useCallback, useState } from 'react';
import { createElement } from '@wordpress/element';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button, Popover } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { plus, edit, trash } from '@wordpress/icons';
import { FieldProps, VideoValue } from '../types';

interface VideoFieldProps extends FieldProps<VideoValue> {
    className?: string;
}

interface MediaItem {
    id: number;
    url: string;
    mime?: string;
    subtype?: string;
}

export function VideoField({
    value,
    onChange,
    config,
    className = '',
    isSelected,
}: VideoFieldProps): JSX.Element {
    const videoValue = value || { url: '', id: null, mime: '' };
    const hasVideo = Boolean(videoValue.url);

    const [container, setContainer] = useState<HTMLDivElement | null>(null);
    const setContainerRef = useCallback((node: HTMLDivElement | null) => {
        setContainer(node);
    }, []);

    // Allow narrowing the picker via config; default to all video types.
    const allowedTypes = (config as { allowedTypes?: string[] }).allowedTypes || [
        'video',
    ];

    const onSelectMedia = (media: MediaItem) => {
        onChange({
            id: media.id,
            url: media.url,
            mime: media.mime || (media.subtype ? `video/${media.subtype}` : ''),
        });
    };

    const onRemoveVideo = () => {
        onChange({ id: null, url: '', mime: '' });
    };

    return (
        <MediaUploadCheck>
            <MediaUpload
                onSelect={onSelectMedia}
                allowedTypes={allowedTypes}
                value={videoValue.id || undefined}
                render={({ open }: { open: () => void }) => (
                    <div
                        ref={setContainerRef}
                        className={`proto-blocks-video-field ${className}`}
                    >
                        {hasVideo ? (
                            <>
                                {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
                                <video
                                    src={videoValue.url}
                                    muted
                                    playsInline
                                    preload="metadata"
                                    onClick={open}
                                    role="button"
                                    tabIndex={0}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            open();
                                        }
                                    }}
                                />
                                {isSelected && container && (
                                    <Popover
                                        anchor={container}
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
                                                label={__('Replace video', 'proto-blocks')}
                                                size="small"
                                            />
                                            <Button
                                                onClick={onRemoveVideo}
                                                icon={trash}
                                                label={__('Remove video', 'proto-blocks')}
                                                isDestructive
                                                size="small"
                                            />
                                        </div>
                                    </Popover>
                                )}
                            </>
                        ) : (
                            <Button
                                className="proto-blocks-image-field__add-button"
                                onClick={open}
                                icon={plus}
                                label={__('Add video', 'proto-blocks')}
                            />
                        )}
                    </div>
                )}
            />
        </MediaUploadCheck>
    );
}
