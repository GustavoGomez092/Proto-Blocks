<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts;

use ProtoBlocks\ContentModel\Jsonc;

/**
 * Sets one top-level key of a JSONC object while keeping every comment and
 * the rest of the text as written (the CLI's enable/disable). Positions are
 * found in Jsonc::strip() output, which keeps every offset of the original.
 */
final class JsoncEdit
{
    /**
     * $text with top-level $key set to $value (JSON-encoded): replaced in
     * place when present, else inserted as the first key.
     *
     * @throws \InvalidArgumentException when the text has no top-level object
     */
    public static function setTopLevel(string $text, string $key, mixed $value): string
    {
        $encoded = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $plain = Jsonc::strip($text);
        $length = strlen($plain);
        if ($length !== strlen($text)) {
            throw new \InvalidArgumentException('Unexpected JSONC layout.');
        }

        $open = null;
        $depth = 0;
        $hasKeys = false;
        for ($i = 0; $i < $length; $i++) {
            $c = $plain[$i];
            if ($c === '"') {
                $end = self::stringEnd($plain, $i);
                if ($depth === 1) {
                    $hasKeys = true;
                    $j = $end + 1;
                    while ($j < $length && ctype_space($plain[$j])) {
                        $j++;
                    }
                    if (($plain[$j] ?? '') === ':' && json_decode(substr($plain, $i, $end - $i + 1)) === $key) {
                        $start = $j + 1;
                        while ($start < $length && ctype_space($plain[$start])) {
                            $start++;
                        }
                        $valueEnd = self::valueEnd($plain, $start);

                        return substr($text, 0, $start) . $encoded . substr($text, $valueEnd);
                    }
                }
                $i = $end;
                continue;
            }
            if ($c === '{' || $c === '[') {
                if ($depth === 0) {
                    if ($c !== '{') {
                        break;
                    }
                    $open = $i;
                }
                $depth++;
            } elseif ($c === '}' || $c === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        if ($open === null) {
            throw new \InvalidArgumentException('The file has no top-level object.');
        }

        $insert = "\n  " . json_encode($key) . ': ' . $encoded . ($hasKeys ? ',' : "\n");

        return substr($text, 0, $open + 1) . $insert . substr($text, $open + 1);
    }

    /** Offset of the closing quote of the string opening at $i. */
    private static function stringEnd(string $s, int $i): int
    {
        $length = strlen($s);
        for ($j = $i + 1; $j < $length; $j++) {
            if ($s[$j] === '\\') {
                $j++;
            } elseif ($s[$j] === '"') {
                return $j;
            }
        }

        return $length - 1;
    }

    /** Offset just past the value starting at $start. */
    private static function valueEnd(string $s, int $start): int
    {
        $length = strlen($s);
        $c = $s[$start] ?? '';
        if ($c === '"') {
            return self::stringEnd($s, $start) + 1;
        }
        if ($c === '{' || $c === '[') {
            $depth = 0;
            for ($j = $start; $j < $length; $j++) {
                $ch = $s[$j];
                if ($ch === '"') {
                    $j = self::stringEnd($s, $j);
                } elseif ($ch === '{' || $ch === '[') {
                    $depth++;
                } elseif ($ch === '}' || $ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        return $j + 1;
                    }
                }
            }
            return $length;
        }
        $j = $start;
        while ($j < $length && preg_match('~[A-Za-z0-9.+\-]~', $s[$j]) === 1) {
            $j++;
        }

        return $j;
    }
}
