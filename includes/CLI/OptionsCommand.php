<?php
/**
 * WP-CLI: `wp proto-blocks options list|create`.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\CLI;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\ContentModel\Slug;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\OptionsPages\InvalidDeclaration;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\PageDefinition;
use ProtoBlocks\OptionsPages\PageTemplate;
use WP_CLI;

/**
 * Manage declared settings pages.
 */
final class OptionsCommand
{
    private const COLUMNS = ['slug', 'title', 'menu', 'capability', 'storage', 'origin'];

    private ?OptionsPagesRegistry $registry;

    private ?SchemaFileWriter $writer;

    public function __construct(?OptionsPagesRegistry $registry = null, ?SchemaFileWriter $writer = null)
    {
        $this->registry = $registry;
        $this->writer = $writer;
    }

    /**
     * List declared settings pages.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv. Default table.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function list(array $args, array $assoc): void
    {
        WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $this->rows(), self::COLUMNS);
    }

    /**
     * Create a settings page declaration from the commented template.
     *
     * ## OPTIONS
     *
     * <title>
     * : The page title.
     *
     * [--slug=<slug>]
     * : Defaults to the slugified title.
     *
     * [--menu=<menu>]
     * : "top" or a parent menu slug. Default top.
     *
     * [--capability=<capability>]
     * : Default manage_options.
     *
     * [--storage-option=<name>]
     * : Store all values in one array option of this name.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function create(array $args, array $assoc): void
    {
        $title = trim((string) ($args[0] ?? ''));
        if ($title === '') {
            WP_CLI::error('Usage: wp proto-blocks options create <title> [--slug=<slug>] [--menu=<menu>] [--capability=<cap>] [--storage-option=<name>]');
            return;
        }

        $slug = isset($assoc['slug']) ? trim((string) $assoc['slug']) : PageDefinition::slugFromTitle($title);
        if ($slug === null || $slug === '') {
            WP_CLI::error('A page slug cannot be derived from that title; pass --slug.');
            return;
        }

        $registry = $this->registry();
        $writer = $this->writer();

        $error = self::validateSlug($slug, array_keys($registry->all()));
        // A file already on disk gets the clearer, path-bearing message (a slug the grammar refuses never reaches the disk).
        if ($error === null || str_contains($error, 'already exists')) {
            $target = $writer->target(Declaration::OPTIONS, $slug, null);
            if ($target['path'] !== '' && (file_exists($target['path']) || is_link($target['path']))) {
                WP_CLI::error(self::relative($target['path']) . ' already exists');
                return;
            }
        }
        if ($error !== null) {
            WP_CLI::error($error);
            return;
        }

        $opts = [];
        foreach (['menu', 'capability', 'storage-option'] as $key) {
            if (isset($assoc[$key])) {
                $opts[$key] = (string) $assoc[$key];
            }
        }
        if (isset($assoc['slug'])) {
            $opts['slug'] = $slug;
        }
        $text = PageTemplate::render($title, $opts);

        // The same grammar and protected-name rules a file gets on load and save.
        try {
            $data = Jsonc::decode($text);
            PageDefinition::fromArray($slug, is_array($data) ? $data : [], 'file');
        } catch (InvalidDeclaration $e) {
            $messages = array_map(
                static fn (array $err): string => ($err['path'] !== '' ? $err['path'] . ': ' : '') . $err['message'],
                $e->errors()
            );
            WP_CLI::error(implode("\n", $messages));
            return;
        }

        try {
            $written = $writer->write(Declaration::OPTIONS, $slug, $text, null, null);
        } catch (WriteException $e) {
            WP_CLI::error($e->getMessage());
            return;
        }

        $registry->refresh();
        WP_CLI::success('Created ' . $written['path']);
    }

    /**
     * An error message for a page slug that cannot be created, or null.
     *
     * @param list<string|int> $existing Slugs already declared.
     */
    public static function validateSlug(string $slug, array $existing): ?string
    {
        $code = Slug::error($slug, Declaration::OPTIONS);
        if ($code !== null) {
            return FileLoader::slugMessage($code, $slug, Declaration::OPTIONS);
        }
        if (in_array($slug, array_map('strval', $existing), true)) {
            /* translators: %s: slug */
            return sprintf(__('The page slug "%s" already exists.', 'proto-blocks'), $slug);
        }

        return null;
    }

    /**
     * @return list<array{slug: string, title: string, menu: string, capability: string, storage: string, origin: string}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->registry()->all() as $slug => $page) {
            $rows[] = [
                'slug' => (string) $slug,
                'title' => $page->title,
                'menu' => $page->menu,
                'capability' => $page->capability,
                'storage' => $page->storageMode === PageDefinition::MODE_ARRAY ? 'array:' . $page->storageOption : 'fields',
                'origin' => $page->origin,
            ];
        }

        return $rows;
    }

    private static function relative(string $path): string
    {
        $folder = FileLoader::FOLDERS[Declaration::OPTIONS];
        $at = strrpos($path, '/' . $folder . '/');

        return $at === false ? $path : substr($path, $at + 1);
    }

    private function registry(): OptionsPagesRegistry
    {
        return $this->registry ??= new OptionsPagesRegistry(Plugin::getInstance()->getContentModel());
    }

    private function writer(): SchemaFileWriter
    {
        return $this->writer ??= new SchemaFileWriter(SchemaPaths::writableRoots(), AdminRestController::fileWritesAllowed());
    }
}
