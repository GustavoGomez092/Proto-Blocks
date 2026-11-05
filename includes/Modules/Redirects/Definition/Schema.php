<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Definition;

use ProtoBlocks\ContentModel\SchemaCheck;

/**
 * includes/ContentModel/schemas/redirects.schema.json, checked with the
 * content model's SchemaCheck subset. Every finding is `schema_invalid`.
 */
final class Schema
{
    public const CODE = 'schema_invalid';

    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    /** @return array<string, mixed> */
    public static function get(): array
    {
        if (self::$schema === null) {
            $raw = file_get_contents(dirname(__DIR__, 3) . '/ContentModel/schemas/redirects.schema.json');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                throw new \RuntimeException('redirects.schema.json is missing or invalid.');
            }
            self::$schema = $decoded;
        }

        return self::$schema;
    }

    /**
     * @param string $def 'group', 'rule', 'when' or 'settings'
     * @return list<array{path: string, code: string, message: string, level: string}>
     */
    public static function check(mixed $data, string $def, string $prefix = ''): array
    {
        $out = [];
        foreach (SchemaCheck::validate($data, self::get(), '#/$defs/' . $def) as $error) {
            $out[] = [
                'path' => $prefix . $error['path'],
                'code' => self::CODE,
                'message' => $error['message'],
                'level' => 'error',
            ];
        }

        return $out;
    }
}
