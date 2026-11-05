<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

use ProtoBlocks\Modules\Redirects\Engine\Dispatcher;

/**
 * `log.external` (spec §5 settings): redirects issued by other code through
 * wp_redirect() (core, WooCommerce, SEO plugins) go to the redirect log with
 * `redirectBy` set to their X-Redirect-By agent and no group or rule. The
 * module's own redirects (`proto-blocks`) are already logged by the
 * Dispatcher. The `log.redirects` policy still applies (-1 logs nothing).
 *
 * Hooked to `x_redirect_by` at priority 100, so the agent seen is the one
 * other filters settled on; the value is always returned unchanged.
 */
final class ExternalRedirects
{
    public const HOOK = 'x_redirect_by';
    public const PRIORITY = 100;
    private const AGENT_MAX = 50;

    public function __construct(private readonly RedirectLog $log)
    {
    }

    public function register(): void
    {
        add_filter(self::HOOK, [$this, 'onRedirectBy'], self::PRIORITY, 3);
    }

    /**
     * @param mixed $redirectBy the X-Redirect-By value (false sends none)
     * @param mixed $status     the redirect status code
     * @param mixed $location   the redirect target
     * @return mixed $redirectBy unchanged
     */
    public function onRedirectBy($redirectBy, $status = 0, $location = '')
    {
        if (!is_string($redirectBy) || trim($redirectBy) === '') {
            return $redirectBy;
        }
        $agent = mb_substr(strtolower(trim($redirectBy)), 0, self::AGENT_MAX);
        if ($agent === Dispatcher::BY) {
            return $redirectBy;
        }
        $this->log->logRedirect([
            'target' => is_string($location) ? $location : null,
            'code' => is_numeric($status) ? (int) $status : 0,
            'group' => null,
            'ruleId' => null,
            'redirectBy' => $agent,
        ]);

        return $redirectBy;
    }
}
