<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../Admin/wp-stubs.php';
require_once __DIR__ . '/../../../includes/OptionsPages/functions.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\AdminApp;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\OptionsPages\OptionsFieldStore;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\OptionsStore;
use ProtoBlocks\OptionsPages\OptionsValues;
use ProtoBlocks\OptionsPages\PageDefinition;
use ProtoBlocks\OptionsPages\PageRenderer;

/**
 * The rendered settings page: its bootstrap payload (one BoxPayload per
 * group) and the capability gate in front of it.
 */
final class PageRendererTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/options-pages';
    private const CADCO = 'cadco-display-settings';

    private string $theme = '';

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_inline'] = [];
        $GLOBALS['pb_test_enqueued_styles'] = [];
        $GLOBALS['pb_test_enqueued_media'] = 0;
        $GLOBALS['pb_test_enqueued_editor'] = 0;
        $GLOBALS['pb_test_caps'] = [
            1 => ['manage_woocommerce'],
            2 => ['manage_woocommerce', 'manage_options', 'unfiltered_html'],
            3 => ['manage_options', 'unfiltered_html'],
            4 => ['manage_options'],
        ];
        $GLOBALS['pb_test_current_user'] = 1;
        $tmp = sys_get_temp_dir() . '/pb-page-renderer-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->theme = (string) realpath($tmp);
        foreach ([self::CADCO, 'theme-settings'] as $slug) {
            $this->place($slug, (string) file_get_contents(self::FIXTURES . '/' . $slug . '/page.jsonc'));
        }
        $GLOBALS['pb_test_theme'] = ['template' => $this->theme, 'stylesheet' => $this->theme];
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_caps'] = [];
        $GLOBALS['pb_test_current_user'] = 0;
        unset(
            $GLOBALS['pb_test_theme'],
            $GLOBALS['pb_test_inline'],
            $GLOBALS['pb_test_enqueued_styles'],
            $GLOBALS['pb_test_enqueued_media'],
            $GLOBALS['pb_test_enqueued_editor']
        );
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->theme, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->theme);
    }

    private function place(string $slug, string $text): void
    {
        $dir = $this->theme . '/proto-options/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/page.jsonc', $text);
    }

    private function registry(): OptionsPagesRegistry
    {
        return new OptionsPagesRegistry(new Registry(new FileLoader(), static fn (): array => SchemaPaths::roots()));
    }

    private function renderer(): PageRenderer
    {
        $registry = $this->registry();
        $types = TypeRegistry::withCoreTypes();
        $store = new OptionsStore();

        return new PageRenderer(
            new OptionsValues($registry, $store, $types, static fn (string $slug): array => []),
            new OptionsFieldStore($registry, $store),
            $types,
            new AdminApp(null)
        );
    }

    private function page(string $slug): PageDefinition
    {
        $page = $this->registry()->get($slug);
        self::assertNotNull($page, $slug . json_encode($this->registry()->warnings()));

        return $page;
    }

    private function renderOutput(string $slug): string
    {
        ob_start();
        try {
            $this->renderer()->render($this->page($slug));
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    public function testPageDataForShopManager(): void
    {
        $data = $this->renderer()->pageData($this->page(self::CADCO), 1);

        foreach (['slug', 'title', 'groups', 'tabs', 'trusted', 'editUrl', 'readOnly'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        self::assertSame(self::CADCO, $data['slug']);
        self::assertSame('Display Settings', $data['title']);
        self::assertNull($data['editUrl']);
        self::assertFalse($data['trusted']);
        self::assertSame([], $data['tabs']);
        self::assertSame('group_cadco_display_settings_1', $data['groups'][0]['groupKey']);
        self::assertSame('option:cadco-display-settings', $data['groups'][0]['object']);
        self::assertFalse($data['groups'][0]['canUnfiltered']);
        self::assertSame(
            ['hide_children', 'children_placement', 'children_heading'],
            array_column($data['groups'][0]['fields'], 'name')
        );
        // Nothing stored yet: each field's default.
        self::assertSame('related', ((array) $data['groups'][0]['values'])['children_placement']);
        self::assertSame('nonce:wp_rest', $data['groups'][0]['restNonce']);
    }

    public function testStoredValuesReachThePayload(): void
    {
        $GLOBALS['pb_test_options']['cadco_display_settings'] = ['children_placement' => 'section', 'children_heading' => 'More'];

        $values = $this->renderer()->pageData($this->page(self::CADCO), 1)['groups'][0]['values'];
        $values = (array) $values;

        self::assertSame('section', $values['children_placement']);
        self::assertSame('More', $values['children_heading']);
    }

    public function testEditUrlOnlyForManageOptions(): void
    {
        $data = $this->renderer()->pageData($this->page(self::CADCO), 2);

        self::assertIsString($data['editUrl']);
        self::assertStringEndsWith('page=proto-blocks-options-pages&slug=cadco-display-settings', $data['editUrl']);
        self::assertTrue($data['trusted']);
    }

    public function testTabsCarryTheirGroupsAndCodeIsReadOnlyForUntrustedUsers(): void
    {
        $data = $this->renderer()->pageData($this->page('theme-settings'), 4);

        self::assertSame([], $data['groups']);
        self::assertSame(['General', 'Tracking'], array_column($data['tabs'], 'title'));
        self::assertSame('phone', $data['tabs'][0]['groups'][0]['fields'][0]['name']);
        self::assertSame('head_scripts', $data['tabs'][1]['groups'][0]['fields'][0]['name']);
        self::assertFalse($data['trusted']);
        self::assertSame(['head_scripts' => 'unfiltered_html'], $data['readOnly']);

        self::assertSame([], $this->renderer()->pageData($this->page('theme-settings'), 3)['readOnly']);
    }

    public function testStandaloneGroupsFollowThePageGroups(): void
    {
        $registry = $this->registry();
        $types = TypeRegistry::withCoreTypes();
        $store = new OptionsStore();
        $standalone = \ProtoBlocks\Modules\Fields\Definition\GroupNormalizer::normalize([
            'key' => 'group_extra',
            'title' => 'Extra',
            'fields' => [['key' => 'field_extra_note', 'name' => 'extra_note', 'type' => 'text']],
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'theme-settings']]],
        ], 'php')['group'];
        $renderer = new PageRenderer(
            new OptionsValues($registry, $store, $types, static fn (string $slug): array => $slug === 'theme-settings' ? [$standalone] : []),
            new OptionsFieldStore($registry, $store),
            $types,
            new AdminApp(null)
        );

        $data = $renderer->pageData($this->page('theme-settings'), 3);

        self::assertSame(['group_extra'], array_column($data['groups'], 'groupKey'));
        self::assertCount(2, $data['tabs']);
    }

    public function testRenderDeniesUsersWithoutTheCapability(): void
    {
        $GLOBALS['pb_test_current_user'] = 3;

        ob_start();
        try {
            $this->renderer()->render($this->page(self::CADCO));
            self::fail('wp_die was not called');
        } catch (\PbTestWpDie $e) {
            self::assertSame(403, $e->status);
        } finally {
            $output = (string) ob_get_clean();
        }
        self::assertSame('', $output);
        self::assertSame([], $GLOBALS['pb_test_enqueued_styles']);
    }

    public function testRenderPrintsEscapedJsonAndTheMountForThePageCapability(): void
    {
        // A shop manager without manage_options still gets the mount node.
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_options']['cadco_display_settings'] = ['children_heading' => '</script><script>alert(1)</script>'];

        $output = $this->renderOutput(self::CADCO);

        self::assertStringContainsString('<script type="application/json" id="' . PageRenderer::DATA_ID . '">', $output);
        self::assertStringContainsString('data-screen="options-page"', $output);
        self::assertStringNotContainsString('<script>alert', $output);
        self::assertSame(1, preg_match('#<script type="application/json" id="[^"]+">(.*?)</script>#s', $output, $m));
        self::assertStringNotContainsString('<', $m[1]);
        $decoded = json_decode($m[1], true);
        self::assertSame('</script><script>alert(1)</script>', $decoded['groups'][0]['values']['children_heading']);
        self::assertContains(PageRenderer::FIELDS_STYLE, $GLOBALS['pb_test_enqueued_styles']);
        self::assertSame(0, $GLOBALS['pb_test_enqueued_media'], 'no media field');
        self::assertSame(0, $GLOBALS['pb_test_enqueued_editor'], 'no wysiwyg field');
    }

    public function testRenderEnqueuesMediaAndEditorWhenFieldsNeedThem(): void
    {
        $this->place('brand-assets', '{"title": "Brand assets", "fields": [{"name": "logo", "type": "image"}, {"name": "intro", "type": "wysiwyg"}]}');
        $GLOBALS['pb_test_current_user'] = 3;

        $this->renderOutput('brand-assets');

        self::assertSame(1, $GLOBALS['pb_test_enqueued_media']);
        self::assertSame(1, $GLOBALS['pb_test_enqueued_editor']);
    }
}
