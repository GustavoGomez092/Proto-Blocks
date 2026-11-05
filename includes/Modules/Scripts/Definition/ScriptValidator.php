<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts\Definition;

use ProtoBlocks\ContentModel\SchemaCheck;

/**
 * Checks one script declaration (the decoded script.jsonc, or the title and
 * arguments of a proto_script() call) against scripts.schema.json and the
 * rules the schema cannot express.
 *
 * Error codes: `schema_invalid` (anything the schema refuses, unknown targets
 * included), `invalid_snippet_file` (a `file` that is not one of the three
 * fixed names, or any `file` form in PHP), `php_in_snippet` (`<?php`, `<?=`
 * or `<?` followed by whitespace in an inline snippet), `snippet_too_large`,
 * `conflicting_where` (roles with `loggedIn: false` can never match).
 * Warning codes: `never_matches` (an explicit empty `include`).
 */
final class ScriptValidator
{
    /** `<?php`, `<?=` and `<?` followed by whitespace; `<?xml` is fine. */
    public const PHP_OPEN = '~<\?(?:php|=|\s)~i';

    public const SNIPPET_MAX = 262144;

    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    /**
     * The decoded scripts.schema.json (memoised).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        if (self::$schema === null) {
            $raw = file_get_contents(dirname(__DIR__, 3) . '/ContentModel/schemas/scripts.schema.json');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                throw new \RuntimeException('scripts.schema.json is missing or invalid.');
            }
            self::$schema = $decoded;
        }

        return self::$schema;
    }

    /**
     * @param array<string|int, mixed> $data
     * @param bool $php A proto_script() declaration: inline strings only.
     * @return array{errors: list<array{path: string, code: string, message: string}>, warnings: list<array{path: string, code: string, message: string}>}
     */
    public static function validate(array $data, bool $php = false): array
    {
        $errors = [];
        $warnings = [];

        foreach (SchemaCheck::validate($data, self::schema(), '#/$defs/script') as $e) {
            $errors[] = ['path' => $e['path'], 'code' => 'schema_invalid', 'message' => self::schemaMessage($data, $e['path'], $e['message'])];
        }

        foreach (ScriptNormalizer::LOCATIONS as $location => $file) {
            if (!array_key_exists($location, $data)) {
                continue;
            }
            $value = $data[$location];
            if (is_array($value)) {
                $named = $value['file'] ?? null;
                if ($php) {
                    $errors[] = [
                        'path' => '/' . $location,
                        'code' => 'invalid_snippet_file',
                        'message' => __('A script declared in PHP takes its snippets as strings; the "file" form only works in script.jsonc.', 'proto-blocks'),
                    ];
                } elseif (is_string($named) && !in_array($named, ScriptNormalizer::LOCATIONS, true)) {
                    $errors[] = [
                        'path' => '/' . $location . '/file',
                        'code' => 'invalid_snippet_file',
                        'message' => sprintf(
                            /* translators: 1: the file name as written, 2: list of allowed names */
                            __('"%1$s" is not a snippet file; use one of %2$s.', 'proto-blocks'),
                            self::clip($named),
                            implode(', ', ScriptNormalizer::LOCATIONS)
                        ),
                    ];
                }
                continue;
            }
            if (is_string($value)) {
                $finding = self::snippetError($value, '/' . $location);
                if ($finding !== null) {
                    $errors[] = $finding;
                }
            }
        }

        $where = is_array($data['where'] ?? null) ? $data['where'] : [];
        $roles = $where['roles'] ?? [];
        if (($where['loggedIn'] ?? null) === false && is_array($roles) && $roles !== []) {
            $errors[] = [
                'path' => '/where/roles',
                'code' => 'conflicting_where',
                'message' => __('"roles" only matches logged-in users, but "loggedIn" is false: the script would never run.', 'proto-blocks'),
            ];
        }
        if (array_key_exists('include', $where) && $where['include'] === []) {
            $warnings[] = [
                'path' => '/where/include',
                'code' => 'never_matches',
                'message' => __('"include" is empty, so the script runs nowhere. Remove the key to run it on every page.', 'proto-blocks'),
            ];
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * The finding for a snippet's text (inline or from a file), or null when it may be output.
     *
     * @return array{path: string, code: string, message: string}|null
     */
    public static function snippetError(string $text, string $path): ?array
    {
        if (strlen($text) > self::SNIPPET_MAX) {
            return [
                'path' => $path,
                'code' => 'snippet_too_large',
                /* translators: %d: maximum size in kilobytes */
                'message' => sprintf(__('A snippet may not be larger than %d KB.', 'proto-blocks'), intdiv(self::SNIPPET_MAX, 1024)),
            ];
        }
        if (preg_match(self::PHP_OPEN, $text) === 1) {
            return [
                'path' => $path,
                'code' => 'php_in_snippet',
                'message' => __('Snippets are HTML, CSS and JavaScript only; a PHP opening tag ("<?php", "<?=" or "<?") is not allowed.', 'proto-blocks'),
            ];
        }

        return null;
    }

    /** A clearer message for an unknown placement target. */
    private static function schemaMessage(array $data, string $path, string $message): string
    {
        if (preg_match('~^/where/(include|exclude)/(\d+)$~D', $path, $m) === 1 && str_starts_with($message, 'Must match pattern')) {
            $value = $data['where'][$m[1]][(int) $m[2]] ?? null;
            if (is_string($value)) {
                return sprintf(
                    /* translators: %s: the target as written */
                    __('"%s" is not a placement target (all, front_page, home, search, 404, archive, singular, singular:<type>, post_type:<type>, page:<slug|id>, post:<slug|id>, taxonomy:<tax>, term:<tax>:<slug>, url:/<glob>).', 'proto-blocks'),
                    self::clip($value)
                );
            }
        }

        return $message;
    }

    private static function clip(string $value): string
    {
        return mb_substr(str_replace(['<', '>'], '', $value), 0, 80);
    }
}
