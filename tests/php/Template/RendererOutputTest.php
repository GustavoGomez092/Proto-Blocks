<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Template;

use PHPUnit\Framework\TestCase;
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
        $this->assertStringStartsWith('<div', ltrim($html));
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
