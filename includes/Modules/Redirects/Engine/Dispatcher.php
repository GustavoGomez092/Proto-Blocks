<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * Carries out a match (spec §3 "Actions").
 *
 * - url:     rule + settings redirect headers, cache headers (301/308 per
 *            settings.cache; 302/303/307 nocache_headers()), hit + log, then
 *            wp_redirect($target, $code, 'proto-blocks') (core sends
 *            X-Redirect-By) and $exit();
 * - error:   status_header($code), nocache_headers(), X-Redirect-By, the main
 *            query set to 404 (now and again on `wp`, after WordPress ran its
 *            own query), the theme's 404 template renders; 410 and 451 also
 *            re-send the status line at send_headers (resendStatus());
 * - pass:    REQUEST_URI / PATH_INFO / QUERY_STRING / $_GET replaced, WordPress
 *            parses the new request;
 * - nothing: fires `proto_blocks_redirects_nothing`.
 *
 * $header(string $name, string $value) and $exit() are injected so tests can
 * capture them; production passes header() and exit.
 */
final class Dispatcher
{
    public const BY = 'proto-blocks';

    /** @var callable(string, string): void */
    private $header;
    /** @var callable(): void */
    private $exit;
    private ?int $pendingStatus = null;
    private bool $errorHooked = false;
    private bool $issuesReported = false;

    /** @param array<string,mixed> $settings */
    public function __construct(
        private readonly array $settings,
        callable $header,
        callable $exit,
        private readonly ?HitRecorder $hits = null,
        private readonly ?RedirectLogger $log = null,
    ) {
        $this->header = $header;
        $this->exit = $exit;
    }

    /**
     * @param array{rule: array<string,mixed>, target: ?string, action: string, code: ?int, reason: string} $match
     */
    public function dispatch(array $match, Request $r): void
    {
        $rule = $match['rule'];
        switch ($match['action']) {
            case 'url':
                $target = (string) $match['target'];
                $code = (int) ($match['code'] ?? 301);
                foreach (Headers::redirect($rule, $this->settings) as $name => $value) {
                    ($this->header)($name, $value);
                }
                $this->record($rule);
                $this->logRule($rule, $r, $target, $code, $match['reason']);
                $this->send($target, $code);
                return;

            case 'error':
                $code = (int) ($match['code'] ?? 404);
                $this->record($rule);
                $this->logRule($rule, $r, null, $code, $match['reason']);
                $this->pendingStatus = $code;
                $this->applyError();
                ($this->header)('X-Redirect-By', self::BY);
                if (!$this->errorHooked && function_exists('add_action')) {
                    // WordPress runs the main query after init and would replace the 404 state.
                    add_action('wp', [$this, 'applyError'], 1000);
                    $this->errorHooked = true;
                }
                return;

            case 'pass':
                $this->record($rule);
                $this->logRule($rule, $r, (string) $match['target'], 200, $match['reason']);
                self::rewrite((string) $match['target']);
                return;

            case 'nothing':
                $this->record($rule);
                if (function_exists('do_action')) {
                    do_action('proto_blocks_redirects_nothing', $rule, $r);
                }
                return;
        }
    }

    /** A canonical (settings) redirect: 301 with redirect headers, logged without a rule. */
    public function redirect(string $target, int $code, Request $r): void
    {
        foreach (Headers::redirect([], $this->settings) as $name => $value) {
            ($this->header)($name, $value);
        }
        if ($this->log !== null) {
            $this->log->logRedirect(self::entry($r, $target, $code, null, null, 'canonical'));
        }
        $this->send(function_exists('wp_sanitize_redirect') ? (string) wp_sanitize_redirect($target) : $target, $code);
    }

