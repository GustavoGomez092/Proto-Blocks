<?php

declare(strict_types=1);

// A recording stand-in for WP_CLI, loaded by the content-model command tests.
// error() throws like the real one exits, so a test sees the non-zero exit.
if (!class_exists('WP_CLI')) {
    final class WP_CLI
    {
        /** @var list<array{0: string, 1: string}> */
        public static array $out = [];

        public static function reset(): void
        {
            self::$out = [];
        }

        /** @return list<string> messages of one channel */
        public static function messages(string $channel): array
        {
            return array_values(array_map(fn($e) => $e[1], array_filter(self::$out, fn($e) => $e[0] === $channel)));
        }

        public static function log($m): void { self::$out[] = ['log', (string) $m]; }
        public static function line($m = ''): void { self::$out[] = ['line', (string) $m]; }
        public static function warning($m): void { self::$out[] = ['warning', (string) $m]; }
        public static function success($m): void { self::$out[] = ['success', (string) $m]; }
        public static function error($m): void
        {
            self::$out[] = ['error', (string) $m];
            throw new \RuntimeException('WP_CLI::error: ' . $m);
        }
    }
}
