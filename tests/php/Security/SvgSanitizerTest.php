<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Security;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Security\SvgSanitizer;

final class SvgSanitizerTest extends TestCase
{
    private const NS = 'xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"';

    private function svg(string $inner, string $attrs = ''): string
    {
        return '<svg ' . self::NS . ' ' . $attrs . '>' . $inner . '</svg>';
    }

    public function testCleanSvgKept(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<path d="M0 0h5"/>', 'viewBox="0 0 20 20"'));
        $this->assertNotNull($out);
        $this->assertStringContainsString('<path', $out);
        $this->assertStringContainsString('viewBox="0 0 20 20"', $out);
        $this->assertStringNotContainsString('<?xml', $out);
    }

    public function testScriptAndForeignObjectRemovedWithContent(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<script>alert(1)</script><foreignObject><div>hi</div></foreignObject><path d="M0 0"/>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsStringIgnoringCase('foreignObject', $out);
        $this->assertStringNotContainsString('alert(', $out);
        $this->assertStringContainsString('<path', $out);
    }

    public function testEventHandlersDropped(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<path onclick="y()" d="M0 0"/>', 'onload="x()"'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('d="M0 0"', $out);
    }

    public function testUnsafeHrefDropped(): void
    {
        $out = SvgSanitizer::sanitize($this->svg(
            '<use href="javascript:x"/><use xlink:href="data:text/html,&lt;b&gt;"/><use href="#a"/><use href=" jav&#x09;ascript:x"/>'
        ));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('data:', $out);
        $this->assertStringContainsString('href="#a"', $out);
    }

    public function testSmilRetargetingDropped(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<a><animate attributeName="href" values="javascript:x"/><set attributeName="xlink:href" to="javascript:x"/></a><path d="M0 0"/>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('<animate', $out);
        $this->assertStringNotContainsString('<set', $out);
        $this->assertStringContainsString('<path', $out);
    }

    public function testUnsafeStyleDropped(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<path style="background:url(javascript:x)" d="M0 0"/>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('style=', $out);
    }

    public function testExternalReferencesRemoved(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<image href="https://evil.test/a.png"/><style>@import url(//evil.test/x.css);</style><path d="M0 0"/>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function testOversizeReturnsNull(): void
    {
        $this->assertNull(SvgSanitizer::sanitize($this->svg('<!-- ' . str_repeat('a', 40000) . ' -->')));
    }

    public function testNonSvgRootReturnsNull(): void
    {
        $this->assertNull(SvgSanitizer::sanitize('<html><body/></html>'));
        $this->assertNull(SvgSanitizer::sanitize('not xml at all'));
        $this->assertNull(SvgSanitizer::sanitize(''));
    }

    public function testDoctypeAndEntitiesRejected(): void
    {
        $this->assertNull(SvgSanitizer::sanitize('<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"/>'));
        $this->assertNull(SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><!ENTITY x "y"></svg>'));
        $this->assertNull(SvgSanitizer::sanitize('<!doctype svg [<!entity x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"/>'));
    }

    public function testStyleElementsRemovedUnconditionally(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<style>path{mask-image:image-set("https://evil.test/x.png" 1x)}</style><style>.a{fill:red}</style><style>@\69mport "https://evil.test/a.css";</style><path d="M0 0"/>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('<style', $out);
        $this->assertStringNotContainsString('evil.test', $out);
        $this->assertStringContainsString('<path', $out);
    }

    public function testImageSetAndCrossFadeInStyleAttributeDropped(): void
    {
        $out = SvgSanitizer::sanitize($this->svg(
            '<path style="mask-image:-webkit-image-set(&quot;https://evil.test/y.png&quot; 1x)" d="M0 0"/>'
            . '<path style="background:cross-fade(red, blue)" d="M1 1"/>'
        ));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('evil.test', $out);
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringContainsString('d="M1 1"', $out);
    }

    public function testSmilTargetingStyleOrClassDropped(): void
    {
        $out = SvgSanitizer::sanitize($this->svg(
            '<path d="M0 0"><set attributeName="style" to="mask-image:image-set(&quot;https://evil.test/z&quot; 1x)"/>'
            . '<animate attributeName="class" to="x"/><animateTransform attributeName="style" to="y"/></path>'
        ));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('evil.test', $out);
        $this->assertStringNotContainsString('<set', $out);
        $this->assertStringNotContainsString('<animate', $out);
    }

    public function testImageAndFeImageHrefs(): void
    {
        $out = SvgSanitizer::sanitize($this->svg(
            '<image xlink:href="https://evil.test/a.png"/><feImage xlink:href="https://evil.test/b.png"/>'
            . '<image xlink:href="#ok1"/><feImage href="#ok2"/>'
        ));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('evil.test', $out);
        $this->assertStringContainsString('xlink:href="#ok1"', $out);
        $this->assertStringContainsString('href="#ok2"', $out);
    }

    public function testFillUrlExternalDroppedFragmentKept(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<path fill="url(https://evil.test/g)" d="M0 0"/><path fill="url(#g)" d="M1 1"/>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('evil.test', $out);
        $this->assertStringContainsString('fill="url(#g)"', $out);
    }

    public function testAnchorJavascriptHrefDropped(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<a xlink:href="javascript:alert(1)"><path d="M0 0"/></a>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringContainsString('<path', $out);
    }

    public function testCommentsAndProcessingInstructionsRemoved(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<!-- secret --><?xml-stylesheet href="https://evil.test/s.css"?><path d="M0 0"/>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('secret', $out);
        $this->assertStringNotContainsString('<?', $out);
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function testNamespacedScriptRemoved(): void
    {
        $out = SvgSanitizer::sanitize($this->svg('<h:script xmlns:h="http://www.w3.org/1999/xhtml">alert(1)</h:script><path d="M0 0"/>'));
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('alert(', $out);
    }

    public function testUtf16DoctypeRejected(): void
    {
        $doc = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"/>';
        $this->assertNull(SvgSanitizer::sanitize("\xFF\xFE" . mb_convert_encoding($doc, 'UTF-16LE', 'UTF-8')));
        $this->assertNull(SvgSanitizer::sanitize("\xFE\xFF" . mb_convert_encoding($doc, 'UTF-16BE', 'UTF-8')));
    }
}
