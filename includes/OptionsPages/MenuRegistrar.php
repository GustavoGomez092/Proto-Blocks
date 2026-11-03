<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

/**
 * Registers settings pages in the WordPress admin menu. Capabilities are passed
 * to WordPress verbatim: an unknown one is kept and surfaced as a warning
 * (WordPress denies unknown capabilities, so the page fails closed).
 */
final class MenuRegistrar
{
    public const GENERIC_ICON = 'dashicons-admin-generic';

    /** @var callable(PageDefinition): array{value: string, warning: ?string} */
    private $iconResolver;
    /** @var callable(PageDefinition): void */
    private $render;
    /** @var callable(string): bool */
    private $capabilityKnown;

    /** @var array<string, string> */
    private array $suffixes = [];
    /** @var list<array{slug: string, message: string, code?: string, level?: string}> */
    private array $warnings = [];

    public function __construct(callable $iconResolver, callable $render, callable $capabilityKnown)
    {
        $this->iconResolver = $iconResolver;
        $this->render = $render;
        $this->capabilityKnown = $capabilityKnown;
    }

    /**
     * @param array<string, PageDefinition> $pages
     */
    public function register(array $pages): void
    {
        $this->suffixes = [];
        $this->warnings = [];

        $declared = [];
        foreach ($pages as $page) {
            $declared[$page->slug] = $page;
        }

        // Break cycles: only pages that sit ON a cycle register top-level;
        // pages that merely lead into one keep their submenu placement.
        $topLevel = [];
        foreach ($declared as $slug => $page) {
            $cur = $page;
            $steps = 0;
            while ($cur->menu !== 'top' && isset($declared[$cur->menu]) && $steps++ <= count($declared)) {
                $cur = $declared[$cur->menu];
                if ($cur->slug === $slug) {
                    $topLevel[$slug] = true;
                    $this->warn($slug, __('Menu parents form a cycle; the page is registered at the top level.', 'proto-blocks'));
                    break;
                }
            }
        }

        $done = [];
        $visit = function (PageDefinition $page) use (&$visit, &$done, $declared, $topLevel): void {
            if (isset($done[$page->slug])) {
                return;
            }
            $done[$page->slug] = true;
            $forceTop = isset($topLevel[$page->slug]);
            if (!$forceTop && isset($declared[$page->menu])) {
                $visit($declared[$page->menu]);
            }
            $this->registerOne($page, $forceTop, $declared);
            foreach ($page->children as $child) {
                $this->registerChild($child, $page, $forceTop);
            }
        };
        foreach ($declared as $page) {
            $visit($page);
        }
    }

    /** @return array<string, string> */
    public function hookSuffixes(): array
    {
        return $this->suffixes;
    }

    /** @return list<array{slug: string, message: string, code?: string, level?: string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<string, PageDefinition> $declared
     */
    private function registerOne(PageDefinition $page, bool $forceTop, array $declared): void
    {
        $this->checkCapability($page);
        $render = fn() => ($this->render)($page);
        $top = $forceTop || $page->menu === 'top';

        if ($top) {
            $icon = ($this->iconResolver)($page);
            if ($icon['warning'] !== null) {
                $this->warn($page->slug, $icon['warning']);
            }
            $suffix = add_menu_page(
                $page->title,
                $page->menuTitle,
                $page->capability,
                $page->slug,
                $render,
                $icon['value'] !== '' ? $icon['value'] : self::GENERIC_ICON,
                $page->position
            );
        } else {
            if (!isset($declared[$page->menu]) && !$this->parentExists($page->menu)) {
                $this->warn($page->slug, sprintf(
                    /* translators: %s: parent menu slug */
                    __('Parent menu "%s" does not exist; the page is reachable only by URL.', 'proto-blocks'),
                    $page->menu
                ));
            }
            $suffix = add_submenu_page(
                $page->menu,
                $page->title,
                $page->menuTitle,
                $page->capability,
                $page->slug,
                $render,
                $page->position
            );
        }
        $this->collect($page->slug, $suffix);

        if ($top && $page->children !== []) {
            // WordPress relabels the parent after its first submenu; repeat the
            // parent so it keeps its own label.
            add_submenu_page($page->slug, $page->title, $page->menuTitle, $page->capability, $page->slug, $render);
        }
    }

    private function registerChild(PageDefinition $child, PageDefinition $page, bool $forceTop): void
    {
        $this->checkCapability($child);
        $parent = $child->menu;
        if (!$forceTop && $page->menu !== 'top') {
            // The page is itself only a submenu, so WordPress would hide
            // anything registered beneath it: place the child beside it.
            $parent = $page->menu;
            $this->warn($child->slug, sprintf(
                /* translators: 1: child page slug, 2: page slug, 3: parent menu */
                __('Child page "%1$s" of "%2$s" is listed under "%3$s", beside its parent, because submenu pages cannot have children.', 'proto-blocks'),
                $child->slug,
                $page->slug,
                $parent
            ), 'child_of_submenu', 'info');
        }
        $suffix = add_submenu_page(
            $parent,
            $child->title,
            $child->menuTitle,
            $child->capability,
            $child->slug,
            fn() => ($this->render)($child),
            $child->position
        );
        $this->collect($child->slug, $suffix);
    }

    private function checkCapability(PageDefinition $page): void
    {
        if (!($this->capabilityKnown)($page->capability)) {
            $this->warn($page->slug, sprintf(
                /* translators: %s: capability name */
                __('Capability "%s" is not granted to any role; only super admins can open this page.', 'proto-blocks'),
                $page->capability
            ));
        }
    }

    private function collect(string $slug, mixed $suffix): void
    {
        if (is_string($suffix) && $suffix !== '') {
            $this->suffixes[$slug] = $suffix;
        }
    }

    private function parentExists(string $parent): bool
    {
        foreach ((array) ($GLOBALS['menu'] ?? []) as $item) {
            if (is_array($item) && ($item[2] ?? null) === $parent) {
                return true;
            }
        }

        return isset($GLOBALS['submenu'][$parent]) || isset($GLOBALS['admin_page_hooks'][$parent]);
    }

    private function warn(string $slug, string $message, ?string $code = null, ?string $level = null): void
    {
        $entry = ['slug' => $slug, 'message' => $message];
        if ($code !== null) {
            $entry['code'] = $code;
            $entry['level'] = $level ?? 'warning';
        }
        $this->warnings[] = $entry;
    }
}
