import {
    isSafeUrl,
    sanitizeRichTextValue,
    sanitizeWysiwygValue,
} from '../sanitize-rich-text';

// The threat: block attributes are contributor-written comment JSON that the
// server never kses'd, and RichText keeps unknown tags with every attribute
// but `on*`. These values must be inert before they reach <RichText>.

describe('sanitizeRichTextValue', () => {
    it('strips an event handler from an inline image (or drops the image)', () => {
        const out = sanitizeRichTextValue('<img src="x" onerror="alert(1)">');
        expect(out).not.toMatch(/onerror/i);
        expect(out).not.toMatch(/alert/);
    });

    it('removes iframes together with a srcdoc payload', () => {
        const out = sanitizeRichTextValue(
            'before<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>after'
        );
        expect(out).not.toMatch(/iframe|srcdoc|script|alert/i);
        expect(out).toBe('beforeafter');
    });

    it('removes <script> with its body instead of unwrapping it to text', () => {
        expect(sanitizeRichTextValue('a<script>alert(1)</script>b')).toBe('ab');
    });

    it('removes <object>, <embed>, <math> and <svg> wholesale', () => {
        const out = sanitizeRichTextValue(
            '<object data="x"></object><embed src="y"><math><mtext><img src=x onerror=alert(1)></mtext></math><svg onload="alert(1)"></svg>ok'
        );
        expect(out).toBe('ok');
    });

    it('keeps <strong> and an https link', () => {
        const input = '<strong>Bold</strong> and <a href="https://example.com">link</a>';
        expect(sanitizeRichTextValue(input)).toBe(input);
    });

    it('keeps the attributes core formats emit', () => {
        const input =
            '<mark style="color:red" class="has-inline-color">x</mark> ' +
            '<a href="https://example.com" data-type="post" data-id="3" target="_blank" rel="noopener">y</a>';
        expect(sanitizeRichTextValue(input)).toBe(input);
    });

    it('drops a javascript: href but keeps the link text', () => {
        const out = sanitizeRichTextValue('<a href="javascript:alert(1)">click</a>');
        expect(out).not.toMatch(/javascript/i);
        expect(out).toContain('click');
    });

    it('drops obfuscated schemes browsers would still execute', () => {
        for (const href of [
            'JaVaScRiPt:alert(1)',
            'java\nscript:alert(1)',
            ' \tjavascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox',
        ]) {
            const out = sanitizeRichTextValue(`<a href="${href.replace(/"/g, '&quot;')}">x</a>`);
            expect(out).not.toMatch(/href=/i);
        }
    });

    it('drops an unknown attribute on an allowed tag', () => {
        expect(sanitizeRichTextValue('<em title="t" foo="bar">x</em>')).toBe('<em>x</em>');
    });

    it('unwraps unknown block tags to inline content', () => {
        const out = sanitizeRichTextValue('<div>a</div><p>b</p>');
        expect(out).not.toMatch(/<(div|p)/);
        expect(out).toContain('a');
        expect(out).toContain('b');
    });

    it('is idempotent', () => {
        const inputs = [
            '<img src="x" onerror="alert(1)">',
            '<strong>Bold</strong> <a href="https://example.com">link</a>',
            'plain &amp; text',
            '<div>a</div><p>b</p>',
            '<a href="javascript:alert(1)">click</a>',
        ];
        for (const input of inputs) {
            const once = sanitizeRichTextValue(input);
            expect(sanitizeRichTextValue(once)).toBe(once);
        }
    });

    it('leaves plain text untouched', () => {
        expect(sanitizeRichTextValue('Hello & welcome')).toBe('Hello & welcome');
        expect(sanitizeRichTextValue('')).toBe('');
    });
});

describe('sanitizeWysiwygValue', () => {
    it('keeps paragraphs, lists, headings and tables', () => {
        const input =
            '<h2>Title</h2><p>Para <em>x</em></p><ul><li>one</li><li>two</li></ul>' +
            '<table><tbody><tr><td colspan="2">c</td></tr></tbody></table>';
        expect(sanitizeWysiwygValue(input)).toBe(input);
    });

    it('still removes script, iframes and handlers inside block content', () => {
        const out = sanitizeWysiwygValue(
            '<p onclick="alert(1)">a<script>alert(1)</script></p><iframe src="https://x"></iframe><p><img src="x" onerror="alert(1)"></p>'
        );
        expect(out).not.toMatch(/script|iframe|onerror|onclick|alert/i);
        expect(out).toContain('<p>a</p>');
    });

    it('is idempotent', () => {
        const input = '<p onclick="x">a</p><ul><li>b<iframe></iframe></li></ul>';
        const once = sanitizeWysiwygValue(input);
        expect(sanitizeWysiwygValue(once)).toBe(once);
    });
});

describe('isSafeUrl', () => {
    it('allows http, https, mailto, tel, relative and fragments', () => {
        for (const url of [
            'https://example.com/a?b=c#d',
            'http://example.com',
            'mailto:someone@example.com',
            'tel:+15555551234',
            '/relative/path',
            'relative/path',
            '#anchor',
            '//cdn.example.com/x.js',
            '',
        ]) {
            expect(isSafeUrl(url)).toBe(true);
        }
    });

    it('rejects every other scheme', () => {
        for (const url of [
            'javascript:alert(1)',
            'JAVASCRIPT:alert(1)',
            'java\u0000script:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:x',
            'ftp://example.com',
            'file:///etc/passwd',
        ]) {
            expect(isSafeUrl(url)).toBe(false);
        }
    });
});
