<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\OptionsPages\InvalidDeclaration;
use ProtoBlocks\OptionsPages\PageDefinition;
use ProtoBlocks\OptionsPages\ProtectedOptions;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../../includes/OptionsPages/functions.php';

final class PageDefinitionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['proto_blocks_php_options_pages'] = [];
    }

    /** Strip // and /* comments and trailing commas outside strings. */
    private static function jsonc(string $file): array
    {
        $src = (string) file_get_contents(__DIR__ . '/../../fixtures/options-pages/' . $file . '/page.jsonc');
        $out = '';
        $len = strlen($src);
        $inString = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $src[$i];
            if ($inString) {
                $out .= $c;
                if ($c === '\\') {
                    $out .= $src[++$i];
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
                $out .= $c;
            } elseif ($c === '/' && ($src[$i + 1] ?? '') === '/') {
                while ($i < $len && $src[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";
            } elseif ($c === '/' && ($src[$i + 1] ?? '') === '*') {
                $i = strpos($src, '*/', $i + 2) + 1;
            } else {
                $out .= $c;
            }
        }
        $out = (string) preg_replace('/,(\s*[\]}])/', '$1', $out);
        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function cadco(): PageDefinition
    {
        return PageDefinition::fromArray('cadco-display-settings', self::jsonc('cadco-display-settings'), 'file');
    }

    private static function theme(array $override = []): PageDefinition
    {
        return PageDefinition::fromArray('theme-settings', $override + self::jsonc('theme-settings'), 'file');
    }

    private function assertInvalid(callable $fn, ?string $message = null): InvalidDeclaration
    {
        try {
            $fn();
        } catch (InvalidDeclaration $e) {
            self::assertNotEmpty($e->errors());
            if ($message !== null) {
                self::assertContains($message, array_column($e->errors(), 'message'));
            }
            return $e;
        }
        self::fail('Expected InvalidDeclaration.');
    }

    public function testDefaults(): void
    {
        $p = PageDefinition::fromArray('my-page', ['title' => 'My Page'], 'php');
        self::assertSame('top', $p->menu);
        self::assertSame('manage_options', $p->capability);
        self::assertTrue($p->autoload);
        self::assertSame(PageDefinition::MODE_FIELDS, $p->storageMode);
        self::assertSame('My Page', $p->menuTitle);
    }

    public function testSlugFromTitle(): void
    {
        self::assertSame('theme-settings', PageDefinition::slugFromTitle('Theme Settings'));
        self::assertSame('my-page', PageDefinition::slugFromTitle('My_Page'));
        self::assertNull(PageDefinition::slugFromTitle('9 Lives'));
        self::assertNull(PageDefinition::slugFromTitle(str_repeat('a', 70)));
    }

    public function testSlugGrammarEnforced(): void
    {
        foreach (['../x', 'a/b', 'A', str_repeat('a', 65)] as $slug) {
            $this->assertInvalid(fn() => PageDefinition::fromArray($slug, ['title' => 'X'], 'php'));
        }
        self::assertSame(64, strlen(PageDefinition::fromArray(str_repeat('a', 64), ['title' => 'X'], 'php')->slug));
        $this->assertInvalid(fn() => self::theme(['children' => [['slug' => '../evil', 'title' => 'X']]]));
        $this->assertInvalid(fn() => PageDefinition::fromArray(
            str_repeat('a', 60),
            ['title' => 'X', 'children' => [['slug' => 'footer', 'title' => 'F']]],
            'php'
        ));
    }

    public function testOptionNamePerField(): void
    {
        self::assertSame('theme-settings_phone', self::theme()->optionName('phone'));
    }

    public function testOptionNameArrayMode(): void
    {
        $p = self::cadco();
        self::assertSame('cadco_display_settings', $p->optionName('hide_children'));
        self::assertSame(PageDefinition::MODE_ARRAY, $p->storageMode);
    }

    public function testBareFieldsAreWrappedAndKeyed(): void
    {
        $p = self::cadco();
        self::assertSame(['hide_children', 'children_placement', 'children_heading'], array_keys($p->fields()));
        self::assertSame('group_cadco_display_settings_1', $p->groups[0]['key']);
        self::assertSame('Display Settings', $p->groups[0]['title']);
        self::assertSame('field_cadco_hide_children', $p->fields()['hide_children']['key']);
        self::assertSame('field_theme_settings_phone', self::theme()->fields()['phone']['key']);
    }

    public function testTabsAndUntabbedGroups(): void
    {
        $p = self::theme();
        self::assertCount(2, $p->tabs);
        self::assertSame('Tracking', $p->tabs[1]['title']);
        self::assertArrayHasKey('head_scripts', $p->fields());
        self::assertSame([], $p->groups);
        self::assertSame(['dashicons-admin-generic', false], [$p->icon, $p->autoload]);
    }

    public function testExplicitGroupsAreKept(): void
    {
        $p = PageDefinition::fromArray('p', ['title' => 'P', 'fields' => [
            ['key' => 'group_x', 'title' => 'X', 'fields' => [['name' => 'a', 'type' => 'text']]],
            ['name' => 'b', 'type' => 'text'],
        ]], 'php');
        self::assertCount(2, $p->groups);
        self::assertSame('group_x', $p->groups[0]['key']);
        self::assertSame('group_p_1', $p->groups[1]['key']);
        self::assertSame(['a', 'b'], array_keys($p->fields()));
    }

    public function testChildrenInheritCapabilityAndGetCompoundSlug(): void
    {
        $p = self::theme(['capability' => 'edit_theme_options']);
        self::assertCount(1, $p->children);
        $c = $p->children[0];
        self::assertSame('theme-settings-footer', $c->slug);
        self::assertSame('edit_theme_options', $c->capability);
        self::assertSame('theme-settings', $c->parentSlug);
        self::assertSame('theme-settings', $c->menu);
        self::assertSame(PageDefinition::MODE_FIELDS, $c->storageMode);
        self::assertSame('theme-settings-footer_copyright', $c->optionName('copyright'));
    }

    public function testChildCannotWidenCapabilityByOmission(): void
    {
        // A child that sets its own capability keeps it verbatim; one that omits it never falls to manage_options.
        $p = self::theme(['capability' => 'manage_woocommerce']);
        self::assertSame('manage_woocommerce', $p->children[0]->capability);
    }

    public function testDuplicateFieldNamesAcrossTabsRejected(): void
    {
        $this->assertInvalid(fn() => self::theme(['tabs' => [
            ['title' => 'A', 'fields' => [['name' => 'phone', 'type' => 'text']]],
            ['title' => 'B', 'fields' => [['name' => 'phone', 'type' => 'text']]],
        ]]), 'Field name "phone" is used twice on page "theme-settings".');
    }

    public function testInvalidCapabilityRejected(): void
    {
        foreach (['manage options', 'Manage_Options', '', 5, ['a']] as $cap) {
            $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'capability' => $cap], 'php'));
        }
    }

    public function testCapabilityKeptVerbatim(): void
    {
        $p = PageDefinition::fromArray('p', ['title' => 'P', 'capability' => 'manage_woocomerce'], 'php');
        self::assertSame('manage_woocomerce', $p->capability);
    }

    public function testInvalidMenuRejected(): void
    {
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'menu' => 'javascript:alert(1)'], 'php'));
        foreach (['edit.php?post_type=product', 'proto-blocks', 'options-general.php'] as $menu) {
            self::assertSame($menu, PageDefinition::fromArray('p', ['title' => 'P', 'menu' => $menu], 'php')->menu);
        }
    }

    public function testInvalidIconRejected(): void
    {
        foreach (['../../x.svg', '/etc/passwd', 'http://x/y.svg', 'icons\\a.svg'] as $icon) {
            $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'icon' => $icon], 'php'));
        }
        self::assertSame('icons/settings.svg', PageDefinition::fromArray('p', ['title' => 'P', 'icon' => 'icons/settings.svg'], 'php')->icon);
    }

    public function testArrayModeRequiresOptionName(): void
    {
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'storage' => ['mode' => 'array']], 'php'));
    }

    public function testProtectedOptionNamesAreRefused(): void
    {
        foreach (['default_role', 'users_can_register', 'siteurl', 'wp_user_roles', '_transient_x', '_site_transient_x', 'cron', 'auth_salt', 'theme_mods_x'] as $opt) {
            $this->assertInvalid(
                fn() => PageDefinition::fromArray('p', ['title' => 'P', 'storage' => ['mode' => 'array', 'option' => $opt]], 'php'),
            );
            self::assertTrue(ProtectedOptions::isProtected($opt), $opt);
        }
        $this->assertInvalid(fn() => PageDefinition::fromArray('users', [
            'title' => 'U', 'fields' => [['name' => 'can_register', 'type' => 'true_false']],
        ], 'php'));
        self::assertFalse(ProtectedOptions::isProtected('cadco_display_settings'));
        self::assertSame('cadco_display_settings', self::cadco()->storageOption);
    }

    public function testTrailingNewlineIsNotAccepted(): void
    {
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'capability' => "manage_options\n"], 'php'));
        $this->assertInvalid(fn() => PageDefinition::fromArray("a\n", ['title' => 'P'], 'php'));
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'fields' => [['name' => "a\n", 'type' => 'text']]], 'php'));
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'storage' => ['mode' => 'array', 'option' => "default_role\n"]], 'php'));
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'menu' => "options-general.php\n"], 'php'));
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', ['title' => 'P', 'icon' => "a.svg\n"], 'php'));
        self::assertTrue(ProtectedOptions::isProtected("default_role\n"));
    }

    public function testExtendedProtectedNames(): void
    {
        foreach (['wp_2_user_roles', 'theme_mods_twentytwentyfive', 'sticky_posts', 'thumbnail_size_w', 'auto_update_plugins', 'widget_text', 'sidebars_widgets', 'stylesheet_root'] as $opt) {
            self::assertTrue(ProtectedOptions::isProtected($opt), $opt);
        }
        self::assertFalse(ProtectedOptions::isProtected('cadco_display_settings'));
    }

    public function testUnderivableTitleWarnsAndRecordsNothing(): void
    {
        $GLOBALS['pb_test_doing_it_wrong'] = [];
        proto_options_page('9 Lives');
        self::assertCount(1, $GLOBALS['pb_test_doing_it_wrong']);
        self::assertSame([], $GLOBALS['proto_blocks_php_options_pages']);
    }

    public function testProtectedOptionsFilterCanAddNames(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_protected_options'][] = static fn(array $l) => array_merge($l, ['my_secret']);
        self::assertTrue(ProtectedOptions::isProtected('my_secret'));
    }

    public function testOptionNameLengthCapped(): void
    {
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', [
            'title' => 'P', 'fields' => [['name' => str_repeat('a', 200), 'type' => 'text']],
        ], 'php'));
        $this->assertInvalid(fn() => PageDefinition::fromArray('p', [
            'title' => 'P', 'storage' => ['mode' => 'array', 'option' => str_repeat('a', 192)],
        ], 'php'));
    }

    public function testToClientArrayIsJsonSafeAndHidesSource(): void
    {
        $p = PageDefinition::fromArray('theme-settings', self::jsonc('theme-settings'), 'file', '/abs/page.jsonc', '/abs');
        $arr = $p->toClientArray();
        self::assertNotFalse(json_encode($arr));
        self::assertStringNotContainsString('/abs', (string) json_encode($arr));
        self::assertSame('theme-settings', $arr['slug']);
        self::assertSame('theme-settings-footer', $arr['children'][0]['slug']);
    }

    public function testProtoOptionsPageRecordsPhpDeclaration(): void
    {
        $line = __LINE__ + 1;
        proto_options_page('Theme Settings', ['menu' => 'themes.php']);
        $rec = $GLOBALS['proto_blocks_php_options_pages']['theme-settings'];
        self::assertSame('Theme Settings', $rec['data']['title']);
        self::assertSame('themes.php', $rec['data']['menu']);
        self::assertStringEndsWith('PageDefinitionTest.php:' . $line, $rec['source']);
    }
}
