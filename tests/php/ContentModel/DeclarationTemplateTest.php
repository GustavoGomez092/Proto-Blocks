<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\DeclarationTemplate;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaCheck;

final class DeclarationTemplateTest extends TestCase
{
    public function testTypeTemplate(): void
    {
        $text = DeclarationTemplate::render('type', 'Product');
        $this->assertStringStartsWith('// Proto-Blocks post type declaration.', $text);
        $this->assertStringContainsString('"name": "Product"', $text);
        foreach (['plural', 'has_archive', 'menu_icon', 'supports', 'rewrite', 'taxonomies'] as $key) {
            $this->assertMatchesRegularExpression('/^\s*\/\/ "' . $key . '": /m', $text, $key);
        }
        $this->assertStringContainsString('"fields": []', $text);

        $data = Jsonc::decode($text);
        $this->assertSame(['name' => 'Product', 'fields' => []], $data);
        $this->assertSame([], SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/type'));
    }

    public function testCommentedExamplesValidateWhenUncommented(): void
    {
        foreach (['type', 'taxonomy'] as $kind) {
            $text = DeclarationTemplate::render($kind, 'Case Study');
            $uncommented = preg_replace('/^(\s*)\/\/ (")/m', '$1$2', $text);
            $data = Jsonc::decode((string) $uncommented);
            $this->assertSame([], SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/' . $kind), $kind);
        }
    }

    public function testTaxonomyTemplate(): void
    {
        $text = DeclarationTemplate::render('taxonomy', 'Genre');
        $this->assertStringStartsWith('// Proto-Blocks taxonomy declaration.', $text);
        $this->assertStringContainsString('"name": "Genre"', $text);
        $this->assertStringContainsString('"post_types": []', $text);

        $data = Jsonc::decode($text);
        $this->assertSame(['name' => 'Genre', 'post_types' => []], $data);
        $this->assertSame([], SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/taxonomy'));
    }

    public function testSlugOnlyWhenPassed(): void
    {
        $this->assertStringNotContainsString('"slug"', DeclarationTemplate::render('type', 'Case Study'));
        $this->assertStringNotContainsString('"slug"', DeclarationTemplate::render('taxonomy', 'Case Study'));

        $text = DeclarationTemplate::render('type', 'Case Study', 'case-study');
        $this->assertStringContainsString('"slug": "case-study"', $text);
        $data = Jsonc::decode($text);
        $this->assertSame('case-study', $data['slug']);
        $this->assertSame([], SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/type'));
    }

    public function testHostileNameStaysAString(): void
    {
        $name = "Evil\" , \"public\": false } // */\n\u{2028}<b>";
        foreach (['type', 'taxonomy'] as $kind) {
            $data = Jsonc::decode(DeclarationTemplate::render($kind, $name));
            $this->assertSame($name, $data['name']);
            $this->assertArrayNotHasKey('public', $data);
        }
    }

    public function testUnknownKindThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeclarationTemplate::render('page', 'X');
    }
}
