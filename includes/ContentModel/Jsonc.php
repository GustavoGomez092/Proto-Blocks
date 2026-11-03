<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Decoder for JSON with comments and trailing commas (JSONC).
 *
 * Declaration files are developer-authored, so comments and trailing commas
 * are tolerated. Objects decode to associative arrays; an object root is
 * required and any `__proto__` key at any depth is refused.
 */
final class Jsonc
{
    private const MAX_DEPTH = 64;

    /**
     * @return array<string|int, mixed>
     * @throws JsoncException
     */
    public static function decode(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $stripped = self::strip($text);

        try {
            $data = json_decode($stripped, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            [$line, $column] = self::locateSyntaxError($stripped);
            throw new JsoncException($e->getMessage() . " (line $line, column $column)", $line, $column);
        }

        if (!is_array($data) || ltrim($stripped)[0] !== '{') {
            throw new JsoncException('The root of a declaration file must be a JSON object.', 1, 1);
        }

        self::rejectProtoKeys($data, $stripped);

        return $data;
    }

    /**
     * Remove comments and trailing commas. String contents are untouched and
     * newlines are preserved so line numbers still match the original text.
     */
    public static function strip(string $text): string
    {
        $out = '';
        $length = strlen($text);
        $inString = false;

        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];

            if ($inString) {
                $out .= $c;
                if ($c === '\\' && $i + 1 < $length) {
                    $out .= $text[++$i];
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($c === '"') {
                $inString = true;
                $out .= $c;
            } elseif ($c === '/' && ($text[$i + 1] ?? '') === '/') {
                while ($i < $length && $text[$i] !== "\n") {
                    $out .= ' ';
                    $i++;
                }
                if ($i < $length) {
                    $out .= "\n";
                }
            } elseif ($c === '/' && ($text[$i + 1] ?? '') === '*') {
                $out .= '  ';
                $i += 2;
                while ($i < $length && !($text[$i] === '*' && ($text[$i + 1] ?? '') === '/')) {
                    $out .= $text[$i] === "\n" ? "\n" : ' ';
                    $i++;
                }
                if ($i < $length) {
                    $out .= '  ';
                    $i++; // land on the closing slash; the loop increment moves past it
                }
            } elseif ($c === '}' || $c === ']') {
                // Blank a trailing comma that precedes this closer.
                $j = strlen($out) - 1;
                while ($j >= 0 && ctype_space($out[$j])) {
                    $j--;
                }
                if ($j >= 0 && $out[$j] === ',') {
                    $out[$j] = ' ';
                }
                $out .= $c;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private static function rejectProtoKeys(array $data, string $stripped): void
    {
        foreach ($data as $key => $value) {
            if ($key === '__proto__') {
                $line = 1;
                if (preg_match('/"__proto__"\s*:/', $stripped, $m, PREG_OFFSET_CAPTURE)) {
                    $line = substr_count($stripped, "\n", 0, $m[0][1]) + 1;
                }
                throw new JsoncException("Forbidden key \"__proto__\" on line $line.", $line, 1);
            }
            if (is_array($value)) {
                self::rejectProtoKeys($value, $stripped);
            }
        }
    }

    /**
     * Find the line and column of the first offending token with a small
     * recursive-descent scan; json_decode only gives a message.
     *
     * @return array{0: int, 1: int}
     */
    private static function locateSyntaxError(string $text): array
    {
        $pos = 0;
        $offset = self::scanValue($text, $pos, 0);
        if ($offset === null) {
            self::skipSpace($text, $pos);
            if ($pos < strlen($text)) {
                $offset = $pos; // trailing garbage after the root value
            }
        }
        if ($offset === null) {
            return [1, 1];
        }

        $line = substr_count($text, "\n", 0, $offset) + 1;
        $lastNewline = strrpos(substr($text, 0, $offset), "\n");
        $column = $offset - ($lastNewline === false ? -1 : $lastNewline);

        return [$line, $column];
    }

    private static function skipSpace(string $t, int &$p): void
    {
        $n = strlen($t);
        while ($p < $n && ctype_space($t[$p])) {
            $p++;
        }
    }

    /** @return int|null Offset of the first error, or null when the value is well-formed. */
    private static function scanValue(string $t, int &$p, int $depth): ?int
    {
        if ($depth > self::MAX_DEPTH) {
            return $p;
        }
        self::skipSpace($t, $p);
        if ($p >= strlen($t)) {
            return $p;
        }

        $c = $t[$p];
        if ($c === '{') {
            $p++;
            self::skipSpace($t, $p);
            if (($t[$p] ?? '') === '}') {
                $p++;
                return null;
            }
            while (true) {
                self::skipSpace($t, $p);
                if (($t[$p] ?? '') !== '"') {
                    return $p;
                }
                if (($err = self::scanString($t, $p)) !== null) {
                    return $err;
                }
                self::skipSpace($t, $p);
                if (($t[$p] ?? '') !== ':') {
                    return $p;
                }
                $p++;
                if (($err = self::scanValue($t, $p, $depth + 1)) !== null) {
                    return $err;
                }
                self::skipSpace($t, $p);
                $next = $t[$p] ?? '';
                if ($next === ',') {
                    $p++;
                    continue;
                }
                if ($next === '}') {
                    $p++;
                    return null;
                }
                return $p;
            }
        }
        if ($c === '[') {
            $p++;
            self::skipSpace($t, $p);
            if (($t[$p] ?? '') === ']') {
                $p++;
                return null;
            }
            while (true) {
                if (($err = self::scanValue($t, $p, $depth + 1)) !== null) {
                    return $err;
                }
                self::skipSpace($t, $p);
                $next = $t[$p] ?? '';
                if ($next === ',') {
                    $p++;
                    continue;
                }
                if ($next === ']') {
                    $p++;
                    return null;
                }
                return $p;
            }
        }
        if ($c === '"') {
            return self::scanString($t, $p);
        }
        if (preg_match('/\G(?:true|false|null|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)/', $t, $m, 0, $p)) {
            $p += strlen($m[0]);
            return null;
        }

        return $p;
    }

    private static function scanString(string $t, int &$p): ?int
    {
        $start = $p;
        $n = strlen($t);
        $p++;
        while ($p < $n) {
            $c = $t[$p];
            if ($c === '\\') {
                $p += 2;
                continue;
            }
            if ($c === '"') {
                $p++;
                return null;
            }
            if ($c === "\n") {
                return $p; // raw newline inside a string
            }
            $p++;
        }

        return $start;
    }
}
