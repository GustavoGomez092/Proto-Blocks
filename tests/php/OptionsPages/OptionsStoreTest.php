<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\OptionsPages\OptionsStore;
use ProtoBlocks\OptionsPages\PageDefinition;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../../includes/OptionsPages/functions.php';

final class OptionsStoreTest extends TestCase
{
    private const DISPLAY = ['hide_children' => '1', 'children_placement' => 'section', 'children_heading' => 'More in this range'];

    protected function setUp(): void
    {
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_autoload'] = [];
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_update_calls'] = 0;
    }

    protected function tearDown(): void
    {
        $GLOBALS['pb_test_filters'] = [];
    }

    private static function page(string $slug, array $data): PageDefinition
    {
        return PageDefinition::fromArray($slug, $data, 'php');
    }

    private static function theme(): PageDefinition
    {
        return self::page('theme-settings', [
            'title' => 'Theme Settings',
            'autoload' => false,
            'fields' => [['name' => 'phone', 'type' => 'text']],
        ]);
    }

    private static function cadco(): PageDefinition
    {
        return self::page('cadco-display-settings', [
            'title' => 'Display',
            'storage' => ['mode' => 'array', 'option' => 'cadco_display_settings'],
            'fields' => [
                ['name' => 'hide_children', 'type' => 'true_false'],
                ['name' => 'children_placement', 'type' => 'text'],
                ['name' => 'children_heading', 'type' => 'text'],
            ],
        ]);
    }

    public function testPerFieldReadReturnsNullWhenMissing(): void
    {
        $store = new OptionsStore();
        self::assertFalse($store->has(self::theme(), 'phone'));
        self::assertNull($store->read(self::theme(), 'phone'));
    }

    public function testPerFieldSaveWritesOneOptionEach(): void
    {
        $store = new OptionsStore();
        $store->save(self::theme(), ['phone' => '555']);
        self::assertSame('555', $GLOBALS['pb_test_options']['theme-settings_phone']);
        self::assertFalse($GLOBALS['pb_test_autoload']['theme-settings_phone']);
        self::assertTrue($store->has(self::theme(), 'phone'));
        self::assertSame('555', $store->read(self::theme(), 'phone'));
    }

    public function testArrayModeReadsExistingOption(): void
    {
        $GLOBALS['pb_test_options']['cadco_display_settings'] = self::DISPLAY;
        $store = new OptionsStore();
        self::assertSame('1', $store->read(self::cadco(), 'hide_children'));
        self::assertTrue($store->has(self::cadco(), 'hide_children'));
        self::assertFalse($store->has(self::cadco(), 'legacy_sort'));
        self::assertNull($store->read(self::cadco(), 'legacy_sort'));
    }

    public function testArrayModeIgnoresNonArrayOption(): void
    {
        $GLOBALS['pb_test_options']['cadco_display_settings'] = 'garbage';
        $store = new OptionsStore();
        self::assertFalse($store->has(self::cadco(), 'hide_children'));
        $store->save(self::cadco(), ['children_placement' => 'related']);
        self::assertSame(['children_placement' => 'related'], $GLOBALS['pb_test_options']['cadco_display_settings']);
    }

    public function testArrayModePreservesUndeclaredKeys(): void
    {
        $GLOBALS['pb_test_options']['cadco_display_settings'] = self::DISPLAY + ['legacy_sort' => 'menu_order'];
        (new OptionsStore())->save(self::cadco(), ['children_placement' => 'related']);
        $stored = $GLOBALS['pb_test_options']['cadco_display_settings'];
        self::assertSame('menu_order', $stored['legacy_sort']);
        self::assertSame('related', $stored['children_placement']);
        self::assertSame('1', $stored['hide_children']);
        self::assertSame('More in this range', $stored['children_heading']);
        self::assertSame(1, $GLOBALS['pb_test_update_calls']);
    }

    public function testTrueFalseKeepsExistingFormat(): void
    {
        self::assertSame('', OptionsStore::coerceLike('1', false));
        self::assertSame('1', OptionsStore::coerceLike('', true));
        self::assertSame(1, OptionsStore::coerceLike(0, true));
        self::assertSame(0, OptionsStore::coerceLike(1, false));
        self::assertSame('no', OptionsStore::coerceLike('yes', false));
        self::assertSame('on', OptionsStore::coerceLike('off', true));
        self::assertSame('false', OptionsStore::coerceLike('true', false));
        self::assertFalse(OptionsStore::coerceLike(true, false));
        self::assertTrue(OptionsStore::coerceLike(null, true));
        self::assertTrue(OptionsStore::coerceLike('weird', true));

        $GLOBALS['pb_test_options']['cadco_display_settings'] = self::DISPLAY;
        (new OptionsStore())->save(self::cadco(), ['hide_children' => false], ['hide_children']);
        self::assertSame('', $GLOBALS['pb_test_options']['cadco_display_settings']['hide_children']);
    }

    public function testPerFieldTrueFalseKeepsExistingFormat(): void
    {
        $GLOBALS['pb_test_options']['theme-settings_phone'] = 'yes';
        (new OptionsStore())->save(self::theme(), ['phone' => false], ['phone']);
        self::assertSame('no', $GLOBALS['pb_test_options']['theme-settings_phone']);
    }

    public function testArrayModeAutoloadDefaultTrue(): void
    {
        (new OptionsStore())->save(self::cadco(), ['children_placement' => 'related']);
        self::assertTrue($GLOBALS['pb_test_autoload']['cadco_display_settings']);
    }

    public function testSaveRefusesProtectedOptionName(): void
    {
        $cadco = self::cadco();
        $GLOBALS['pb_test_options']['cadco_display_settings'] = self::DISPLAY;
        $GLOBALS['pb_test_filters']['proto_blocks_protected_options'][] =
            static fn(array $list): array => [...$list, 'cadco_display_settings'];
        try {
            (new OptionsStore())->save($cadco, ['children_placement' => 'related']);
            self::fail('Expected DomainException.');
        } catch (\DomainException) {
        }
        self::assertSame(self::DISPLAY, $GLOBALS['pb_test_options']['cadco_display_settings']);
        self::assertSame(0, $GLOBALS['pb_test_update_calls']);
    }

    public function testPerFieldProtectedNameWritesNothing(): void
    {
        // Build while the core list is emptied, then restore it before saving.
        $GLOBALS['pb_test_filters']['proto_blocks_protected_options'][] = static fn(array $list): array => [];
        $users = self::page('users', ['title' => 'Users', 'fields' => [['name' => 'can_register', 'type' => 'text']]]);
        $GLOBALS['pb_test_filters'] = [];

        try {
            (new OptionsStore())->save($users, ['can_register' => '1']);
            self::fail('Expected DomainException.');
        } catch (\DomainException) {
        }
        self::assertArrayNotHasKey('users_can_register', $GLOBALS['pb_test_options']);
    }

    public function testNothingWrittenWhenAnyPerFieldNameIsProtected(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_protected_options'][] = static fn(array $list): array => [];
        $page = self::page('users', ['title' => 'Users', 'fields' => [
            ['name' => 'ok', 'type' => 'text'],
            ['name' => 'can_register', 'type' => 'text'],
        ]]);
        $GLOBALS['pb_test_filters'] = [];

        try {
            (new OptionsStore())->save($page, ['ok' => 'a', 'can_register' => '1']);
            self::fail('Expected DomainException.');
        } catch (\DomainException) {
        }
        self::assertSame([], $GLOBALS['pb_test_options']);
    }
}