    /** Status, no-cache and the 404 query state for a pending error action. Hooked on `wp`. */
    public function applyError(): void
    {
        if ($this->pendingStatus === null) {
            return;
        }
        global $wp_query;
        if (is_object($wp_query) && method_exists($wp_query, 'set_404')) {
            $wp_query->set_404();
        }
        if (function_exists('status_header')) {
            status_header($this->pendingStatus);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }

    /** The error status still to (re-)send, or null. */
    public function pendingStatus(): ?int
    {
        return $this->pendingStatus;
    }

    /** send_headers: 410 and 451 set the status line again so caches see it. */
    public function resendStatus(): void
    {
        if (in_array($this->pendingStatus, [410, 451], true) && function_exists('status_header')) {
            status_header($this->pendingStatus);
        }
    }

    /** send_headers: settings.headers.site on every non-excluded front-end response. */
    public function sendSiteHeaders(): void
    {
        foreach (Headers::site($this->settings) as $name => $value) {
            ($this->header)($name, $value);
        }
    }

    /** A request WordPress resolved to a 404 that no rule handled. */
    public function log404(Request $r): void
    {
        $this->log?->log404($r);
    }

    /**
     * Records refused targets in the redirect log (code 0), once per request.
     *
     * @param list<array{code: string, group: string, ruleId: string, message: string}> $issues
     */
    public function reportIssues(array $issues, Request $r): void
    {
        if ($this->issuesReported || $this->log === null) {
            return;
        }
        foreach ($issues as $issue) {
            if ($issue['code'] === 'unsafe_target') {
                $this->issuesReported = true;
                $this->log->logRedirect(self::entry($r, null, 0, $issue['group'], $issue['ruleId'], 'unsafe_target'));
                return;
            }
        }
    }

    private function send(string $target, int $code): void
    {
        if ($code === 301 || $code === 308) {
            $this->cacheHeaders();
        } elseif (function_exists('nocache_headers')) {
            nocache_headers();
        }
        wp_redirect($target, $code, self::BY);
        ($this->exit)();
    }

    private function cacheHeaders(): void
    {
        $hours = (int) ($this->settings['cache'] ?? 1);
        if ($hours < 0) {
            if (function_exists('nocache_headers')) {
                nocache_headers();
            }
            return;
        }
        if ($hours === 0) {
            return;
        }
        $seconds = $hours * 3600;
        $now = function_exists('current_time') ? (int) current_time('timestamp', true) : time();
        ($this->header)('Cache-Control', 'max-age=' . $seconds);
        ($this->header)('Expires', gmdate('D, d M Y H:i:s', $now + $seconds) . ' GMT');
    }

    /** @param array<string,mixed> $rule */
    private function record(array $rule): void
    {
        if ($this->hits === null || ($this->settings['trackHits'] ?? true) === false || ($rule['log'] ?? true) === false) {
            return;
        }
        $this->hits->record((string) ($rule['group'] ?? ''), (string) ($rule['id'] ?? ''));
    }

    /** @param array<string,mixed> $rule */
    private function logRule(array $rule, Request $r, ?string $target, int $code, string $reason): void
    {
        if ($this->log === null || ($rule['log'] ?? true) === false) {
            return;
        }
        $this->log->logRedirect(self::entry($r, $target, $code, (string) ($rule['group'] ?? ''), (string) ($rule['id'] ?? ''), $reason));
    }

    /** @return array<string,mixed> */
    private static function entry(Request $r, ?string $target, int $code, ?string $group, ?string $ruleId, string $reason): array
    {
        return [
            'url' => $r->uri,
            'target' => $target,
            'code' => $code,
            'group' => $group,
            'ruleId' => $ruleId,
            'redirectBy' => self::BY,
            'method' => $r->method,
            'agent' => $r->userAgent,
            'referrer' => $r->referrer,
            'reason' => $reason,
            'request' => $r,
        ];
    }

    /** Internal rewrite for `pass`: WordPress then parses $target as the request. */
    private static function rewrite(string $target): void
    {
        $q = strpos($target, '?');
        $path = $q === false ? $target : substr($target, 0, $q);
        $query = $q === false ? '' : substr($target, $q + 1);
        $hash = strpos($query, '#');
        if ($hash !== false) {
            $query = substr($query, 0, $hash);
        }
        $hash = strpos($path, '#');
        if ($hash !== false) {
            $path = substr($path, 0, $hash);
        }
        $_SERVER['REQUEST_URI'] = $query === '' ? $path : $path . '?' . $query;
        $_SERVER['PATH_INFO'] = $path;
        $_SERVER['QUERY_STRING'] = $query;
        $get = [];
        if ($query !== '') {
            parse_str($query, $get);
        }
        $_GET = function_exists('wp_slash') ? (array) wp_slash($get) : $get;
        // As wp_magic_quotes() builds it: the (already slashed) POST wins over GET.
        $_REQUEST = array_merge($_GET, isset($_POST) && is_array($_POST) ? $_POST : []);
    }
}
