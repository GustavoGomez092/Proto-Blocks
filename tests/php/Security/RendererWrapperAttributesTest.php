<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Security;

require_once __DIR__ . '/wp-stubs.php';

use DOMDocument;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Template\Renderer;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;

/**
 * Renderer::applyCoreFeatures(): the wrapper id/class/style built from block
 * attributes (contributor-controlled JSON) is sanitised, while the template's
 * own root class/style is kept verbatim.
 */
final class RendererWrapperAttributesTest extends TestCase
{
    private function render(array $attributes): \DOMElement
    {
        $renderer = new Renderer(new FieldRegistry(), new ControlRegistry());
        $html     = $renderer->render(__DIR__ . '/fixtures/wrapper-block.php', $attributes, []);

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $dom->documentElement;
    }

    public function test_style_support_declarations_go_through_safecss_filter_attr(): void
    {
        $root = $this->render(['style' => [
            'color'      => ['text' => 'red;position:fixed;inset:0;z-index:99999', 'background' => '#fff'],
            'typography' => ['lineHeight' => '1.5'],
            'spacing'    => ['margin' => ['top' => '0;position:fixed']],
        ]]);

        $style = $root->getAttribute('style');
        $this->assertStringContainsString('--tpl-var: 1', $style, 'template-authored style is kept');
        $this->assertStringContainsString('color: red', $style);
        $this->assertStringContainsString('background-color: #fff', $style);
        $this->assertStringContainsString('line-height: 1.5', $style);
        $this->assertStringContainsString('margin-top: 0', $style);
        $this->assertStringNotContainsString('position', $style);
        $this->assertStringNotContainsString('z-index', $style);
    }

    public function test_preset_slugs_are_restricted_to_class_safe_characters(): void
    {
        $root = $this->render(['style' => [
            'color'   => ['background' => 'var:preset|color|primary x"'],
            'spacing' => ['padding' => 'var:preset|spacing|40);position:fixed'],
        ]]);

        $this->assertStringContainsString('has-primaryx-background-color', $root->getAttribute('class'));
        $this->assertStringContainsString('padding: var(--wp--preset--spacing--40positionfixed)', $root->getAttribute('style'));
    }

    public function test_anchor_and_preset_classes_are_sanitised_and_classname_keeps_tailwind_tokens(): void
    {
        $root = $this->render([
            'anchor'          => 'my id" onmouseover="x',
            'className'       => 'md:grid-cols-2 w-1/2 bg-[#fff] x"><script>',
            'align'           => 'wide evil',
            'backgroundColor' => 'primary"',
            'textColor'       => ['not', 'a', 'string'],
            'fontSize'        => 'large',
        ]);

        $this->assertSame('myidonmouseoverx', $root->getAttribute('id'));

        $classes = explode(' ', $root->getAttribute('class'));
        $this->assertContains('pb-wrapper-fixture', $classes);
        $this->assertContains('md:flex', $classes, 'template-authored class kept');
        $this->assertContains('md:grid-cols-2', $classes);
        $this->assertContains('w-1/2', $classes);
        $this->assertContains('bg-[#fff]', $classes);
        $this->assertContains('xscript', $classes);
        $this->assertContains('alignwideevil', $classes);
        $this->assertContains('has-primary-background-color', $classes);
        $this->assertContains('has-large-font-size', $classes);
        $this->assertNotContains('has-text-color', $classes, 'non-string textColor is ignored');
        foreach ($classes as $class) {
            $this->assertDoesNotMatchRegularExpression('/[<>"\']/', $class);
        }
    }

    public function test_no_attribute_styles_leaves_template_style_untouched(): void
    {
        $root = $this->render([]);

        $this->assertSame('--tpl-var: 1;', $root->getAttribute('style'));
    }
}
