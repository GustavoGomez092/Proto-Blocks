<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\OptionsPages\MenuRegistrar;
use ProtoBlocks\OptionsPages\PageDefinition;

require_once __DIR__ . '/wp-stubs.php';

final class MenuRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_menus'] = [];
        $GLOBALS['pb_test_parent_menus'] = [];
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $GLOBALS['admin_page_hooks'] = ['edit.php?post_type=product' => 'x'];
    }

    private static function page(string $slug, array $data = []): PageDefinition
    {
        return PageDefinition::fromArray($slug, $data + ['title' => ucwords(str_replace('-', ' ', $slug))], 'php');
    }

    /** @param array<string, PageDefinition> $pages */
    private function register(array $pages, ?callable $icon = null): MenuRegistrar
    {
        $r = new MenuRegistrar(
            $icon ?? static fn(PageDefinition $p): array => ['value' => 'dashicons-admin-generic', 'warning' => null],
            static function (PageDefinition $p): void {},
            static fn(string $cap): bool => in_array($cap, ['manage_options', 'manage_woocommerce', 'edit_theme_options'], true)
        );
        $r->register($pages);
        return $r;
    }

    public function testTopLevelPage(): void
    {
        $this->register(['theme-settings' => self::page('theme-settings')]);
        $m = $GLOBALS['pb_test_menus'];
        self::assertCount(1, $m);
        self::assertNull($m[0]['parent']);
        self::assertSame('manage_options', $m[0]['capability']);
        self::assertSame('theme-settings', $m[0]['slug']);
        self::assertSame('dashicons-admin-generic', $m[0]['icon']);
    }

    public function testSubmenuUnderPostTypeMenu(): void
    {
        $p = self::page('cadco-display-settings', [
            'title' => 'Display Settings', 'menu' => 'edit.php?post_type=product', 'capability' => 'manage_woocommerce',
        ]);
        $this->register(['cadco-display-settings' => $p]);
        $m = $GLOBALS['pb_test_menus'][0];
        self::assertSame(
            ['edit.php?post_type=product', 'Display Settings', 'Display Settings', 'manage_woocommerce', 'cadco-display-settings'],
            [$m['parent'], $m['page_title'], $m['menu_title'], $m['capability'], $m['slug']]
        );
    }

    public function testChildrenRegisteredAfterParentUnderParentSlug(): void
    {
        $p = self::page('theme-settings', ['children' => [['slug' => 'footer', 'title' => 'Footer']]]);
        $this->register(['theme-settings' => $p]);
        $m = $GLOBALS['pb_test_menus'];
        self::assertCount(3, $m);
        self::assertSame('theme-settings', $m[0]['slug']);
        self::assertNull($m[0]['parent']);
        self::assertSame(['theme-settings', 'Theme Settings', 'theme-settings'], [$m[1]['parent'], $m[1]['menu_title'], $m[1]['slug']]);
        self::assertSame(['theme-settings', 'Footer', 'theme-settings-footer'], [$m[2]['parent'], $m[2]['menu_title'], $m[2]['slug']]);
    }

    public function testPagesWithMenuPointingAtAnotherDeclaredPageRegisterAfterIt(): void
    {
        $b = self::page('page-b', ['menu' => 'page-a']);
        $a = self::page('page-a');
        $this->register(['page-b' => $b, 'page-a' => $a]);
        $m = $GLOBALS['pb_test_menus'];
        self::assertSame('page-a', $m[0]['slug']);
        self::assertNull($m[0]['parent']);
        $last = end($m);
        self::assertSame(['page-a', 'page-b'], [$last['parent'], $last['slug']]);
    }

    public function testMissingParentRecordsWarning(): void
    {
        $r = $this->register(['x' => self::page('x', ['menu' => 'edit.php?post_type=nope'])]);
        self::assertCount(1, $GLOBALS['pb_test_menus']);
        self::assertContains(
            ['slug' => 'x', 'message' => 'Parent menu "edit.php?post_type=nope" does not exist; the page is reachable only by URL.'],
            $r->warnings()
        );
    }

    public function testIconWarningIsRecorded(): void
    {
        $r = $this->register(
            ['x' => self::page('x')],
            static fn(PageDefinition $p): array => ['value' => 'dashicons-admin-generic', 'warning' => 'Icon not found: icons/x.svg']
        );
        self::assertContains(['slug' => 'x', 'message' => 'Icon not found: icons/x.svg'], $r->warnings());
    }

    public function testIconResolvedOnlyForTopLevel(): void
    {
        $calls = [];
        $this->register(
            ['a' => self::page('a'), 'b' => self::page('b', ['menu' => 'a'])],
            static function (PageDefinition $p) use (&$calls): array {
                $calls[] = $p->slug;
                return ['value' => 'dashicons-admin-generic', 'warning' => null];
            }
        );
        self::assertSame(['a'], $calls);
    }

    public function testUnknownCapabilityKeptAndWarned(): void
    {
        $r = $this->register(['x' => self::page('x', ['capability' => 'manage_woocomerce'])]);
        self::assertSame('manage_woocomerce', $GLOBALS['pb_test_menus'][0]['capability']);
        self::assertContains(
            ['slug' => 'x', 'message' => 'Capability "manage_woocomerce" is not granted to any role; only super admins can open this page.'],
            $r->warnings()
        );
    }

    public function testCycleRegistersTopLevelWithWarning(): void
    {
        $r = $this->register(['a' => self::page('a', ['menu' => 'b']), 'b' => self::page('b', ['menu' => 'a'])]);
        self::assertCount(2, $GLOBALS['pb_test_menus']);
        foreach ($GLOBALS['pb_test_menus'] as $m) {
            self::assertNull($m['parent']);
        }
        self::assertNotEmpty($r->warnings());
    }

    public function testHookSuffixesCollected(): void
    {
        $p = self::page('cadco-display-settings', ['menu' => 'edit.php?post_type=product']);
        $r = $this->register(['cadco-display-settings' => $p]);
        self::assertSame('hook_cadco-display-settings', $r->hookSuffixes()['cadco-display-settings']);
    }
}
