<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * One row of an import preview: `['rule' => raw rule, 'status' => 'new'|'duplicate'|'invalid',
 * 'reason' => ?string, 'line' => int]`. `reason` is a stable code; message() gives English text.
 * `line` is 1-based; 0 means the whole input (too large, unreadable).
 */
final class ParsedRow
{
    public const NEW = 'new';
    public const DUPLICATE = 'duplicate';
    public const INVALID = 'invalid';

    private const MESSAGES = [
        'too_large' => 'The file is larger than 5 MB.',
        'invalid_json' => 'The file is not valid JSON.',
        'unknown_shape' => 'The JSON is neither a Proto-Blocks export nor a Redirection export.',
        'not_an_object' => 'The rule is not an object.',
        'too_few_columns' => 'The row needs at least a source and a target.',
        'empty_source' => 'The source is empty.',
        'missing_target' => 'The rule needs a target.',
        'self_redirect' => 'The source and the target are the same.',
        'invalid_code' => 'The status code is not one a redirect or an error can use.',
        'invalid_target' => 'The target is not a path or an http(s) URL.',
        'invalid_regex' => 'The regular expression does not compile.',
        'unsupported_action' => 'The action has no Proto-Blocks equivalent.',
        'unsupported_condition' => 'The condition has no Proto-Blocks equivalent.',
        'multiple_conditions' => 'Only one condition per rule is supported.',
        'unsupported_directive' => 'The directive is not a redirect.',
        'unsupported_flag' => 'The rewrite flag has no Proto-Blocks equivalent.',
        'unsupported_variable' => 'The target uses a server variable or a condition back-reference.',
        'unsupported_placeholder' => 'Named placeholders (:name) are not supported; use * and :splat.',
        'malformed_line' => 'The line could not be read.',
        'duplicate' => 'A rule with the same source, regex flag and condition already exists.',
    ];

    /** @param array<string, mixed> $rule */
    public static function ok(array $rule, int $line): array
    {
        return ['rule' => $rule, 'status' => self::NEW, 'reason' => null, 'line' => $line];
    }

    /** @param array<string, mixed> $rule what could be read, possibly empty */
    public static function invalid(string $reason, int $line, array $rule = []): array
    {
        return ['rule' => $rule, 'status' => self::INVALID, 'reason' => $reason, 'line' => $line];
    }

    /** @return list<array> a single whole-input row when $text is over the cap, else [] */
    public static function guardSize(string $text): array
    {
        return strlen($text) > Formats::MAX_BYTES ? [self::invalid('too_large', 0)] : [];
    }

    public static function message(string $reason): string
    {
        return self::MESSAGES[$reason] ?? $reason;
    }
}
