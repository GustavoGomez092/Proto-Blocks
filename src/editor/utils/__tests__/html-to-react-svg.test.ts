// `html-to-react` pulls in the fields renderer, whose components need
// @wordpress/block-editor and @wordpress/components (not installed for Jest
// and irrelevant here). Stub both so only the converter itself is exercised.
jest.mock('@wordpress/block-editor', () => ({}), { virtual: true });
jest.mock('../../fields/render', () => ({ renderField: () => null }));

import { processHtmlToReact } from '../html-to-react';

type Node = {
    type: unknown;
    props: Record<string, unknown> & { children?: unknown };
};

function children(node: Node): Node[] {
    const raw = node.props.children;
    if (raw === undefined || raw === null) {
        return [];
    }
    return (Array.isArray(raw) ? raw : [raw]).filter(
        (child): child is Node => typeof child === 'object' && child !== null
    );
}

function walk(node: Node, visit: (node: Node) => void): void {
    visit(node);
    children(node).forEach((child) => walk(child, visit));
}

const options = { attributes: {}, setAttributes: () => {}, fields: {} };

describe('processHtmlToReact: <svg>', () => {
    it('builds React elements instead of injecting innerHTML', () => {
        const tree = processHtmlToReact(
            '<svg viewBox="0 0 24 24" class="w-5 h-5" stroke-width="2" xmlns:xlink="http://www.w3.org/1999/xlink">' +
                '<defs><linearGradient id="g"><stop offset="0"/></linearGradient></defs>' +
                '<path d="M5 12h14" fill="url(#g)"/><use xlink:href="#g"/></svg>',
            options
        ) as Node;

        expect(tree.type).toBe('svg');
        expect(tree.props.dangerouslySetInnerHTML).toBeUndefined();
        expect(tree.props.viewBox).toBe('0 0 24 24');
        expect(tree.props.className).toBe('w-5 h-5');
        expect(tree.props.strokeWidth).toBe('2');
        expect(tree.props.xmlnsXlink).toBe('http://www.w3.org/1999/xlink');

        const types: unknown[] = [];
        walk(tree, (node) => types.push(node.type));
        // SVG tag case is preserved so gradients/clipPaths still resolve.
        expect(types).toEqual(['svg', 'defs', 'linearGradient', 'stop', 'path', 'use']);

        const uses: Node[] = [];
        walk(tree, (node) => {
            if (node.type === 'use') {
                uses.push(node);
            }
        });
        expect(uses.map((node) => node.props.xlinkHref)).toEqual(['#g']);
    });

    it('never produces dangerouslySetInnerHTML, script, foreignObject or handlers', () => {
        const tree = processHtmlToReact(
            '<div><svg onload="alert(1)"><script>alert(2)</script>' +
                '<foreignObject><body onload="alert(3)"></body></foreignObject>' +
                '<a href="javascript:alert(4)" xlink:href="javascript:alert(5)"><text>t</text></a>' +
                '<path onclick="alert(6)" d="M0 0"/></svg></div>',
            options
        ) as Node;

        const seen: Node[] = [];
        walk(tree, (node) => seen.push(node));

        const types = seen.map((node) => node.type);
        expect(types).not.toContain('script');
        expect(types).not.toContain('foreignObject');
        expect(types).not.toContain('body');
        expect(types).toContain('path');

        const rawHtml = seen.filter((node) => node.props.dangerouslySetInnerHTML !== undefined);
        expect(rawHtml).toEqual([]);

        const offending: string[] = [];
        for (const node of seen) {
            for (const [name, value] of Object.entries(node.props)) {
                if (/^on/i.test(name)) {
                    offending.push(`${String(node.type)}[${name}]`);
                }
                if (typeof value === 'string' && /javascript:|alert\(/i.test(value)) {
                    offending.push(`${String(node.type)}[${name}=${value}]`);
                }
            }
        }
        expect(offending).toEqual([]);
    });
});
