/**
 * Admin App Component for Proto-Blocks
 *
 * Main admin interface for managing Proto-Blocks settings
 */

import React from 'react';
import { createElement, useState, useEffect } from '@wordpress/element';
import {
    Button,
    Card,
    CardBody,
    CardHeader,
    Notice,
    Spinner,
    ToggleControl,
    __experimentalHeading as Heading,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface BlockInfo {
    name: string;
    title: string;
    description: string;
    category: string;
    path: string;
}

interface AdminState {
    blocks: BlockInfo[];
    cacheEnabled: boolean;
    debugEnabled: boolean;
    isLoading: boolean;
    isSaving: boolean;
    notice: { type: 'success' | 'error'; message: string } | null;
}

export function AdminApp(): JSX.Element {
    const adminData = window.protoBlocksAdmin || {
        blocks: [],
        cacheEnabled: true,
        debugEnabled: false,
        version: '1.0.0',
        nonce: '',
        apiUrl: '',
    };

    const [state, setState] = useState<AdminState>({
        blocks: adminData.blocks,
        cacheEnabled: adminData.cacheEnabled,
        debugEnabled: adminData.debugEnabled,
        isLoading: false,
        isSaving: false,
        notice: null,
    });

    /**
     * Clear the template cache
     */
    const handleClearCache = async () => {
        setState((prev) => ({ ...prev, isLoading: true }));

        try {
            const response = await fetch(`${adminData.apiUrl}/cache`, {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': adminData.nonce,
                },
            });

            if (response.ok) {
                setState((prev) => ({
                    ...prev,
                    isLoading: false,
                    notice: {
                        type: 'success',
                        message: __('Cache cleared successfully!', 'proto-blocks'),
                    },
                }));
            } else {
                throw new Error('Failed to clear cache');
            }
        } catch (error) {
            setState((prev) => ({
                ...prev,
                isLoading: false,
                notice: {
                    type: 'error',
                    message: __('Failed to clear cache. Please try again.', 'proto-blocks'),
                },
            }));
        }
    };

    /**
     * Dismiss notice
     */
    const dismissNotice = () => {
        setState((prev) => ({ ...prev, notice: null }));
    };

    return (
        <div className="proto-blocks-admin">
            <div className="proto-blocks-admin__header">
                <Heading level={1}>
                    {__('Proto-Blocks', 'proto-blocks')}
                </Heading>
                <span className="proto-blocks-admin__version">
                    v{adminData.version}
                </span>
            </div>

            {state.notice && (
                <Notice
                    status={state.notice.type}
                    onRemove={dismissNotice}
                    isDismissible
                >
                    {state.notice.message}
                </Notice>
            )}

            <div className="proto-blocks-admin__content">
                {/* Registered Blocks Card */}
                <Card className="proto-blocks-admin__card">
                    <CardHeader>
                        <Heading level={2}>
                            {__('Registered Blocks', 'proto-blocks')}
                        </Heading>
                    </CardHeader>
                    <CardBody>
                        {state.blocks.length > 0 ? (
                            <table className="proto-blocks-admin__table widefat">
                                <thead>
                                    <tr>
                                        <th>{__('Name', 'proto-blocks')}</th>
                                        <th>{__('Title', 'proto-blocks')}</th>
                                        <th>{__('Category', 'proto-blocks')}</th>
                                        <th>{__('Path', 'proto-blocks')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {state.blocks.map((block) => (
                                        <tr key={block.name}>
                                            <td>
                                                <code>proto-blocks/{block.name}</code>
                                            </td>
                                            <td>{block.title}</td>
                                            <td>{block.category}</td>
                                            <td>
                                                <code>{block.path}</code>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <p className="proto-blocks-admin__empty">
                                {__(
                                    'No blocks registered yet. Create a block in your theme\'s proto-blocks/ directory.',
                                    'proto-blocks'
                                )}
                            </p>
                        )}
                    </CardBody>
                </Card>

                {/* Cache Management Card */}
                <Card className="proto-blocks-admin__card">
                    <CardHeader>
                        <Heading level={2}>
                            {__('Cache Management', 'proto-blocks')}
                        </Heading>
                    </CardHeader>
                    <CardBody>
                        <p>
                            {__(
                                'Proto-Blocks caches compiled templates for better performance. Clear the cache if you\'ve made changes to your block templates.',
                                'proto-blocks'
                            )}
                        </p>
                        <Button
                            variant="secondary"
                            onClick={handleClearCache}
                            disabled={state.isLoading}
                        >
                            {state.isLoading ? (
                                <>
                                    <Spinner />
                                    {__('Clearing...', 'proto-blocks')}
                                </>
                            ) : (
                                __('Clear Template Cache', 'proto-blocks')
                            )}
                        </Button>
                    </CardBody>
                </Card>

                {/* Debug Info Card */}
                <Card className="proto-blocks-admin__card">
                    <CardHeader>
                        <Heading level={2}>
                            {__('Debug Information', 'proto-blocks')}
                        </Heading>
                    </CardHeader>
                    <CardBody>
                        <dl className="proto-blocks-admin__debug-info">
                            <dt>{__('Plugin Version', 'proto-blocks')}</dt>
                            <dd>{adminData.version}</dd>

                            <dt>{__('Cache Status', 'proto-blocks')}</dt>
                            <dd>
                                {state.cacheEnabled
                                    ? __('Enabled', 'proto-blocks')
                                    : __('Disabled', 'proto-blocks')}
                            </dd>

                            <dt>{__('Debug Mode', 'proto-blocks')}</dt>
                            <dd>
                                {state.debugEnabled
                                    ? __('Enabled', 'proto-blocks')
                                    : __('Disabled', 'proto-blocks')}
                            </dd>

                            <dt>{__('Registered Blocks', 'proto-blocks')}</dt>
                            <dd>{state.blocks.length}</dd>
                        </dl>

                        <p className="description">
                            {__(
                                'To enable debug mode, add define(\'PROTO_BLOCKS_DEBUG\', true); to your wp-config.php',
                                'proto-blocks'
                            )}
                        </p>
                    </CardBody>
                </Card>

                {/* Documentation Card */}
                <Card className="proto-blocks-admin__card">
                    <CardHeader>
                        <Heading level={2}>
                            {__('Documentation', 'proto-blocks')}
                        </Heading>
                    </CardHeader>
                    <CardBody>
                        <h3>{__('Quick Start', 'proto-blocks')}</h3>
                        <ol>
                            <li>
                                {__(
                                    'Create a proto-blocks/ directory in your theme',
                                    'proto-blocks'
                                )}
                            </li>
                            <li>
                                {__(
                                    'Add a subdirectory for each block (e.g., proto-blocks/card/)',
                                    'proto-blocks'
                                )}
                            </li>
                            <li>
                                {__(
                                    'Create a block.json file with your block configuration',
                                    'proto-blocks'
                                )}
                            </li>
                            <li>
                                {__(
                                    'Create a template.php file with your block markup',
                                    'proto-blocks'
                                )}
                            </li>
                        </ol>

                        <h3>{__('Example block.json', 'proto-blocks')}</h3>
                        <pre className="proto-blocks-admin__code">
{`{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "proto-blocks/card",
  "title": "Card",
  "category": "proto-blocks",
  "icon": "admin-post",
  "protoBlocks": {
    "fields": {
      "title": { "type": "text", "tagName": "h2" },
      "content": { "type": "wysiwyg" },
      "image": { "type": "image" }
    }
  }
}`}
                        </pre>
                    </CardBody>
                </Card>
            </div>
        </div>
    );
}
