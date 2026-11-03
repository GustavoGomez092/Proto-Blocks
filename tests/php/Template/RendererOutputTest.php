<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Template;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Template\HtmlLoader;
use ProtoBlocks\Template\Renderer;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;

/**
 * The renderer loads block HTML into DOMDocument with an '<?xml encoding="UTF-8"?>'
 * prefix to stop libxml assuming ISO-8859-1. libxml keeps that prefix as a
 * processing-instruction node in the document, so a whole-document saveHTML()
 * serialises it straight back into the rendered output — putting a stray
 * '<?xml encoding="UTF-8"?>' in front of every block on the front end.
 *
 * Browsers parse it as a bogus comment so nothing is visible, but it is invalid
 * markup inside <body>, it shows up in view-source and in any DOM-diffing or
 * content-scraping downstream, and it is emitted once per rendered block.
 */
final class RendererOutputTest extends TestCase
{
    private function render(): string
    {
        $renderer = new Renderer(new FieldRegistry(), new ControlRegistry());

        return $renderer->render(
            dirname(__DIR__) . '/fixtures/utf8-block.php',
            [],
            []
        );
    }

    public function test_rendered_output_has_no_xml_processing_instruction(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('<?xml', $html);
        // libxml >= 2.14 parses the declaration as a bogus comment instead.
        $this->assertStringNotContainsString('<!--?xml', $html);
        $this->assertStringStartsWith('<div', ltrim($html));
    }

    /** Both node forms libxml can leave behind, whichever version runs the test. */
    public function test_strip_removes_processing_instruction_and_comment_forms(): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->appendChild($dom->createProcessingInstruction('xml', 'encoding="UTF-8"'));
        $dom->appendChild($dom->createComment('?xml encoding="UTF-8"?'));
        $dom->appendChild($dom->createComment(' keep me '));
        $dom->appendChild($dom->createElement('div', 'x'));

        HtmlLoader::stripXmlDeclarations($dom);
        $html = (string) $dom->saveHTML();

        $this->assertStringNotContainsString('<?xml', $html);
        $this->assertStringNotContainsString('<!--?xml', $html);
        $this->assertStringContainsString('<!-- keep me -->', $html);
        $this->assertStringContainsString('<div>x</div>', $html);
    }

    public function test_markup_starting_with_an_xml_prolog_loses_it_on_any_libxml(): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        HtmlLoader::load($dom, '<?xml version="1.0" encoding="UTF-8"?><div>Café</div>');
        $html = (string) $dom->saveHTML();

        $this->assertStringNotContainsString('<?xml', $html);
        $this->assertStringNotContainsString('<!--?xml', $html);
        $this->assertStringStartsWith('<div>', $html);
    }

    public function test_loader_keeps_utf8_and_escaping_without_an_encoding_hint(): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        HtmlLoader::load($dom, '<p title="naïve &quot;x&quot;">日本語 😀 &lt;b&gt; &amp;</p>');
        $p = $dom->getElementsByTagName('p')->item(0);

        $this->assertNotNull($p);
        $this->assertSame('日本語 😀 <b> &', $p->textContent);
        $this->assertSame('naïve "x"', $p->getAttribute('title'));
        $this->assertSame(0, $p->getElementsByTagName('b')->length, 'escaped markup must stay text');
    }

    public function test_invalid_utf8_does_not_break_loading(): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        HtmlLoader::load($dom, "<p>ok \xC3\x28 &lt;i&gt; é</p>");
        $p = $dom->getElementsByTagName('p')->item(0);

        $this->assertNotNull($p);
        $this->assertStringContainsString('ok', $p->textContent);
        $this->assertStringContainsString('<i> é', $p->textContent);
        $this->assertSame(0, $p->getElementsByTagName('i')->length);
    }

    public function test_non_ascii_inside_script_survives_rendering(): void
    {
        $template = sys_get_temp_dir() . '/pb-script-' . uniqid() . '.php';
        file_put_contents($template, '<div><script>var s = "Café — 日本語";</script></div>');
        try {
            $html = (new Renderer(new FieldRegistry(), new ControlRegistry()))->render($template, [], []);
        } finally {
            @unlink($template);
        }

        $this->assertStringContainsString('var s = "Café — 日本語";', $html);
    }

    public function test_utf8_content_survives_rendering(): void
    {
        $html = $this->render();

        // The whole point of the '<?xml encoding' prefix was to stop libxml
        // mangling these, so removing the stray node must not reintroduce that.
        $this->assertStringContainsString('Café', $html);
        $this->assertStringContainsString('naïve résumé', $html);
        $this->assertStringContainsString('日本語', $html);
        $this->assertStringContainsString('—', $html);
    }
}
