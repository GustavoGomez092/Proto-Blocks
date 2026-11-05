<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations;

require_once dirname(__DIR__, 4) . '/includes/Modules/Redirects/functions.php';

/**
 * Temp parent/child theme roots for the redirects tests (FileLoaderTest pattern).
 */
abstract class RedirectsTestCase extends TestCase
{
    protected string $tmp = '';
    protected string $parent = '';
    protected string $child = '';

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-redirects-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->tmp = (string) realpath($tmp);
        $this->parent = $this->tmp . '/parent';
        $this->child = $this->tmp . '/child';
        mkdir($this->parent);
        mkdir($this->child);
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
        $GLOBALS['pb_test_theme'] = [
            'template' => $this->parent,
            'stylesheet' => $this->child,
            'template_uri' => 'http://example.test/p',
            'stylesheet_uri' => 'http://example.test/c',
        ];
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
        unset($GLOBALS['pb_test_home_url'], $GLOBALS['pb_test_theme']);
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        self::removeTree($this->tmp);
    }

    protected function put(string $root, string $relative, string $text): string
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $text);

        return $path;
    }

    /** @param list<array<string, mixed>> $rules */
    protected static function groupText(array $rules, array $extra = []): string
    {
        return (string) json_encode($extra + ['rules' => $rules], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** @param list<\ProtoBlocks\ContentModel\Issue> $issues */
    protected static function issueCodes(array $issues): array
    {
        return array_map(static fn($issue): string => $issue->level . ':' . $issue->code, $issues);
    }

    protected static function removeTree(string $path): void
    {
        if ($path === '' || (!is_dir($path) && !is_link($path))) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @chmod($path, 0755);
        @rmdir($path);
    }
}
