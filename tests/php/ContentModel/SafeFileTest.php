<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\SafeFile;
use ProtoBlocks\ContentModel\WriteException;

final class SafeFileTest extends TestCase
{
    private string $tmp;
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-safefile-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->tmp = (string) realpath($tmp);
        $this->root = $this->tmp . '/theme';
        $this->outside = $this->tmp . '/outside';
        mkdir($this->root);
        mkdir($this->outside);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    public function testNotYetExistingPathInsideRootIsConfined(): void
    {
        $this->assertTrue(SafeFile::isConfined($this->root . '/proto-types/x/type.jsonc', [$this->root]));
    }

    public function testRootItselfIsNotConfined(): void
    {
        $this->assertFalse(SafeFile::isConfined($this->root, [$this->root]));
    }

    public function testSymlinkedKindFolderIsNotConfined(): void
    {
        symlink($this->outside, $this->root . '/proto-types');
        $this->assertFalse(SafeFile::isConfined($this->root . '/proto-types/x/type.jsonc', [$this->root]));
    }

    public function testSymlinkInsideRootPointingInsideIsStillRefused(): void
    {
        mkdir($this->root . '/real');
        symlink($this->root . '/real', $this->root . '/proto-types');
        $this->assertFalse(SafeFile::isConfined($this->root . '/proto-types/x/type.jsonc', [$this->root]));
    }

    public function testDotDotIsNotConfined(): void
    {
        $this->assertFalse(SafeFile::isConfined($this->root . '/../x', [$this->root]));
        $this->assertFalse(SafeFile::isConfined($this->root . '/proto-types/../../outside/x', [$this->root]));
        $this->assertFalse(SafeFile::isConfined($this->root . '/./x', [$this->root]));
    }

    public function testSiblingWithRootPrefixIsNotConfined(): void
    {
        mkdir($this->root . '-evil');
        $this->assertFalse(SafeFile::isConfined($this->root . '-evil/x', [$this->root]));
    }

    public function testNullByteRelativeAndEmptyRootsAreNotConfined(): void
    {
        $this->assertFalse(SafeFile::isConfined($this->root . "/x\0y", [$this->root]));
        $this->assertFalse(SafeFile::isConfined('proto-types/x', [$this->root]));
        $this->assertFalse(SafeFile::isConfined($this->root . '/x', []));
        $this->assertFalse(SafeFile::isConfined($this->root . '/x', ['']));
        $this->assertFalse(SafeFile::isConfined('/x', ['/']));
    }

    public function testNonRealpathRootIsRefused(): void
    {
        symlink($this->root, $this->tmp . '/theme-link');
        $this->assertFalse(SafeFile::isConfined($this->tmp . '/theme-link/x', [$this->tmp . '/theme-link']));
    }

    public function testWriteAtomicWritesAndLeavesNoTempFile(): void
    {
        $target = $this->root . '/a.jsonc';
        SafeFile::writeAtomic($target, '{"a": 1}');
        $this->assertSame('{"a": 1}', file_get_contents($target));
        $this->assertSame([], self::tempFiles($this->root));
    }

    public function testWriteAtomicFailedRenameLeavesNoTempFile(): void
    {
        $target = $this->root . '/a.jsonc';
        mkdir($target);
        try {
            SafeFile::writeAtomic($target, 'x');
            $this->fail('Expected WriteException');
        } catch (WriteException $e) {
            $this->assertSame('write_failed', $e->errorCode);
            $this->assertSame(500, $e->status);
        }
        $this->assertSame([], self::tempFiles($this->root));
    }

    public function testWriteAtomicRefusesSymlinkedTarget(): void
    {
        $outsideFile = $this->outside . '/victim.txt';
        file_put_contents($outsideFile, 'original');
        symlink($outsideFile, $this->root . '/a.jsonc');
        try {
            SafeFile::writeAtomic($this->root . '/a.jsonc', 'evil');
            $this->fail('Expected WriteException');
        } catch (WriteException $e) {
            $this->assertSame('write_failed', $e->errorCode);
        }
        $this->assertSame('original', file_get_contents($outsideFile));
        $this->assertTrue(is_link($this->root . '/a.jsonc'));
        $this->assertSame([], self::tempFiles($this->root));
    }

    /** @return list<string> */
    public static function tempFiles(string $dir): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (str_contains($file->getFilename(), '.tmp')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    public static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0755);
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
