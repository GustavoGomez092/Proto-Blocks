/**
 * Proto-Blocks Admin Entry Point
 *
 * Handles the admin settings page functionality
 */

import './admin.css';
import { createRoot, render, createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { AdminApp } from './AdminApp';

// Get admin data from PHP
declare global {
    interface Window {
        protoBlocksAdmin: {
            nonce: string;
            apiUrl: string;
            blocks: Array<{
                name: string;
                title: string;
                description: string;
                category: string;
                path: string;
            }>;
            cacheEnabled: boolean;
            debugEnabled: boolean;
            version: string;
        };
    }
}

// Initialize admin app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('proto-blocks-admin-app');

    if (container) {
        // Use createRoot for React 18+ compatibility, fall back to render
        if (typeof createRoot === 'function') {
            const root = createRoot(container);
            root.render(createElement(AdminApp));
        } else {
            render(createElement(AdminApp), container);
        }
    }
});
