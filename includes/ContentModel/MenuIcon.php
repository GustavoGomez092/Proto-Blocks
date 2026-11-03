<?php
/**
 * Menu icon resolution for declared post types
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

use ProtoBlocks\Security\SvgSanitizer;

/**
 * Turns the "menu_icon" a theme declares into a value register_post_type()
 * accepts, applying the spec's icon rules. Anything that cannot be resolved
 * safely becomes the generic icon plus an "icon_invalid" warning.
 */
final class MenuIcon
{
    /** Same glyph as $icon_svg in AdminPage::addMenuPage(), fill="black". */
    public const DEFAULT_ICON = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCA1MTIgNTEyIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxwYXRoIGZpbGw9ImJsYWNrIiBkPSJNIDI0Ny4yOSAxMTMuMjcgQyAyODEuODEgOTIuNzYgMzE3LjIwIDczLjczIDM1MS43NCA1My4yNCBDIDM4MS41NCA3MC40MSA0MTEuMjYgODcuNzMgNDQxLjA1IDEwNC45MSBDIDQ0NS45NCAxMDcuNTUgNDUwLjU1IDExMC42NyA0NTUuMTcgMTEzLjc2IEMgNDU1LjE3IDE5MC41MCA0NTUuMTcgMjY3LjI0IDQ1NS4xNiAzNDMuOTggQyA0MjEuMTYgMzY0LjE1IDM4Ni42OCAzODMuNTIgMzUyLjY1IDQwMy42NCBDIDMyMC40NiA0MjIuMzggMjg4LjI2IDQ0MS4wOSAyNTYuMDIgNDU5LjczIEMgMjE5LjM0IDQzOC41NyAxODIuNzAgNDE3LjMzIDE0Ni4xOSAzOTUuODggQyAxMTYuMzkgMzc4LjU5IDg2LjQ4IDM2MS41MSA1Ni44MyAzNDMuOTkgQyA1Ni44MyAzMDMuOTEgNTYuODIgMjYzLjgzIDU2LjgzIDIyMy43NSBDIDg2LjIwIDIwNi40MSAxMTUuODMgMTg5LjUwIDE0NS4zMiAxNzIuMzcgQyAxNTAuNTcgMTY5LjU4IDE1NS4zOSAxNjYuMDkgMTYwLjUzIDE2My4xMiBDIDE2Ni4xNiAxNjYuNzcgMTcxLjkyIDE3MC4yMCAxNzguMDUgMTcyLjk3IEMgMjAxLjE3IDE4Ni4yNCAyMjQuMjEgMTk5LjY4IDI0Ny4zMCAyMTMuMDEgQyAyNDcuMjcgMTc5Ljc2IDI0Ny4zMCAxNDYuNTIgMjQ3LjI5IDExMy4yNyBNIDI3My40MiAxMTguMjUgQyAyOTkuMzUgMTMzLjI2IDMyNS4yNSAxNDguMzEgMzUxLjIyIDE2My4yNCBDIDM1OS44NSAxNTkuMDMgMzY4LjAzIDE1NC4wMiAzNzYuMzUgMTQ5LjI1IEMgMzkzLjkxIDEzOS4wOCA0MTEuNDkgMTI4Ljk0IDQyOS4wNCAxMTguNzUgQyA0MDMuMTAgMTAzLjc2IDM3Ny4yMCA4OC42OCAzNTEuMjIgNzMuNzYgQyAzMjUuMzUgODguNjggMjk5LjQxIDEwMy41MCAyNzMuNDIgMTE4LjI1IE0gMjY1LjIzIDEzNC4wOCBDIDI2NS4yMCAxNjMuOTggMjY1LjIyIDE5My44OCAyNjUuMjEgMjIzLjc4IEMgMjkxLjI1IDIzOC4xNCAzMTYuNTcgMjUzLjc4IDM0Mi41NCAyNjguMjYgQyAzNDIuNDkgMjM4LjQyIDM0Mi41NiAyMDguNTggMzQyLjUxIDE3OC43NCBDIDMxNi45NCAxNjMuNTMgMjkwLjk5IDE0OC45NiAyNjUuMjMgMTM0LjA4IE0gMzU5Ljk1IDE3OC43NSBDIDM1OS45MyAyMDguNDEgMzU5Ljk0IDIzOC4wNyAzNTkuOTQgMjY3LjczIEMgMzg1LjkyIDI1My4wMCA0MTEuODQgMjM4LjE1IDQzNy43MyAyMjMuMjcgQyA0MzcuNzkgMTkzLjQzIDQzNy43NSAxNjMuNjAgNDM3Ljc1IDEzMy43NiBDIDQxMS44NCAxNDguNzkgMzg1LjY3IDE2My40MCAzNTkuOTUgMTc4Ljc1IE0gODIuOTUgMjI4LjI3IEMgMTA4Ljg1IDI0My4zOCAxMzQuOTIgMjU4LjIzIDE2MC43MyAyNzMuNTAgQyAxODYuNTIgMjU4LjQ0IDIxMi4yNyAyNDMuMzMgMjM4LjA1IDIyOC4yNiBDIDIxMi4zMyAyMTMuMTggMTg2LjE5IDE5OC44MSAxNjAuNzYgMTgzLjI1IEMgMTM1LjAxIDE5OC41NiAxMDguODYgMjEzLjIxIDgyLjk1IDIyOC4yNyBNIDIzNC43MCAyNTAuNzAgQyAyMTUuODYgMjYxLjU0IDE5Ny4wMiAyNzIuMzggMTc4LjE4IDI4My4yNCBDIDIwNC4wOSAyOTguMjkgMjMwLjI0IDMxMi45NCAyNTYuMDAgMzI4LjIzIEMgMjgxLjczIDMxMy4zNiAzMDcuNTUgMjk4LjYzIDMzMy4yNyAyODMuNzQgQyAzMDcuNDQgMjY4LjQ4IDI4MS4yMiAyNTMuODkgMjU1LjQ3IDIzOC41MSBDIDI0OC43NCAyNDIuOTAgMjQxLjYxIDI0Ni42MSAyMzQuNzAgMjUwLjcwIE0gNzQuMjQgMjQzLjc2IEMgNzQuMjUgMjczLjYwIDc0LjIxIDMwMy40MyA3NC4yNiAzMzMuMjcgQyAxMDAuMTkgMzQ4LjI1IDEyNi4xMCAzNjMuMjcgMTUyLjA0IDM3OC4yMyBDIDE1Mi4wOCAzNDguNDEgMTUyLjA1IDMxOC41OSAxNTIuMDUgMjg4Ljc3IEMgMTI2LjA5IDI3My44MSAxMDAuMTggMjU4Ljc2IDc0LjI0IDI0My43NiBNIDM1OS45NSAyODguNzQgQyAzNTkuOTMgMzE4LjU2IDM1OS45MiAzNDguMzggMzU5Ljk1IDM3OC4yMCBDIDM4NS45MSAzNjMuMjggNDExLjgwIDM0OC4yMyA0MzcuNzQgMzMzLjI3IEMgNDM3Ljc4IDMwMy42MCA0MzcuNzUgMjczLjkzIDQzNy43NSAyNDQuMjYgQyA0MTEuODIgMjU5LjA5IDM4NS44NiAyNzMuODcgMzU5Ljk1IDI4OC43NCBNIDI2NC43NCAzNDMuNDQgQyAyNjQuNjYgMzczLjM3IDI2NC43MyA0MDMuMzEgMjY0LjcxIDQzMy4yNCBDIDI5MC42OCA0MTguNDAgMzE2Ljc4IDQwMy43NiAzNDIuNTEgMzg4LjUyIEMgMzQyLjU0IDM1OC41MSAzNDIuNTIgMzI4LjUxIDM0Mi41MyAyOTguNTEgQyAzMTYuNTggMzEzLjQ2IDI5MC43MyAzMjguNTYgMjY0Ljc0IDM0My40NCBNIDE2OS40OCAzODguNTEgQyAxOTUuMDcgNDAzLjc4IDIyMS4wNCA0MTguNDMgMjQ2Ljc3IDQzMy40NiBDIDI0Ni43OSA0MDMuNjUgMjQ2Ljc3IDM3My44NCAyNDYuNzkgMzQ0LjAzIEMgMjIwLjk5IDMyOS4xMSAxOTUuMjIgMzE0LjEzIDE2OS40MyAyOTkuMTkgQyAxNjkuNTMgMzI4Ljk2IDE2OS40MyAzNTguNzQgMTY5LjQ4IDM4OC41MSBaIiAvPjwvc3ZnPg==';

