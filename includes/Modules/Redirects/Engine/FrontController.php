<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

use ProtoBlocks\Modules\Redirects\Registry;

/**
 * Wires the engine into a front-end request (spec §3 "Where it runs").
 *
 * - init (priority from `proto_blocks_redirects_priority`, default 10):
 *   canonical first, then every non-404 rule;
 * - template_redirect 10: `404` rules when WordPress resolved a 404, else the
 *   404 is logged (filter `proto_blocks_redirects_log_404`, request);
 * - redirect_canonical: false once a rule matched, passed or said "nothing";
 * - send_headers: settings `headers.site`, and the 410/451 status line again.
 *
 * Nothing runs in wp-admin, WP-CLI, cron, XML-RPC or for an excluded path
 * (Request::isExcluded()). A request handled at init (any action) is neither
 * re-matched at the 404 stage nor 404-logged.
 */
final class FrontController
{
    /** @var Registry|callable(): list<array<string,mixed>> */
    private $rules;
    /** @var callable(): array{cli?: bool, xmlrpc?: bool} */
    private $environment;
    /** @var callable(): Request */
    private $requestFactory;
    private ?Matcher $matcher = null;
    private ?Request $request = null;
    private ?bool $runs = null;
    private bool $handled = false;

    /**
     * @param Registry|callable(): list<array<string,mixed>> $rules    the enabled rules in evaluation order
     * @param array<string,mixed>                             $settings
     * @param (callable(): array{cli?: bool, xmlrpc?: bool})|null $environment defaults to the WP_CLI / XMLRPC_REQUEST constants
     * @param (callable(): Request)|null                      $requestFactory defaults to Request::fromGlobals()
     */
    public function __construct(
        Registry|callable $rules,
        private readonly array $settings,
        private readonly Dispatcher $dispatcher,
        ?callable $environment = null,
        ?callable $requestFactory = null,
    ) {
        $this->rules = $rules;
        $this->environment = $environment ?? static fn(): array => [
            'cli' => defined('WP_CLI') && constant('WP_CLI'),
            'xmlrpc' => defined('XMLRPC_REQUEST') && constant('XMLRPC_REQUEST'),
        ];
        $this->requestFactory = $requestFactory ?? static fn(): Request => Request::fromGlobals();
    }

    public function register(): void
    {
        $priority = function_exists('apply_filters') ? (int) apply_filters('proto_blocks_redirects_priority', 10) : 10;
        add_action('init', [$this, 'onInit'], $priority);
        add_action('send_headers', [$this, 'onSendHeaders'], 10);
        add_action('template_redirect', [$this, 'onTemplateRedirect'], 10);
        add_filter('redirect_canonical', [$this, 'onRedirectCanonical'], 10, 1);
    }

    public function onInit(): void
    {
        if (!$this->runs()) {
            return;
        }
        $r = $this->request();
        $canonical = Canonical::target($r, $this->settings);
        if ($canonical !== null) {
            $this->handled = true;
            $this->dispatcher->redirect($canonical, 301, $r);
            return;
        }
        $this->run($r, false);
    }

    public function onTemplateRedirect(): void
    {
        if (!$this->runs() || $this->handled || !function_exists('is_404') || !is_404()) {
            return;
        }
        $r = $this->request();
        if ($this->run($r, true)) {
            return;
        }
        $log = function_exists('apply_filters') ? (bool) apply_filters('proto_blocks_redirects_log_404', true, $r) : true;
        if ($log) {
            $this->dispatcher->log404($r);
        }
    }

    /**
     * @param mixed $redirectUrl
     * @return mixed false once a rule handled the request, else $redirectUrl unchanged
     */
    public function onRedirectCanonical($redirectUrl)
    {
        return $this->handled ? false : $redirectUrl;
    }

    public function onSendHeaders(): void
    {
        if (!$this->runs()) {
            return;
        }
        $this->dispatcher->sendSiteHeaders();
        $this->dispatcher->resendStatus();
    }

    /** Whether a rule (or canonical) handled this request. */
    public function handled(): bool
    {
        return $this->handled;
    }

    /** Whether the engine runs for this request at all. */
    public function runs(): bool
    {
        if ($this->runs !== null) {
            return $this->runs;
        }
        $env = ($this->environment)();
        if ((function_exists('is_admin') && is_admin())
            || !empty($env['cli'])
            || !empty($env['xmlrpc'])
            || (function_exists('wp_doing_cron') && wp_doing_cron())
        ) {
            return $this->runs = false;
        }
        return $this->runs = !$this->request()->isExcluded();
    }

    private function run(Request $r, bool $notFoundStage): bool
    {
        $matcher = $this->matcher();
        $match = $matcher->match($r, $notFoundStage);
        $this->dispatcher->reportIssues($matcher->issues(), $r);
        if ($match === null) {
            return false;
        }
        $this->handled = true;
        $this->dispatcher->dispatch($match, $r);
        return true;
    }

    private function request(): Request
    {
        return $this->request ??= ($this->requestFactory)();
    }

    private function matcher(): Matcher
    {
        if ($this->matcher === null) {
            $rules = is_callable($this->rules) ? ($this->rules)() : $this->rules->enabledRules();
            $this->matcher = new Matcher(is_array($rules) ? array_values($rules) : [], $this->settings);
        }
        return $this->matcher;
    }
}
