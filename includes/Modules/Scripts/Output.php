<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\Modules\Scripts\Definition\ScriptNormalizer;
use ProtoBlocks\Modules\Scripts\Definition\Targets;

/**
 * Prints the snippets (spec §3): `wp_head`, `wp_body_open` and `wp_footer`
 * are hooked once each at priority 1; inside, the enabled, in-scope scripts
 * with code at that location run in order of their priority for the
 * location, then slug. Each snippet is echoed exactly as stored, after a
 * `<!-- proto-blocks: <slug> -->` marker (filter
 * `proto_blocks_scripts_markers`, bool, receives the slug and location).
 *
 * Nothing is printed in the admin, feeds, embeds, the customizer preview,
 * REST requests, iframe requests (IFRAME_REQUEST, the block editor and
 * customizer frames), cron or ajax; `proto_blocks_scripts_enabled` (bool,
 * receives the location) can switch output off for a request, never on in
 * those contexts.
 *
 * Output is raw by design: anyone who can write these declarations can run
 * JavaScript on the front end, as anyone who can edit the theme can.
 */
final class Output
{
    public const MARKERS_FILTER = 'proto_blocks_scripts_markers';
    public const ENABLED_FILTER = 'proto_blocks_scripts_enabled';

    /** Location => the action it prints on. */
    public const HOOKS = ['head' => 'wp_head', 'bodyOpen' => 'wp_body_open', 'bodyClose' => 'wp_footer'];

    public const PRIORITY = 1;

    /** stylesheet => true when the last front-end page of that theme never fired wp_body_open. */
    public const BODY_OPEN_OPTION = 'proto_blocks_scripts_body_open';

    private readonly \Closure $registry;
    private readonly \Closure $context;

    /** @var array<string, bool> slug => in scope for this request */
    private array $scope = [];

    private ?RequestContext $resolved = null;

    /**
     * @param Registry|callable(): Registry          $registry
     * @param (callable(): RequestContext)|null      $context default RequestContext::current()
     */
    public function __construct(Registry|callable $registry, ?callable $context = null)
    {
        $this->registry = $registry instanceof Registry ? static fn (): Registry => $registry : \Closure::fromCallable($registry);
        $this->context = $context !== null ? \Closure::fromCallable($context) : static fn (): RequestContext => RequestContext::current();
    }

    public function register(): void
    {
        add_action(self::HOOKS['head'], [$this, 'printHead'], self::PRIORITY);
        add_action(self::HOOKS['bodyOpen'], [$this, 'printBodyOpen'], self::PRIORITY);
        add_action(self::HOOKS['bodyClose'], [$this, 'printBodyClose'], self::PRIORITY);
    }

    public function printHead(): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw by design (spec §3, §5)
        echo $this->render('head');
    }

    public function printBodyOpen(): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw by design
        echo $this->render('bodyOpen');
    }

    public function printBodyClose(): void
    {
        $this->recordBodyOpen();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw by design
        echo $this->render('bodyClose');
    }

    /** Everything printed at $location for the current request ('' when nothing). */
    public function render(string $location): string
    {
        if (!isset(self::HOOKS[$location]) || !$this->enabled($location)) {
            return '';
        }

        $out = '';
        foreach ($this->scriptsFor($location) as $script) {
            $slug = (string) $script['slug'];
            $code = (string) $script['locations'][$location]['code'];
            $markers = apply_filters(self::MARKERS_FILTER, true, $slug, $location) !== false;
            $out .= ($markers ? "\n<!-- proto-blocks: " . $slug . " -->\n" : "\n") . $code . "\n";
        }

        return $out;
    }

    /**
     * The scripts that print at $location on this request, in output order.
     *
     * @return list<array<string, mixed>>
     */
    public function scriptsFor(string $location): array
    {
        $out = [];
        foreach (($this->registry)()->all() as $script) {
            if (!$script['enabled'] || ($script['locations'][$location]['code'] ?? '') === '' || !$this->inScope($script)) {
                continue;
            }
            $out[] = $script;
        }
        usort($out, static fn (array $a, array $b): int => [(int) $a['priorities'][$location], (string) $a['slug']] <=> [(int) $b['priorities'][$location], (string) $b['slug']]);

        return $out;
    }

    /** Whether anything may print at $location on this request. */
    public function enabled(string $location): bool
    {
        if (self::vetoReason() !== null) {
            return false;
        }

        return apply_filters(self::ENABLED_FILTER, true, $location) === true;
    }

    /**
     * Why this request never prints snippets, or null on a front-end page.
     *
     * @return 'admin'|'feed'|'embed'|'customizer'|'rest'|'iframe'|'cron'|'ajax'|null
     */
    public static function vetoReason(): ?string
    {
        if (function_exists('is_admin') && is_admin()) {
            return 'admin';
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'cron';
        }
        if ((defined('REST_REQUEST') && \REST_REQUEST) || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())) {
            return 'rest';
        }
        if (defined('IFRAME_REQUEST') && \IFRAME_REQUEST) {
            return 'iframe';
        }
        if (function_exists('is_customize_preview') && is_customize_preview()) {
            return 'customizer';
        }
        if (function_exists('is_feed') && is_feed()) {
            return 'feed';
        }
        if (function_exists('is_embed') && is_embed()) {
            return 'embed';
        }

        return null;
    }

    /**
     * At wp_footer: remembers whether this theme fired wp_body_open, writing
     * the option only when the answer changes.
     */
    public function recordBodyOpen(): void
    {
        if (self::vetoReason() !== null) {
            return;
        }
        $theme = self::theme();
        $missing = did_action('wp_body_open') === 0;
        $stored = get_option(self::BODY_OPEN_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        if (($stored[$theme] ?? false) === $missing) {
            return;
        }
        if ($missing) {
            $stored[$theme] = true;
        } else {
            unset($stored[$theme]);
        }
        update_option(self::BODY_OPEN_OPTION, $stored, false);
    }

    /**
     * `missing_body_open` warnings: one per enabled script with a bodyOpen
     * snippet while the active theme was last seen not firing wp_body_open.
     *
     * @param list<array<string, mixed>> $scripts
     * @return list<Issue>
     */
    public static function bodyOpenIssues(array $scripts): array
    {
        $stored = get_option(self::BODY_OPEN_OPTION, []);
        if (!is_array($stored) || ($stored[self::theme()] ?? false) !== true) {
            return [];
        }
        $out = [];
        foreach ($scripts as $script) {
            if (!$script['enabled'] || ($script['locations']['bodyOpen']['code'] ?? '') === '') {
                continue;
            }
            $out[] = new Issue(
                Issue::WARNING,
                'missing_body_open',
                __('The active theme does not call wp_body_open(), so the "bodyOpen" snippet of this script is never printed. Add <?php wp_body_open(); ?> right after <body> in the theme, or move the snippet to "head" or "bodyClose".', 'proto-blocks'),
                (string) $script['source'],
                (string) $script['slug'],
                'script'
            );
        }

        return $out;
    }

    /** The active theme's folder name. */
    private static function theme(): string
    {
        return basename((string) get_stylesheet_directory());
    }

    /** @param array<string, mixed> $script */
    private function inScope(array $script): bool
    {
        $slug = (string) $script['slug'];
        if (!isset($this->scope[$slug])) {
            $this->resolved ??= ($this->context)();
            $this->scope[$slug] = Targets::inScope($script['where'], $this->resolved);
        }

        return $this->scope[$slug];
    }
}
