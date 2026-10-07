/**
 * Telling a click on a repeater row from a click on the block around the rows.
 *
 * This is what decides whether the sidebar keeps showing one row's controls or
 * goes back to the block's own settings, so it is worth pinning down: the editor
 * replaces the server's `data-proto-repeater-item` with a sortable wrapper, and a
 * check that knew only the server's attribute would never match on the canvas.
 */

import {
    REPEATER_ITEM_SELECTOR,
    isInsideRepeaterItem,
} from '../repeater-item-context';

describe('isInsideRepeaterItem', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('matches a row as the editor renders it', () => {
        // The editor's shape: the class, no data attribute.
        document.body.innerHTML =
            '<div class="proto-blocks-repeater__sortable-item"><span id="t">Name</span></div>';

        expect(isInsideRepeaterItem(document.getElementById('t'))).toBe(true);
    });

    it('matches a row as the server renders it', () => {
        document.body.innerHTML = '<li data-proto-repeater-item><span id="t">Name</span></li>';

        expect(isInsideRepeaterItem(document.getElementById('t'))).toBe(true);
    });

    it('does not match the block around the rows', () => {
        // A click on the section's own padding: the author means the block.
        document.body.innerHTML =
            '<section id="s"><div class="proto-blocks-repeater__sortable-item"></div></section>';

        expect(isInsideRepeaterItem(document.getElementById('s'))).toBe(false);
    });

    it('treats a target that cannot answer as not a row', () => {
        // A text node has no closest(); falling back to the block's settings is the
        // safe direction, and it must not throw.
        expect(isInsideRepeaterItem(document.createTextNode('x'))).toBe(false);
        expect(isInsideRepeaterItem(null)).toBe(false);
    });

    it('knows both spellings of a row', () => {
        expect(REPEATER_ITEM_SELECTOR).toContain('data-proto-repeater-item');
        expect(REPEATER_ITEM_SELECTOR).toContain('proto-blocks-repeater__sortable-item');
    });
});
