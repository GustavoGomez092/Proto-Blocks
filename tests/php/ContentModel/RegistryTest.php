<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;

require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../../../includes/content-model.php';

final class RegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $tmp = sys_get_temp_dir() . '/pb-registry-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        self::removeTree($this->root);
    }

    public function testFileWinsOverPhpAndIsReportedDouble(): void
    {
        proto_post_type('Product', ['has_archive' => false]); $line = __LINE__;
        $file = $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');

        $registry = $this->registry();
        $product = $registry->get('type', 'product');

        $this->assertNotNull($product);
        $this->assertSame('file', $product->origin);
        $this->assertArrayNotHasKey('has_archive', $product->data);

        $doubles = $registry->doubles();
        $this->assertCount(1, $doubles);
        $this->assertSame('type', $doubles[0]['kind']);
        $this->assertSame('product', $doubles[0]['slug']);
        $this->assertSame($file, $doubles[0]['file']);
        $this->assertStringEndsWith('RegistryTest.php:' . $line, $doubles[0]['php']);

        $issue = $this->issue($registry, 'double_declaration');
        $this->assertSame('warning', $issue->level);
        $this->assertStringContainsString($file, $issue->message);
        $this->assertStringContainsString('RegistryTest.php:' . $line, $issue->message);
    }

    public function testPhpOnlyDeclarationIsUsed(): void
    {
        proto_post_type('Event');

        $registry = $this->registry();

        $this->assertSame('php', $registry->get('type', 'event')->origin);
        $this->assertSame([], $registry->doubles());
        $this->assertArrayHasKey('event', $registry->postTypes());
    }

    public function testInlineTaxonomyGetsParentType(): void
    {
        $file = $this->write('proto-types/book/type.jsonc', '{"name": "Book", "taxonomies": ["category", {"name": "Genre"}]}');

        $registry = $this->registry();
        $genre = $registry->get('taxonomy', 'genre');

        $this->assertNotNull($genre);
        $this->assertSame('book', $genre->parentType);
        $this->assertSame(['book'], $genre->data['post_types']);
        $this->assertSame('Genre', $genre->name());
        $this->assertSame('file', $genre->origin);
        $this->assertSame($file, $genre->source);
        $this->assertSame($this->root, $genre->root);
        $this->assertNull($registry->get('taxonomy', 'category'));
        $this->assertArrayHasKey('genre', $registry->taxonomies());
    }

    public function testInlineTaxonomyMergesListedPostTypes(): void
    {
        $this->write('proto-types/book/type.jsonc', '{"name": "Book", "taxonomies": [{"name": "Genre", "slug": "book_genre", "post_types": ["film", "book"]}]}');

        $genre = $this->registry()->get('taxonomy', 'book_genre');

        $this->assertSame(['book', 'film'], $genre->data['post_types']);
    }

    public function testInlineTaxonomyFromPhpType(): void
    {
        proto_post_type('Book', ['taxonomies' => [['name' => 'Genre']]]);

        $genre = $this->registry()->get('taxonomy', 'genre');

        $this->assertSame('php', $genre->origin);
        $this->assertSame('book', $genre->parentType);
    }

    public function testStandaloneTaxonomyBeatsInline(): void
    {
        $this->write('proto-types/book/type.jsonc', '{"name": "Book", "taxonomies": [{"name": "Genre", "hierarchical": true}]}');
        $standalone = $this->write('proto-taxonomies/genre/taxonomy.jsonc', '{"name": "Genre", "post_types": ["film"]}');

        $registry = $this->registry();
        $genre = $registry->get('taxonomy', 'genre');

        $this->assertSame($standalone, $genre->source);
        $this->assertNull($genre->parentType);
        $this->assertArrayNotHasKey('hierarchical', $genre->data);
        $this->assertSame(['film', 'book'], $genre->data['post_types']);
        $issue = $this->issue($registry, 'double_declaration');
        $this->assertSame('warning', $issue->level);
        $this->assertSame('genre', $issue->slug);
        $this->assertSame('taxonomy', $issue->kind);
        $this->assertStringContainsString('"book" was added', $issue->message);
    }

    public function testStandaloneTaxonomyWithoutPostTypesGetsInlineParent(): void
    {
        $this->write('proto-types/book/type.jsonc', '{"name": "Book", "taxonomies": [{"name": "Genre"}]}');
        $this->write('proto-taxonomies/genre/taxonomy.jsonc', '{"name": "Genre"}');

        $this->assertSame(['book'], $this->registry()->get('taxonomy', 'genre')->data['post_types']);
    }

    public function testIdenticalInlineTaxonomiesAreSharedWithoutWarning(): void
    {
        $this->write('proto-types/book/type.jsonc', '{"name": "Book", "taxonomies": [{"name": "Genre", "hierarchical": true, "labels": {"a": "1", "b": "2"}}]}');
        $this->write('proto-types/film/type.jsonc', '{"name": "Film", "taxonomies": [{"labels": {"b": "2", "a": "1"}, "hierarchical": true, "name": "Genre", "post_types": ["series"]}]}');

        $registry = $this->registry();
        $genre = $registry->get('taxonomy', 'genre');

        $this->assertSame('book', $genre->parentType);
        $this->assertSame(['book', 'film'], $genre->data['post_types']);
        $this->assertSame([], $registry->issues());
    }

    public function testConflictingInlineTaxonomiesWarn(): void
    {
        $book = $this->write('proto-types/book/type.jsonc', '{"name": "Book", "taxonomies": [{"name": "Genre", "hierarchical": true}]}');
        $film = $this->write('proto-types/film/type.jsonc', '{"name": "Film", "taxonomies": [{"name": "Genre", "hierarchical": false}]}');

        $registry = $this->registry();
        $genre = $registry->get('taxonomy', 'genre');

        $this->assertSame('book', $genre->parentType);
        $this->assertTrue($genre->data['hierarchical']);
        $this->assertSame(['book', 'film'], $genre->data['post_types']);
        $issue = $this->issue($registry, 'double_declaration');
        $this->assertStringContainsString($book, $issue->message);
        $this->assertStringContainsString($film, $issue->message);
    }

    public function testInvalidateReloads(): void
    {
        $file = $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        $registry = $this->registry();
        $this->assertSame('Product', $registry->get('type', 'product')->name());

        file_put_contents($file, '{"name": "Merchandise"}');
        $this->assertSame('Product', $registry->get('type', 'product')->name(), 'memoised until invalidated');

        $registry->invalidate();
        $this->assertSame('Merchandise', $registry->get('type', 'product')->name());
    }

    public function testRootsClosureIsCalledLazilyAndOnce(): void
    {
        $calls = 0;
        $registry = new Registry(new FileLoader(), function () use (&$calls): array {
            $calls++;
            return [$this->root];
        });
        $this->assertSame(0, $calls);

        $registry->postTypes();
        $registry->taxonomies();
        $registry->issues();
        $this->assertSame(1, $calls);
    }

    public function testFileIssuesAndLatePhpIssuesAreReported(): void
    {
        $this->write('proto-types/author/type.jsonc', '{"name": "Author"}');
        $registry = $this->registry();
        $registry->postTypes();

        PhpDeclarations::seal();
        proto_post_type('Late');

        $codes = array_map(static fn(Issue $i): string => $i->code, $registry->issues());
        $this->assertContains('slug_reserved', $codes);
        $this->assertContains('declared_too_late', $codes);
    }

    private function registry(): Registry
    {
        return new Registry(new FileLoader(), fn(): array => [$this->root]);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        return $path;
    }

    private function issue(Registry $registry, string $code): Issue
    {
        foreach ($registry->issues() as $issue) {
            if ($issue->code === $code) {
                return $issue;
            }
        }
        $this->fail("Expected an issue with code $code");
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
