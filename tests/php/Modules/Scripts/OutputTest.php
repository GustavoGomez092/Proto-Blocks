<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ProtoBlocks\Modules\Scripts\Definition\ScriptNormalizer;
use ProtoBlocks\Modules\Scripts\Output;
use ProtoBlocks\Modules\Scripts\Registry;
use ProtoBlocks\Modules\Scripts\RequestContext;

/**
 * Spec §3: hooks, order, markers, per-location priority, absent and
 * disabled locations, veto contexts, the enabled filter, missing_body_open.
 */
final class OutputTest extends ScriptsTestCase
{
    private static function s(string $slug, array $data): array
    {
        return ScriptNormalizer::normalize(['title' => $slug] + $data, $slug, 'file', '/t/proto-scripts/' . $slug . '/script.jsonc');
    }

    /** @param list<array<string, mixed>> $scripts */
    private static function printer(array $scripts, ?RequestContext $ctx = null): Output
    {
        return new Output(Registry::fromArrays($scripts), static fn (): RequestContext => $ctx ?? new RequestContext('/'));
    }

    public function testRegistersTheThreeHooksAtPriorityOne(): void
    {
        $output = self::printer([]);
        $output->register();
        foreach (['wp_head' => 'printHead', 'wp_body_open' => 'printBodyOpen', 'wp_footer' => 'printBodyClose'] as $hook => $method) {
            self::assertSame([[$output, $method]], array_column($GLOBALS['pb_test_hooks'][$hook], 'callback'), $hook);
            self::assertSame(1, $GLOBALS['pb_test_hooks'][$hook][0]['priority']);
        }
    }

    public function testMarkersAndRawOutput(): void
    {
        $code = "<script>window.dataLayer=[];/* & < > \" ' */</script>";
        $out = self::printer([self::s('gtm', ['head' => $code])])->render('head');
        self::assertSame("\n<!-- proto-blocks: gtm -->\n" . $code . "\n", $out);
    }

    public function testMarkersFilterDropsThem(): void
    {
        $seen = [];
        $GLOBALS['pb_test_filters'][Output::MARKERS_FILTER][] = static function (bool $on, string $slug, string $location) use (&$seen): bool {
            $seen[] = [$slug, $location];
            return false;
        };
        self::assertSame("\n<meta>\n", self::printer([self::s('gtm', ['head' => '<meta>'])])->render('head'));
        self::assertSame([['gtm', 'head']], $seen);
    }

    public function testOrderByPriorityThenSlug(): void
    {
        $scripts = [
            self::s('b-second', ['head' => 'B', 'priority' => 5]),
            self::s('a-first', ['head' => 'A', 'priority' => 5]),
            self::s('late', ['head' => 'L', 'priority' => 20]),
            self::s('early', ['head' => 'E', 'priority' => -1]),
            self::s('default', ['head' => 'D']),
        ];
        self::assertSame(['early', 'a-first', 'b-second', 'default', 'late'], array_column(self::printer($scripts)->scriptsFor('head'), 'slug'));
    }

    public function testPerLocationPriorityOverride(): void
    {
        $scripts = [
            self::s('alpha', ['head' => 'A', 'bodyClose' => 'A', 'priority' => 10, 'priorities' => ['bodyClose' => 99]]),
            self::s('beta', ['head' => 'B', 'bodyClose' => 'B', 'priority' => 20]),
        ];
        $output = self::printer($scripts);
        self::assertSame(['alpha', 'beta'], array_column($output->scriptsFor('head'), 'slug'));
        self::assertSame(['beta', 'alpha'], array_column($output->scriptsFor('bodyClose'), 'slug'));
    }

    public function testEachHookPrintsItsOwnLocation(): void
    {
        $output = self::printer([self::s('gtm', ['head' => 'H', 'bodyOpen' => 'O', 'bodyClose' => 'C'])]);
        ob_start();
        $output->printHead();
        $head = (string) ob_get_clean();
        ob_start();
        $output->printBodyOpen();
        $open = (string) ob_get_clean();
        ob_start();
        $output->printBodyClose();
        $close = (string) ob_get_clean();
        self::assertStringContainsString("\nH\n", $head);
        self::assertStringContainsString("\nO\n", $open);
        self::assertStringContainsString("\nC\n", $close);
        self::assertStringNotContainsString('O', $head . $close);
    }

    public function testAbsentAndDisabled(): void
    {
        $output = self::printer([
            self::s('off', ['head' => 'X', 'enabled' => false]),
            self::s('nohead', ['bodyClose' => 'Y']),
            self::s('empty', ['head' => '']),
        ]);
        self::assertSame('', $output->render('head'));
        self::assertSame(['nohead'], array_column($output->scriptsFor('bodyClose'), 'slug'));
        self::assertSame('', $output->render('nowhere'));
    }

    public function testMissingSnippetFileOutputsNothing(): void
    {
        $script = ScriptNormalizer::normalize(['title' => 'x', 'head' => ['file' => 'head.html']], 'x', 'file', '/x');
        self::assertSame('', self::printer([$script])->render('head'));
    }

