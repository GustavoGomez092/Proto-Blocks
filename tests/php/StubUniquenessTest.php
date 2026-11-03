<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every WordPress stand-in is defined in exactly one file. Two guarded copies
 * mean whichever directory PHPUnit loads first decides the shape, so a
 * directory run alone (or in another order) behaves differently. Shared stubs
 * belong in tests/php/bootstrap.php.
 */
final class StubUniquenessTest extends TestCase
{
    public function testNoGlobalStubIsDefinedInMoreThanOneFile(): void
    {
        $root = __DIR__;
        $definedIn = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // Guarded global stubs: if (!function_exists('x')) / if (!class_exists('X')).
            preg_match_all("/if\\s*\\(\\s*!\\s*(function|class)_exists\\(\\s*'([A-Za-z_][A-Za-z0-9_]*)'/", $source, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $definedIn[strtolower($match[2])][$relative] = true;
            }
        }

        $duplicates = [];
        foreach ($definedIn as $name => $where) {
            // Same-directory copies inside one test file (e.g. a namespaced
            // exec() override guarded per test) are not cross-directory traps.
            $dirs = array_unique(array_map(static fn(string $path): string => dirname($path), array_keys($where)));
            if (count($dirs) > 1) {
                $duplicates[$name] = array_keys($where);
            }
        }

        self::assertSame([], $duplicates, 'Move shared stubs into tests/php/bootstrap.php: ' . json_encode($duplicates));
    }
}