    private const RASTER_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * @return array{icon: string, warning: ?string}
     */
    public static function resolve(string $value, string $themeDir, string $themeUrl): array
    {
        $value = trim($value);
        if ($value === '') {
            return self::result(self::DEFAULT_ICON, null);
        }
        if (preg_match('/^dashicons-[a-z0-9-]+$/', $value) === 1) {
            return self::result($value, null);
        }
        if (str_starts_with($value, 'https://')) {
            $url = esc_url_raw($value);
            return $url !== '' ? self::result($url, null) : self::invalid();
        }

        // Relative theme path only: no scheme, absolute path, backslash or NUL.
        if (
            $value[0] === '/' || str_contains($value, ':') || str_contains($value, '\\')
            || str_contains($value, "\0")
        ) {
            return self::invalid();
        }
        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        if ($extension !== 'svg' && !in_array($extension, self::RASTER_EXTENSIONS, true)) {
            return self::invalid();
        }

        // realpath() resolves symlinks and "..", so the prefix check below
        // confines the file to the theme directory.
        $base = realpath($themeDir);
        $real = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . $value);
        if (
            $base === false || $real === false || !is_file($real)
            || !str_starts_with($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            return self::invalid();
        }

        if ($extension === 'svg') {
            $size = filesize($real);
            if ($size === false || $size > SvgSanitizer::MAX_BYTES) {
                return self::invalid();
            }
            $markup = file_get_contents($real);
            $clean  = is_string($markup) ? SvgSanitizer::sanitize($markup) : null;
            return $clean === null
                ? self::invalid()
                : self::result('data:image/svg+xml;base64,' . base64_encode($clean), null);
        }

        $relative = substr($real, strlen(rtrim($base, DIRECTORY_SEPARATOR)) + 1);
        $segments = array_map('rawurlencode', explode(DIRECTORY_SEPARATOR, $relative));
        return self::result(rtrim($themeUrl, '/') . '/' . implode('/', $segments), null);
    }

    /**
     * @return array{icon: string, warning: ?string}
     */
    private static function invalid(): array
    {
        return self::result(self::DEFAULT_ICON, 'icon_invalid');
    }

    /**
     * @return array{icon: string, warning: ?string}
     */
    private static function result(string $icon, ?string $warning): array
    {
        return ['icon' => $icon, 'warning' => $warning];
    }
}
