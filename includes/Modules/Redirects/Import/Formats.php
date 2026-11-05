<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Format registry: detection by filename first, then by content.
 */
final class Formats
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @return list<FormatInterface> in sniffing order (most specific first) */
    public static function all(): array
    {
        return [new JsonFormat(), new ApacheFormat(), new NetlifyFormat(), new CsvFormat()];
    }

    public static function byId(string $id): ?FormatInterface
    {
        foreach (self::all() as $format) {
            if ($format->id() === $id) {
                return $format;
            }
        }
        return null;
    }

    public static function detect(string $filename, string $text): ?FormatInterface
    {
        foreach (self::all() as $format) {
            if ($format->detect($filename, $text)) {
                return $format;
            }
        }
        return null;
    }

    /** The format id a filename decides on its own, or null (no name, `.txt`, unknown extension). */
    public static function byFilename(string $filename): ?string
    {
        $base = strtolower(basename(str_replace('\\', '/', trim($filename))));
        if ($base === '') {
            return null;
        }
        if ($base === '_redirects') {
            return 'redirects';
        }
        if (str_ends_with($base, '.htaccess') || str_ends_with($base, '.conf') || $base === 'htaccess') {
            return 'apache';
        }
        if (str_ends_with($base, '.csv')) {
            return 'csv';
        }
        if (str_ends_with($base, '.json') || str_ends_with($base, '.jsonc')) {
            return 'json';
        }
        return null;
    }

    /** Text without a UTF-8 BOM, with LF line endings. */
    public static function clean(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** The first lines that are neither empty nor `#` comments, at most $max. @return list<string> */
    public static function meaningfulLines(string $text, int $max = 20): array
    {
        $out = [];
        foreach (explode("\n", self::clean(substr($text, 0, 65536))) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $out[] = $line;
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }
}
