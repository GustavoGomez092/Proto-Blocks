<?php
/**
 * Browser Compiler - Receives browser-compiled Tailwind CSS and stores it.
 *
 * The shell-less counterpart to the CLI compile: the browser produces raw CSS,
 * this validates it, scopes it (reusing Scoper) and saves it (reusing Cache).
 * No exec.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

class BrowserCompiler
{
    /**
     * Default size cap for CSS accepted from the browser (bytes).
     */
    public const DEFAULT_MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Patterns that have no business in a compiled Tailwind stylesheet. The
     * file is served as text/css on every front-end page AND injected into the
     * block editor, so anything that could break out of a style context or
     * pull in remote resources is refused outright.
     */
    private const FORBIDDEN_PATTERNS = [
        '/<\s*\/?\s*style\b/i'                        => '</style',
        '/<\s*script\b/i'                             => '<script',
        '/expression\s*\(/i'                          => 'expression(',
        '/javascript\s*:/i'                           => 'javascript:',
        '/@import\s+url\s*\(\s*["\']?\s*https?:/i'    => '@import url(http',
        '/@import\s+["\']\s*https?:/i'                => '@import "http',
        '/-moz-binding\s*:/i'                         => '-moz-binding',
        '/\bbehavior\s*:/i'                           => 'behavior:',
    ];

    private Scoper $scoper;
    private Cache $cache;
    private ?string $lastError = null;

    public function __construct(Scoper $scoper, Cache $cache)
    {
        $this->scoper = $scoper;
        $this->cache = $cache;
    }

    /**
     * Validate CSS received from the browser.
     *
     * @return string|null Error message when rejected, null when acceptable.
     */
    public static function validate(string $css): ?string
    {
        if (trim($css) === '') {
            return \__('No CSS was produced by the browser compiler.', 'proto-blocks');
        }

        $maxBytes = self::DEFAULT_MAX_BYTES;
        if (function_exists('apply_filters')) {
            /**
             * Filter the maximum size (bytes) of CSS accepted from the browser compiler.
             *
             * @param int $maxBytes Default 2 MB.
             */
            $maxBytes = (int) \apply_filters('proto_blocks_tailwind_max_css_bytes', $maxBytes);
        }
        if ($maxBytes > 0 && strlen($css) > $maxBytes) {
            return sprintf(
                /* translators: %s: size limit in bytes */
                \__('Compiled CSS is too large to store (limit %s bytes).', 'proto-blocks'),
                number_format($maxBytes)
            );
        }

        if (strpos($css, "\0") !== false || (function_exists('mb_check_encoding') && !mb_check_encoding($css, 'UTF-8'))) {
            return \__('Compiled CSS contains invalid bytes.', 'proto-blocks');
        }

        foreach (self::FORBIDDEN_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $css)) {
                return sprintf(
                    /* translators: %s: the rejected token */
                    \__('Compiled CSS was rejected: it contains "%s".', 'proto-blocks'),
                    $label
                );
            }
        }

        return null;
    }

    /**
     * Validate, scope and persist browser-produced CSS.
     *
     * @return bool True when written; false when the input is rejected, the
     *              scoper fails, or the write fails (see getLastError()).
     */
    public function store(string $rawCss): bool
    {
        $this->lastError = null;

        $error = self::validate($rawCss);
        if ($error !== null) {
            $this->lastError = $error;
            return false;
        }

        try {
            $scoped = $this->scoper->scopeCompiledCss($rawCss);
        } catch (\RuntimeException $e) {
            // Never persist unscoped CSS.
            $this->lastError = $e->getMessage();
            return false;
        }

        if (!$this->cache->saveContent($scoped)) {
            $this->lastError = \__('Could not write the compiled CSS to the cache directory.', 'proto-blocks');
            return false;
        }

        return true;
    }

    /**
     * Why the last store() call returned false, if it did.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
