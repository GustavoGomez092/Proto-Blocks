/**
 * SVG sanitiser shared by the preview converter (html-to-react) and the
 * block.json icon renderer (icon-utils).
 *
 * Safety over fidelity: `<script>`, `<foreignObject>` (an HTML island) and a
 * few other non-graphic elements are removed with their content, every
 * `on*` handler is dropped, SMIL animations that retarget `href` are dropped,
 * and `href` / `xlink:href` must be a fragment (`#id`), an http(s) URL or a
 * relative path. The DOM is mutated in place so callers can keep walking it.
 */

const DROP_ELEMENTS = new Set([
    'script',
    'foreignobject',
    'iframe',
    'object',
    'embed',
    'audio',
    'video',
    'canvas',
    'template',
    'noscript',
    'math',
    'link',
    'meta',
    'base',
    'form',
    'input',
    'textarea',
    'button',
    'select',
]);

/** SMIL elements that can rewrite an attribute (and so a URL) at runtime. */
const ANIMATION_ELEMENTS = new Set(['animate', 'set', 'animatemotion', 'animatetransform']);

const UNSAFE_STYLE = /(expression\s*\(|javascript:|vbscript:|@import|behavior\s*:|-moz-binding)/i;

/**
 * Whether an SVG URL reference is acceptable: a fragment, http(s), or a
 * relative path. Any other scheme (`javascript:`, `data:` ...) is rejected.
 */
export function isSafeSvgUrl(value: string): boolean {
    // eslint-disable-next-line no-control-regex
    const cleaned = value.replace(/[\u0000- \u007f-\u009f]/g, '').trim();
    if (cleaned === '' || cleaned.startsWith('#')) {
        return true;
    }
    const match = /^([a-z][a-z0-9+.-]*):/i.exec(cleaned);
    if (!match) {
        return true;
    }
    const scheme = match[1].toLowerCase();
    return scheme === 'http' || scheme === 'https';
}

function scrubElement(element: Element): void {
    Array.from(element.attributes).forEach(({ name, value }) => {
        const lower = name.toLowerCase();
        if (lower.startsWith('on')) {
            element.removeAttribute(name);
        } else if (
            (lower === 'href' || lower === 'xlink:href' || lower === 'src') &&
            !isSafeSvgUrl(value)
        ) {
            element.removeAttribute(name);
        } else if (lower === 'style' && UNSAFE_STYLE.test(value)) {
            element.removeAttribute(name);
        }
    });
}

/**
 * Sanitise an `<svg>` element (or any subtree) in place.
 */
export function sanitizeSvgElement(root: Element): Element {
    const toRemove: Element[] = [];
    const walk = (element: Element) => {
        const tag = element.localName.toLowerCase();
        if (DROP_ELEMENTS.has(tag)) {
            toRemove.push(element);
            return;
        }
        if (ANIMATION_ELEMENTS.has(tag)) {
            // `<set attributeName="href" to="javascript:...">` rewrites a
            // URL at runtime; the animation itself is not worth keeping.
            const target = (element.getAttribute('attributeName') || '').toLowerCase();
            if (target.includes('href')) {
                toRemove.push(element);
                return;
            }
        }
        scrubElement(element);
        Array.from(element.children).forEach(walk);
    };

    walk(root);
    toRemove.forEach((element) => element.parentNode?.removeChild(element));
    return root;
}

/**
 * Sanitise an SVG markup string. Parsed as HTML (lenient, inert), so a
 * slightly malformed hand-written icon still renders. Returns an empty
 * string when the markup contains no `<svg>`.
 */
export function sanitizeSvgString(svg: string): string {
    if (typeof svg !== 'string' || svg.indexOf('<') === -1) {
        return '';
    }
    const doc = document.implementation.createHTMLDocument('');
    doc.body.innerHTML = svg;
    const root = doc.body.querySelector('svg');
    if (!root) {
        return '';
    }
    sanitizeSvgElement(root);
    return root.outerHTML;
}
