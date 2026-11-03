<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Minimal JSON Schema checker for declaration files.
 *
 * Supports exactly: type, enum, pattern, minLength, maxLength, minimum,
 * maximum, properties, required, additionalProperties, items, minItems,
 * maxItems, uniqueItems,
 * anyOf and local `#/$defs/<name>` references. Unknown keywords are ignored.
 * The browser-side checker implements the same subset.
 */
final class SchemaCheck
{
    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    /**
     * The decoded content-model.schema.json (memoised).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        if (self::$schema === null) {
            $raw = file_get_contents(__DIR__ . '/schemas/content-model.schema.json');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                throw new \RuntimeException('content-model.schema.json is missing or invalid.');
            }
            self::$schema = $decoded;
        }

        return self::$schema;
    }

    /**
     * Validate $data against the schema node addressed by $ref.
     *
     * @param array<string, mixed> $schema Root schema that $ref values resolve against.
     * @return list<array{path: string, message: string}>
     */
    public static function validate(mixed $data, array $schema, string $ref = '#'): array
    {
        $node = self::resolve($schema, $ref);
        if ($node === null) {
            return [['path' => '', 'message' => "Unresolvable schema reference $ref."]];
        }

        return self::check($data, $node, $schema, '');
    }

    /**
     * @param array<string, mixed> $root
     * @return array<string, mixed>|null
     */
    private static function resolve(array $root, string $ref): ?array
    {
        if ($ref === '#') {
            return $root;
        }
        if (preg_match('~^#/\$defs/([A-Za-z0-9_-]+)$~', $ref, $m) && is_array($root['$defs'][$m[1]] ?? null)) {
            return $root['$defs'][$m[1]];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $root
     * @return list<array{path: string, message: string}>
     */
    private static function check(mixed $data, array $node, array $root, string $path): array
    {
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $target = self::resolve($root, $node['$ref']);
            if ($target === null) {
                return [['path' => $path, 'message' => "Unresolvable schema reference {$node['$ref']}."]];
            }
            return self::check($data, $target, $root, $path);
        }

        $errors = [];

        if (isset($node['type']) && !self::typeMatches($data, $node['type'])) {
            $want = implode(' or ', (array) $node['type']);
            return [['path' => $path, 'message' => "Must be of type $want."]];
        }

        if (isset($node['enum']) && is_array($node['enum']) && !in_array($data, $node['enum'], true)) {
            $errors[] = ['path' => $path, 'message' => 'Must be one of: ' . json_encode($node['enum']) . '.'];
        }

        if (is_string($data)) {
            $len = mb_strlen($data);
            if (isset($node['minLength']) && $len < $node['minLength']) {
                $errors[] = ['path' => $path, 'message' => "Must be at least {$node['minLength']} characters."];
            }
            if (isset($node['maxLength']) && $len > $node['maxLength']) {
                $errors[] = ['path' => $path, 'message' => "Must be at most {$node['maxLength']} characters."];
            }
            if (isset($node['pattern']) && is_string($node['pattern'])) {
                $regex = '~' . str_replace('~', '\~', $node['pattern']) . '~Du';
                if (preg_match($regex, $data) !== 1) {
                    $errors[] = ['path' => $path, 'message' => "Must match pattern {$node['pattern']}."];
                }
            }
        }

        if ((is_int($data) || is_float($data))) {
            if (isset($node['minimum']) && $data < $node['minimum']) {
                $errors[] = ['path' => $path, 'message' => "Must be at least {$node['minimum']}."];
            }
            if (isset($node['maximum']) && $data > $node['maximum']) {
                $errors[] = ['path' => $path, 'message' => "Must be at most {$node['maximum']}."];
            }
        }

        if (self::isObject($data)) {
            /** @var array<string|int, mixed> $data */
            foreach ((array) ($node['required'] ?? []) as $key) {
                if (!array_key_exists($key, $data)) {
                    $errors[] = ['path' => $path . '/' . self::escape((string) $key), 'message' => 'Required.'];
                }
            }
            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $additional = $node['additionalProperties'] ?? true;
            foreach ($data as $key => $value) {
                $childPath = $path . '/' . self::escape((string) $key);
                if (array_key_exists($key, $properties) && is_array($properties[$key])) {
                    array_push($errors, ...self::check($value, $properties[$key], $root, $childPath));
                } elseif ($additional === false) {
                    $errors[] = ['path' => $childPath, 'message' => 'Unknown property.'];
                } elseif (is_array($additional)) {
                    array_push($errors, ...self::check($value, $additional, $root, $childPath));
                }
            }
        }

        if (self::isList($data)) {
            /** @var list<mixed> $data */
            if (isset($node['items']) && is_array($node['items'])) {
                foreach ($data as $i => $value) {
                    array_push($errors, ...self::check($value, $node['items'], $root, "$path/$i"));
                }
            }
            if (isset($node['minItems']) && count($data) < $node['minItems']) {
                $errors[] = ['path' => $path, 'message' => "Must have at least {$node['minItems']} items."];
            }
            if (isset($node['maxItems']) && count($data) > $node['maxItems']) {
                $errors[] = ['path' => $path, 'message' => "Must have at most {$node['maxItems']} items."];
            }
            if (!empty($node['uniqueItems'])) {
                $seen = [];
                foreach ($data as $i => $value) {
                    $fingerprint = json_encode($value);
                    if (isset($seen[$fingerprint])) {
                        $errors[] = ['path' => "$path/$i", 'message' => 'Items must be unique.'];
                    }
                    $seen[$fingerprint] = true;
                }
            }
        }

        if (isset($node['anyOf']) && is_array($node['anyOf'])) {
            array_push($errors, ...self::checkAnyOf($data, $node['anyOf'], $root, $path));
        }

        return $errors;
    }

    /**
     * @param array<int, array<string, mixed>> $branches
     * @param array<string, mixed> $root
     * @return list<array{path: string, message: string}>
     */
    private static function checkAnyOf(mixed $data, array $branches, array $root, string $path): array
    {
        $best = null;
        foreach ($branches as $branch) {
            $errors = self::check($data, $branch, $root, $path);
            if ($errors === []) {
                return [];
            }
            // Prefer the branch whose declared type fits the data: its errors
            // are the most specific ones to show.
            $resolved = isset($branch['$ref']) ? (self::resolve($root, (string) $branch['$ref']) ?? []) : $branch;
            $fits = !isset($resolved['type']) || self::typeMatches($data, $resolved['type']);
            if ($fits && ($best === null || count($errors) < count($best))) {
                $best = $errors;
            }
        }

        return $best ?? [['path' => $path, 'message' => 'Does not match any allowed form.']];
    }

    private static function typeMatches(mixed $data, mixed $type): bool
    {
        foreach ((array) $type as $t) {
            $ok = match ($t) {
                'object' => self::isObject($data),
                'array' => self::isList($data),
                'string' => is_string($data),
                'boolean' => is_bool($data),
                'integer' => is_int($data) || (is_float($data) && floor($data) === $data),
                'number' => is_int($data) || is_float($data),
                'null' => $data === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /** An empty PHP array is both a valid JSON object and a valid JSON array. */
    private static function isObject(mixed $data): bool
    {
        return is_array($data) && ($data === [] || !array_is_list($data));
    }

    private static function isList(mixed $data): bool
    {
        return is_array($data) && array_is_list($data);
    }

    private static function escape(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }
}
