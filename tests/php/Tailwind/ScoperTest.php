<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\Scoper;

/**
 * Selector scoping must never corrupt the stylesheet. Two regressions are
 * pinned here, both found in production-shaped Tailwind v4 output:
 *
 * 1. Comma splitting inside functional pseudo-classes. The group-open
 *    variant compiles to `:is([open],:popover-open,:open)`; splitting the
 *    selector list on every comma shreds it into paren-unbalanced
 *    fragments. Browsers hit the unclosed `(` and silently consume the
 *    REST OF THE STYLESHEET as part of that selector, dropping every rule
 *    after it (all hover:/lg: rules -> broken desktop layout).
 *
 * 2. Escaped colons in variant class names. The wrapper-compound form
 *    (`.proto-blocks-scope.utility`) was skipped for any selector
 *    containing `:`, which wrongly excluded `.lg\:h-\[640px\]`-style
 *    classes -- so responsive/state utilities placed on a block's
 *    outermost wrapper element (the one carrying proto-blocks-scope
 *    itself) never matched.
 */
final class ScoperTest extends TestCase
{
    private Scoper $scoper;

    protected function setUp(): void
    {
        $this->scoper = new Scoper();
    }

    public function test_commas_inside_functional_pseudo_classes_are_not_split(): void
    {
        $css = '.group-open\:rotate-180:is(:where(.group):is([open],:popover-open,:open) *){rotate:180deg}';

        $scoped = $this->scoper->scopeCompiledCss($css);

        // The :is() argument list must survive intact...
        $this->assertStringContainsString(':is([open],:popover-open,:open)', $scoped);
        // ...and no fragment of it may leak out as its own scoped selector.
        $this->assertStringNotContainsString('.proto-blocks-scope :popover-open', $scoped);
        $this->assertSame(
            substr_count($scoped, '('),
            substr_count($scoped, ')'),
            'Scoped output has unbalanced parentheses -- browsers would drop every rule after this point'
        );
    }

    public function test_variant_class_gets_wrapper_compound_form(): void
    {
        // lg: variant on a block wrapper element (e.g. the hero section itself).
        $css = '@media (min-width:64rem){.lg\:h-\[640px\]{height:640px}}';

        $scoped = $this->scoper->scopeCompiledCss($css);

        $this->assertStringContainsString('.proto-blocks-scope.lg\:h-\[640px\]', $scoped, 'wrapper compound form missing');
        $this->assertStringContainsString('.proto-blocks-scope .lg\:h-\[640px\]', $scoped, 'descendant form missing');
    }

    public function test_simple_class_gets_both_forms(): void
    {
        $scoped = $this->scoper->scopeCompiledCss('.rounded-full{border-radius:9999px}');

        $this->assertStringContainsString('.proto-blocks-scope.rounded-full', $scoped);
        $this->assertStringContainsString('.proto-blocks-scope .rounded-full', $scoped);
    }

    public function test_selector_with_combinator_gets_descendant_form_only(): void
    {
        $css = '.group-hover\:invert:is(:where(.group):hover *){filter:invert(100%)}';

        $scoped = $this->scoper->scopeCompiledCss($css);

        $this->assertStringContainsString('.proto-blocks-scope .group-hover\:invert', $scoped);
        $this->assertStringNotContainsString('.proto-blocks-scope.group-hover\:invert', $scoped);
    }

    public function test_multi_selector_list_is_split_on_top_level_commas(): void
    {
        $scoped = $this->scoper->scopeCompiledCss('.a,.b{color:red}');

        $this->assertStringContainsString('.proto-blocks-scope.a', $scoped);
        $this->assertStringContainsString('.proto-blocks-scope.b', $scoped);
    }

    public function test_realistic_sheet_stays_balanced(): void
    {
        // A mini stylesheet shaped like real compiler output: base utility,
        // the comma-carrying group-open rule, then a media block. If rule 2
        // is mangled, everything after it is lost in the browser.
        $css = '.select-none{user-select:none}'
            . '.group-open\:rotate-180:is(:where(.group):is([open],:popover-open,:open) *){rotate:180deg}'
            . '@media (min-width:64rem){.lg\:flex{display:flex}.lg\:hidden{display:none}}';

        $scoped = $this->scoper->scopeCompiledCss($css);

        $this->assertSame(substr_count($scoped, '('), substr_count($scoped, ')'));
        $this->assertSame(substr_count($scoped, '{'), substr_count($scoped, '}'));
        $this->assertStringContainsString('.proto-blocks-scope.lg\:flex', $scoped);
        $this->assertStringContainsString('.proto-blocks-scope.lg\:hidden', $scoped);
    }
}
