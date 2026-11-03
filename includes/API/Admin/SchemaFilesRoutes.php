<?php
/**
 * SchemaFilesRoutes - read, save and delete declaration files
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\DeclarationTemplate;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\JsoncException;
use ProtoBlocks\ContentModel\SchemaCheck;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;
use WP_Error;
use WP_REST_Request;

/**
 * admin/schema-files/<kind>/<slug> (kind `type`, `taxonomy` or `options`): the
 * boundary between the admin UI and the theme's JSONC declaration files.
 * Every write goes through SchemaFileWriter (validation, confinement to the
 * theme roots, hash check, atomic swap); this class adds the slug check, the content guardrails and
 * the typed-slug confirmation for deletes.
 *
 * - GET admin/schema-files/<kind>/<slug> (permission), query `name?` → FileResult
 * - POST|PUT|PATCH admin/schema-files/<kind>/<slug> (writePermission), body
 *   {text: string, hash: string|null, acknowledge: string[]} → {path, hash, created, warnings: Issue[]}
 *   400 proto_blocks_invalid_slug / _invalid_request / _invalid_jsonc / _schema_invalid / _slug_mismatch,
 *   403 proto_blocks_file_mods / _multisite / _path_outside_root / _not_writable, 409 proto_blocks_conflict {hash},
 *   409 proto_blocks_needs_confirmation {guardrails}, 413 proto_blocks_file_too_large, 500 proto_blocks_write_failed
 * - DELETE admin/schema-files/<kind>/<slug> (writePermission), query `confirm` (the typed slug; the client's
 *   adminDelete sends no body) → {deleted, kept}
 *   400 proto_blocks_invalid_slug, 403 proto_blocks_file_mods / _multisite / _path_outside_root / _not_writable,
 *   404 proto_blocks_not_found, 409 proto_blocks_not_a_file (PHP or inline declaration),
 *   409 proto_blocks_confirm_required {content}, 500 proto_blocks_write_failed; from the writer also
 *   400 proto_blocks_not_a_file
 */
