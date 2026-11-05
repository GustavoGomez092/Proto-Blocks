<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Yoast SEO Premium: option `wpseo-premium-redirects-base`, a list of
 * `origin`, `url`, `type` (status) and `format` (plain | regex). Origins are
 * written without the leading slash; Yoast regexes are case-insensitive.
 */
final class YoastImporter extends AbstractPluginImporter
{
    public const OPTION = 'wpseo-premium-redirects-base';

    public function id(): string
    {
        return 'yoast';
    }

    public function label(): string
    {
        return 'Yoast SEO Premium';
    }

    public function available(): bool
    {
        $value = $this->rawOption(self::OPTION);
        return is_array($value) && $value !== [];
    }

    public function preview(): array
    {
        $this->skipped = [];
        $value = $this->rawOption(self::OPTION);
        $rows = [];
        $line = 0;
        foreach (is_array($value) ? $value : [] as $entry) {
            $line++;
            $rows[] = is_array($entry) ? self::rule($entry, $line) : ParsedRow::invalid('not_an_object', $line);
        }
        $rules = $this->keep($rows);
        return $rules === [] ? [] : ['yoast-premium' => self::group('Yoast SEO Premium', $rules)];
    }

    /** @param array<string, mixed> $entry */
    private static function rule(array $entry, int $line): array
    {
        $origin = trim((string) ($entry['origin'] ?? ''));
        if ($origin === '') {
            return ParsedRow::invalid('empty_source', $line);
        }
        $regex = strtolower((string) ($entry['format'] ?? 'plain')) === 'regex';
        $rule = $regex
            ? ['from' => self::slashAnchored($origin), 'regex' => true, 'ignoreCase' => true]
            : ['from' => RawRule::source($origin), 'regex' => false];

        $code = (int) ($entry['type'] ?? 301);
        $action = RawRule::actionForCode($code);
        if ($action === null) {
            return ParsedRow::invalid('invalid_code', $line, RawRule::build($rule + ['code' => $code]));
        }
        if ($action === 'url') {
            $to = (string) ($entry['url'] ?? '');
            $target = RawRule::target($to);
            if ($target === null) {
                return ParsedRow::invalid(trim($to) === '' ? 'missing_target' : 'invalid_target', $line, RawRule::build($rule));
            }
            $rule['to'] = $target;
        }
        return self::finish($rule + ['code' => $code, 'action' => $action], $line);
    }

    public function cleanupHint(): ?string
    {
        return 'Remove the redirects in Yoast SEO Premium (or its redirect manager) once the imported rules are verified.';
    }
}
