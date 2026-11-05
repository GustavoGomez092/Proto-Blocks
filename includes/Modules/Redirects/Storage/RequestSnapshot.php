<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

/**
 * The few request facts the logs need, as a plain array:
 * `['url', 'method', 'ip', 'agent', 'referrer', 'headers']`, where `ip` is
 * REMOTE_ADDR (the logger resolves the client address from it and the
 * headers) and `headers` maps lower-case names to values. Keeps the storage
 * classes independent of the engine's Request class while accepting it.
 */
final class RequestSnapshot
{
    /**
     * @param array<string, mixed> $server $_SERVER (WordPress slashes it; this unslashes)
     * @return array{url: string, method: string, ip: string, agent: string, referrer: string, headers: array<string, string>}
     */
    public static function fromGlobals(array $server): array
    {
        $server = wp_unslash($server);
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = $key;
            } else {
                continue;
            }
            $headers[strtolower(str_replace('_', '-', $name))] = (string) $value;
        }

        return [
            'url' => self::str($server['REQUEST_URI'] ?? ''),
            'method' => self::str($server['REQUEST_METHOD'] ?? ''),
            'ip' => self::str($server['REMOTE_ADDR'] ?? ''),
            'agent' => self::str($server['HTTP_USER_AGENT'] ?? ''),
            'referrer' => self::str($server['HTTP_REFERER'] ?? ''),
            'headers' => $headers,
        ];
    }

    /**
     * A snapshot from a snapshot array (missing keys defaulted) or from an
     * object with the engine Request's public properties (`uri`, `method`,
     * `remoteAddr`, `userAgent`, `referrer`, `headers`). Anything else is $_SERVER.
     *
     * @return array{url: string, method: string, ip: string, agent: string, referrer: string, headers: array<string, string>}
     */
    public static function from(mixed $request): array
    {
        if (is_object($request)) {
            $request = [
                'url' => $request->uri ?? '',
                'method' => $request->method ?? '',
                'ip' => $request->remoteAddr ?? '',
                'agent' => $request->userAgent ?? '',
                'referrer' => $request->referrer ?? '',
                'headers' => $request->headers ?? [],
            ];
        } elseif (!is_array($request)) {
            return self::fromGlobals($_SERVER);
        }

        $headers = [];
        foreach ((array) ($request['headers'] ?? []) as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[strtolower($name)] = (string) $value;
            }
        }

        return [
            'url' => self::str($request['url'] ?? ''),
            'method' => self::str($request['method'] ?? ''),
            'ip' => self::str($request['ip'] ?? ''),
            'agent' => self::str($request['agent'] ?? ''),
            'referrer' => self::str($request['referrer'] ?? ''),
            'headers' => $headers,
        ];
    }

    /**
     * The $_SERVER-shaped view IpResolver reads.
     *
     * @param array{ip: string, headers: array<string, string>} $snapshot
     * @return array<string, string>
     */
    public static function toServer(array $snapshot): array
    {
        $server = ['REMOTE_ADDR' => (string) ($snapshot['ip'] ?? '')];
        foreach ((array) ($snapshot['headers'] ?? []) as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = (string) $value;
        }

        return $server;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
