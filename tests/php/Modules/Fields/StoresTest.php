<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\PostMetaStore;
use ProtoBlocks\Modules\Fields\Storage\TermMetaStore;
use ProtoBlocks\Modules\Fields\Storage\UserMetaStore;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Storage.php';

final class StoresTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (['pb_test_meta', 'pb_test_meta_reg', 'pb_test_options', 'pb_test_autoload', 'pb_test_slashed', 'pb_test_posts', 'pb_test_terms'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_posts'][10] = ['ID' => 10, 'post_type' => 'event', 'post_status' => 'draft'];
        $GLOBALS['pb_test_terms'][5] = ['term_id' => 5, 'taxonomy' => 'product_cat', 'slug' => 'a'];
    }

    private static function field(string $name, string $type = 'text'): Field
    {
        return new Field('field_' . $name, $name, $name, $type, $type, false, null, '', '', [], [], [], []);
    }

    private static function optionsGroup(array $extra = []): GroupIndex
    {
        $raw = [
            'key' => 'group_opts',
            'title' => 'Opts',
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'theme-settings']]],
            'fields' => [['key' => 'field_phone', 'name' => 'phone', 'label' => 'Phone', 'type' => 'text']],
        ] + $extra;

        return new GroupIndex([GroupNormalizer::normalize($raw, 'php')['group']]);
    }

    public function testOptionStoreAutoloadsByDefault(): void
    {
        $index = self::optionsGroup();
        $phone = $index->all()[0]->fields[0];
        $store = new OptionStore($index);
        $ref = ObjectRef::option('theme-settings');

        $this->assertFalse($store->has($ref, $phone));
        $this->assertNull($store->get($ref, $phone));
        $store->set($ref, $phone, '555-0100');
        $this->assertSame('555-0100', $GLOBALS['pb_test_options']['theme-settings_phone']);
        $this->assertTrue($GLOBALS['pb_test_autoload']['theme-settings_phone']);
        $this->assertTrue($store->has($ref, $phone));
        $this->assertSame('555-0100', $store->get($ref, $phone));
    }

    public function testOptionStoreAutoloadOff(): void
    {
        $index = self::optionsGroup(['autoload' => false]);
        $phone = $index->all()[0]->fields[0];
        (new OptionStore($index))->set(ObjectRef::option('theme-settings'), $phone, '1');
        $this->assertFalse($GLOBALS['pb_test_autoload']['theme-settings_phone']);
    }

    public function testOptionStoreAutoloadOnlyFalseTurnsItOff(): void
    {
        $index = self::optionsGroup(['autoload' => 0]);
        $phone = $index->all()[0]->fields[0];
        (new OptionStore($index))->set(ObjectRef::option('theme-settings'), $phone, '1');
        $this->assertTrue($GLOBALS['pb_test_autoload']['theme-settings_phone']);
    }

    public function testOptionStoreRefusesProtectedNames(): void
    {
        $this->expectException(\DomainException::class);
        try {
            (new OptionStore())->set(ObjectRef::option('wp'), self::field('user_roles'), ['x']);
        } finally {
            $this->assertArrayNotHasKey('wp_user_roles', $GLOBALS['pb_test_options']);
        }
    }

    public function testOptionStoreStoredFalseIsPresent(): void
    {
        $GLOBALS['pb_test_options']['theme-settings_flag'] = false;
        $store = new OptionStore();
        $this->assertTrue($store->has(ObjectRef::option('theme-settings'), self::field('flag')));
        $this->assertFalse($store->get(ObjectRef::option('theme-settings'), self::field('flag')));
    }

    public function testMetaStoresRoundTripArrays(): void
    {
        $value = [['title' => 'A \\ b', 'n' => 1, 'nested' => ['x' => [1, 2]]], ['title' => '', 'n' => null]];
        $cases = [
            [new PostMetaStore(), ObjectRef::post(10), 'post'],
            [new TermMetaStore(), ObjectRef::term(5, 'product_cat'), 'term'],
            [new UserMetaStore(), ObjectRef::user(3), 'user'],
        ];
        foreach ($cases as [$store, $ref, $kind]) {
            $f = self::field('rows', 'repeater');
            $this->assertFalse($store->has($ref, $f), $kind);
            $this->assertNull($store->get($ref, $f), $kind);
            $store->set($ref, $f, $value);
            $this->assertTrue($store->has($ref, $f), $kind);
            $this->assertSame($value, $store->get($ref, $f), $kind);
            $this->assertSame([$value], $GLOBALS['pb_test_meta'][$kind][$ref->id]['rows'], "$kind: one row");
        }
        $this->assertCount(3, $GLOBALS['pb_test_slashed'], 'every meta write is slashed first');
    }

    public function testMetaStoreWritesRunTheRegisteredSanitizeCallback(): void
    {
        $GLOBALS['pb_test_meta_reg']['post']['event']['subtitle'] = ['sanitize_callback' => static fn($v) => strtoupper((string) $v)];
        (new PostMetaStore())->set(ObjectRef::post(10), self::field('subtitle'), 'hi');
        $this->assertSame('HI', $GLOBALS['pb_test_meta']['post'][10]['subtitle'][0]);
    }

    public function testStoredEmptyStringIsPresent(): void
    {
        $GLOBALS['pb_test_meta']['post'][10]['subtitle'] = [''];
        $store = new PostMetaStore();
        $this->assertTrue($store->has(ObjectRef::post(10), self::field('subtitle')));
        $this->assertSame('', $store->get(ObjectRef::post(10), self::field('subtitle')));
    }

    public function testWrongKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PostMetaStore())->set(ObjectRef::user(3), self::field('x'), 'v');
    }

    public function testObjectRefs(): void
    {
        $post = ObjectRef::post(10);
        $this->assertSame(['post', 10, 'event', ''], [$post->kind, $post->id, $post->subtype, $post->page]);
        $this->assertSame('', ObjectRef::post(999)->subtype);
        $term = ObjectRef::term(5, 'product_cat');
        $this->assertSame(['term', 5, 'product_cat'], [$term->kind, $term->id, $term->subtype]);
        $this->assertSame(['user', 3, ''], [ObjectRef::user(3)->kind, ObjectRef::user(3)->id, ObjectRef::user(3)->subtype]);
        $opt = ObjectRef::option('theme-settings');
        $this->assertSame(['option', 0, '', 'theme-settings'], [$opt->kind, $opt->id, $opt->subtype, $opt->page]);
    }
}
