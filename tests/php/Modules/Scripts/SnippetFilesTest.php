<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts;

use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Modules\Scripts\SnippetFiles;

/**
 * Snippet writer confinement, size cap, PHP refusal, and delete safety.
 */
final class SnippetFilesTest extends ScriptsTestCase
{
    private function snippets(bool $fileMods = true): SnippetFiles
    {
        return new SnippetFiles([$this->root], $fileMods);
    }

    private function dir(string $slug = 'gtm'): string
    {
        $dir = $this->root . '/proto-scripts/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    private static function expectCode(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail('expected ' . $code);
        } catch (WriteException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    public function testWritesAndReadsTheThreeNames(): void
    {
        $dir = $this->dir();
        foreach (['head.html' => '<meta>', 'body-open.html' => '<noscript></noscript>', 'body-close.html' => '<script>1</script>'] as $name => $text) {
            self::assertTrue($this->snippets()->write($dir, $name, $text));
            self::assertSame([$text, null], SnippetFiles::read($dir, $name));
        }
    }

    public function testEmptyTextRemovesTheFile(): void
    {
        $dir = $this->dir();
        $this->snippets()->write($dir, 'head.html', '<meta>');
        self::assertFalse($this->snippets()->write($dir, 'head.html', ''));
        self::assertFileDoesNotExist($dir . '/head.html');
        self::assertFalse($this->snippets()->write($dir, 'head.html', ''), 'removing a missing file is fine');
    }

    public function testOnlyTheThreeNames(): void
    {
        $dir = $this->dir();
        foreach (['script.jsonc', 'evil.php', '../head.html', 'head.html/../x', '.htaccess', 'HEAD.html'] as $name) {
            self::expectCode('path_outside_root', fn () => $this->snippets()->write($dir, $name, 'x'));
        }
        self::assertSame([null, 'invalid_name'], SnippetFiles::read($dir, 'evil.php'));
    }

    public function testOnlyInsideAScriptFolderOfAWritableRoot(): void
    {
        $outside = $this->mkRoot('pb-snip-out-');
        mkdir($outside . '/proto-scripts/gtm', 0777, true);
        self::expectCode('path_outside_root', fn () => $this->snippets()->write($outside . '/proto-scripts/gtm', 'head.html', 'x'));
        mkdir($this->root . '/other/gtm', 0777, true);
        self::expectCode('path_outside_root', fn () => $this->snippets()->write($this->root . '/other/gtm', 'head.html', 'x'));
        self::expectCode('path_outside_root', fn () => $this->snippets()->write($this->root . '/proto-scripts/../proto-scripts/gtm', 'head.html', 'x'));
        self::expectCode('path_outside_root', fn () => $this->snippets()->write($this->root . '/proto-scripts/Bad', 'head.html', 'x'));
        self::assertFileDoesNotExist($outside . '/proto-scripts/gtm/head.html');
    }

    public function testRefusesASymlinkedFolder(): void
    {
        $outside = $this->mkRoot('pb-snip-out-');
        mkdir($this->root . '/proto-scripts', 0777, true);
        symlink($outside, $this->root . '/proto-scripts/gtm');
        self::expectCode('path_outside_root', fn () => $this->snippets()->write($this->root . '/proto-scripts/gtm', 'head.html', 'x'));
        self::assertFileDoesNotExist($outside . '/head.html');
    }

    public function testRefusesASymlinkedTarget(): void
    {
        $outside = $this->mkRoot('pb-snip-out-');
        file_put_contents($outside . '/victim.txt', 'keep');
        $dir = $this->dir();
        symlink($outside . '/victim.txt', $dir . '/head.html');
        self::expectCode('path_outside_root', fn () => $this->snippets()->write($dir, 'head.html', 'x'));
        self::assertSame('keep', file_get_contents($outside . '/victim.txt'));
        self::assertSame([null, 'path_outside_root'], SnippetFiles::read($dir, 'head.html'));
    }

    public function testSizeCapAndPhp(): void
    {
        $dir = $this->dir();
        self::expectCode('file_too_large', fn () => $this->snippets()->write($dir, 'head.html', str_repeat('a', 262145)));
        self::assertTrue($this->snippets()->write($dir, 'head.html', str_repeat('a', 262144)));
        self::expectCode('php_in_snippet', fn () => $this->snippets()->write($dir, 'body-close.html', '<?php echo 1;'));
        self::assertFileDoesNotExist($dir . '/body-close.html');
    }

    public function testFileModsOff(): void
    {
        self::expectCode('file_mods', fn () => $this->snippets(false)->write($this->dir(), 'head.html', 'x'));
    }

    public function testFolderMustExist(): void
    {
        mkdir($this->root . '/proto-scripts');
        self::expectCode('not_found', fn () => $this->snippets()->write($this->root . '/proto-scripts/gtm', 'head.html', 'x'));
    }

    public function testDeletableOnlyWithValidScriptAndKnownFiles(): void
    {
        $dir = $this->dir();
        self::assertFalse(SnippetFiles::deletable($dir), 'no script.jsonc');

        file_put_contents($dir . '/script.jsonc', '{"title":"GTM"}');
        file_put_contents($dir . '/head.html', 'x');
        file_put_contents($dir . '/body-open.html', 'x');
        file_put_contents($dir . '/body-close.html', 'x');
        self::assertTrue(SnippetFiles::deletable($dir));

        file_put_contents($dir . '/notes.md', 'mine');
        self::assertFalse(SnippetFiles::deletable($dir), 'an unknown file');
        unlink($dir . '/notes.md');

        file_put_contents($dir . '/script.jsonc', '{ broken');
        self::assertFalse(SnippetFiles::deletable($dir), 'invalid JSONC');
        file_put_contents($dir . '/script.jsonc', '{"title":"x","head":{"file":"x.php"}}');
        self::assertFalse(SnippetFiles::deletable($dir), 'schema errors');
        file_put_contents($dir . '/script.jsonc', '{"title":"GTM"}');

        mkdir($dir . '/sub');
        self::assertFalse(SnippetFiles::deletable($dir), 'a sub folder');
        rmdir($dir . '/sub');

        $outside = $this->mkRoot('pb-snip-out-');
        unlink($dir . '/head.html');
        symlink($outside, $dir . '/head.html');
        self::assertFalse(SnippetFiles::deletable($dir), 'a symlink');
    }

    public function testRemoveLeftovers(): void
    {
        $dir = $this->dir();
        file_put_contents($dir . '/head.html', 'x');
        file_put_contents($dir . '/body-close.html', 'x');
        self::assertSame([], $this->snippets()->removeLeftovers($dir));
        self::assertDirectoryDoesNotExist($dir);

        $dir = $this->dir('other');
        file_put_contents($dir . '/head.html', 'x');
        file_put_contents($dir . '/keep.txt', 'mine');
        self::assertSame(['keep.txt'], $this->snippets()->removeLeftovers($dir));
        self::assertFileExists($dir . '/head.html', 'nothing removed when something else is there');
    }

    public function testRemoveLeftoversRefusesOutsideFolders(): void
    {
        $outside = $this->mkRoot('pb-snip-out-');
        mkdir($outside . '/proto-scripts/gtm', 0777, true);
        file_put_contents($outside . '/proto-scripts/gtm/head.html', 'x');
        self::expectCode('path_outside_root', fn () => $this->snippets()->removeLeftovers($outside . '/proto-scripts/gtm'));
        self::assertFileExists($outside . '/proto-scripts/gtm/head.html');
    }
}
