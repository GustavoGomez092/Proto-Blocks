<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Template;

use PHPUnit\Framework\TestCase;

/**
 * Guards the bootstrap stubs that escaping assertions depend on: they must
 * behave like WordPress's, not as identity functions.
 */
final class StubFidelityTest extends TestCase
{
    public function testEscHtmlEncodesMarkupAndQuotes(): void
    {
        $this->assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', esc_html('<b>"x"</b>'));
    }

    public function testEscHtmlDoesNotDoubleEncode(): void
    {
        $this->assertSame('&amp;', esc_html('&amp;'));
    }

    public function testSanitizeTextFieldStripsTagsScriptsAndWhitespace(): void
    {
        $this->assertSame('x y', sanitize_text_field("<b>x</b><script>a</script>  y "));
    }

    public function testWpBlockStubHasContext(): void
    {
        $this->assertSame([], (new \WP_Block())->context);
    }
}
