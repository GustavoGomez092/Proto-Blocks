<?php
/**
 * Directory Guard - Keeps Tailwind working directories out of the web.
 *
 * Everything the Tailwind subsystem writes lives under wp-content/uploads,
 * which is always web-served. This helper drops an index.php (directory
 * listing) and a .htaccess (Apache/LiteSpeed access rules) into a directory
 * the first time it is created. Nginx ignores .htaccess, which is why the
 * compile inputs are also deleted after every compile (see Compiler).
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

final class DirectoryGuard
{
    /**
     * Deny every direct web request to the directory.
     *
     * Delegates to Template\Cache::protectDirectory() when that helper exists
     * so both subsystems write identical guard files; otherwise writes its own.
     */
    public static function denyAll(string $dir): void
    {
        $dir = self::normalize($dir);
        if (!is_dir($dir)) {
            return;
        }

        if (method_exists(\ProtoBlocks\Template\Cache::class, 'protectDirectory')) {
            \ProtoBlocks\Template\Cache::protectDirectory($dir);
            // Belt and braces: make sure both files exist whatever the helper wrote.
        }

        self::writeIfMissing($dir . 'index.php', self::indexPhp());
        self::writeIfMissing($dir . '.htaccess', self::denyAllHtaccess());
    }

    /**
     * Deny every direct web request except for *.css files.
     *
     * Used for the compiled-output directory: tailwind.css must stay public
     * (it is enqueued by URL), but the hash file and anything else that lands
     * next to it must not be.
     */
    public static function allowOnlyCss(string $dir): void
    {
        $dir = self::normalize($dir);
        if (!is_dir($dir)) {
            return;
        }

        self::writeIfMissing($dir . 'index.php', self::indexPhp());
        self::writeIfMissing($dir . '.htaccess', self::cssOnlyHtaccess());
    }

    /**
     * Contents of the deny-all .htaccess (public for tests).
     */
    public static function denyAllHtaccess(): string
    {
        return "# Proto-Blocks: no direct web access to this directory.\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order deny,allow\n"
            . "    Deny from all\n"
            . "</IfModule>\n";
    }

    /**
     * Contents of the css-only .htaccess (public for tests).
     */
    public static function cssOnlyHtaccess(): string
    {
        return "# Proto-Blocks: only compiled stylesheets are web-readable here.\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "    <FilesMatch \"\\.css$\">\n"
            . "        Require all granted\n"
            . "    </FilesMatch>\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order deny,allow\n"
            . "    Deny from all\n"
            . "    <FilesMatch \"\\.css$\">\n"
            . "        Allow from all\n"
            . "    </FilesMatch>\n"
            . "</IfModule>\n";
    }

    private static function indexPhp(): string
    {
        return "<?php\n// Silence is golden.\n";
    }

    private static function normalize(string $dir): string
    {
        return rtrim($dir, '/\\') . '/';
    }

    private static function writeIfMissing(string $path, string $contents): void
    {
        if (file_exists($path) || is_link($path)) {
            return;
        }

        Cache::atomicWrite($path, $contents);
    }
}
