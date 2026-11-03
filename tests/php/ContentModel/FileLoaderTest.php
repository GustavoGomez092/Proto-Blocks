<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;


final class FileLoaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/content-model/themes';

    private string $tmp;
    private string $parent;
    private string $child;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pb-fileloader-' . bin2hex(random_bytes(6));
        mkdir($this->tmp);
        $this->tmp = (string) realpath($this->tmp);
        self::copyTree(self::FIXTURES, $this->tmp);
        $this->parent = $this->tmp . '/parent';
        $this->child = $this->tmp . '/child';
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    public function testLoadsJsoncAndJson(): void
    {
        $result = (new FileLoader())->load([$this->parent]);

        $product = $result['declarations']['type']['product'] ?? null;
        $genre = $result['declarations']['taxonomy']['genre'] ?? null;
        $this->assertInstanceOf(Declaration::class, $product);
        $this->assertInstanceOf(Declaration::class, $genre);
        $this->assertSame('product', $product->slug);
        $this->assertSame('genre', $genre->slug);
        $this->assertSame('file', $product->origin);
        $this->assertSame('file', $genre->origin);
        $this->assertSame(Declaration::TYPE, $product->kind);
        $this->assertSame(Declaration::TAXONOMY, $genre->kind);
        $this->assertSame('Product', $product->name());
        $this->assertSame(['title', 'editor'], $product->data['supports']);
        $this->assertSame($this->parent . '/proto-types/product/type.jsonc', $product->source);
        $this->assertSame($this->parent, $product->root);
        $this->assertSame([], $result['issues']);
    }

    public function testJsoncWinsOverJsonWithWarning(): void
    {
        file_put_contents($this->parent . '/proto-types/product/type.json', '{"name": "From Json"}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertSame('Product', $result['declarations']['type']['product']->name());
        $issue = $this->issue($result, 'both_extensions');
        $this->assertSame('warning', $issue->level);
        $this->assertSame('product', $issue->slug);
    }

    public function testFileForPrefersJsonc(): void
    {
        $dir = $this->parent . '/proto-types/product';
        $this->assertSame($dir . '/type.jsonc', FileLoader::fileFor($dir, 'type'));
        unlink($dir . '/type.jsonc');
        $this->assertNull(FileLoader::fileFor($dir, 'type'));
        file_put_contents($dir . '/type.json', '{"name": "Product"}');
        $this->assertSame($dir . '/type.json', FileLoader::fileFor($dir, 'type'));
    }

    public function testInvalidFileIsSkippedOthersLoad(): void
    {
        $this->write('proto-types/broken/type.jsonc', "{\n  \"name\": \"Broken\"\n");

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('broken', $result['declarations']['type']);
        $this->assertArrayHasKey('product', $result['declarations']['type']);
        $issue = $this->issue($result, 'invalid_jsonc');
        $this->assertSame('error', $issue->level);
        $this->assertStringContainsString('line', $issue->message);
        $this->assertSame('broken', $issue->slug);
        $this->assertStringEndsWith('broken/type.jsonc', $issue->source);
    }

    public function testNamelessOverrideFileLoads(): void
    {
        $this->write('proto-types/post/type.jsonc', '{"template": [["core/paragraph"]]}');
        $this->write('proto-taxonomies/category/taxonomy.jsonc', '{"labels": {"menu_name": "Topics"}}');

        $result = (new FileLoader())->load([$this->parent]);

        $post = $result['declarations']['type']['post'] ?? null;
        $this->assertInstanceOf(Declaration::class, $post);
        $this->assertNull($post->name());
        $this->assertSame([['core/paragraph']], $post->data['template']);
        $this->assertArrayHasKey('category', $result['declarations']['taxonomy']);
        $this->assertNull($result['declarations']['taxonomy']['category']->name());
        $this->assertSame([], $result['issues']);
    }

    public function testInlineTaxonomyWithoutNameIsSchemaError(): void
    {
        $this->write('proto-types/book/type.jsonc', '{"name": "Book", "taxonomies": [{"hierarchical": true}]}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('book', $result['declarations']['type']);
        $this->assertStringContainsString('/taxonomies/0/name', $this->issue($result, 'schema_invalid')->message);
    }

    public function testSchemaErrorsSkipDeclaration(): void
    {
        $this->write('proto-types/widget/type.jsonc', '{"name": "Widget", "public": "yes", "menu_position": "22"}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('widget', $result['declarations']['type']);
        $schemaIssues = array_values(array_filter($result['issues'], static fn(Issue $i) => $i->code === 'schema_invalid'));
        $this->assertCount(2, $schemaIssues);
        $this->assertArrayHasKey('product', $result['declarations']['type']);
    }

    public function testPhpFileInFolderIsIgnored(): void
    {
        $php = $this->write('proto-types/product/type.php', "<?php\nthrow new \\RuntimeException('type.php was executed');\n");

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayHasKey('product', $result['declarations']['type']);
        $this->assertSame($this->parent . '/proto-types/product/type.jsonc', $result['declarations']['type']['product']->source);
        $this->assertNotContains($php, get_included_files());
        $this->assertNotContains(realpath($php), get_included_files());
    }

    public function testPhpOnlyFolderIsNotADeclaration(): void
    {
        $this->write('proto-types/sneaky/type.php', "<?php\nthrow new \\RuntimeException('type.php was executed');\n");

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('sneaky', $result['declarations']['type']);
    }

    public function testOversizeFileIsRejected(): void
    {
        $body = '{"name": "Big", "description": "' . str_repeat('x', 300 * 1024) . '"}';
        $this->write('proto-types/big/type.jsonc', $body);

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('big', $result['declarations']['type']);
        $this->assertSame('error', $this->issue($result, 'file_too_large')->level);
        $this->assertNull($this->find($result, 'invalid_jsonc'));
        $this->assertArrayHasKey('product', $result['declarations']['type']);
    }

    public function testTenMegabyteFileRejectedUnread(): void
    {
        $file = $this->write('proto-types/huge/type.jsonc', '');
        $handle = fopen($file, 'r+');
        ftruncate($handle, 10 * 1024 * 1024);
        fclose($handle);

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $before = memory_get_peak_usage();
        $result = (new FileLoader())->load([$this->parent]);
        $growth = memory_get_peak_usage() - $before;

        $this->assertLessThan(2 * 1024 * 1024, $growth);
        $this->assertArrayNotHasKey('huge', $result['declarations']['type']);
        $this->assertSame('huge', $this->issue($result, 'file_too_large')->slug);
    }

    public function testProtoKeyFileSkipped(): void
    {
        $this->write('proto-types/poison/type.jsonc', '{"name": "Poison", "labels": {"__proto__": {"x": "y"}}}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('poison', $result['declarations']['type']);
        $this->assertSame('poison', $this->issue($result, 'invalid_jsonc')->slug);
        $this->assertArrayHasKey('product', $result['declarations']['type']);
        $this->assertArrayHasKey('genre', $result['declarations']['taxonomy']);
    }

    public function testFolderSlugMismatch(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "slug": "item"}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('product', $result['declarations']['type']);
        $this->assertArrayNotHasKey('item', $result['declarations']['type']);
        $issue = $this->issue($result, 'slug_mismatch');
        $this->assertSame('error', $issue->level);
        $this->assertSame('product', $issue->slug);
    }

    public function testMatchingSlugKeyIsAccepted(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "slug": "product"}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayHasKey('product', $result['declarations']['type']);
        $this->assertSame([], $result['issues']);
    }

    public function testReservedSlugIsError(): void
    {
        $this->write('proto-types/author/type.jsonc', '{"name": "Author"}');
        $this->write('proto-types/post/type.jsonc', '{"name": "Post", "template": [["core/paragraph"]]}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('author', $result['declarations']['type']);
        $issue = $this->issue($result, 'slug_reserved');
        $this->assertSame('error', $issue->level);
        $this->assertSame('author', $issue->slug);
        $this->assertArrayHasKey('post', $result['declarations']['type']);
    }

    public function testBadFolderNameIsPatternError(): void
    {
        $this->write('proto-types/Bad Name/type.jsonc', '{"name": "Bad"}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('Bad Name', $result['declarations']['type']);
        $this->assertSame('error', $this->issue($result, 'slug_pattern')->level);
    }

    public function testTrailingNewlineFolderIsPatternError(): void
    {
        $this->write("proto-types/product\n/type.jsonc", '{"name": "Product"}');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey("product\n", $result['declarations']['type']);
        $this->assertSame("product\n", $this->issue($result, 'slug_pattern')->slug);
        $this->assertArrayHasKey('product', $result['declarations']['type']);
    }

    public function testChildRootReplacesParent(): void
    {
        $result = (new FileLoader())->load([$this->parent, $this->child]);

        $product = $result['declarations']['type']['product'];
        $this->assertSame('shop', $product->data['has_archive']);
        $this->assertSame($this->child, $product->root);
        $this->assertSame($this->child . '/proto-types/product/type.jsonc', $product->source);
        $this->assertArrayHasKey('genre', $result['declarations']['taxonomy']);
        $this->assertSame([], $result['issues']);
    }

    public function testSymlinkedFileOutsideRootSkipped(): void
    {
        $outside = $this->tmp . '/outside.jsonc';
        file_put_contents($outside, '{"name": "Evil"}');
        mkdir($this->parent . '/proto-types/evil');
        symlink($outside, $this->parent . '/proto-types/evil/type.jsonc');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('evil', $result['declarations']['type']);
        $issue = $this->issue($result, 'path_outside_root');
        $this->assertSame('error', $issue->level);
        $this->assertSame('evil', $issue->slug);
        $this->assertArrayHasKey('product', $result['declarations']['type']);
    }

    public function testSymlinkedFolderOutsideRootSkipped(): void
    {
        mkdir($this->tmp . '/elsewhere');
        file_put_contents($this->tmp . '/elsewhere/type.jsonc', '{"name": "Elsewhere"}');
        symlink($this->tmp . '/elsewhere', $this->parent . '/proto-types/elsewhere');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertArrayNotHasKey('elsewhere', $result['declarations']['type']);
        $this->assertSame('elsewhere', $this->issue($result, 'path_outside_root')->slug);
    }

    public function testSymlinkInsideRootIsAllowed(): void
    {
        mkdir($this->parent . '/shared');
        file_put_contents($this->parent . '/shared/book.jsonc', '{"name": "Book"}');
        mkdir($this->parent . '/proto-types/book');
        symlink($this->parent . '/shared/book.jsonc', $this->parent . '/proto-types/book/type.jsonc');

        $result = (new FileLoader())->load([$this->parent]);

        $this->assertSame('Book', $result['declarations']['type']['book']->name());
    }

    public function testSymlinkedRootIsResolved(): void
    {
        $link = $this->tmp . '/linked-theme';
        symlink($this->parent, $link);

        $result = (new FileLoader())->load([$link]);

        $this->assertArrayHasKey('product', $result['declarations']['type']);
        $this->assertSame($this->parent, $result['declarations']['type']['product']->root);
    }

    public function testMissingRootIsIgnored(): void
    {
        $result = (new FileLoader())->load([$this->tmp . '/nope', $this->parent]);

        $this->assertArrayHasKey('product', $result['declarations']['type']);
        $this->assertSame([], $result['issues']);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->parent . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        return $path;
    }

    /** @param array{issues: list<Issue>} $result */
    private function find(array $result, string $code): ?Issue
    {
        foreach ($result['issues'] as $issue) {
            if ($issue->code === $code) {
                return $issue;
            }
        }
        return null;
    }

    /** @param array{issues: list<Issue>} $result */
    private function issue(array $result, string $code): Issue
    {
        $issue = $this->find($result, $code);
        $this->assertNotNull($issue, "Expected an issue with code $code");
        return $issue;
    }

    public static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (scandir($from) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from . '/' . $entry;
            if (is_dir($src)) {
                self::copyTree($src, $to . '/' . $entry);
            } else {
                copy($src, $to . '/' . $entry);
            }
        }
    }

    public static function removeTree(string $dir): void
    {
        if (!file_exists($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir) || !is_dir($dir)) {
            unlink($dir);
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
