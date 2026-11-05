<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts;

/**
 * What placement rules look at besides the conditional tags: the request
 * path (decoded, no query, relative to the site's home path) and the
 * visitor's login state and roles.
 */
final class RequestContext
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly string $path = '/',
        public readonly bool $loggedIn = false,
        public readonly array $roles = []
    ) {
    }

    /** The current request, from $_SERVER['REQUEST_URI'], home_url() and the current user. */
    public static function current(): self
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $homePath = function_exists('home_url') ? (string) (parse_url((string) home_url('/'), PHP_URL_PATH) ?? '/') : '/';

        $loggedIn = function_exists('is_user_logged_in') && is_user_logged_in();
        $roles = [];
        if ($loggedIn && function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            $raw = is_object($user) && isset($user->roles) && is_array($user->roles) ? $user->roles : [];
            $roles = array_values(array_map('strval', array_filter($raw, 'is_string')));
        }

        return new self(self::pathFrom($uri, $homePath), $loggedIn, $roles);
    }

    /**
     * The decoded path of $uri without query or fragment, repeated slashes
     * collapsed, with $homePath (WordPress in a sub-folder) removed.
     */
    public static function pathFrom(string $uri, string $homePath = '/'): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $uri) === 1) {
            $uri = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        }
        $path = strtok($uri, '?#');
        $path = rawurldecode($path === false ? '' : $path);
        $path = '/' . ltrim((string) preg_replace('~/{2,}~', '/', $path), '/');

        $home = rtrim(rawurldecode($homePath), '/');
        if ($home !== '' && ($path === $home || str_starts_with($path, $home . '/'))) {
            $path = substr($path, strlen($home));
            $path = $path === '' ? '/' : $path;
        }

        return $path;
    }
}
