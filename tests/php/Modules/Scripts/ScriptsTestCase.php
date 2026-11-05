<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Scripts\ScriptsModule;
use ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations;

/**
 * Shared fixture for the scripts tests: a temporary theme root (parent and
 * child when asked), clean request globals and a clean declaration store.
 */
abstract class ScriptsTestCase extends TestCase
{
    private const GLOBALS = [
        'pb_test_query', 'pb_test_hooks', 'pb_test_filters', 'pb_test_actions', 'pb_test_routes',
        'pb_test_options', 'pb_test_users', 'pb_test_roles',
    ];

    protected string $root = '';

    /** @var list<string> */
    private array $made = [];

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
        ScriptsModule::setInstance(null);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        unset($GLOBALS['pb_test_is_admin'], $GLOBALS['pb_test_customize_preview'], $GLOBALS['pb_test_doing_cron'], $GLOBALS['pb_test_doing_ajax'], $GLOBALS['pb_test_home_url']);
        $GLOBALS['pb_test_current_user'] = 0;
        $GLOBALS['pb_test_caps'] = [];
        $_SERVER['REQUEST_URI'] = '/';
        $this->root = $this->mkRoot('pb-scripts-');
        $GLOBALS['pb_test_theme'] = [
            'template' => $this->root,
            'stylesheet' => $this->root,
            'template_uri' => 'http://example.test/t',
            'stylesheet_uri' => 'http://example.test/t',
        ];
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
        ScriptsModule::setInstance(null);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        unset($GLOBALS['pb_test_theme'], $GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_caps']);
        unset($_SERVER['REQUEST_URI']);
        foreach ($this->made as $dir) {
            self::removeTree($dir);
        }
        $this->made = [];
    }

    protected function mkRoot(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir);
        $real = (string) realpath($dir);
        $this->made[] = $real;

        return $real;
    }

    protected function put(string $relative, string $text, ?string $root = null): string
    {
        $path = ($root ?? $this->root) . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $text);

        return $path;
    }

    /** Writes proto-scripts/<slug>/script.jsonc (JSON-encoded $data) and the given snippet files. */
    protected function script(string $slug, array $data, array $files = [], ?string $root = null): string
    {
        $path = $this->put('proto-scripts/' . $slug . '/script.jsonc', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $root);
        foreach ($files as $name => $text) {
            $this->put('proto-scripts/' . $slug . '/' . $name, $text, $root);
        }

        return $path;
    }

    protected function loginAs(int $id, array $roles = [], array $caps = []): void
    {
        $GLOBALS['pb_test_current_user'] = $id;
        $GLOBALS['pb_test_users'][$id] = ['ID' => $id, 'roles' => $roles];
        $GLOBALS['pb_test_caps'][$id] = $caps;
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0755);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
