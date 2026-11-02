/**
 * Client-side sanitiser for RichText-bound attribute values.
 *
 * Block attributes live in the block-comment JSON, which a contributor can
 * write raw through the REST posts API; the server's kses pass only covers the
 * *preview* HTML, and html-to-react discards that preview for every
 * `data-proto-field` element and re-renders from the stored attribute.
 * `@wordpress/rich-text` keeps unknown tags (and every attribute but `on*`),
 * so a stored `<iframe srcdoc>` / `<img src onerror>` / `javascript:` link
 * would become live DOM in the admin's editor. Nothing stored is trusted:
 * every value is laundered through here before it reaches `<RichText>` and
 * again on change, so an existing payload is dropped the moment the post is
 * saved again.
 *
 * The allowlist is the same phrasing-content schema RichText applies on paste
 * (`getPhrasingContentSchema` from `@wordpress/dom`), minus embedded content
 * (`audio`, `video`, `object`, `embed`, `canvas`, `math` -- `math` in
 * particular allows arbitrary children), plus the attributes core formats need
 * (`style`/`class` on `mark`/`span`, inline `img`). Wysiwyg values get the
 * block-level elements on top (`p`, lists, headings, tables ...).
 * `getBlockContentSchema` from `@wordpress/blocks` was deliberately not used:
 * it is assembled at runtime from whatever blocks happen to be registered, the
 * package is not installed for Jest, and an explicit allowlist is auditable.
 */

import { removeInvalidHTML, getPhrasingContentSchema } from '@wordpress/dom';

type SchemaEntry = {
    attributes?: string[];
    classes?: Array<string | RegExp>;
    children?: Schema;
    allowEmpty?: boolean;
};
type Schema = Record<string, SchemaEntry>;

/**
 * Elements that are removed outright (with their content) before the
 * allowlist runs. `removeInvalidHTML` only *unwraps* unknown elements, which
 * would leave `<script>` bodies behind as visible text.
 */
const DROP_WITH_CONTENT = [
    'script',
    'style',
    'iframe',
    'frame',
    'frameset',
    'object',
    'embed',
    'applet',
    'template',
    'noscript',
    'math',
    'svg',
    'link',
    'meta',
    'base',
    'form',
    'input',
    'textarea',
    'select',
    'button',
    'canvas',
    'audio',
    'video',
    'picture',
    'source',
    'track',
    'title',
    'head',
];

/** Phrasing-content tags we never allow even though WP's schema has them. */
const INLINE_DENY = new Set(['audio', 'canvas', 'embed', 'object', 'video', 'math']);

/**
 * Attributes added on top of the WP phrasing schema. These are what core
 * formats emit (`core/text-color` -> `<mark style class>`, `core/image` ->
 * `<img style class>`, `core/language` -> `<bdo lang>`, `core/footnote` ->
 * `<sup data-fn>`), so laundering a value does not strip legitimate markup.
 */
const INLINE_EXTRA_ATTRIBUTES: Record<string, string[]> = {
    // `core/link` stores the linked post as data-type / data-id.
    a: ['title', 'type', 'data-type', 'data-id'],
    mark: ['style'],
    span: ['style', 'lang', 'dir'],
    img: ['style', 'title', 'loading'],
    bdo: ['lang'],
    bdi: ['lang'],
    sup: ['data-fn'],
};

/** Attributes that carry a URL and therefore get a scheme check. */
const URL_ATTRIBUTES = [
    'href',
    'src',
    'srcset',
    'cite',
    'poster',
    'action',
    'formaction',
    'background',
    'data',
    'xlink:href',
];

/** Schemes a stored link/image may use. Everything else is dropped. */
const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

/** Block-level elements allowed in wysiwyg values: tag -> extra attributes. */
const BLOCK_ELEMENTS: Record<string, string[]> = {
    p: [],
    h1: [],
    h2: [],
    h3: [],
    h4: [],
    h5: [],
    h6: [],
    ul: [],
    ol: ['start', 'reversed', 'type'],
    li: ['value'],
    blockquote: ['cite'],
    pre: [],
    hr: [],
    figure: [],
    figcaption: [],
    div: [],
    section: [],
    address: [],
    table: [],
    caption: [],
    thead: [],
    tbody: [],
    tfoot: [],
    tr: [],
    th: ['colspan', 'rowspan', 'scope'],
    td: ['colspan', 'rowspan'],
    dl: [],
    dt: [],
    dd: [],
};

/** Elements that never have children (a schema entry without `children`). */
const VOID_TAGS = new Set(['br', 'wbr', 'img', 'hr', '#text']);

/**
 * Tags whose textContent is considered part of the schema source.
 * Built once from WP's phrasing-content schema.
 */
function buildInlineSchema(): Schema {
    const source = getPhrasingContentSchema() as Schema;
    const schema: Schema = {};

    const add = (tag: string, attributes: string[]) => {
        const entry: SchemaEntry = { attributes, classes: ['*'] };
        if (!VOID_TAGS.has(tag)) {
            // Self-referential: any allowed inline element may nest any other.
            entry.children = schema;
        }
        schema[tag] = entry;
    };

    Object.keys(source).forEach((tag) => {
        if (INLINE_DENY.has(tag)) {
            return;
        }
        const base = source[tag].attributes || [];
        // `srcset`, `usemap`, `ismap` on img are not something an author sets
        // from the editor; keep the attribute surface minimal.
        const attributes =
            tag === 'img'
                ? ['alt', 'src', 'width', 'height']
                : base.slice();
        add(tag, attributes.concat(INLINE_EXTRA_ATTRIBUTES[tag] || []));
    });

    // `span` is not in WP's schema (it is unwrapped on paste) but core formats
    // and the server's wp_kses_post both keep it.
    add('span', INLINE_EXTRA_ATTRIBUTES.span);

    return schema;
}

