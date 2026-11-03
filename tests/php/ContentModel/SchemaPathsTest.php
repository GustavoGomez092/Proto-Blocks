<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\SchemaPaths;

require_once __DIR__ . '/stubs/theme.php';

final class SchemaPathsTest extends TestCase
{
    private string $tmp;
    private string $parent;
    private string $child;
    private string $extra;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-schemapaths-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->tmp = (string) realpath($tmp);
        foreach (['parent', 'child', 'extra'] as $dir) {
            mkdir($this->tmp . '/' . $dir);
            $this->$dir = $this->tmp . '/' . $dir;
        }
        $GLOBALS['pb_test_filters'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_theme']);
        $GLOBALS['pb_test_filters'] = [];
        foreach (['link', 'parent', 'child', 'extra'] as $entry) {
            $path = $this->tmp . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        rmdir($this->tmp);
    }

    public function testParentThenChild(): void
    {
        $GLOBALS['pb_test_theme'] = ['template' => $this->parent, 'stylesheet' => $this->child];

        $this->assertSame([$this->parent, $this->child], SchemaPaths::roots());
    }

    public function testSameDirectoryIsOneEntry(): void
    {
        $GLOBALS['pb_test_theme'] = ['template' => $this->parent, 'stylesheet' => $this->parent . '/'];

        $this->assertSame([$this->parent], SchemaPaths::roots());
        $this->assertSame([$this->parent], SchemaPaths::writableRoots());
    }

    public function testFilterAppendsRootButNotWritable(): void
    {
        $GLOBALS['pb_test_theme'] = ['template' => $this->parent, 'stylesheet' => $this->child];
        $GLOBALS['pb_test_filters']['proto_blocks_schema_paths'][] = function (array $roots): array {
            $roots[] = $this->extra;
            return $roots;
        };

        $this->assertSame([$this->parent, $this->child, $this->extra], SchemaPaths::roots());
        $writable = SchemaPaths::writableRoots();
        $this->assertNotContains($this->extra, $writable);
        $this->assertEqualsCanonicalizing([$this->parent, $this->child], $writable);
    }

    public function testFilterResultIsNormalised(): void
    {
        $GLOBALS['pb_test_theme'] = ['template' => $this->parent, 'stylesheet' => $this->child];
        $GLOBALS['pb_test_filters']['proto_blocks_schema_paths'][] = fn(array $roots): array => [
            ...$roots,
            $this->tmp . '/missing',
            $this->child . '/../child',
            42,
        ];

        $this->assertSame([$this->parent, $this->child], SchemaPaths::roots());
    }

    public function testNonArrayFilterResultFallsBackToThemeRoots(): void
    {
        $GLOBALS['pb_test_theme'] = ['template' => $this->parent, 'stylesheet' => $this->child];
        $GLOBALS['pb_test_filters']['proto_blocks_schema_paths'][] = fn($roots) => 'nope';

        $this->assertSame([$this->parent, $this->child], SchemaPaths::roots());
    }

    public function testSymlinkedThemeRootResolved(): void
    {
        $link = $this->tmp . '/link';
        symlink($this->child, $link);
        $GLOBALS['pb_test_theme'] = ['template' => $this->parent, 'stylesheet' => $link];

        $this->assertContains($this->child, SchemaPaths::roots());
        $this->assertNotContains($link, SchemaPaths::roots());
        $this->assertContains($this->child, SchemaPaths::writableRoots());
        $this->assertNotContains($link, SchemaPaths::writableRoots());
    }
}
