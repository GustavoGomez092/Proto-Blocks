<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

// Same stub order as AdminRestControllerTest (first definition wins), then
// the content-model stand-ins.
require_once __DIR__ . '/../Security/wp-stubs.php';
require_once __DIR__ . '/../Admin/wp-stubs.php';
require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/content-model-stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Guardrails;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\TemplateScaffolder;
use ProtoBlocks\Modules\Manager;

/**
 * Shared fixture for the content-model route tests: a temporary theme root,
 * a registry over it, a writer confined to it, guardrails over counts the
 * test sets, and a module manager.
 */
abstract class ContentModelRoutesTestCase extends TestCase
{
    private const GLOBALS = [
        'pb_test_hooks', 'pb_test_registered', 'pb_test_post_types', 'pb_test_taxonomies',
        'pb_test_supports', 'pb_test_tax_links', 'pb_test_filters', 'pb_test_routes',
    ];

    protected string $root = '';

    /** @var array<string, int> post type => posts */
    protected array $posts = [];

    /** @var array<string, int> taxonomy => terms */
    protected array $terms = [];

    protected ?Registry $registry = null;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        // A booted Fields module (any earlier test) adds the `field-group` kind process-wide.
        SchemaFileWriter::resetKinds();
        $tmp = sys_get_temp_dir() . '/pb-cm-routes-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['pb_test_theme'] = [
            'template' => $this->root,
            'stylesheet' => $this->root,
            'template_uri' => 'http://example.test/t',
            'stylesheet_uri' => 'http://example.test/t',
        ];
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_block_theme'] = true;
        $this->posts = [];
        $this->terms = [];
        $this->registry = null;
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        unset($GLOBALS['pb_test_theme'], $GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_caps'], $GLOBALS['pb_test_block_theme']);
        // modules() boots the Fields module too: drop its process-wide state.
        \ProtoBlocks\Modules\Fields\FieldsModule::setInstance(null);
        \ProtoBlocks\Modules\Fields\Read\FieldReader::setShared(null);
        SchemaFileWriter::resetKinds();
        if ($this->root !== '') {
            @chmod($this->root, 0755);
            self::removeTree($this->root);
        }
    }

    protected function write(string $relative, string $text): string
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $text);

        return $path;
    }

    protected function registry(): Registry
    {
        return $this->registry ??= new Registry(new FileLoader(), fn (): array => [$this->root]);
    }

    protected function writer(bool $fileMods = true): SchemaFileWriter
    {
        return new SchemaFileWriter([$this->root], $fileMods);
    }

    protected function guardrails(): Guardrails
    {
        return new Guardrails(
            fn (string $type): int => $this->posts[$type] ?? 0,
            fn (string $taxonomy): int => $this->terms[$taxonomy] ?? 0,
            // As ContentModelRoutes::guardrails(): the registered type's supports.
            static fn (string $type): ?array => post_type_exists($type) ? array_keys(get_all_post_type_supports($type)) : null
        );
    }

    protected function scaffolder(bool $blockTheme = true, bool $fileMods = true): TemplateScaffolder
    {
        return new TemplateScaffolder($this->root, $this->root, $blockTheme, $fileMods);
    }

    /**
     * A manager over the fixture registry. Booted (so the module states
     * apply) and, when $register, with both modules' init work run.
     *
     * @param array<string, bool> $states
     */
    protected function modules(array $states = [], bool $register = true): Manager
    {
        if ($states !== []) {
            $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn (array $defaults): array => $states + $defaults;
        }
        $manager = new Manager($this->registry());
        $manager->boot();
        // Booting the Fields module adds the `field-group` kind; these tests pin the
        // Phase 1 kinds (FieldGroupWriteTest covers the extended one).
        SchemaFileWriter::resetKinds();
        if ($register) {
            $manager->postTypes()?->register();
            $manager->taxonomies()?->register();
        }

        return $manager;
    }

    /**
     * @param mixed $result
     */
    protected static function assertWpError(string $code, int $status, $result): \WP_Error
    {
        self::assertInstanceOf(\WP_Error::class, $result, 'expected ' . $code . ', got ' . json_encode($result));
        self::assertSame($code, $result->get_error_code(), $result->get_error_message());
        self::assertSame($status, $result->get_error_data()['status']);

        return $result;
    }

    /**
     * The registered route entry for $route (leading slash ignored).
     *
     * @return array{namespace: string, route: string, args: array<int|string, mixed>}|null
     */
    protected static function route(string $route): ?array
    {
        foreach ($GLOBALS['pb_test_routes'] ?? [] as $entry) {
            if (ltrim((string) $entry['route'], '/') === $route) {
                return $entry;
            }
        }

        return null;
    }

    /** Assert no string anywhere in $data is an absolute path inside the fixture root. */
    protected function assertNoAbsolutePaths(mixed $data): void
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($this->root, $json);
        self::assertStringNotContainsString(sys_get_temp_dir(), $json);
    }

    protected static function removeTree(string $path): void
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
