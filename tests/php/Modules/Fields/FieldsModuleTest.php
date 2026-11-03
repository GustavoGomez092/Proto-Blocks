<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;
use ProtoBlocks\Modules\ModuleInterface;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';
require_once __DIR__ . '/stubs/Read.php';
require_once __DIR__ . '/../../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/SourcesTest.php';
require_once __DIR__ . '/../../../../includes/Modules/Fields/functions.php';

final class FieldsModuleTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../fixtures/fields';
    private const ADMIN = 1;
    private const AUTHOR = 2;

    protected function setUp(): void
    {
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        foreach (['pb_test_actions', 'pb_test_hooks', 'pb_test_filters', 'pb_test_meta', 'pb_test_meta_reg', 'pb_test_posts', 'pb_test_bindings', 'pb_test_doing_it_wrong'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_posts'][10] = ['ID' => 10, 'post_type' => 'speaker', 'post_status' => 'publish', 'post_author' => self::AUTHOR];
        $GLOBALS['pb_test_caps'][self::ADMIN] = ['edit_posts', 'edit_post', 'unfiltered_html'];
        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts', 'edit_post'];
        $GLOBALS['pb_test_current_user'] = self::AUTHOR;
    }

    protected function tearDown(): void
    {
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        FieldReader::setShared(null);
        unset($GLOBALS['pb_test_caps'][self::ADMIN], $GLOBALS['pb_test_caps'][self::AUTHOR]);
        foreach (['pb_test_actions', 'pb_test_hooks', 'pb_test_filters', 'pb_test_meta', 'pb_test_meta_reg', 'pb_test_posts', 'pb_test_bindings'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_current_user'] = 0;
        $GLOBALS['pb_test_is_admin'] = false;
    }

    private static function module(): FieldsModule
    {
        return new FieldsModule(static fn(): array => [SourcesTest::THEME], [SourcesTest::class, 'decode']);
    }

    /** @return array<int, array{callback: mixed, priority: int, args: int}> */
    private static function hooks(string $hook): array
    {
        return $GLOBALS['pb_test_hooks'][$hook] ?? [];
    }

    public function testIdAndInterface(): void
    {
        $module = new FieldsModule();
        $this->assertSame('fields', $module->id());
        $this->assertInstanceOf(ModuleInterface::class, $module);
    }

    public function testBootWiresTheHooksAndTheSharedReader(): void
    {
        $roots = 0;
        $module = new FieldsModule(static function () use (&$roots): array {
            $roots++;
            return [SourcesTest::THEME];
        }, [SourcesTest::class, 'decode']);
        $GLOBALS['pb_test_is_admin'] = true;
        $module->boot();
        $module->boot();

        $this->assertSame(20, has_action('init', [$module, 'registerMeta']));
        $initPriorities = array_column(self::hooks('init'), 'priority');
        sort($initPriorities);
        $this->assertSame([10, 20], $initPriorities, 'bindings source on init 10, meta on init 20, once each');
        $this->assertSame(100, has_action('after_setup_theme', [$module, 'loadAliases']));
        $this->assertSame(10, has_action('admin_notices', [$module, 'renderNotices']));
        $this->assertSame(10, has_filter('proto_blocks_declaration_errors', [$module, 'declarationErrors']));
        $this->assertSame(4, self::hooks('proto_blocks_declaration_errors')[0]['args']);
        $this->assertFalse(has_action('plugins_loaded'));

        $this->assertSame($module->reader(), FieldReader::shared());
        $this->assertSame(0, $roots, 'boot() never touches the filesystem');
    }

    public function testNoNoticeHookOutsideAdmin(): void
    {
        self::module()->boot();
        $this->assertArrayNotHasKey('admin_notices', $GLOBALS['pb_test_hooks']);
    }

    public function testBindingsSourceRegistersOnInit(): void
    {
        $module = self::module();
        $module->boot();
        foreach (self::hooks('init') as $hook) {
            if ($hook['priority'] === 10) {
                ($hook['callback'])();
            }
        }
        $this->assertArrayHasKey('proto-blocks/field', $GLOBALS['pb_test_bindings']);
    }

    public function testServicesAreBuiltOnce(): void
    {
        $module = self::module();
        $this->assertSame($module->index(), $module->index());
        $this->assertSame($module->reader(), $module->reader());
        $this->assertSame($module->types(), $module->types());
        $this->assertSame(['post', 'term', 'user', 'option'], array_keys($module->stores()));
    }

    public function testProtoFieldGroupAddsAPhpGroup(): void
    {
        proto_field_group('product', ['key' => 'group_product', 'title' => 'Product', 'fields' => [['key' => 'f_sku', 'name' => 'sku', 'label' => 'SKU', 'type' => 'text']]]);
        $line = __LINE__ - 1;
        $module = new FieldsModule();
        do_action('init');

        $groups = $module->index()->all();
        $this->assertSame(['group_product'], array_map(static fn($g) => $g->key, $groups));
        $this->assertSame('php', $groups[0]->source);
        $this->assertSame(__FILE__ . ':' . $line, $groups[0]->path);
        $this->assertSame('product', $groups[0]->location->or[0][0]['value']);
    }

    public function testRegisterMetaOnInit20(): void
    {
        proto_field_group('product', ['key' => 'group_product', 'title' => 'Product', 'fields' => [['key' => 'f_sku', 'name' => 'sku', 'label' => 'SKU', 'type' => 'text']]]);
        $module = self::module();
        $module->boot();
        do_action('init');
        $module->registerMeta();

        $this->assertArrayHasKey('sku', $GLOBALS['pb_test_meta_reg']['post']['product']);
        $this->assertArrayHasKey('subtitle', $GLOBALS['pb_test_meta_reg']['post']['event']);
        $this->assertArrayHasKey('company', $GLOBALS['pb_test_meta_reg']['post']['speaker']);
    }

    public function testCodeFieldFromAnAcfShapedFileKeepsTheWriteTrustRules(): void
    {
        $GLOBALS['pb_test_meta']['post'][10]['embed'] = ['<script>trusted()</script>'];
        $module = self::module();
        $module->boot();
        do_action('init');
        $module->registerMeta();

        $this->assertFalse(update_post_meta(10, 'embed', '<script>evil()</script>'), 'an author may not write a code field');
        $this->assertSame(['<script>trusted()</script>'], $GLOBALS['pb_test_meta']['post'][10]['embed']);
        $this->assertSame('<script>trusted()</script>', proto_field('embed', 10), 'code is returned as stored');

        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        update_post_meta(10, 'embed', '<script>new()</script>');
        $this->assertSame(['<script>new()</script>'], $GLOBALS['pb_test_meta']['post'][10]['embed']);
    }

    public function testAcfYmdDateIsConvertedWhenRead(): void
    {
        $GLOBALS['pb_test_meta']['post'][10]['born'] = ['19840229'];
        $module = self::module();
        $module->boot();
        do_action('init');

        $this->assertSame('1984-02-29', proto_field('born', 10));
        $this->assertSame('19840229', proto_field('born', 10, true), 'raw is the stored value');

        $GLOBALS['pb_test_meta']['post'][10]['born'] = ['19840230'];
        $this->assertSame('19840230', proto_field('born', 10), 'an impossible Ymd date is left alone (escaped)');
    }

    // ---------------------------------------------------------------- declaration errors filter

    public function testTypeFieldWithoutKeyIsAnError(): void
    {
        $module = new FieldsModule();
        $data = ['name' => 'Event', 'fields' => [['title' => 'No key', 'fields' => [['key' => 'f', 'name' => 'x', 'type' => 'text']]]]];

        $errors = $module->declarationErrors([], 'type', $data, 'event');
        $this->assertCount(1, $errors);
        $this->assertSame('/fields/0/key', $errors[0]['path']);
        $this->assertSame('missing_key', $errors[0]['code']);
        $this->assertArrayNotHasKey('level', $errors[0]);
        $this->assertNotSame('', $errors[0]['message']);
    }

    public function testFieldGroupUnknownRuleIsAWarning(): void
    {
        $module = new FieldsModule();
        $data = json_decode((string) file_get_contents(self::FIXTURES . '/groups-invalid/unknown-rule.json'), true);

        $findings = $module->declarationErrors([], 'field-group', $data, 'unknown-rule');
        $this->assertNotSame([], $findings);
        foreach ($findings as $finding) {
            $this->assertSame('warning', $finding['level']);
        }
        $this->assertContains('unsupported_location_rule', array_column($findings, 'code'));
    }

    public function testUnknownAcfFieldTypeIsAnErrorNeverCoerced(): void
    {
        $module = new FieldsModule();
        $data = ['key' => 'group_x', 'title' => 'X', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]], 'fields' => [
            ['key' => 'f_code', 'name' => 'snippet', 'type' => 'acfe_code_editor'],
        ]];

        $errors = $module->declarationErrors([], 'field-group', $data, 'x');
        $this->assertSame(['unknown_type'], array_column($errors, 'code'));
        $this->assertArrayNotHasKey('level', $errors[0]);
        $this->assertSame('/fields/0/type', $errors[0]['path']);
    }

    public function testFilterKeepsEarlierFindingsAndIgnoresOtherKinds(): void
    {
        $module = new FieldsModule();
        $earlier = [['path' => '/x', 'message' => 'from another module']];

        $this->assertSame($earlier, $module->declarationErrors($earlier, 'options', ['fields' => [['nope' => 1]]], 'site'));
        $this->assertSame($earlier, $module->declarationErrors($earlier, 'type', ['name' => 'Event'], 'event'));
        $this->assertCount(2, $module->declarationErrors($earlier, 'taxonomy', ['fields' => 'not a list'], 'genre'));
    }

    public function testValidTaxonomyFieldsPass(): void
    {
        $module = new FieldsModule();
        $data = ['fields' => [['key' => 'group_t', 'title' => 'T', 'fields' => [['key' => 'f', 'name' => 'colour', 'type' => 'color_picker']]]]];
        $this->assertSame([], $module->declarationErrors([], 'taxonomy', $data, 'genre'));
    }

    // ---------------------------------------------------------------- issues

    public function testIssuesNameTheirSource(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-fields-issues-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/proto-fields/Bad Name', 0777, true);
        mkdir($tmp . '/proto-fields/unknown', 0777, true);
        copy(self::FIXTURES . '/groups-invalid/unknown-rule.json', $tmp . '/proto-fields/unknown/group.json');
        try {
            proto_field_group('Bad Target', ['key' => 'g', 'title' => 'G', 'fields' => []]);
            $module = new FieldsModule(static fn(): array => [$tmp], [SourcesTest::class, 'decode']);
            do_action('init');

            $issues = $module->issues();
            $byCode = [];
            foreach ($issues as $issue) {
                $this->assertInstanceOf(Issue::class, $issue);
                $byCode[$issue->code] = $issue;
            }
            $this->assertSame(Issue::ERROR, $byCode['invalid_slug']->level);
            $this->assertSame('field-group', $byCode['invalid_slug']->kind);
            $this->assertSame(Issue::WARNING, $byCode['unsupported_location_rule']->level);
            $this->assertSame('unknown', $byCode['unsupported_location_rule']->slug);
            $this->assertStringEndsWith('proto-fields/unknown/group.json', $byCode['unsupported_location_rule']->source);
            $this->assertSame(Issue::ERROR, $byCode['declaration_invalid']->level);
            $this->assertStringContainsString(__FILE__, $byCode['declaration_invalid']->source);
        } finally {
            unlink($tmp . '/proto-fields/unknown/group.json');
            rmdir($tmp . '/proto-fields/unknown');
            rmdir($tmp . '/proto-fields/Bad Name');
            rmdir($tmp . '/proto-fields');
            rmdir($tmp);
        }
    }

    public function testInstanceIsTheRegisteredModule(): void
    {
        $module = new FieldsModule();
        FieldsModule::setInstance($module);
        $this->assertSame($module, FieldsModule::instance());
    }

    public function testSaveHooks(): void
    {
        $module = self::module();
        $module->boot();
        $save = $module->saveHandler();

        $this->assertSame(10, has_action('save_post', [$save, 'savePost']));
        $this->assertSame(2, self::hooks('save_post')[0]['args']);
        $this->assertSame(10, has_action('created_term', [$save, 'saveTerm']));
        $this->assertSame(10, has_action('edited_term', [$save, 'saveTerm']));
        $this->assertSame(3, self::hooks('edited_term')[0]['args']);
        foreach (['user_register', 'personal_options_update', 'edit_user_profile_update'] as $hook) {
            $this->assertSame(10, has_action($hook, [$save, 'saveUser']), $hook);
            $this->assertSame(1, self::hooks($hook)[0]['args'], $hook);
        }
        $this->assertSame(10, has_action('rest_api_init', [$module->editRoutes(), 'registerRoutes']));
        $this->assertSame($save, $module->saveHandler());
    }
}
