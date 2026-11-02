<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Template;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Template\Renderer;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;

/**
 * Regression tests for the entity handling at the end of Renderer::render().
 *
 * libxml's saveHTML() entity-encodes every non-ASCII character, so the
 * renderer has to decode entities to hand back the template's UTF-8. It used
 * to do that with a blanket html_entity_decode() over the whole document,
 * which also decoded &lt; &gt; &amp; &quot; &#039; -- undoing every
 * esc_attr()/esc_html()/esc_js() the template had applied and turning escaped
 * visitor or contributor input into live markup. These tests pin the fixed
 * behaviour: typographic entities come back as characters, markup entities
 * never do, whatever their spelling.
 */
final class RendererEscapingTest extends TestCase
{
    private const IMG_PAYLOAD    = '"><img src=x onerror=alert(1)>';
    private const SCRIPT_PAYLOAD = '<script>alert(1)</script>';

    private function render(array $attributes): string
    {
        $renderer = new Renderer(new FieldRegistry(), new ControlRegistry());

        return $renderer->render(__DIR__ . '/fixtures/escaping-block.php', $attributes, []);
    }

    private function decode(string $html): string
    {
        $m = new \ReflectionMethod(Renderer::class, 'decodeNonMarkupEntities');
        $m->setAccessible(true);

        return (string) $m->invoke(null, $html);
    }

    /** Re-parse rendered output the way a browser would and return the DOM. */
    private function parse(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $dom;
    }

    public function test_escaped_attribute_payload_cannot_break_out_of_the_attribute(): void
    {
        $html = $this->render(['attr_payload' => self::IMG_PAYLOAD]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror="', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);

        $dom = $this->parse($html);
        $this->assertSame(0, $dom->getElementsByTagName('img')->length, 'payload must not become an element');
        $this->assertSame(self::IMG_PAYLOAD, $dom->documentElement->getAttribute('data-note'), 'payload must survive as the literal attribute value');
    }

    public function test_escaped_script_tag_in_text_node_stays_text(): void
    {
        $html = $this->render(['text_payload' => self::SCRIPT_PAYLOAD]);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        $dom   = $this->parse($html);
        $texts = [];
        foreach ($dom->getElementsByTagName('p') as $p) {
            if ($p->getAttribute('class') === 'text') {
                $texts[] = $p->textContent;
            }
        }
        $this->assertSame([self::SCRIPT_PAYLOAD], $texts, 'payload must be the <p> text, not a child element');
        // The fixture's own <script> is the only one allowed in the document.
        $this->assertSame(1, $dom->getElementsByTagName('script')->length);
    }

    public function test_escaped_js_payload_cannot_close_the_script_element(): void
    {
        $html = $this->render(['js_payload' => '</script><img src=x onerror=alert(1)>']);

        $this->assertStringNotContainsString('</script><img', $html);
        $this->assertStringContainsString('&lt;/script&gt;&lt;img', $html);
        $this->assertSame(1, substr_count($html, '</script>'));
        // libxml entity-encodes non-ASCII inside <script> too; it must be restored there.
        $this->assertStringContainsString('var snow = "☃";', $html);
    }

    public function test_typographic_entities_come_back_as_literal_characters(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('® — “curly” ‘single’', $html);
        $this->assertStringContainsString('title="® — “t”"', $html);
        $this->assertStringContainsString("\u{00A0}end", $html, '&nbsp; is restored to U+00A0');
        $this->assertStringNotContainsString('&reg;', $html);
        $this->assertStringNotContainsString('&mdash;', $html);
        $this->assertStringNotContainsString('&ldquo;', $html);
    }

    public function test_numeric_spellings_of_markup_characters_are_not_decoded(): void
    {
        $input = 'a&#x3C;b&#60;c&#X3C;d&#0060;e&#62;f&#x3E;g&#38;h&#038;i&#x26;j&#34;k&#x22;l&#39;m&#x27;n';

        $this->assertSame($input, $this->decode($input));
    }

    public function test_named_spellings_of_markup_characters_are_not_decoded(): void
    {
        $input = '&lt;&gt;&amp;&quot;&apos;&AMP;&LT;&GT;&QUOT;';

        $this->assertSame($input, $this->decode($input));
    }

    public function test_pre_encoded_markup_echoed_raw_by_a_template_stays_inert(): void
    {
        // A template author (or a kses-filtered value) emitting entities directly.
        $html = $this->render(['raw_markup' => '&#x3C;script&#62;alert(1)&#x3C;/script&#62; &#60;img src=x&#62;']);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame(0, $this->parse($html)->getElementsByTagName('img')->length);
    }

    public function test_decoder_never_creates_a_new_entity_by_double_decoding(): void
    {
        // "&amp;reg;" is how libxml serialises the literal text "&reg;".
        $this->assertSame('&amp;reg; &amp;#60; &amp;lt;', $this->decode('&amp;reg; &amp;#60; &amp;lt;'));
    }

    public function test_decoder_restores_safe_entities_and_leaves_unrepresentable_ones_alone(): void
    {
        $this->assertSame('® — “q” fj', $this->decode('&reg; &mdash; &ldquo;q&rdquo; &fjlig;'));
        $this->assertSame("\u{2242}\u{0338}", $this->decode('&NotEqualTilde;'), 'multi-codepoint HTML5 entity');

        // Unknown names, NUL, C0/C1 controls, surrogates and out-of-range code
        // points are left for the browser rather than turned into bytes.
        $input = '&bogus; &#0; &#1; &#x7F; &#x80; &#xD800; &#x110000; &#xFFFFFFFF;';
        $this->assertSame($input, $this->decode($input));
    }
}
