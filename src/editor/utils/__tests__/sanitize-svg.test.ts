import { isSafeSvgUrl, sanitizeSvgElement, sanitizeSvgString } from '../sanitize-svg';

function parseSvg(markup: string): Element {
    const doc = document.implementation.createHTMLDocument('');
    doc.body.innerHTML = markup;
    const svg = doc.body.querySelector('svg');
    if (!svg) {
        throw new Error('no <svg> in fixture');
    }
    return svg;
}

describe('sanitizeSvgElement', () => {
    it('removes <script> and <foreignObject> with their content', () => {
        const svg = parseSvg(
            '<svg viewBox="0 0 24 24"><script>alert(1)</script>' +
                '<foreignObject><div onclick="alert(1)">html</div></foreignObject>' +
                '<path d="M0 0h24v24H0z"/></svg>'
        );
        sanitizeSvgElement(svg);
        expect(svg.querySelector('script')).toBeNull();
        expect(svg.querySelector('foreignObject')).toBeNull();
        expect(svg.outerHTML).not.toMatch(/alert|html/);
        expect(svg.querySelector('path')?.getAttribute('d')).toBe('M0 0h24v24H0z');
    });

    it('strips on* handlers from every element', () => {
        const svg = parseSvg(
            '<svg onload="alert(1)"><g onmouseover="alert(2)"><circle r="1" ONCLICK="alert(3)"/></g></svg>'
        );
        sanitizeSvgElement(svg);
        expect(svg.outerHTML).not.toMatch(/on(load|mouseover|click)/i);
        expect(svg.querySelector('circle')?.getAttribute('r')).toBe('1');
    });

    it('drops javascript: and data: hrefs but keeps fragments and https', () => {
        const svg = parseSvg(
            '<svg>' +
                '<a href="javascript:alert(1)"><text>js</text></a>' +
                '<a xlink:href="data:text/html,x"><text>data</text></a>' +
                '<use href="#icon"/>' +
                '<use xlink:href="https://example.com/sprite.svg#icon"/>' +
                '<image href="  JAVA\nSCRIPT:alert(1)"/>' +
                '</svg>'
        );
        sanitizeSvgElement(svg);
        const anchors = svg.querySelectorAll('a');
        expect(anchors[0].hasAttribute('href')).toBe(false);
        expect(anchors[1].hasAttribute('xlink:href')).toBe(false);
        const uses = svg.querySelectorAll('use');
        expect(uses[0].getAttribute('href')).toBe('#icon');
        expect(uses[1].getAttribute('xlink:href')).toBe('https://example.com/sprite.svg#icon');
        expect(svg.querySelector('image')?.hasAttribute('href')).toBe(false);
        // Text content survives even when the link is defanged.
        expect(svg.textContent).toContain('js');
    });

    it('drops SMIL elements that retarget href', () => {
        const svg = parseSvg(
            '<svg><a href="#ok"><set attributeName="href" to="javascript:alert(1)"/>' +
                '<animate attributeName="opacity" from="0" to="1"/><text>t</text></a></svg>'
        );
        sanitizeSvgElement(svg);
        expect(svg.querySelector('set')).toBeNull();
        expect(svg.querySelector('animate')).not.toBeNull();
    });

    it('keeps an ordinary icon intact', () => {
        const markup =
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">' +
            '<defs><linearGradient id="g"><stop offset="0"></stop></linearGradient></defs>' +
            '<path d="M5 12h14" fill="url(#g)"></path></svg>';
        const svg = parseSvg(markup);
        sanitizeSvgElement(svg);
        expect(svg.outerHTML).toBe(markup);
    });
});

describe('sanitizeSvgString', () => {
    it('returns sanitised svg markup', () => {
        const out = sanitizeSvgString(
            '<svg viewBox="0 0 24 24" onload="alert(1)"><script>x</script><path d="M1 1"/></svg>'
        );
        expect(out.startsWith('<svg')).toBe(true);
        expect(out).not.toMatch(/onload|script/);
        expect(out).toContain('d="M1 1"');
    });

    it('returns an empty string when there is no svg', () => {
        expect(sanitizeSvgString('<img src=x onerror=alert(1)>')).toBe('');
        expect(sanitizeSvgString('')).toBe('');
    });

    it('is idempotent', () => {
        const once = sanitizeSvgString('<svg onload="x"><a href="javascript:y"><text>t</text></a></svg>');
        expect(sanitizeSvgString(once)).toBe(once);
    });
});

describe('isSafeSvgUrl', () => {
    it('accepts fragments, http(s) and relative paths only', () => {
        expect(isSafeSvgUrl('#id')).toBe(true);
        expect(isSafeSvgUrl('https://example.com/a.svg#b')).toBe(true);
        expect(isSafeSvgUrl('http://example.com/a.svg')).toBe(true);
        expect(isSafeSvgUrl('/sprite.svg#x')).toBe(true);
        expect(isSafeSvgUrl('javascript:alert(1)')).toBe(false);
        expect(isSafeSvgUrl('data:image/svg+xml,x')).toBe(false);
        expect(isSafeSvgUrl('mailto:x@y')).toBe(false);
    });
});
