<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * Writes the redirect log and the 404 log (spec §5). Implemented by Track C's
 * Storage\RedirectLog / NotFoundLog, which honour settings['log'] (retention
 * -1 = do not write, IP policy, header capture).
 *
 * logRedirect() entry keys, all present:
 *   url         string  the request URI as received (path + query)
 *   target      ?string the Location sent; null for error actions and unsafe_target reports
 *   code        int     HTTP status; 200 for pass, 0 for an unsafe_target report
 *   group       ?string rule group slug; null for canonical redirects
 *   ruleId      ?string rule id; null for canonical redirects
 *   redirectBy  string  'proto-blocks'
 *   method      string  upper-case request method
 *   agent       string  User-Agent ('' when absent)
 *   referrer    string  Referer ('' when absent)
 *   reason      string  'matched' | 'else' | 'canonical' | 'unsafe_target' | 'self_loop'
 *   request     Request the normalised request (server vars for IP resolution, headers)
 */
interface RedirectLogger
{
    /** @param array<string,mixed> $entry see the interface docblock */
    public function logRedirect(array $entry): void;

    public function log404(Request $r): void;
}
