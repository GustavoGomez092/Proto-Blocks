/**
 * Accordion Block - Interactivity API View Script
 *
 * Handles accordion toggle functionality using the WordPress Interactivity API.
 */

import { store, getContext } from '@wordpress/interactivity';

const { state, actions } = store('proto-blocks/accordion', {
    state: {
        get isItemOpen() {
            const context = getContext();
            const index = context.index;
            const openItems = context.openItems || [];
            return openItems.includes(index);
        },
    },

    actions: {
        toggle() {
            const context = getContext();
            const index = context.index;

            if (typeof index !== 'number') {
                return;
            }

            const openItems = [...(context.openItems || [])];
            const itemIndex = openItems.indexOf(index);

            if (itemIndex > -1) {
                // Close the item
                openItems.splice(itemIndex, 1);
            } else {
                // Open the item
                if (context.allowMultiple) {
                    openItems.push(index);
                } else {
                    // Only allow one open at a time
                    openItems.length = 0;
                    openItems.push(index);
                }
            }

            context.openItems = openItems;
        },

        openAll() {
            const context = getContext();
            const items = document.querySelectorAll(
                '[data-wp-context] .proto-accordion__item'
            );
            context.openItems = Array.from(
                { length: items.length },
                (_, i) => i
            );
        },

        closeAll() {
            const context = getContext();
            context.openItems = [];
        },
    },
});
