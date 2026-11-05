<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

use ProtoBlocks\Modules\Redirects\Engine\Conditions\Server;

/**
 * Canonical host and scheme (spec §7 `settings.canonical`), checked first on
 * `init`: one 301 that keeps path and query.
 *
 * - relocate: every request except wp-admin, wp-login.php and REST goes to
 *   the relocate URL + path + query (nothing else applies);
 * - aliases: a listed host is sent to the site host (home_url);
 * - www / nowww: only for the site's own host, with or without `www.`;
 * - https: http requests are upgraded.
 *
 * Targets only ever name the site host (home_url), its www. variant or the
 * relocate URL: a request whose Host is none of these nor an alias gets the
 * site host when https fires, and no redirect otherwise (an unchecked Host
 * header is never echoed).
 *
 * Filter `proto_blocks_redirects_canonical_target` (?string target, Request, settings).
 */
final class Canonical
{
    /** @param array<string,mixed> $settings */
    public static function target(Request $r, array $settings): ?string
    {
        $target = self::compute($r, $settings);
        if (function_exists('apply_filters')) {
            $target = apply_filters('proto_blocks_redirects_canonical_target', $target, $r, $settings);
        }
        return is_string($target) && $target !== '' ? $target : null;
    }

    /** @param array<string,mixed> $settings */
    private static function compute(Request $r, array $settings): ?string
    {
        $c = is_array($settings['canonical'] ?? null) ? $settings['canonical'] : [];
        if ($r->host === '') {
            return null;
        }
        $pathAndQuery = self::pathAndQuery($r);

        $relocate = is_string($c['relocate'] ?? null) ? trim($c['relocate']) : '';
        if ($relocate !== '') {
            if (preg_match('#^https?://[^/?\#]+#i', $relocate) !== 1 || $r->isProtected()) {
                return null;
            }
            $to = Request::fromUrl($relocate);
            if ($to->host === $r->host && $to->scheme === $r->scheme && $to->port === $r->port) {
                return null;
            }
            $base = rtrim((string) preg_replace('#[?\#].*$#s', '', $relocate), '/');
            return $base . $pathAndQuery;
        }

        $home = function_exists('home_url') ? Request::fromUrl((string) home_url('/')) : null;
        $homeHost = $home !== null ? $home->host : '';
        $scheme = $r->scheme;
        $host = $r->host;
        $port = $r->port;

        $bare = str_starts_with($homeHost, 'www.') ? substr($homeHost, 4) : $homeHost;
        $isSite = static fn(string $h): bool => $homeHost !== '' && ($h === $bare || $h === 'www.' . $bare);

        $aliases = [];
        foreach ((array) ($c['aliases'] ?? []) as $alias) {
            if (is_string($alias) && trim($alias) !== '') {
                $aliases[] = Server::normalise($alias);
            }
        }
        if ($homeHost !== '' && in_array(Server::normalise($host), $aliases, true) && $host !== $homeHost) {
            $host = $homeHost;
            $port = $home?->port;
        }

        // A host that is neither the site's (with or without www.) nor an alias
        // came from an unchecked Host header: it is never echoed into a target.
        $foreign = !$isSite($host);

        if (!empty($c['https']) && $scheme === 'http') {
            $scheme = 'https';
            $port = null;
        }

        if ($foreign) {
            if ($homeHost === '' || $scheme === $r->scheme) {
                return null;
            }
            $host = $homeHost;
        }

        $www = (string) ($c['www'] ?? '');
        if ($homeHost !== '' && ($www === 'www' || $www === 'nowww') && $isSite($host)) {
            $host = $www === 'www' ? 'www.' . $bare : $bare;
        }

        if ($scheme === $r->scheme && $host === $r->host && $port === $r->port) {
            return null;
        }
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $authority = $host . ($port !== null && $port !== $defaultPort ? ':' . $port : '');
        return $scheme . '://' . $authority . $pathAndQuery;
    }

    /** The request's path and query as received (still encoded), safe to append. */
    private static function pathAndQuery(Request $r): string
    {
        $uri = Request::clean($r->uri);
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $uri = substr($uri, 0, $hash);
        }
        if (preg_match('#^https?://[^/]*(.*)$#is', $uri, $m) === 1) {
            $uri = $m[1];
        }
        $uri = (string) preg_replace('#^[/\\\\]+#', '/', $uri);
        return str_starts_with($uri, '/') ? $uri : '/' . $uri;
    }
}
