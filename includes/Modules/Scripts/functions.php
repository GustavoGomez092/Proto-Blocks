<?php
/**
 * Global helpers of the scripts module (proto_*() declaration functions).
 * Loaded at file scope from proto-blocks.php so themes can call them from
 * functions.php.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('proto_script')) {
    /**
     * Declares a snippet output on the front end (spec: docs/scripts.md).
     *
     *     proto_script('GTM', [
     *         'head'      => '<script>…</script>',
     *         'bodyOpen'  => '<noscript>…</noscript>',
     *         'where'     => ['exclude' => ['page:privacy-policy']],
     *     ]);
     *
     * Keys as in script.jsonc (`slug`, `enabled`, `priority`, `priorities`,
     * `head`, `bodyOpen`, `bodyClose`, `where`); snippets are strings only.
     * A file folder with the same slug wins. Calls after `template_redirect`
     * are ignored (`declared_too_late`).
     *
     * @param array<string, mixed> $args
     * @return string|null the script slug, or null when the call was refused
     */
    function proto_script(string $title, array $args = []): ?string
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $source = (string) ($frame['file'] ?? 'unknown') . ':' . (int) ($frame['line'] ?? 0);

        return \ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations::add($title, $args, $source);
    }
}