final class SchemaFilesRoutes extends ContentModelRoutes
{
    /** Longest `name` accepted for the new-file template, in characters. */
    private const NAME_MAX = 100;

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        // The pattern only bounds the characters; slugError() applies each kind's own grammar and length.
        register_rest_route($namespace, '/' . AdminRestController::BASE . '/schema-files/(?P<kind>type|taxonomy|options)/(?P<slug>[a-z][a-z0-9_-]{0,63})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get'],
                'permission_callback' => $permission,
            ],
            [
                // WP_REST_Server::EDITABLE: the client's adminPost sends POST; PUT is accepted too.
                'methods' => self::EDITABLE,
                'callback' => [$this, 'save'],
                'permission_callback' => $writePermission,
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete'],
                'permission_callback' => $writePermission,
            ],
        ]);
    }

    /** @return array<string, mixed>|WP_Error FileResult */
    public function get(WP_REST_Request $request)
    {
        $kind = self::urlParam($request, 'kind');
        $slug = self::urlParam($request, 'slug');
        $error = self::slugError($kind, $slug);
        if ($error !== null) {
            return $error;
        }

        $declaration = $this->registry()->get($kind, $slug);
        $file = self::fileDeclaration($declaration);
        $target = $this->writer()->target($kind, $slug, $file);

        $path = $file !== null ? $file->source : $target['path'];
        $text = null;
        if ($file !== null) {
            $text = self::read($file->source);
        } elseif ($target['path'] !== '' && self::isOwnFile($target['path'])) {
            // A file the loader rejected (broken JSONC, schema errors): serve it so it can be fixed.
            $text = self::read($target['path']);
        }

        if ($text !== null) {
            $data = self::decode($text) ?? ($file !== null ? $file->data : []);
            $hash = SchemaFileWriter::hash($text);
        } else {
            $text = DeclarationTemplate::render($kind, $this->templateName($request, $slug, $declaration));
            $data = self::decode($text) ?? [];
            $hash = null;
        }

        return [
            'exists' => $hash !== null,
            'path' => self::displayPath($path),
            'text' => $text,
            'hash' => $hash,
            'data' => self::map($data),
            'writable' => $target['writable'],
            'reason' => $target['reason'],
        ];
    }

    /**
     * Save a declaration file. Content guardrails run before anything is
     * written; any finding not listed in `acknowledge` returns 409.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function save(WP_REST_Request $request)
    {
        $kind = self::urlParam($request, 'kind');
        $slug = self::urlParam($request, 'slug');
        $error = self::slugError($kind, $slug);
        if ($error !== null) {
            return $error;
        }

        $text = $request->get_param('text');
        $hash = $request->get_param('hash');
        $acknowledge = $request->get_param('acknowledge') ?? [];
        if (!is_string($text) || ($hash !== null && !is_string($hash)) || !is_array($acknowledge)) {
            return new WP_Error(
                'proto_blocks_invalid_request',
                __('Send the file as {text: string, hash: string|null, acknowledge: string[]}.', 'proto-blocks'),
                ['status' => 400]
            );
        }
        $acknowledge = array_values(array_filter($acknowledge, 'is_string'));

        $declaration = $this->registry()->get($kind, $slug);
        $file = self::fileDeclaration($declaration);
        $target = $this->writer()->target($kind, $slug, $file);
        if (!$target['writable']) {
            return $this->unwritable($target);
        }

        // The hash first: a stale editor must reload before it is asked to confirm anything.
        if (is_file($target['path'])) {
            $current = (string) hash_file('sha256', $target['path']);
            if ($hash === null || !hash_equals($current, $hash)) {
                return self::writeError(new WriteException(
                    'conflict',
                    409,
                    __('The file changed on disk since it was loaded. Reload it and apply your changes again.', 'proto-blocks'),
                    ['hash' => $current]
                ));
            }
        }

        // Guardrails only on data the writer would accept; otherwise its validation error is the answer.
        $after = self::validData($kind, $slug, $text);
        if ($after !== null) {
            // No declaration yet: Guardrails starts from the registered type (a new override file).
            $findings = $this->guardrails()->checkSave($kind, $slug, $declaration?->data, $after);
            foreach ($findings as $finding) {
                if (!in_array($finding['code'], $acknowledge, true)) {
                    return new WP_Error(
                        'proto_blocks_needs_confirmation',
                        __('This change affects existing content. Confirm to save it.', 'proto-blocks'),
                        ['status' => 409, 'guardrails' => $findings]
                    );
                }
            }
        }

        try {
            $result = $this->writer()->write($kind, $slug, $text, $hash, $file);
        } catch (WriteException $e) {
            return self::writeError($e);
        }
        $this->registry()->invalidate();

        return [
            'path' => self::displayPath($result['path']),
            'hash' => $result['hash'],
            'created' => $result['created'],
            'warnings' => $this->issuesFor($kind, $slug),
        ];
    }

    /**
     * Delete a declaration file (and its folder when nothing else is in it).
     * When the type or taxonomy still holds content, `confirm` must be the slug.
     *
     * @return array{deleted: string, kept: list<string>}|WP_Error
     */
    public function delete(WP_REST_Request $request)
    {
        $kind = self::urlParam($request, 'kind');
        $slug = self::urlParam($request, 'slug');
        $error = self::slugError($kind, $slug);
        if ($error !== null) {
            return $error;
        }
        if ($this->writer()->target($kind, $slug, null)['reason'] === 'file_mods') {
            return AdminRestController::writeBlockedError();
        }

        $declaration = $this->registry()->get($kind, $slug);
        if ($declaration === null) {
            return new WP_Error('proto_blocks_not_found', __('There is no declaration file to delete.', 'proto-blocks'), ['status' => 404]);
        }
        if ($declaration->origin !== Declaration::ORIGIN_FILE) {
            return new WP_Error(
                'proto_blocks_not_a_file',
                sprintf(
                    /* translators: %s: file and line of the PHP call */
                    __('This declaration is made in PHP (%s); remove the call from the theme instead.', 'proto-blocks'),
                    self::displayPath($declaration->source)
                ),
                ['status' => 409]
            );
        }
        if ($declaration->parentType !== null) {
            return new WP_Error(
                'proto_blocks_not_a_file',
                sprintf(
                    /* translators: %s: post type slug */
                    __('This taxonomy is declared inside the post type "%s"; remove it from that file instead.', 'proto-blocks'),
                    $declaration->parentType
                ),
                ['status' => 409]
            );
        }

        $content = $this->guardrails()->contentFor($kind, $slug, $declaration->data);
        $hasContent = $content['count'] > 0 || array_sum($content['taxonomies']) > 0;
        if ($hasContent && $request->get_param('confirm') !== $slug) {
            return new WP_Error(
                'proto_blocks_confirm_required',
                __('Content still uses this declaration. Type the slug to confirm; the content itself is kept.', 'proto-blocks'),
                ['status' => 409, 'content' => ['count' => $content['count'], 'taxonomies' => self::map($content['taxonomies'])]]
            );
        }

        try {
            $result = $this->writer()->delete($kind, $slug, $declaration);
        } catch (WriteException $e) {
            return self::writeError($e);
        }
        $this->registry()->invalidate();

        return ['deleted' => self::displayPath($result['deleted']), 'kept' => $result['kept']];
    }

    /**
     * The error for a save target that cannot be written.
     *
     * @param array{path: string, writable: bool, reason: ?string} $target
     */
    private function unwritable(array $target): WP_Error
    {
        return match ($target['reason']) {
            'file_mods' => AdminRestController::writeBlockedError(),
            'not_writable' => new WP_Error(
                'proto_blocks_not_writable',
                /* translators: %s: path */
                sprintf(__('The theme folder is not writable: %s', 'proto-blocks'), self::displayPath($target['path'])),
                ['status' => 403]
            ),
            default => new WP_Error(
                'proto_blocks_path_outside_root',
                __('Declarations can only be written inside the active theme or its parent theme.', 'proto-blocks'),
                ['status' => 403]
            ),
        };
    }

    /** The name the new-file template starts from. */
    private function templateName(WP_REST_Request $request, string $slug, ?Declaration $declaration): string
    {
        $name = $request->get_param('name');
        if (is_string($name)) {
            $name = mb_substr(sanitize_text_field($name), 0, self::NAME_MAX);
            if ($name !== '') {
                return $name;
            }
        }

        // An options page is named by its title.
        $title = $declaration?->kind === Declaration::OPTIONS ? ($declaration->data['title'] ?? null) : null;
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return $declaration?->name() ?? ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Decoded $text when the writer would accept it (JSONC, schema, slug key), else null.
     *
     * @return array<string|int, mixed>|null
     */
    private static function validData(string $kind, string $slug, string $text): ?array
    {
        if (strlen($text) > FileLoader::MAX_BYTES) {
            return null;
        }
        $data = self::decode($text);
        if ($data === null || SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/' . $kind) !== []) {
            return null;
        }
        if (SchemaFileWriter::declarationErrors($kind, $data, $slug) !== []) {
            return null;
        }
        if (array_key_exists('slug', $data) && $data['slug'] !== $slug) {
            return null;
        }

        return $data;
    }

    /** @return array<string|int, mixed>|null */
    private static function decode(string $text): ?array
    {
        try {
            return Jsonc::decode($text);
        } catch (JsoncException) {
            return null;
        }
    }

    /** A regular file whose path has no symlink in it (the writer's roots are realpaths). */
    private static function isOwnFile(string $path): bool
    {
        return is_file($path) && !is_link($path) && realpath($path) === $path;
    }

    private static function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $text = @file_get_contents($path, false, null, 0, FileLoader::MAX_BYTES + 1);

        return is_string($text) && strlen($text) <= FileLoader::MAX_BYTES ? $text : null;
    }
}
