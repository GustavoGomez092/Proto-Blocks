<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\DeclarationTemplate;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;

require_once __DIR__ . '/SafeFileTest.php';

final class SchemaFileWriterTest extends TestCase
{
    private string $tmp;
    private string $child;
    private string $parent;
    private string $outside;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-writer-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->tmp = (string) realpath($tmp);
        $this->child = $this->tmp . '/child';
        $this->parent = $this->tmp . '/parent';
        $this->outside = $this->tmp . '/outside';
        mkdir($this->child);
        mkdir($this->parent);
        mkdir($this->outside);
    }

    protected function tearDown(): void
    {
        SafeFileTest::removeTree($this->tmp);
    }

    private function writer(bool $fileMods = true, ?array $roots = null): SchemaFileWriter
    {
        return new SchemaFileWriter($roots ?? [$this->child, $this->parent], $fileMods);
    }

    private static function product(): string
    {
        return DeclarationTemplate::render('type', 'Product');
    }

    private function assertWriteError(string $code, int $status, callable $fn): WriteException
    {
        try {
            $fn();
        } catch (WriteException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
            $this->assertSame($status, $e->status);
            return $e;
        }
        $this->fail("Expected WriteException $code");
    }

    /** @return list<string> every path under the temp parent */
    private function tree(): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $out[] = substr($file->getPathname(), strlen($this->tmp));
        }
        sort($out);

        return $out;
    }

    private function fileDeclaration(string $root, string $slug = 'product', string $ext = 'jsonc'): Declaration
    {
        $dir = $root . '/proto-types/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/type.' . $ext;
        file_put_contents($file, '{"name": "Product"}');

        return new Declaration('type', $slug, ['name' => 'Product'], Declaration::ORIGIN_FILE, $file, $root);
    }

    // --- security first ---

    public function testWriteRejectsTraversalSlug(): void
    {
        $before = $this->tree();
        foreach (['../evil', 'a/b', '..', '', 'A', "x\0y", 'product/../../x'] as $slug) {
            $this->assertWriteError('invalid_slug', 400, fn () => $this->writer()->write('type', $slug, self::product(), null, null));
        }
        $this->assertSame($before, $this->tree());
    }

    public function testWriteRejectsUnknownKind(): void
    {
        $this->assertWriteError('invalid_slug', 400, fn () => $this->writer()->write('../x', 'product', self::product(), null, null));
        $this->assertSame([], glob($this->child . '/*'));
    }

    public function testWriteRefusesSymlinkedKindFolder(): void
    {
        symlink($this->outside, $this->child . '/proto-types');
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->write('type', 'product', self::product(), null, null));
        $this->assertSame([], scandir($this->outside) === false ? [] : array_values(array_diff(scandir($this->outside), ['.', '..'])));
    }

    public function testWriteRefusesSymlinkedFolder(): void
    {
        mkdir($this->child . '/proto-types');
        symlink($this->outside, $this->child . '/proto-types/product');
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->write('type', 'product', self::product(), null, null));
        $this->assertSame(['.', '..'], scandir($this->outside));
    }

    public function testWriteRefusesSymlinkedFile(): void
    {
        mkdir($this->child . '/proto-types/product', 0777, true);
        $victim = $this->outside . '/victim.jsonc';
        file_put_contents($victim, '{"name": "Victim"}');
        symlink($victim, $this->child . '/proto-types/product/type.jsonc');
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->write('type', 'product', self::product(), null, null));
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->write('type', 'product', self::product(), SchemaFileWriter::hash('{"name": "Victim"}'), null));
        $this->assertSame('{"name": "Victim"}', file_get_contents($victim));
    }

    public function testSymlinkedThemeRootIsWritable(): void
    {
        $real = $this->tmp . '/real-theme';
        mkdir($real);
        symlink($real, $this->tmp . '/theme-link');
        $writer = new SchemaFileWriter([(string) realpath($this->tmp . '/theme-link')], true);
        $result = $writer->write('type', 'product', self::product(), null, null);
        $this->assertSame($real . '/proto-types/product/type.jsonc', $result['path']);
        $this->assertFileExists($real . '/proto-types/product/type.jsonc');
    }

    public function testNonRealpathRootIsRefused(): void
    {
        symlink($this->child, $this->tmp . '/child-link');
        $writer = new SchemaFileWriter([$this->tmp . '/child-link'], true);
        $this->assertWriteError('path_outside_root', 403, fn () => $writer->write('type', 'product', self::product(), null, null));
    }

    public function testWriteRefusesFilterRoot(): void
    {
        $existing = $this->fileDeclaration($this->outside);
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->write('type', 'product', self::product(), SchemaFileWriter::hash('{"name": "Product"}'), $existing));
        $this->assertSame('{"name": "Product"}', file_get_contents($existing->source));
        $this->assertFalse(is_dir($this->child . '/proto-types'));
    }

    public function testWriteRefusesForgedExistingSource(): void
    {
        // A declaration whose source claims to be in a writable root but is not the declaration file.
        file_put_contents($this->child . '/functions.php', '<?php');
        $forged = new Declaration('type', 'product', [], Declaration::ORIGIN_FILE, $this->child . '/functions.php', $this->child);
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->write('type', 'product', self::product(), SchemaFileWriter::hash('<?php'), $forged));
        $this->assertSame('<?php', file_get_contents($this->child . '/functions.php'));

        $otherSlug = $this->fileDeclaration($this->child, 'other');
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->write('type', 'product', self::product(), SchemaFileWriter::hash('{"name": "Product"}'), $otherSlug));
    }

    public function testFileModsDisabled(): void
    {
        $this->assertWriteError('file_mods', 403, fn () => $this->writer(false)->write('type', 'product', self::product(), null, null));
        $this->assertSame([], glob($this->child . '/*'));
        $target = $this->writer(false)->target('type', 'product', null);
        $this->assertFalse($target['writable']);
        $this->assertSame('file_mods', $target['reason']);
    }

    public function testNotWritableRootRefused(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: permissions are not enforced.');
        }
        chmod($this->child, 0555);
        try {
            $target = $this->writer()->target('type', 'product', null);
            $this->assertFalse($target['writable']);
            $this->assertSame('not_writable', $target['reason']);
            $this->assertWriteError('not_writable', 403, fn () => $this->writer()->write('type', 'product', self::product(), null, null));
        } finally {
            chmod($this->child, 0755);
        }
        $this->assertSame([], glob($this->child . '/*'));
    }

    public function testOversize(): void
    {
        $text = str_repeat('{', 10 * 1024 * 1024);
        $this->assertWriteError('file_too_large', 413, fn () => $this->writer()->write('type', 'product', $text, null, null));
        $this->assertWriteError('file_too_large', 413, fn () => $this->writer()->write('type', 'product', str_repeat(' ', FileLoader::MAX_BYTES) . '{"name":"X"}', null, null));
        $this->assertSame([], glob($this->child . '/*'));
    }

    // --- behaviour ---

    public function testCreatesNewFileInFirstRoot(): void
    {
        $text = self::product();
        $result = $this->writer()->write('type', 'product', $text, null, null);
        $this->assertSame($this->child . '/proto-types/product/type.jsonc', $result['path']);
        $this->assertTrue($result['created']);
        $this->assertSame(SchemaFileWriter::hash($text), $result['hash']);
        $this->assertSame(hash('sha256', $text), $result['hash']);
        $this->assertSame('Product', $result['data']['name']);
        $this->assertSame($text, file_get_contents($result['path']));
    }

    public function testTargetForNewDeclaration(): void
    {
        $this->assertSame(
            ['path' => $this->child . '/proto-taxonomies/genre/taxonomy.jsonc', 'writable' => true, 'reason' => null],
            $this->writer()->target('taxonomy', 'genre', null)
        );
    }

    public function testEditsExistingFileInPlace(): void
    {
        $existing = $this->fileDeclaration($this->parent);
        $text = self::product();
        $result = $this->writer()->write('type', 'product', $text, SchemaFileWriter::hash('{"name": "Product"}'), $existing);
        $this->assertSame($existing->source, $result['path']);
        $this->assertFalse($result['created']);
        $this->assertSame($text, file_get_contents($existing->source));
        $this->assertFalse(is_dir($this->child . '/proto-types'));
    }

    public function testEditsExistingJsonFileInPlace(): void
    {
        $existing = $this->fileDeclaration($this->child, 'product', 'json');
        $result = $this->writer()->write('type', 'product', '{"name": "Product"}', SchemaFileWriter::hash('{"name": "Product"}'), $existing);
        $this->assertSame($existing->source, $result['path']);
        $this->assertFileDoesNotExist($this->child . '/proto-types/product/type.jsonc');
    }

    public function testWriteRejectsStaleHash(): void
    {
        $existing = $this->fileDeclaration($this->child);
        $e = $this->assertWriteError('conflict', 409, fn () => $this->writer()->write('type', 'product', self::product(), 'stale', $existing));
        $this->assertSame(SchemaFileWriter::hash('{"name": "Product"}'), $e->data['hash']);
        $this->assertSame('{"name": "Product"}', file_get_contents($existing->source));
    }

    public function testCreateOverAnExistingFileIsAConflict(): void
    {
        $existing = $this->fileDeclaration($this->child);
        $this->assertWriteError('conflict', 409, fn () => $this->writer()->write('type', 'product', self::product(), null, null));
        $this->assertSame('{"name": "Product"}', file_get_contents($existing->source));
    }

    public function testInvalidJsonc(): void
    {
        $e = $this->assertWriteError('invalid_jsonc', 400, fn () => $this->writer()->write('type', 'product', "{\n  \"name\": \"X\",\n  oops\n}", null, null));
        $this->assertSame(3, $e->data['line']);
        $this->assertArrayHasKey('column', $e->data);
    }

    public function testSchemaInvalid(): void
    {
        $e = $this->assertWriteError('schema_invalid', 400, fn () => $this->writer()->write('type', 'product', '{"name": "X", "public": "yes"}', null, null));
        $this->assertContains('/public', array_column($e->data['errors'], 'path'));
        $this->assertSame([], glob($this->child . '/*'));
    }

    public function testTaxonomyIsValidatedAgainstTaxonomySchema(): void
    {
        $this->assertWriteError('schema_invalid', 400, fn () => $this->writer()->write('taxonomy', 'genre', '{"name": "Genre", "post_types": "book"}', null, null));
    }

    public function testSlugMismatch(): void
    {
        $this->assertWriteError('slug_mismatch', 400, fn () => $this->writer()->write('type', 'product', '{"name": "X", "slug": "other"}', null, null));
        $result = $this->writer()->write('type', 'product', '{"name": "X", "slug": "product"}', null, null);
        $this->assertTrue($result['created']);
    }

    public function testEditingPhpDeclarationCreatesFile(): void
    {
        $php = new Declaration('type', 'product', ['name' => 'Product'], Declaration::ORIGIN_PHP, '/theme/functions.php:12', null);
        $result = $this->writer()->write('type', 'product', self::product(), null, $php);
        $this->assertTrue($result['created']);
        $this->assertSame($this->child . '/proto-types/product/type.jsonc', $result['path']);
    }

    public function testNoTempFilesLeftBehind(): void
    {
        mkdir($this->child . '/proto-types/product/type.jsonc', 0777, true);
        $writer = $this->writer();
        try {
            $writer->write('type', 'product', self::product(), null, null);
        } catch (WriteException) {
            // conflict, write_failed: either way nothing may be left over
        }
        $this->assertSame([], SafeFileTest::tempFiles($this->tmp));
    }

    // --- delete ---

    public function testDeleteRemovesFolderWithKnownFiles(): void
    {
        $existing = $this->fileDeclaration($this->child);
        file_put_contents($this->child . '/proto-types/product/icon.svg', '<svg/>');
        $result = $this->writer()->delete('type', 'product', $existing);
        $this->assertSame($existing->source, $result['deleted']);
        $this->assertSame([], $result['kept']);
        $this->assertDirectoryDoesNotExist($this->child . '/proto-types/product');
        $this->assertDirectoryExists($this->child . '/proto-types');
    }

    public function testDeleteKeepsFolderWithOtherFiles(): void
    {
        $existing = $this->fileDeclaration($this->child);
        file_put_contents($this->child . '/proto-types/product/notes.md', 'hi');
        file_put_contents($this->child . '/proto-types/product/icon.svg', '<svg/>');
        $result = $this->writer()->delete('type', 'product', $existing);
        $this->assertSame(['notes.md'], $result['kept']);
        $this->assertFileDoesNotExist($existing->source);
        $this->assertFileExists($this->child . '/proto-types/product/notes.md');
        $this->assertFileExists($this->child . '/proto-types/product/icon.svg');
    }

    public function testDeleteKeepsFolderWithSubfolderOrSymlink(): void
    {
        $existing = $this->fileDeclaration($this->child);
        $victim = $this->outside . '/victim.json';
        file_put_contents($victim, '{}');
        symlink($victim, $this->child . '/proto-types/product/linked.json');
        mkdir($this->child . '/proto-types/product/sub');
        $result = $this->writer()->delete('type', 'product', $existing);
        $this->assertSame(['linked.json', 'sub'], $result['kept']);
        $this->assertFileExists($victim);
    }

    public function testDeleteRefusesSymlinkedFolder(): void
    {
        mkdir($this->child . '/proto-types');
        mkdir($this->outside . '/product');
        file_put_contents($this->outside . '/product/type.jsonc', '{"name": "Product"}');
        symlink($this->outside . '/product', $this->child . '/proto-types/product');
        $existing = new Declaration('type', 'product', ['name' => 'Product'], Declaration::ORIGIN_FILE, $this->child . '/proto-types/product/type.jsonc', $this->child);
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->delete('type', 'product', $existing));
        $this->assertFileExists($this->outside . '/product/type.jsonc');
    }

    public function testDeleteRefusesFilterRootAndFileModsAndPhp(): void
    {
        $existing = $this->fileDeclaration($this->outside);
        $this->assertWriteError('path_outside_root', 403, fn () => $this->writer()->delete('type', 'product', $existing));
        $this->assertFileExists($existing->source);

        $mine = $this->fileDeclaration($this->child);
        $this->assertWriteError('file_mods', 403, fn () => $this->writer(false)->delete('type', 'product', $mine));
        $this->assertFileExists($mine->source);

        $php = new Declaration('type', 'product', [], Declaration::ORIGIN_PHP, '/x.php:1', null);
        $this->assertWriteError('not_a_file', 400, fn () => $this->writer()->delete('type', 'product', $php));

        $this->assertWriteError('invalid_slug', 400, fn () => $this->writer()->delete('type', '../x', $mine));
        $this->assertFileExists($mine->source);
    }
}
