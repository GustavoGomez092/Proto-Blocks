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
    Notice,
    TextControl,
    ToggleControl,
} from '@wordpress/components';
import { link as linkIcon } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { FieldProps, LinkValue } from '../types';
import { RichText } from '@wordpress/block-editor';
import { isSafeUrl, sanitizeRichTextValue } from '../utils/sanitize-rich-text';

interface LinkFieldProps extends FieldProps<LinkValue> {
    className?: string;
    tagName?: string;
    children?: React.ReactNode;
}

export function LinkField({
    name,
    value,
    onChange,
    config,
    className = '',
    tagName = 'a',
    isSelected,
    children,
}: LinkFieldProps): JSX.Element {
    const [isEditing, setIsEditing] = useState(false);
    const [urlNotice, setUrlNotice] = useState<string | null>(null);
    const wrapperRef = useRef<HTMLDivElement>(null);

    const linkValue: LinkValue = value || {
        url: '',
        text: '',
        target: '',
        rel: '',
        title: '',
    };

    // The stored value is contributor-controlled comment JSON. Never render or
    // re-store a URL with a scheme we do not allow, and never hand RichText
    // the raw text. Every write below spreads `safeLink`, so an unsafe stored
    // URL is dropped the next time the post is saved.
    const storedUrlUnsafe = Boolean(linkValue.url) && !isSafeUrl(linkValue.url);
    const safeLink: LinkValue = {
        ...linkValue,
        url: storedUrlUnsafe ? '' : linkValue.url,
        text: sanitizeRichTextValue(linkValue.text || ''),
    };
    const urlWarning =
        urlNotice ||
        (storedUrlUnsafe
            ? __('The saved link used an unsupported URL scheme and was removed.', 'proto-blocks')
            : null);

    /**
     * Update a single link property
     */
    const updateLink = (key: keyof LinkValue, newValue: string) => {
        onChange({
            ...safeLink,
            [key]: newValue,
        });
    };

    /**
     * Handle URL change: only http, https, mailto, tel, relative and #anchor
     * links are stored; anything else is cleared with a notice.
     */
    const handleUrlChange = (url: string) => {
        if (url && !isSafeUrl(url)) {
            setUrlNotice(
                __('Only http, https, mailto, tel, relative and #anchor links are allowed.', 'proto-blocks')
            );
            updateLink('url', '');
            return;
        }
        setUrlNotice(null);
        updateLink('url', url);
    };

    /**
     * Handle text change
     */
    const handleTextChange = (text: string) => {
        onChange({
            ...safeLink,
            text: sanitizeRichTextValue(text),
        });
    };

    /**
     * Toggle new tab setting
     */
    const handleTargetToggle = (checked: boolean) => {
        onChange({
            ...safeLink,
            target: checked ? '_blank' : '',
            rel: checked ? 'noopener noreferrer' : '',
        });
    };

    // Determine if we should render as a link wrapper with children inside
    const hasChildren = children && (Array.isArray(children) ? children.length > 0 : true);

    // If we have children (like icons), wrap everything in the proper tag structure
    if (hasChildren) {
        return (
            <div
                ref={wrapperRef}
                className="proto-blocks-link-field-wrapper"
                style={{ display: 'inline-block', position: 'relative' }}
            >
                {createElement(
                    tagName,
                    { className: `proto-blocks-link-field ${className}` },
                    <RichText
                        tagName="span"
                        value={safeLink.text || ''}
                        onChange={handleTextChange}
                        placeholder={__('Enter link text...', 'proto-blocks')}
                        allowedFormats={[]}
                        className="proto-blocks-link-field__text"
                    />,
                    children
                )}

                {isSelected && (
                    <Button
                        icon={linkIcon}
                        label={__('Link settings', 'proto-blocks')}
                        onClick={() => setIsEditing(!isEditing)}
                        className="proto-blocks-link-field__settings-btn"
                        isPressed={isEditing}
                        style={{
                            position: 'absolute',
                            top: '4px',
                            right: '4px',
                            zIndex: 20,
                            background: isEditing ? '#007cba' : '#fff',
                            color: isEditing ? '#fff' : '#1e1e1e',
                            borderRadius: '2px',
                            boxShadow: '0 1px 3px rgba(0,0,0,0.2)',
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
                            {urlWarning && (
                                <Notice status="warning" isDismissible={false}>
                                    {urlWarning}
                                </Notice>
                            )}
                            <TextControl
                                label={__('URL', 'proto-blocks')}
                                value={safeLink.url}
                                onChange={handleUrlChange}
                                placeholder="https://"
                            />

                            <TextControl
                                label={__('Link Text', 'proto-blocks')}
                                value={safeLink.text || ''}
                                onChange={(text) => updateLink('text', text)}
                                placeholder={__('Button text', 'proto-blocks')}
                            />

                            <ToggleControl
                                label={__('Open in new tab', 'proto-blocks')}
                                checked={safeLink.target === '_blank'}
                                onChange={handleTargetToggle}
                            />

                            <div className="proto-blocks-link-popover__actions">
                                <Button
                                    variant="primary"
                                    onClick={() => setIsEditing(false)}
                                >
                                    {__('Done', 'proto-blocks')}
                                </Button>
                                {safeLink.url && (
                                    <Button
                                        variant="link"
                                        isDestructive
                                        onClick={() => {
                                            onChange({
                                                url: '',
                                                text: safeLink.text,
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

    // Default rendering without children
    return (
        <div
            ref={wrapperRef}
            className="proto-blocks-link-field-wrapper"
            style={{ display: 'inline-block', position: 'relative' }}
        >
            <RichText
                tagName={tagName}
                value={safeLink.text || ''}
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
                        {urlWarning && (
                            <Notice status="warning" isDismissible={false}>
                                {urlWarning}
                            </Notice>
                        )}
                        <TextControl
                            label={__('URL', 'proto-blocks')}
                            value={safeLink.url}
                            onChange={handleUrlChange}
                            placeholder="https://"
                        />

                        <TextControl
                            label={__('Link Text', 'proto-blocks')}
                            value={safeLink.text || ''}
                            onChange={(text) => updateLink('text', text)}
                            placeholder={__('Button text', 'proto-blocks')}
                        />

                        <ToggleControl
                            label={__('Open in new tab', 'proto-blocks')}
                            checked={safeLink.target === '_blank'}
                            onChange={handleTargetToggle}
                        />

                        <div className="proto-blocks-link-popover__actions">
                            <Button
                                variant="primary"
                                onClick={() => setIsEditing(false)}
                            >
                                {__('Done', 'proto-blocks')}
                            </Button>
                            {safeLink.url && (
                                <Button
                                    variant="link"
                                    isDestructive
                                    onClick={() => {
                                        onChange({
                                            url: '',
                                            text: safeLink.text,
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
