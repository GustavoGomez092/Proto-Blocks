<?php
/**
 * Global helpers of the redirects module (proto_*() declaration functions).
 * Loaded at file scope from proto-blocks.php so themes can call them from
 * functions.php. Calls are accepted until `init` priority 9; the module
 * validates them like group files, and a file group or file rule declaring
 * the same thing wins (`double_declaration`).
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('proto_redirect')) {
    /**
     * Declares one redirect in the virtual group "Declared in PHP".
     *
     *     proto_redirect('/old', '/new');                     // 301
     *     proto_redirect('/promo', '/sale', 302);
     *     proto_redirect('/gone', null, 410);                 // 4xx/5xx: action "error"
     *     proto_redirect('^/blog/(.*)$', '/news/$1', ['regex' => true]);
     *
     * @param int|array<string, mixed> $codeOrArgs the code, or every rule key of a group file
     */
    function proto_redirect(string $from, ?string $to = null, int|array $codeOrArgs = 301): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $source = (string) ($caller['file'] ?? 'unknown') . ':' . (int) ($caller['line'] ?? 0);

        if (is_int($codeOrArgs)) {
            $rule = ['code' => $codeOrArgs];
            if ($codeOrArgs >= 400 && $codeOrArgs <= 599) {
                $rule['action'] = 'error';
            }
        } else {
            $rule = $codeOrArgs;
        }
        $rule = ['from' => $from] + ($to !== null ? ['to' => $to] : []) + $rule;
        $rule['from'] = $from;
        if ($to !== null) {
            $rule['to'] = $to;
        }

        \ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations::addRule($rule, $source);
    }
}

if (!function_exists('proto_redirect_group')) {
    /**
     * Declares a whole redirect group with the keys of a group file
     * (`enabled`, `position`, `defaults`, `rules`); the slug derives from the
     * title unless `slug` is given.
     *
     * @param array<string, mixed> $group
     */
    function proto_redirect_group(string $title, array $group = []): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $source = (string) ($caller['file'] ?? 'unknown') . ':' . (int) ($caller['line'] ?? 0);

        \ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations::addGroup($title, $group, $source);
    }
}
