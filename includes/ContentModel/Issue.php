<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * One validation finding about a declaration: an error (the declaration is
 * skipped) or a warning (it still registers).
 */
final class Issue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param string      $level   'error' or 'warning'
     * @param string      $code    Machine code, e.g. 'invalid_jsonc'.
     * @param string      $message Translated, human-readable message.
     * @param string      $source  Absolute file path, or "<file>:<line>" of a PHP call.
     * @param string|null $slug    Declaration slug, when known.
     * @param string|null $kind    'type' or 'taxonomy', when known.
     */
    public function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $message,
        public readonly string $source,
        public readonly ?string $slug = null,
        public readonly ?string $kind = null
    ) {
    }

    /**
     * @return array{level: string, code: string, message: string, source: string, slug: ?string, kind: ?string}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'message' => $this->message,
            'source' => $this->source,
            'slug' => $this->slug,
            'kind' => $this->kind,
        ];
    }
}