function buildBlockSchema(inline: Schema): Schema {
    const schema: Schema = {};
    Object.keys(inline).forEach((tag) => {
        schema[tag] = { ...inline[tag] };
        if (schema[tag].children) {
            schema[tag].children = schema;
        }
    });
    Object.keys(BLOCK_ELEMENTS).forEach((tag) => {
        const entry: SchemaEntry = {
            attributes: BLOCK_ELEMENTS[tag],
            classes: ['*'],
            allowEmpty: true,
        };
        if (!VOID_TAGS.has(tag)) {
            entry.children = schema;
        }
        schema[tag] = entry;
    });
    return schema;
}

let inlineSchema: Schema | null = null;
let blockSchema: Schema | null = null;

function getInlineSchema(): Schema {
    if (!inlineSchema) {
        inlineSchema = buildInlineSchema();
    }
    return inlineSchema;
}

function getBlockSchema(): Schema {
    if (!blockSchema) {
        blockSchema = buildBlockSchema(getInlineSchema());
    }
    return blockSchema;
}

/**
 * Whether a URL is safe to store/render: http(s), mailto, tel, a fragment or
 * a relative/protocol-relative path. Any other scheme (`javascript:`,
 * `data:`, `vbscript:` ...) is rejected. Browsers strip ASCII control
 * characters and whitespace inside a scheme, so those are removed before the
 * check (`java\nscript:` must not slip through).
 */
export function isSafeUrl(url: string): boolean {
    if (typeof url !== 'string') {
        return false;
    }
    // eslint-disable-next-line no-control-regex
    const cleaned = url.replace(/[\u0000- \u007f-\u009f]/g, '').trim();
    if (cleaned === '') {
        return true;
    }
    const match = /^([a-z][a-z0-9+.-]*):/i.exec(cleaned);
    if (!match) {
        // No scheme: relative path, fragment or protocol-relative URL.
        return true;
    }
    return ALLOWED_SCHEMES.includes(match[1].toLowerCase());
}

/**
 * Inline `style` is not a script sink in current browsers, but keep out the
 * historical ones and anything that loads a remote resource.
 */
const UNSAFE_STYLE = /(expression\s*\(|javascript:|vbscript:|url\s*\(|@import|behavior\s*:|-moz-binding)/i;

function withDocument(html: string, mutate: (body: HTMLElement) => void): string {
    const doc = document.implementation.createHTMLDocument('');
    doc.body.innerHTML = html;
    mutate(doc.body);
    return doc.body.innerHTML;
}

function dropDangerousElements(body: HTMLElement): void {
    body.querySelectorAll(DROP_WITH_CONTENT.join(',')).forEach((node) => {
        node.parentNode?.removeChild(node);
    });
}

function scrubAttributes(body: HTMLElement): void {
    body.querySelectorAll('*').forEach((element) => {
        Array.from(element.attributes).forEach(({ name, value }) => {
            const lower = name.toLowerCase();
            if (lower.startsWith('on')) {
                element.removeAttribute(name);
            } else if (URL_ATTRIBUTES.includes(lower) && !isSafeUrl(value)) {
                element.removeAttribute(name);
            } else if (lower === 'style' && UNSAFE_STYLE.test(value)) {
                element.removeAttribute(name);
            }
        });
    });
}

function sanitize(html: string, schema: Schema, inline: boolean): string {
    const preCleaned = withDocument(html, dropDangerousElements);
    const allowlisted = removeInvalidHTML(preCleaned, schema, inline);
    return withDocument(allowlisted, scrubAttributes);
}

/**
 * Small per-value memo. Values are re-sanitised on every render and every
 * keystroke, and a laundered value is stored as its own key so the second
 * pass is a lookup.
 */
const MAX_CACHE = 500;
function memoised(cache: Map<string, string>, html: string, run: () => string): string {
    const hit = cache.get(html);
    if (hit !== undefined) {
        return hit;
    }
    const out = run();
    if (cache.size >= MAX_CACHE) {
        cache.clear();
    }
    cache.set(html, out);
    cache.set(out, out);
    return out;
}

const inlineCache = new Map<string, string>();
const blockCache = new Map<string, string>();

/**
 * Sanitise a value bound to an inline RichText (text / link fields).
 */
export function sanitizeRichTextValue(html: string): string {
    if (typeof html !== 'string' || html === '') {
        return '';
    }
    if (html.indexOf('<') === -1) {
        // Plain text (entities included): nothing to do.
        return html;
    }
    return memoised(inlineCache, html, () => sanitize(html, getInlineSchema(), true));
}

/**
 * Sanitise a value bound to a block-level RichText (wysiwyg fields):
 * paragraphs, lists, headings and tables survive.
 */
export function sanitizeWysiwygValue(html: string): string {
    if (typeof html !== 'string' || html === '') {
        return '';
    }
    if (html.indexOf('<') === -1) {
        return html;
    }
    return memoised(blockCache, html, () => sanitize(html, getBlockSchema(), false));
}