    public function testScopeIsApplied(): void
    {
        $GLOBALS['pb_test_query'] = ['is_page' => ['privacy-policy']];
        $scripts = [
            self::s('everywhere', ['head' => 'E']),
            self::s('not-privacy', ['head' => 'N', 'where' => ['exclude' => ['page:privacy-policy']]]),
            self::s('landing', ['head' => 'L', 'where' => ['include' => ['url:/landing/*']]]),
            self::s('members', ['head' => 'M', 'where' => ['loggedIn' => true]]),
        ];
        self::assertSame(['everywhere'], array_column(self::printer($scripts, new RequestContext('/privacy-policy'))->scriptsFor('head'), 'slug'));
        $GLOBALS['pb_test_query'] = [];
        self::assertSame(['everywhere', 'landing', 'members', 'not-privacy'], array_column(self::printer($scripts, new RequestContext('/landing/x', true))->scriptsFor('head'), 'slug'));
    }

    public function testVetoContexts(): void
    {
        $scripts = [self::s('gtm', ['head' => 'H'])];
        $cases = [
            'admin' => static function (): void { $GLOBALS['pb_test_is_admin'] = true; },
            'feed' => static function (): void { $GLOBALS['pb_test_query']['is_feed'] = true; },
            'embed' => static function (): void { $GLOBALS['pb_test_query']['is_embed'] = true; },
            'customizer' => static function (): void { $GLOBALS['pb_test_customize_preview'] = true; },
            'cron' => static function (): void { $GLOBALS['pb_test_doing_cron'] = true; },
            'ajax' => static function (): void { $GLOBALS['pb_test_doing_ajax'] = true; },
        ];
        foreach ($cases as $reason => $set) {
            unset($GLOBALS['pb_test_is_admin'], $GLOBALS['pb_test_customize_preview'], $GLOBALS['pb_test_doing_cron'], $GLOBALS['pb_test_doing_ajax']);
            $GLOBALS['pb_test_query'] = [];
            self::assertNotSame('', self::printer($scripts)->render('head'), 'front end before ' . $reason);
            $set();
            self::assertSame($reason, Output::vetoReason());
            self::assertSame('', self::printer($scripts)->render('head'), $reason);
        }
    }

    public function testEnabledFilterCanVetoButNotForce(): void
    {
        $scripts = [self::s('gtm', ['head' => 'H', 'bodyClose' => 'C'])];
        $GLOBALS['pb_test_filters'][Output::ENABLED_FILTER][] = static fn (bool $on, string $location): bool => $location !== 'head';
        $output = self::printer($scripts);
        self::assertSame('', $output->render('head'));
        self::assertNotSame('', $output->render('bodyClose'));

        $GLOBALS['pb_test_filters'][Output::ENABLED_FILTER] = [static fn (): bool => true];
        $GLOBALS['pb_test_is_admin'] = true;
        self::assertSame('', self::printer($scripts)->render('head'), 'the filter cannot turn the admin on');

        unset($GLOBALS['pb_test_is_admin']);
        $GLOBALS['pb_test_filters'][Output::ENABLED_FILTER] = [static fn (): string => 'yes'];
        self::assertSame('', self::printer($scripts)->render('head'), 'only a real true keeps output on');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestRequestIsVetoed(): void
    {
        define('REST_REQUEST', true);
        self::assertSame('rest', Output::vetoReason());
        self::assertSame('', self::printer([self::s('gtm', ['head' => 'H'])])->render('head'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIframeRequestIsVetoed(): void
    {
        define('IFRAME_REQUEST', true);
        self::assertSame('iframe', Output::vetoReason());
    }

    public function testMissingBodyOpenIsRecordedAndReported(): void
    {
        $scripts = [self::s('gtm', ['bodyOpen' => '<noscript></noscript>']), self::s('headonly', ['head' => 'H'])];
        $output = self::printer($scripts);

        ob_start();
        $output->printBodyClose();
        ob_end_clean();
        $theme = basename($this->root);
        self::assertSame([$theme => true], $GLOBALS['pb_test_options'][Output::BODY_OPEN_OPTION]);
        self::assertTrue($GLOBALS['pb_test_autoload'][Output::BODY_OPEN_OPTION], 'autoloaded: read on every page');

        $issues = Output::bodyOpenIssues($scripts);
        self::assertSame(['missing_body_open'], array_map(static fn ($i): string => $i->code, $issues));
        self::assertSame('gtm', $issues[0]->slug);

        // The theme fires it on a later page: the warning goes away.
        do_action('wp_body_open');
        $output->printBodyClose();
        self::assertArrayNotHasKey(Output::BODY_OPEN_OPTION, $GLOBALS['pb_test_options'], 'deleted, not stored empty');
        self::assertSame([], Output::bodyOpenIssues($scripts));

        // Unchanged state: no write.
        $GLOBALS['pb_test_options'][Output::BODY_OPEN_OPTION] = [basename($this->root) => true];
        $GLOBALS['pb_test_actions'] = [];
        $writes = $GLOBALS['pb_test_update_calls'] ?? 0;
        $output->printBodyClose();
        self::assertSame($writes, $GLOBALS['pb_test_update_calls'] ?? 0);
        self::assertSame(true, $GLOBALS['pb_test_autoload'][Output::BODY_OPEN_OPTION] ?? true);
    }

    public function testBodyOpenNotRecordedInVetoedContexts(): void
    {
        $GLOBALS['pb_test_is_admin'] = true;
        self::printer([])->recordBodyOpen();
        self::assertArrayNotHasKey(Output::BODY_OPEN_OPTION, $GLOBALS['pb_test_options']);
    }
}
