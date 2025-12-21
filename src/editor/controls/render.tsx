/**
 * Control Renderer for Proto-Blocks
 *
 * Renders WordPress inspector controls based on control configuration
 */

import React from 'react';
import { createElement } from '@wordpress/element';
import {
    TextControl,
    TextareaControl,
    SelectControl,
    ToggleControl,
    RangeControl,
    ColorPicker,
    ColorPalette,
    __experimentalNumberControl as NumberControl,
    PanelRow,
} from '@wordpress/components';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { ControlConfig, BlockAttributes } from '../types';
import { __ } from '@wordpress/i18n';

interface MediaItem {
    id: number;
    url: string;
    alt?: string;
}

/**
 * Render a control based on its configuration
 */
export function renderControl(
    name: string,
    config: ControlConfig,
    attributes: BlockAttributes,
    setAttributes: (attrs: Partial<BlockAttributes>) => void
): JSX.Element | null {
    const value = attributes[name];
    const onChange = (newValue: unknown) => setAttributes({ [name]: newValue });

    switch (config.type) {
        case 'text':
            return (
                <TextControl
                    label={config.label}
                    value={(value as string) || ''}
                    onChange={onChange}
                />
            );

        case 'textarea':
            return (
                <TextareaControl
                    label={config.label}
                    value={(value as string) || ''}
                    onChange={onChange}
                />
            );

        case 'select':
            return (
                <SelectControl
                    label={config.label}
                    value={(value as string) || ''}
                    options={
                        config.options?.map((opt) => ({
                            value: opt.key,
                            label: opt.label,
                        })) || []
                    }
                    onChange={onChange}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                />
            );

        case 'toggle':
            return (
                <ToggleControl
                    label={config.label}
                    checked={Boolean(value)}
                    onChange={onChange}
                    __nextHasNoMarginBottom
                />
            );

        case 'checkbox':
            return (
                <ToggleControl
                    label={config.label}
                    checked={Boolean(value)}
                    onChange={onChange}
                    __nextHasNoMarginBottom
                />
            );

        case 'range':
            return (
                <RangeControl
                    label={config.label}
                    value={(value as number) || config.min || 0}
                    onChange={onChange}
                    min={config.min || 0}
                    max={config.max || 100}
                    step={config.step || 1}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                />
            );

        case 'number':
            return (
                <NumberControl
                    label={config.label}
                    value={(value as number) || 0}
                    onChange={(val: string | undefined) =>
                        onChange(val ? parseFloat(val) : 0)
                    }
                    min={config.min}
                    max={config.max}
                    step={config.step}
                    __next40pxDefaultSize
                />
            );

        case 'color':
            return (
                <PanelRow>
                    <div className="proto-blocks-color-control">
                        <label className="components-base-control__label">
                            {config.label}
                        </label>
                        <ColorPicker
                            color={(value as string) || ''}
                            onChange={onChange}
                            enableAlpha
                        />
                    </div>
                </PanelRow>
            );

        case 'color-palette':
            return (
                <PanelRow>
                    <div className="proto-blocks-color-palette-control">
                        <label className="components-base-control__label">
                            {config.label}
                        </label>
                        <ColorPalette
                            value={(value as string) || ''}
                            onChange={(color: string | undefined) => onChange(color || '')}
                        />
                    </div>
                </PanelRow>
            );

        case 'image':
            return renderImageControl(name, config, value, setAttributes);

        case 'radio':
            return (
                <div className="proto-blocks-radio-control">
                    <label className="components-base-control__label">
                        {config.label}
                    </label>
                    <div className="proto-blocks-radio-options">
                        {config.options?.map((opt) => (
                            <label key={opt.key} className="proto-blocks-radio-option">
                                <input
                                    type="radio"
                                    name={name}
                                    value={opt.key}
                                    checked={value === opt.key}
                                    onChange={() => onChange(opt.key)}
                                />
                                <span>{opt.label}</span>
                            </label>
                        ))}
                    </div>
                </div>
            );

        default:
            console.warn(`Proto-Blocks: Unknown control type "${config.type}"`);
            return null;
    }
}

/**
 * Render an image control with media library
 */
function renderImageControl(
    name: string,
    config: ControlConfig,
    value: unknown,
    setAttributes: (attrs: Partial<BlockAttributes>) => void
): JSX.Element {
    const imageValue = value as { id?: number; url?: string } | undefined;

    return (
        <div className="proto-blocks-image-control">
            <label className="components-base-control__label">{config.label}</label>
            <MediaUploadCheck>
                <MediaUpload
                    onSelect={(media: MediaItem) => {
                        setAttributes({
                            [name]: {
                                id: media.id,
                                url: media.url,
                                alt: media.alt || '',
                            },
                        });
                    }}
                    allowedTypes={['image']}
                    value={imageValue?.id}
                    render={({ open }: { open: () => void }) => (
                        <div className="proto-blocks-image-control__preview">
                            {imageValue?.url ? (
                                <>
                                    <img
                                        src={imageValue.url}
                                        alt=""
                                        className="proto-blocks-image-control__image"
                                    />
                                    <div className="proto-blocks-image-control__buttons">
                                        <button
                                            type="button"
                                            className="components-button is-secondary is-small"
                                            onClick={open}
                                        >
                                            {__('Replace', 'proto-blocks')}
                                        </button>
                                        <button
                                            type="button"
                                            className="components-button is-link is-destructive is-small"
                                            onClick={() =>
                                                setAttributes({
                                                    [name]: { id: null, url: '', alt: '' },
                                                })
                                            }
                                        >
                                            {__('Remove', 'proto-blocks')}
                                        </button>
                                    </div>
                                </>
                            ) : (
                                <button
                                    type="button"
                                    className="components-button is-secondary"
                                    onClick={open}
                                >
                                    {__('Select Image', 'proto-blocks')}
                                </button>
                            )}
                        </div>
                    )}
                />
            </MediaUploadCheck>
        </div>
    );
}
