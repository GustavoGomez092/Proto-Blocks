<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Sources\AcfBridge;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/SourcesTest.php';

/**
 * Every test runs in its own process: an "active ACF" (class ACF, acf(),
 * ACF_VERSION) and the get_field() aliases cannot be undefined once defined.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AcfBridgeTest extends TestCase
{
    private const KEY = 'group_6543a1b2c3d4e';

    protected function setUp(): void
    {
        PhpGroups::reset();
        foreach (['pb_test_actions', 'pb_test_hooks', 'pb_test_filters', 'pb_test_acf_groups'] as $g) {
            $GLOBALS[$g] = [];
        }
    }

    /** @return list<\ProtoBlocks\Modules\Fields\Definition\FieldGroup> */
    private static function fixtureGroups(): array
    {
        $raw = json_decode((string) file_get_contents(SourcesTest::THEME . '/proto-fields/acf-export/group.json'), true);
        $raw[] = ['key' => 'group_other', 'title' => 'Other', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]], 'fields' => [['key' => 'f_o', 'name' => 'o', 'type' => 'text']]];

        return GroupNormalizer::normalizeMany($raw, 'file:acf-export', '/t/proto-fields/acf-export/group.json')['groups'];
    }

    public function testWithoutAcfNothingIsFiltered(): void
    {
        $this->assertFalse(AcfBridge::active());
        $bridge = new AcfBridge();
        $groups = self::fixtureGroups();

        $this->assertSame($groups, $bridge->filterDuplicates($groups));
        $this->assertSame([], $bridge->skipped());
        $this->assertSame([], $bridge->acfGroups());
    }

    public function testAcfKnownKeyIsDroppedAndListed(): void
    {
        require __DIR__ . '/stubs/Acf.php';
        $GLOBALS['pb_test_acf_groups'][self::KEY] = [
            'key' => self::KEY, 'title' => 'Event details (ACF)',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event'], ['param' => 'post_status', 'operator' => '!=', 'value' => 'draft']], [['param' => 'options_page', 'operator' => '==', 'value' => 'x']]],
        ];

        $this->assertTrue(AcfBridge::active());
        $bridge = new AcfBridge();
        $kept = $bridge->filterDuplicates(self::fixtureGroups());

        $this->assertSame(['group_other'], array_map(static fn($g) => $g->key, $kept));
        $this->assertSame([['key' => self::KEY, 'title' => 'Event details', 'source' => '/t/proto-fields/acf-export/group.json']], $bridge->skipped());
        $this->assertSame([[
            'key' => self::KEY,
            'title' => 'Event details (ACF)',
            'locationSummary' => 'post_type == event and post_status != draft or options_page == x',
        ]], $bridge->acfGroups());
    }

    public function testActiveThroughTheAccessorFunction(): void
    {
        eval('function acf() { return null; }');
        $this->assertTrue(AcfBridge::active());
    }

    public function testActiveThroughTheVersionConstant(): void
    {
        define('ACF_VERSION', '6.3.0');
        $this->assertTrue(AcfBridge::active());
        // No acf_get_field_group(): nothing can be compared, nothing is dropped.
        $groups = self::fixtureGroups();
        $this->assertSame($groups, (new AcfBridge())->filterDuplicates($groups));
    }

    public function testModuleSkipsTheGroupAndShowsTheNotice(): void
    {
        require __DIR__ . '/stubs/Acf.php';
        $GLOBALS['pb_test_acf_groups'][self::KEY] = ['key' => self::KEY, 'title' => 'Event details'];
        $module = new FieldsModule(static fn(): array => [SourcesTest::THEME], [SourcesTest::class, 'decode']);
        do_action('init');

        $this->assertSame(['group_speaker'], array_map(static fn($g) => $g->key, $module->index()->all()));
        $codes = array_column($module->report()->warnings, 'code');
        $this->assertContains('acf_duplicate_key', $codes);

        // Not on an unrelated screen.
        $GLOBALS['pb_test_screen'] = (object) ['id' => 'dashboard', 'base' => 'dashboard'];
        ob_start();
        $module->renderNotices();
        $this->assertSame('', ob_get_clean());

        $GLOBALS['pb_test_screen'] = (object) ['id' => 'event', 'base' => 'post'];
        ob_start();
        $module->renderNotices();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Proto-Blocks skipped the field group &quot;Event details&quot; because Advanced Custom Fields already registers the same key.', $html);

        $GLOBALS['pb_test_screen'] = (object) ['id' => 'toplevel_page_proto-blocks', 'base' => 'toplevel_page_proto-blocks'];
        ob_start();
        $module->renderNotices();
        $this->assertStringContainsString('notice-warning', (string) ob_get_clean());
    }

    // ---------------------------------------------------------------- aliases (ruling: after_setup_theme 100)

    public function testAliasesLoadWhenAcfIsAbsent(): void
    {
        $module = new FieldsModule();
        $this->assertTrue($module->loadAliases());
        $this->assertTrue(function_exists('get_field'));
    }

    public function testAliasesAreNotDefinedWhenGetFieldAlreadyExists(): void
    {
        // A theme-bundled ACF included from functions.php defines get_field()
        // before after_setup_theme 100 runs.
        require __DIR__ . '/stubs/AcfGetField.php';

        $module = new FieldsModule();
        $this->assertFalse($module->loadAliases());
        $this->assertSame('acf-stub', get_field('subtitle'));
        $this->assertFalse(function_exists('the_field'));
        $this->assertFalse(function_exists('get_fields'));
    }

    public function testAliasesAreNotDefinedWhenAcfIsActive(): void
    {
        require __DIR__ . '/stubs/Acf.php';

        $module = new FieldsModule();
        $this->assertFalse($module->loadAliases());
        $this->assertFalse(function_exists('get_field'));
    }

    public function testBootDefersTheAliasesToLateAfterSetupTheme(): void
    {
        $module = new FieldsModule();
        $module->boot();

        $this->assertSame(100, has_action('after_setup_theme', [$module, 'loadAliases']));
        $this->assertFalse(has_action('plugins_loaded'), 'never on plugins_loaded: modules boot after it');
        $this->assertFalse(function_exists('get_field'), 'boot itself defines nothing');
        FieldReader::setShared(null);
    }
}
