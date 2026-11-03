<?php
/**
 * TaxonomiesRoutes - REST routes for the Taxonomies screen
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Declaration;
use WP_Error;
use WP_REST_Request;

/**
 * admin/taxonomies: every taxonomy on the site tagged by origin (ListResult)
 * and one taxonomy's detail (TaxDetail). Read-only; files are written
 * through SchemaFilesRoutes.
 *
 * - GET admin/taxonomies         → ListResult
 * - GET admin/taxonomies/<slug>  → TaxDetail; 400 proto_blocks_invalid_slug, 404 proto_blocks_not_found
 */
final class TaxonomiesRoutes extends ContentModelRoutes
{
    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE . '/taxonomies';

        register_rest_route($namespace, $base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route($namespace, $base . '/(?P<slug>[a-z][a-z0-9_-]{0,31})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'show'],
                'permission_callback' => $permission,
            ],
        ]);
    }

    /** @return array<string, mixed> ListResult */
    public function index(): array
    {
        return $this->listResult(Declaration::TAXONOMY);
    }

    /** @return array<string, mixed>|WP_Error TaxDetail */
    public function show(WP_REST_Request $request)
    {
        $slug = self::urlParam($request, 'slug');
        $object = is_string($slug) ? self::registeredObject(Declaration::TAXONOMY, $slug) : null;
        $error = self::slugError(Declaration::TAXONOMY, $slug, $object !== null);
        if ($error !== null) {
            return $error;
        }
        $declaration = $this->registry()->get(Declaration::TAXONOMY, $slug);
        if ($object === null && $declaration === null) {
            return self::notFound(Declaration::TAXONOMY);
        }

        $objectTypes = $object !== null ? ($object->object_type ?? []) : ($declaration->data['post_types'] ?? []);

        return [
            'item' => $this->listItem(Declaration::TAXONOMY, $slug, $object, $declaration),
            'effective' => self::effective(Declaration::TAXONOMY, $object, $declaration),
            'objectTypes' => array_values(array_filter(is_array($objectTypes) ? $objectTypes : [], 'is_string')),
            'content' => ['count' => $this->guardrails()->contentFor(Declaration::TAXONOMY, $slug, null)['count']],
        ];
    }
}
