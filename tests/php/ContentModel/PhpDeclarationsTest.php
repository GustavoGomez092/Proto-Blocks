<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PhpDeclarations;

require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/stubs/theme.php';
require_once __DIR__ . '/../../../includes/content-model.php';

final class PhpDeclarationsTest extends TestCase
{
    protected function setUp(): void
    {
        PhpDeclarations::reset();
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
    }

    public function testNameDerivesSlugAndRecordsCallSite(): void
    {
        proto_post_type('Case Study'); $line = __LINE__;

        $decl = PhpDeclarations::all()['type']['case-study'] ?? null;
        $this->assertInstanceOf(Declaration::class, $decl);
        $this->assertSame('case-study', $decl->slug);
        $this->assertSame(['name' => 'Case Study'], $decl->data);
        $this->assertSame('php', $decl->origin);
        $this->assertNull($decl->root);
        $this->assertNull($decl->parentType);
        $this->assertStringEndsWith('PhpDeclarationsTest.php:' . $line, $decl->source);
        $this->assertSame([], PhpDeclarations::issues());
    }

    public function testExplicitSlug(): void
    {
        proto_post_type('Resource', ['slug' => 'oit_resource', 'has_archive' => false]);

        $decl = PhpDeclarations::all()['type']['oit_resource'] ?? null;
        $this->assertInstanceOf(Declaration::class, $decl);
        $this->assertSame('Resource', $decl->name());
        $this->assertFalse($decl->data['has_archive']);
    }

    public function testTaxonomy(): void
    {
        proto_taxonomy('Media Type', ['post_types' => ['resource'], 'hierarchical' => true]);

        $decl = PhpDeclarations::all()['taxonomy']['media-type'] ?? null;
        $this->assertInstanceOf(Declaration::class, $decl);
        $this->assertSame(Declaration::TAXONOMY, $decl->kind);
        $this->assertSame(['resource'], $decl->data['post_types']);
    }

    public function testSecondCallWinsWithWarning(): void
    {
        proto_post_type('Product', ['has_archive' => true]);
        proto_post_type('Product', ['has_archive' => 'shop']); $line = __LINE__;

        $decl = PhpDeclarations::all()['type']['product'];
        $this->assertSame('shop', $decl->data['has_archive']);
        $this->assertStringEndsWith(':' . $line, $decl->source);
        $issues = PhpDeclarations::issues();
        $this->assertCount(1, $issues);
        $this->assertSame('duplicate_php', $issues[0]->code);
        $this->assertSame('warning', $issues[0]->level);
        $this->assertSame('product', $issues[0]->slug);
    }

    public function testReservedSlugIsErrorAndNotStored(): void
    {
        proto_post_type('Author');

        $this->assertArrayNotHasKey('author', PhpDeclarations::all()['type'] ?? []);
        $issues = PhpDeclarations::issues();
        $this->assertCount(1, $issues);
        $this->assertSame('slug_reserved', $issues[0]->code);
        $this->assertSame('error', $issues[0]->level);
    }

    public function testInvalidExplicitSlugIsError(): void
    {
        proto_post_type('Thing', ['slug' => '../thing']);

        $this->assertSame([], PhpDeclarations::all()['type'] ?? []);
        $this->assertSame('slug_pattern', PhpDeclarations::issues()[0]->code);
    }

    public function testTrailingNewlineSlugIsRefused(): void
    {
        proto_post_type('X', ['slug' => "x\n"]);

        $this->assertSame([], PhpDeclarations::all()['type']);
        $this->assertSame('slug_pattern', PhpDeclarations::issues()[0]->code);
    }

    public function testDeclaredAfterSealIsIgnoredWithWarning(): void
    {
        PhpDeclarations::seal();
        proto_post_type('Late'); $line = __LINE__;

        $this->assertArrayNotHasKey('late', PhpDeclarations::all()['type'] ?? []);
        $issues = PhpDeclarations::issues();
        $this->assertCount(1, $issues);
        $this->assertSame('declared_too_late', $issues[0]->code);
        $this->assertSame('warning', $issues[0]->level);
        $this->assertStringContainsString('PhpDeclarationsTest.php:' . $line, $issues[0]->message);
    }

    public function testResetUnseals(): void
    {
        PhpDeclarations::seal();
        PhpDeclarations::reset();
        proto_post_type('Early');

        $this->assertArrayHasKey('early', PhpDeclarations::all()['type']);
    }

    public function testIssueToArray(): void
    {
        $issue = new Issue('error', 'slug_reserved', 'Reserved.', '/x.php:3', 'author', 'type');
        $this->assertSame([
            'level' => 'error',
            'code' => 'slug_reserved',
            'message' => 'Reserved.',
            'source' => '/x.php:3',
            'slug' => 'author',
            'kind' => 'type',
        ], $issue->toArray());
    }
}
