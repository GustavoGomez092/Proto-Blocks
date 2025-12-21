/**
 * Link Field Component for Proto-Blocks
 *
 * Renders an editable link field with popover for URL editing
 */

import React from 'react';
import { createElement, useState, useRef } from '@wordpress/element';
import {
    Popover,
    Button,
    TextControl,
    ToggleControl,
} from '@wordpress/components';
import { link as linkIcon } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { FieldProps, LinkValue } from '../types';
import { RichText } from '@wordpress/block-editor';

interface LinkFieldProps extends FieldProps<LinkValue> {
    className?: string;
    tagName?: string;
}

export function LinkField({
    name,
    value,
    onChange,
    config,
    className = '',
    tagName = 'a',
    isSelected,
}: LinkFieldProps): JSX.Element {
    const [isEditing, setIsEditing] = useState(false);
    const wrapperRef = useRef<HTMLDivElement>(null);

    const linkValue: LinkValue = value || {
        url: '',
        text: '',
        target: '',
        rel: '',
        title: '',
    };

    /**
     * Update a single link property
     */
    const updateLink = (key: keyof LinkValue, newValue: string) => {
        onChange({
            ...linkValue,
            [key]: newValue,
        });
    };

    /**
     * Handle text change
     */
    const handleTextChange = (text: string) => {
        onChange({
            ...linkValue,
            text,
        });
    };

    /**
     * Toggle new tab setting
     */
    const handleTargetToggle = (checked: boolean) => {
        onChange({
            ...linkValue,
            target: checked ? '_blank' : '',
            rel: checked ? 'noopener noreferrer' : '',
        });
    };

    return (
        <div
            ref={wrapperRef}
            className="proto-blocks-link-field-wrapper"
            style={{ display: 'inline-block', position: 'relative' }}
        >
            <RichText
                tagName={tagName}
                value={linkValue.text || ''}
                onChange={handleTextChange}
                placeholder={__('Enter link text...', 'proto-blocks')}
                allowedFormats={[]}
                className={`proto-blocks-link-field ${className}`}
            />

            {isSelected && (
                <Button
                    icon={linkIcon}
                    label={__('Link settings', 'proto-blocks')}
                    onClick={() => setIsEditing(!isEditing)}
                    className="proto-blocks-link-field__settings-btn"
                    isPressed={isEditing}
                    style={{
                        position: 'absolute',
                        top: '-30px',
                        right: '0',
                        background: isEditing ? '#007cba' : '#fff',
                        color: isEditing ? '#fff' : '#1e1e1e',
                        borderRadius: '2px',
                        boxShadow: '0 1px 3px rgba(0,0,0,0.1)',
                    }}
                />
            )}

            {isEditing && isSelected && (
                <Popover
                    anchor={wrapperRef.current}
                    onClose={() => setIsEditing(false)}
                    placement="bottom-start"
                    className="proto-blocks-link-popover"
                >
                    <div className="proto-blocks-link-popover__content">
                        <TextControl
                            label={__('URL', 'proto-blocks')}
                            value={linkValue.url}
                            onChange={(url) => updateLink('url', url)}
                            placeholder="https://"
                        />

                        <TextControl
                            label={__('Link Text', 'proto-blocks')}
                            value={linkValue.text || ''}
                            onChange={(text) => updateLink('text', text)}
                            placeholder={__('Button text', 'proto-blocks')}
                        />

                        <ToggleControl
                            label={__('Open in new tab', 'proto-blocks')}
                            checked={linkValue.target === '_blank'}
                            onChange={handleTargetToggle}
                        />

                        <div className="proto-blocks-link-popover__actions">
                            <Button
                                variant="primary"
                                onClick={() => setIsEditing(false)}
                            >
                                {__('Done', 'proto-blocks')}
                            </Button>
                            {linkValue.url && (
                                <Button
                                    variant="link"
                                    isDestructive
                                    onClick={() => {
                                        onChange({
                                            url: '',
                                            text: linkValue.text,
                                            target: '',
                                            rel: '',
                                            title: '',
                                        });
                                        setIsEditing(false);
                                    }}
                                >
                                    {__('Remove link', 'proto-blocks')}
                                </Button>
                            )}
                        </div>
                    </div>
                </Popover>
            )}
        </div>
    );
}
