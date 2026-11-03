<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

require_once __DIR__ . '/wp-stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\DeclarationTemplate;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaCheck;
use ProtoBlocks\OptionsPages\PageDefinition;
use ProtoBlocks\OptionsPages\PageTemplate;

/**
 * The commented page.jsonc a new options page starts from.
 */
final class PageTemplateTest extends TestCase
{
    public function testRendersCommentedValidJsonc(): void
    {
        $text = PageTemplate::render('Display Settings', [
            'menu' => 'edit.php?post_type=product',
            'capability' => 'manage_woocommerce',
            'storage-option' => 'cadco_display_settings',
        ]);

        $data = Jsonc::decode($text);
        self::assertSame('Display Settings', $data['title']);
        self::assertSame('edit.php?post_type=product', $data['menu']);
        self::assertSame('manage_woocommerce', $data['capability']);
        self::assertSame('array', $data['storage']['mode']);
        self::assertSame('cadco_display_settings', $data['storage']['option']);
        self::assertSame([], $data['fields']);
        self::assertArrayNotHasKey('slug', $data);
        self::assertSame([], SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/options'));
        self::assertStringContainsString('// Where the page appears', $text);

        // The template is a page PageDefinition accepts as is.
        $page = PageDefinition::fromArray('display-settings', $data, 'file');
        self::assertSame('cadco_display_settings', $page->optionName('anything'));
    }

    public function testDefaultsStayCommentedOut(): void
    {
        $text = PageTemplate::render('Shop Notices');
        $data = Jsonc::decode($text);

        self::assertSame(['title' => 'Shop Notices', 'fields' => []], $data);
        self::assertSame([], SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/options'));
        self::assertStringContainsString('// "menu": "top",', $text);
        self::assertStringContainsString('// "capability": "manage_options",', $text);
        self::assertStringEndsWith("}\n", $text);
    }

    public function testSlugOptionIsLive(): void
    {
        $data = Jsonc::decode(PageTemplate::render('Shop Notices', ['slug' => 'shop-notices']));

        self::assertSame('shop-notices', $data['slug']);
    }

    public function testTitleCannotBreakOutOfItsLine(): void
    {
        $title = "Evil\u{2028}\"x\": 1, // \n\"capability\": \"read\"";
        $data = Jsonc::decode(PageTemplate::render($title, ['menu' => "top\n\"capability\": \"read\""]));

        self::assertSame($title, $data['title']);
        self::assertArrayNotHasKey('capability', $data);
        self::assertArrayNotHasKey('x', $data);
    }

    public function testDeclarationTemplateDelegates(): void
    {
        self::assertSame(PageTemplate::render('Shop Notices'), DeclarationTemplate::render('options', 'Shop Notices'));
        self::assertSame(
            PageTemplate::render('Shop Notices', ['slug' => 'shop-notices']),
            DeclarationTemplate::render('options', 'Shop Notices', 'shop-notices')
        );
    }
}
