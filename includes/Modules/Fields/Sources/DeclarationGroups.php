<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Sources;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;

/**
 * Field groups in the `fields` array of post type and taxonomy declarations
 * (Phase 1 files, or proto_post_type()/proto_taxonomy() calls). The location
 * is implied by the declaration: `post_type == <slug>` for a type,
 * `taxonomy == <slug>` for a taxonomy. FieldGroup source is
 * 'type:<slug>' / 'taxonomy:<slug>'; path is the declaration file for a file
 * declaration, null for a PHP one. A group with structural errors is dropped
 * (fail closed); its findings surface through FieldsModule::declarationFindings().
 */
final class DeclarationGroups
{
    private readonly \Closure $declarations;

    /**
     * @param callable(): iterable<object> $declarations objects with kind, slug, data, origin, source (Phase 1 Declaration)
     */
    public function __construct(callable $declarations)
    {
        $this->declarations = \Closure::fromCallable($declarations);
    }

    /** @return list<FieldGroup> */
    public function all(): array
    {
        $out = [];
        foreach ($this->declarations() as $declaration) {
            $fields = $declaration->data['fields'] ?? null;
            if (!is_array($fields) || !array_is_list($fields)) {
                continue;
            }
            $source = $declaration->kind . ':' . $declaration->slug;
            $path = ($declaration->origin ?? null) === 'file' && is_string($declaration->source ?? null) ? $declaration->source : null;
            foreach ($fields as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $one = GroupNormalizer::normalize($raw, $source, $path, self::implied($declaration));
                if ($one['group'] !== null) {
                    $out[] = $one['group'];
                }
            }
        }

        return $out;
    }

    /**
     * The declarations that can carry field groups: kind 'type' or 'taxonomy'
     * with a slug and a data array.
     *
     * @return list<object>
     */
    public function declarations(): array
    {
        $out = [];
        foreach (($this->declarations)() as $declaration) {
            if (
                is_object($declaration)
                && in_array($declaration->kind ?? null, ['type', 'taxonomy'], true)
                && is_string($declaration->slug ?? null) && $declaration->slug !== ''
                && is_array($declaration->data ?? null)
            ) {
                $out[] = $declaration;
            }
        }

        return $out;
    }

    /** @return array<string, string> the location a declaration implies */
    public static function implied(object $declaration): array
    {
        return $declaration->kind === 'taxonomy'
            ? ['taxonomy' => (string) $declaration->slug]
            : ['post_type' => (string) $declaration->slug];
    }
}
