<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * One declared post type or taxonomy, from a JSONC file or a PHP call.
 */
final class Declaration
{
    public const TYPE = 'type';
    public const TAXONOMY = 'taxonomy';

    public const ORIGIN_FILE = 'file';
    public const ORIGIN_PHP = 'php';

    /**
     * @param string               $kind       self::TYPE or self::TAXONOMY
     * @param array<string, mixed> $data       Declaration data as written (no defaults applied).
     * @param string               $origin     'file' or 'php'
     * @param string               $source     Absolute file path, or "<file>:<line>" of the PHP call.
     * @param string|null          $root       Schema root the file was found in; null for PHP.
     * @param string|null          $parentType Set for a taxonomy declared inline in a type.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $slug,
        public readonly array $data,
        public readonly string $origin,
        public readonly string $source,
        public readonly ?string $root,
        public readonly ?string $parentType = null
    ) {
    }

    public function name(): ?string
    {
        $name = $this->data['name'] ?? null;
        return is_string($name) ? $name : null;
    }
}
