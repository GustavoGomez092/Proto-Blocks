<?php
/**
 * PostTypesRoutes - REST routes for the Post Types screen
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\WriteException;
use WP_Error;
use WP_REST_Request;

/**
 * admin/types: every post type on the site tagged by origin (ListResult),
 * one type's detail (TypeDetail), and the block-template scaffold.
 *
 * - GET  admin/types                  → ListResult
 * - GET  admin/types/<slug>           → TypeDetail; 400 proto_blocks_invalid_slug, 404 proto_blocks_not_found
 * - POST admin/types/<slug>/scaffold  (writePermission) body {taxonomies: bool} → {written, skipped};
 *   400 invalid_slug, 404 not_found, 409 proto_blocks_classic_theme, 403 proto_blocks_file_mods /
 *   proto_blocks_path_outside_root / proto_blocks_not_writable
 */
final class PostTypesRoutes extends ContentModelRoutes
{
    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE . '/types';
        $slug = '(?P<slug>[a-z][a-z0-9_-]{0,19})';

        register_rest_route($namespace, $base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route($namespace, $base . '/' . $slug, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'show'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route($namespace, $base . '/' . $slug . '/scaffold', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'scaffold'],
                'permission_callback' => $writePermission,
            ],
        ]);
    }

    /** @return array<string, mixed> ListResult */
    public function index(): array
    {
        return $this->listResult(Declaration::TYPE);
    }

    /** @return array<string, mixed>|WP_Error TypeDetail */
    public function show(WP_REST_Request $request)
    {
        $slug = self::urlParam($request, 'slug');
        $object = is_string($slug) ? self::registeredObject(Declaration::TYPE, $slug) : null;
        $error = self::slugError(Declaration::TYPE, $slug, $object !== null);
        if ($error !== null) {
            return $error;
        }
        $declaration = $this->registry()->get(Declaration::TYPE, $slug);
        if ($object === null && $declaration === null) {
            return self::notFound(Declaration::TYPE);
        }

        $effective = self::effective(Declaration::TYPE, $object, $declaration);
        $content = $this->guardrails()->contentFor(Declaration::TYPE, $slug, $declaration?->data);

        try {
            $names = $this->scaffolder()->plan($slug, self::hasArchive($effective), $this->taxonomySlugs($slug), true);
        } catch (WriteException) {
            $names = [];
        }
        $templates = [];
        foreach ($names as $name) {
            $templates[] = ['name' => $name, 'exists' => self::templateExists($name)];
        }

        return [
            'item' => $this->listItem(Declaration::TYPE, $slug, $object, $declaration),
            'effective' => $effective,
            'content' => ['count' => $content['count'], 'taxonomies' => self::map($content['taxonomies'])],
            'blockTheme' => (bool) wp_is_block_theme(),
            'templates' => $templates,
        ];
    }

    /**
     * Write single-/archive-/taxonomy- templates into the active theme. Never
     * overwrites; existing templates come back in `skipped`.
     *
     * @return array{written: list<string>, skipped: list<string>}|WP_Error
     */
    public function scaffold(WP_REST_Request $request)
    {
        $slug = self::urlParam($request, 'slug');
        $error = self::slugError(Declaration::TYPE, $slug);
        if ($error !== null) {
            return $error;
        }
        $object = self::registeredObject(Declaration::TYPE, $slug);
        $declaration = $this->registry()->get(Declaration::TYPE, $slug);
        if ($object === null && $declaration === null) {
            return self::notFound(Declaration::TYPE);
        }

        $withTaxonomies = in_array($request->get_param('taxonomies'), [true, 1, '1', 'true'], true);
        $effective = self::effective(Declaration::TYPE, $object, $declaration);

        try {
            return $this->scaffolder()->scaffold($slug, self::hasArchive($effective), $this->taxonomySlugs($slug), $withTaxonomies);
        } catch (WriteException $e) {
            return self::writeError($e);
        }
    }

    /**
     * Declared taxonomies (standalone or inline) attached to $type.
     *
     * @return list<string>
     */
    private function taxonomySlugs(string $type): array
    {
        $out = [];
        foreach ($this->registry()->taxonomies() as $slug => $taxonomy) {
            $types = $taxonomy->data['post_types'] ?? [];
            if (is_array($types) && in_array($type, $types, true)) {
                $out[] = (string) $slug;
            }
        }

        return $out;
    }

    /** @param array<string, mixed>|\stdClass $effective */
    private static function hasArchive(array|\stdClass $effective): bool
    {
        return is_array($effective) && !empty($effective['has_archive']);
    }

    private static function templateExists(string $name): bool
    {
        foreach (array_unique([(string) get_stylesheet_directory(), (string) get_template_directory()]) as $base) {
            if ($base === '') {
                continue;
            }
            $path = $base . '/templates/' . $name;
            if (file_exists($path) || is_link($path)) {
                return true;
            }
        }

        return false;
    }
}
