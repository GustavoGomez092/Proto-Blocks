<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../../includes/OptionsPages/functions.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\PageDefinition;

/**
 * Options pages discovered from `proto-options/<slug>/page.jsonc` in the
 * theme and from proto_options_page() calls, over Phase 1's Registry.
 */
final class OptionsPagesRegistryTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/options-pages';

    private string $theme = '';

    private ?Registry $declarations = null;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $GLOBALS['pb_test_filters'] = [];
        $tmp = sys_get_temp_dir() . '/pb-options-registry-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->theme = (string) realpath($tmp);
        foreach (['cadco-display-settings', 'theme-settings'] as $slug) {
            $this->place($slug, (string) file_get_contents(self::FIXTURES . '/' . $slug . '/page.jsonc'));
        }
        $GLOBALS['pb_test_theme'] = ['template' => $this->theme, 'stylesheet' => $this->theme];
        $this->declarations = null;
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        $GLOBALS['pb_test_filters'] = [];
        unset($GLOBALS['pb_test_theme']);
        self::removeTree($this->theme);
    }

    private function place(string $slug, string $text): string
    {
        $dir = $this->theme . '/proto-options/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/page.jsonc', $text);

        return $dir . '/page.jsonc';
    }

    private function registry(): OptionsPagesRegistry
    {
        $this->declarations = new Registry(new FileLoader(), static fn (): array => SchemaPaths::roots());

        return new OptionsPagesRegistry($this->declarations);
    }

    /** @return list<array{slug: string, source: ?string, message: string}> */
    private static function warningsFor(OptionsPagesRegistry $registry, string $slug): array
    {
        return array_values(array_filter($registry->warnings(), static fn (array $w): bool => $w['slug'] === $slug));
    }

    public function testDiscoversFixtureFiles(): void
    {
        $all = $this->registry()->all();

        self::assertSame(['cadco-display-settings', 'theme-settings', 'theme-settings-footer'], array_keys($all));
        foreach ($all as $slug => $page) {
            self::assertInstanceOf(PageDefinition::class, $page);
            self::assertSame($slug, $page->slug);
            self::assertSame('file', $page->origin);
            self::assertStringEndsWith('page.jsonc', (string) $page->source);
            self::assertSame($this->theme, $page->root);
        }
        self::assertSame('theme-settings', $all['theme-settings-footer']->parentSlug);
        self::assertSame('manage_woocommerce', $all['cadco-display-settings']->capability);
        self::assertSame([], $this->registry()->warnings());
    }

    public function testGetIsLazyAndRefreshReloads(): void
    {
        $registry = $this->registry();
        self::assertNotNull($registry->get('theme-settings'));
        self::assertNull($registry->get('shop-notices'));

        $this->place('shop-notices', '{"title": "Shop Notices", "menu": "woocommerce"}');
        self::assertNull($registry->get('shop-notices'), 'memoised until refresh()');

        $registry->refresh();
        self::assertSame('Shop Notices', $registry->get('shop-notices')?->title);
    }

    public function testPhpDeclarationRegistered(): void
    {
        $line = __LINE__ + 1;
        proto_options_page('Shop Notices', ['menu' => 'woocommerce']);

        $page = $this->registry()->get('shop-notices');
        self::assertNotNull($page);
        self::assertSame('php', $page->origin);
        self::assertSame('woocommerce', $page->menu);
        self::assertSame('Shop Notices', $page->title);
        self::assertStringEndsWith('OptionsPagesRegistryTest.php:' . $line, (string) $page->source);
        self::assertNull($page->root);
    }

    public function testFileWinsOverPhpAndRecordsDoubleDeclaration(): void
    {
        $line = __LINE__ + 1;
        proto_options_page('Display Settings', ['slug' => 'cadco-display-settings', 'capability' => 'manage_options']);

        $registry = $this->registry();
        $page = $registry->get('cadco-display-settings');
        self::assertNotNull($page);
        self::assertSame('manage_woocommerce', $page->capability);
        self::assertSame('file', $page->origin);

        $doubles = $this->declarations?->doubles() ?? [];
        self::assertCount(1, $doubles);
        self::assertSame('options', $doubles[0]['kind']);
        self::assertSame('cadco-display-settings', $doubles[0]['slug']);
        self::assertSame($this->theme . '/proto-options/cadco-display-settings/page.jsonc', $doubles[0]['file']);
        self::assertStringEndsWith('OptionsPagesRegistryTest.php:' . $line, $doubles[0]['php']);

        $warnings = self::warningsFor($registry, 'cadco-display-settings');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('declared twice', $warnings[0]['message']);
    }

    public function testInvalidFileIsSkippedWithWarning(): void
    {
        $path = $this->place('broken', (string) file_get_contents(self::FIXTURES . '/invalid/bad-jsonc.jsonc'));

        $registry = $this->registry();
        self::assertNull($registry->get('broken'));

        $warnings = self::warningsFor($registry, 'broken');
        self::assertCount(1, $warnings);
        self::assertSame($path, $warnings[0]['source']);
        self::assertStringContainsString('line', $warnings[0]['message']);

        self::assertNotNull($registry->get('cadco-display-settings'));
        self::assertNotNull($registry->get('theme-settings-footer'));
    }

    public function testSchemaInvalidFileIsSkippedWithWarning(): void
    {
        $this->place('unknown-mode', (string) file_get_contents(self::FIXTURES . '/invalid/unknown-mode.jsonc'));
        $this->place('missing-title', (string) file_get_contents(self::FIXTURES . '/invalid/missing-title.jsonc'));

        $registry = $this->registry();
        self::assertNull($registry->get('unknown-mode'));
        self::assertNull($registry->get('missing-title'));
        self::assertStringStartsWith('/storage', self::warningsFor($registry, 'unknown-mode')[0]['message']);
        self::assertStringStartsWith('/title', self::warningsFor($registry, 'missing-title')[0]['message']);
    }

    public function testDefinitionErrorsBecomeWarnings(): void
    {
        $path = $this->place('dupes', json_encode([
            'title' => 'Dupes',
            'tabs' => [
                ['title' => 'One', 'fields' => [['name' => 'phone', 'type' => 'text']]],
                ['title' => 'Two', 'fields' => [['name' => 'phone', 'type' => 'text']]],
            ],
        ], JSON_THROW_ON_ERROR));

        $registry = $this->registry();
        self::assertNull($registry->get('dupes'));

        $warnings = self::warningsFor($registry, 'dupes');
        self::assertCount(1, $warnings);
        self::assertSame($path, $warnings[0]['source']);
        self::assertStringContainsString('is used twice', $warnings[0]['message']);
        self::assertStringStartsWith('/tabs/1/fields/0/name', $warnings[0]['message']);
        self::assertNotNull($registry->get('theme-settings'));
    }

    public function testProtectedStorageOptionInFileIsSkipped(): void
    {
        $this->place('hijack', '{"title": "Hijack", "storage": {"mode": "array", "option": "active_plugins"}}');

        $registry = $this->registry();
        self::assertNull($registry->get('hijack'));
        self::assertStringContainsString('active_plugins', self::warningsFor($registry, 'hijack')[0]['message']);
    }

    public function testBrokenFileDoesNotFallBackToPhp(): void
    {
        $line = __LINE__ + 1;
        proto_options_page('Broken', ['capability' => 'read']);
        $path = $this->place('broken', (string) file_get_contents(self::FIXTURES . '/invalid/bad-jsonc.jsonc'));

        $registry = $this->registry();
        self::assertNull($registry->get('broken'));
        self::assertArrayNotHasKey('broken', $registry->all());

        $messages = array_column(self::warningsFor($registry, 'broken'), 'message');
        $php = __FILE__ . ':' . $line;
        self::assertContains(sprintf('The file %s is invalid; the PHP declaration at %s is not used either.', $path, $php), $messages);
    }

    public function testPhpDeclarationErrorsBecomeWarnings(): void
    {
        proto_options_page('Loose', ['capability' => 'Manage Options']);

        $registry = $this->registry();
        self::assertNull($registry->get('loose'));
        self::assertStringContainsString('Capability', self::warningsFor($registry, 'loose')[0]['message']);
    }

    public function testChildSlugCollisionIsWarnedAndSkipped(): void
    {
        $this->place('theme-settings-footer', '{"title": "Standalone Footer"}');

        $registry = $this->registry();
        self::assertSame('Standalone Footer', $registry->get('theme-settings-footer')?->title);
        $warnings = self::warningsFor($registry, 'theme-settings-footer');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('theme-settings', $warnings[0]['message']);
    }

    public function testReservedCompoundChildSlugIsSkipped(): void
    {
        // proto + blocks-post-types would be the plugin's own Post Types screen.
        $path = $this->place('proto', json_encode([
            'title' => 'Proto',
            'children' => [
                ['slug' => 'blocks-post-types', 'title' => 'Hijack', 'capability' => 'read'],
                ['slug' => 'extras', 'title' => 'Extras'],
            ],
        ], JSON_THROW_ON_ERROR));
        proto_options_page('Options', ['slug' => 'options-x', 'children' => [['slug' => 'y', 'title' => 'Y']]]);

        $registry = $this->registry();
        self::assertNull($registry->get('proto-blocks-post-types'));
        self::assertNotNull($registry->get('proto'));
        self::assertSame('proto', $registry->get('proto-extras')?->parentSlug);
        self::assertNotNull($registry->get('options-x-y'), 'a reserved word inside a longer slug is fine');

        $warnings = self::warningsFor($registry, 'proto-blocks-post-types');
        self::assertCount(1, $warnings);
        self::assertSame($path, $warnings[0]['source']);
        self::assertStringContainsString('reserved', $warnings[0]['message']);
        $codes = array_map(static fn (Issue $issue): string => $issue->code, $registry->issues());
        self::assertContains('child_slug_reserved', $codes);
    }

    public function testSharedStorageIsWarned(): void
    {
        // Same array option as the CADCO fixture.
        $this->place('copy', '{"title": "Copy", "storage": {"mode": "array", "option": "cadco_display_settings"}}');
        // An array option equal to another page's derived per-field option name (`settings` + `phone`).
        $this->place('settings', '{"title": "Settings", "fields": [{"name": "phone", "type": "text"}]}');
        $this->place('phone-page', '{"title": "Phone", "storage": {"mode": "array", "option": "settings_phone"}}');
        $this->place('apart', '{"title": "Apart", "storage": {"mode": "array", "option": "apart_values"}, "fields": [{"name": "a", "type": "text"}]}');

        $registry = $this->registry();
        foreach (['copy', 'phone-page', 'apart'] as $slug) {
            self::assertNotNull($registry->get($slug), 'shared storage warns, it does not skip');
        }

        $copy = self::warningsFor($registry, 'copy');
        self::assertCount(1, $copy);
        self::assertStringContainsString('cadco_display_settings', $copy[0]['message']);
        self::assertStringContainsString('cadco-display-settings', $copy[0]['message']);

        $sharedPhone = array_filter(
            $registry->warnings(),
            static fn (array $w): bool => str_contains($w['message'], '"settings_phone"')
        );
        $message = array_values($sharedPhone)[0]['message'];
        self::assertStringContainsString('"phone-page"', $message);
        self::assertStringContainsString('"settings"', $message);
        self::assertCount(1, $sharedPhone);
        self::assertSame([], self::warningsFor($registry, 'apart'));
        self::assertSame([], self::warningsFor($registry, 'theme-settings-footer'));
    }

    public function testIssuesForValidate(): void
    {
        proto_options_page('Broken', ['capability' => 'read']);
        $this->place('broken', (string) file_get_contents(self::FIXTURES . '/invalid/bad-jsonc.jsonc'));
        $this->place('dupes', '{"title": "Dupes", "fields": [{"name": "a", "type": "text"}, {"name": "a", "type": "text"}]}');

        $registry = $this->registry();
        $warnings = $registry->warnings();
        $issues = $registry->issues();

        self::assertCount(count($warnings), $issues);
        self::assertGreaterThanOrEqual(3, count($issues));
        foreach ($issues as $i => $issue) {
            self::assertInstanceOf(Issue::class, $issue);
            self::assertSame('options', $issue->kind);
            self::assertSame($warnings[$i]['slug'], $issue->slug);
            self::assertSame($warnings[$i]['message'], $issue->message);
        }
        $codes = array_map(static fn (Issue $issue): string => $issue->code, $issues);
        self::assertContains('invalid_jsonc', $codes);
        self::assertContains('declaration_invalid', $codes);
        // A broken file and an invalid definition are errors: validate fails on them.
        foreach ($issues as $issue) {
            if (in_array($issue->code, ['invalid_jsonc', 'declaration_invalid'], true)) {
                self::assertSame(Issue::ERROR, $issue->level);
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || (!is_dir($path) && !is_file($path))) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
