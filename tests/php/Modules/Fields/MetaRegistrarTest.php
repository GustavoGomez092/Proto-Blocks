<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Registry\MetaRegistrar;
use ProtoBlocks\Modules\Fields\Registry\TrustCallbacks;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';

final class MetaRegistrarTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../fixtures/fields';

    protected function setUp(): void
    {
        foreach (['pb_test_filters', 'pb_test_hooks', 'pb_test_meta_reg', 'pb_test_supports', 'pb_test_meta', 'pb_test_posts', 'pb_test_object_terms', 'pb_test_terms', 'pb_test_users'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_current_user'] = 0;
        unset($GLOBALS['wp_version']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_version']);
        $GLOBALS['pb_test_current_user'] = 0;
        $GLOBALS['pb_test_caps'] = [];
    }

    /** @return list<FieldGroup> */
    private static function realistic(): array
    {
        $raw = json_decode((string) file_get_contents(self::FIXTURES . '/acf-export-realistic.json'), true);

        return GroupNormalizer::normalizeMany($raw, 'php')['groups'];
    }

    private static function group(string $key, array $location, array $fields, int $order = 0, array $extra = []): FieldGroup
    {
        $raw = ['key' => $key, 'title' => $key, 'location' => $location, 'fields' => $fields, 'menu_order' => $order] + $extra;
        $group = GroupNormalizer::normalize($raw, 'php')['group'];
        self::assertNotNull($group);

        return $group;
    }

    private static function text(string $key, string $name, string $type = 'text'): array
    {
        return ['key' => $key, 'name' => $name, 'label' => $name, 'type' => $type];
    }

    private static function registrar(array $groups): MetaRegistrar
    {
        $types = TypeRegistry::withCoreTypes();
        $r = new MetaRegistrar(new GroupIndex($groups), $types, new TrustCallbacks($types));
        $r->register();

        return $r;
    }

    /** Hands the registered is_protected_meta callbacks to the bootstrap's apply_filters(). */
    private static function bridgeProtectedMetaFilter(): void
    {
        foreach ($GLOBALS['pb_test_hooks']['is_protected_meta'] ?? [] as $h) {
            $GLOBALS['pb_test_filters']['is_protected_meta'][] = $h['callback'];
        }
    }

    public function testRealisticExportRegistersPostMeta(): void
    {
        $GLOBALS['pb_test_supports']['event']['revisions'] = true;
        $r = self::registrar(self::realistic());

        $args = $GLOBALS['pb_test_meta_reg']['post']['event']['subtitle'];
        $this->assertSame('string', $args['type']);
        $this->assertTrue($args['single']);
        $this->assertTrue($args['revisions_enabled']);
        $this->assertSame('', $args['default']);
        $this->assertSame(['type' => 'string'], $args['show_in_rest']['schema']);
        $this->assertIsCallable($args['sanitize_callback']);
        $this->assertIsCallable($args['auth_callback']);
        $this->assertContains(['post', 'event', 'subtitle'], $r->registered());
        $this->assertTrue($GLOBALS['pb_test_supports']['event']['custom-fields']);

        $agenda = $GLOBALS['pb_test_meta_reg']['post']['event']['agenda'];
        $this->assertSame('array', $agenda['type']);
        $this->assertSame('array', $agenda['show_in_rest']['schema']['type']);
        $this->assertSame(20, $agenda['show_in_rest']['schema']['maxItems']);
        $this->assertSame(['time', 'title', 'notes'], array_keys($agenda['show_in_rest']['schema']['items']['properties']));
        $this->assertSame([], $agenda['default']);

        $this->assertFalse($GLOBALS['pb_test_meta_reg']['post']['event']['is_online']['default']);
        $this->assertSame('boolean', $GLOBALS['pb_test_meta_reg']['post']['event']['is_online']['type']);

        // tab fields hold no value and are never registered.
        $names = array_column($r->registered(), 2);
        $this->assertNotContains('', $names);
        $this->assertCount(15, $names);
    }

    public function testRevisionsOffWithoutSupport(): void
    {
        self::registrar(self::realistic());
        $this->assertFalse($GLOBALS['pb_test_meta_reg']['post']['event']['subtitle']['revisions_enabled']);
    }

    public function testRevisionsOffBeforeWordPress64(): void
    {
        $GLOBALS['pb_test_supports']['event']['revisions'] = true;
        $GLOBALS['wp_version'] = '6.3.2';
        self::registrar(self::realistic());
        $this->assertFalse($GLOBALS['pb_test_meta_reg']['post']['event']['subtitle']['revisions_enabled']);
    }

    public function testProtectedMetaFilter(): void
    {
        self::registrar(self::realistic());
        self::bridgeProtectedMetaFilter();
        $this->assertTrue(apply_filters('is_protected_meta', false, 'subtitle', 'post'));
        $this->assertFalse(apply_filters('is_protected_meta', false, 'unrelated_key', 'post'));
        $this->assertFalse(apply_filters('is_protected_meta', false, 'subtitle', 'user'), 'by meta type');
        $this->assertTrue(apply_filters('is_protected_meta', true, '_edit_lock', 'post'), 'never unprotects');
    }

    public function testUnknownRuleRegistersNothing(): void
    {
        $raw = json_decode((string) file_get_contents(self::FIXTURES . '/groups-invalid/unknown-rule.json'), true);
        $group = GroupNormalizer::normalize($raw, 'php')['group'];
        $this->assertNotNull($group);
        $r = self::registrar([$group]);
        $this->assertSame([], $r->registered());
        $this->assertSame([], $GLOBALS['pb_test_meta_reg']);
        $this->assertSame([], $GLOBALS['pb_test_supports']);
    }

    public function testTaxonomyGroupRegistersTermMeta(): void
    {
        $r = self::registrar([self::group('group_t', [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'product_cat']]], [self::text('field_t1', 'badge')])]);
        $this->assertSame([['term', 'product_cat', 'badge']], $r->registered());
        $args = $GLOBALS['pb_test_meta_reg']['term']['product_cat']['badge'];
        $this->assertArrayNotHasKey('revisions_enabled', $args);
        $this->assertSame('string', $args['type']);
    }

    public function testTaxonomyAllRegistersForEveryTaxonomy(): void
    {
        $r = self::registrar([self::group('group_t', [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'all']]], [self::text('field_t1', 'badge')])]);
        $this->assertSame([['term', '', 'badge']], $r->registered());
    }

    public function testUserRoleAllRegistersUserMeta(): void
    {
        $r = self::registrar([self::group('group_u', [[['param' => 'user_role', 'operator' => '==', 'value' => 'all']]], [self::text('field_u1', 'phone')])]);
        $this->assertSame([['user', '', 'phone']], $r->registered());
        $this->assertArrayHasKey('phone', $GLOBALS['pb_test_meta_reg']['user']['']);
        $this->assertArrayNotHasKey('revisions_enabled', $GLOBALS['pb_test_meta_reg']['user']['']['phone']);
    }

    public function testSensitiveCoreUserMetaKeysRefused(): void
    {
        $loc = [[['param' => 'user_role', 'operator' => '==', 'value' => 'all']]];
        $r = self::registrar([self::group('group_u', $loc, [
            self::text('field_u1', 'wp_capabilities'),
            self::text('field_u2', 'wp_user_level'),
            self::text('field_u3', 'wp_2_capabilities'),
            self::text('field_u4', 'session_tokens'),
            self::text('field_u5', 'capabilities'),
            self::text('field_u6', 'phone'),
            self::text('field_u7', 'shop_capabilities'),
        ])]);
        $this->assertSame([['user', '', 'capabilities'], ['user', '', 'phone'], ['user', '', 'shop_capabilities']], $r->registered());
        $this->assertSame(array_fill(0, 4, 'protected_meta_key'), array_column($r->report()->warnings, 'code'));
        $this->assertFalse(MetaRegistrar::isProtectedUserKey('my_capability_note'));
        $this->assertFalse(MetaRegistrar::isProtectedUserKey('xwp_capabilities'));
        $this->assertFalse(MetaRegistrar::isProtectedUserKey('wp_x_user_level'));
        $this->assertFalse(MetaRegistrar::isProtectedUserKey('session_tokens_copy'));
    }

    public function testProtectedUserKeysFollowTheDatabasePrefix(): void
    {
        $GLOBALS['wpdb'] = new class {
            public string $base_prefix = 'site_';
            public function get_blog_prefix(): string
            {
                return 'site_3_';
            }
        };
        try {
            $this->assertTrue(MetaRegistrar::isProtectedUserKey('site_3_capabilities'));
            $this->assertTrue(MetaRegistrar::isProtectedUserKey('site_user_level'));
            $this->assertTrue(MetaRegistrar::isProtectedUserKey('site_capabilities'), 'main site');
            $this->assertTrue(MetaRegistrar::isProtectedUserKey('site_12_capabilities'));
            $this->assertFalse(MetaRegistrar::isProtectedUserKey('wp_capabilities'), 'not this install\'s prefix');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testCodeFieldsAreGuardedAfterRegistration(): void
    {
        $GLOBALS['pb_test_caps'] = [2 => ['edit_post']];
        $GLOBALS['pb_test_current_user'] = 2;
        $GLOBALS['pb_test_posts'][7] = ['ID' => 7, 'post_type' => 'event', 'post_status' => 'draft'];
        $GLOBALS['pb_test_meta']['post'][7]['embed'] = ['<script>ok()</script>'];
        self::registrar([self::group('group_c', [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], [self::text('field_c1', 'embed', 'code'), self::text('field_c2', 'note')])]);

        $this->assertFalse(update_post_meta(7, 'embed', '<script>evil()</script>'));
        $this->assertSame('<script>ok()</script>', $GLOBALS['pb_test_meta']['post'][7]['embed'][0]);
        $this->assertTrue(update_post_meta(7, 'note', '<b>n</b>'));
        $this->assertSame('n', $GLOBALS['pb_test_meta']['post'][7]['note'][0]);
    }

    public function testBranchWithoutPostTypeRegistersForAllPostTypes(): void
    {
        $r = self::registrar([self::group('group_s', [[['param' => 'post_status', 'operator' => '==', 'value' => 'draft']]], [self::text('field_s1', 'note')])]);
        $this->assertSame([['post', '', 'note']], $r->registered());
        $this->assertFalse($GLOBALS['pb_test_meta_reg']['post']['']['note']['revisions_enabled']);
        $this->assertSame([], $GLOBALS['pb_test_supports'], 'a "" subtype follows each type\'s own support');
    }

    public function testOptionsPageIsNotMeta(): void
    {
        $r = self::registrar([self::group('group_o', [[['param' => 'options_page', 'operator' => '==', 'value' => 'theme-settings']]], [self::text('field_o1', 'phone')])]);
        $this->assertSame([], $r->registered());
        $this->assertSame([], $GLOBALS['pb_test_meta_reg']);
        $this->assertSame([['option', 'theme-settings', 'theme-settings_phone']], $r->registeredOptions());
        $this->assertIsCallable($GLOBALS['pb_test_hooks']['sanitize_option_theme-settings_phone'][0]['callback']);
    }

    public function testProtectedOptionNameIsNeverHooked(): void
    {
        $r = self::registrar([self::group('group_o', [[['param' => 'options_page', 'operator' => '==', 'value' => 'wp']]], [self::text('field_o1', 'user_roles')])]);
        $this->assertSame([], $r->registeredOptions());
        $this->assertArrayNotHasKey('sanitize_option_wp_user_roles', $GLOBALS['pb_test_hooks']);
        $this->assertSame('protected_option', $r->report()->warnings[0]['code']);
    }

    public function testDuplicateNameFirstWins(): void
    {
        $loc = [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]];
        $late = self::group('group_late', $loc, [self::text('field_l1', 'subtitle', 'number')], 5);
        $early = self::group('group_early', $loc, [self::text('field_e1', 'subtitle')], 1);
        $r = self::registrar([$late, $early]);

        $this->assertSame([['post', 'event', 'subtitle']], $r->registered());
        $this->assertSame('string', $GLOBALS['pb_test_meta_reg']['post']['event']['subtitle']['type'], 'menu_order 1 beats 5');
        $this->assertSame('duplicate_meta_key', $r->report()->warnings[0]['code']);

        $GLOBALS['pb_test_posts'][7] = ['ID' => 7, 'post_type' => 'event', 'post_status' => 'draft'];
        $found = (new GroupIndex([$late, $early]))->fieldFor(ObjectRef::post(7), 'subtitle');
        $this->assertSame('group_early', $found['group']->key, 'the getter resolves the same winner');
    }

    public function testDuplicateAcrossAllAndConcreteSubtype(): void
    {
        $all = self::group('group_all', [[['param' => 'post_status', 'operator' => '==', 'value' => 'draft']]], [self::text('field_a1', 'note')], 0);
        $event = self::group('group_ev', [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], [self::text('field_b1', 'note')], 1);
        $r = self::registrar([$all, $event]);
        $this->assertSame([['post', '', 'note']], $r->registered());
        $this->assertSame('duplicate_meta_key', $r->report()->warnings[0]['code']);
    }

    public function testSameOrderKeepsSourceOrder(): void
    {
        $loc = [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]];
        $a = self::group('group_a', $loc, [self::text('field_a1', 'subtitle', 'number')]);
        $b = self::group('group_b', $loc, [self::text('field_b1', 'subtitle')]);
        self::registrar([$a, $b]);
        $this->assertSame('number', $GLOBALS['pb_test_meta_reg']['post']['event']['subtitle']['type'], 'first in source order wins');
    }

    public function testRegisterTwiceIsHarmless(): void
    {
        $r = self::registrar(self::realistic());
        $count = count($r->registered());
        $r->register();
        $this->assertCount($count, $r->registered());
        $this->assertCount(1, $GLOBALS['pb_test_hooks']['is_protected_meta']);
    }

    public function testMultiplePostTypesInOrBranches(): void
    {
        $r = self::registrar([self::group('group_m', [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'event']],
            [['param' => 'post_type', 'operator' => '==', 'value' => 'venue']],
        ], [self::text('field_m1', 'code_note', 'code')])]);
        $this->assertSame([['post', 'event', 'code_note'], ['post', 'venue', 'code_note']], $r->registered());
        $this->assertTrue($GLOBALS['pb_test_supports']['venue']['custom-fields']);
    }

    public function testSchemasValidateTheirDefaults(): void
    {
        // register_meta() rejects a default that its own schema refuses; check the type the default has.
        self::registrar(self::realistic());
        foreach ($GLOBALS['pb_test_meta_reg']['post']['event'] as $name => $args) {
            $default = $args['default'];
            $ok = match ($args['type']) {
                'string' => is_string($default),
                'boolean' => is_bool($default),
                'integer' => is_int($default) || $default === null,
                'number' => is_int($default) || is_float($default) || $default === null,
                'array' => is_array($default) && array_is_list($default),
                'object' => is_array($default),
                default => false,
            };
            $this->assertTrue($ok, "$name: default " . var_export($default, true) . " for type {$args['type']}");
        }
    }

    public function testShowInRestOptOutAndSecretDefaults(): void
    {
        $loc = [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]];
        self::registrar([self::group('group_rest', $loc, [
            self::text('f_plain', 'plain'),
            ['key' => 'f_hidden', 'name' => 'hidden_note', 'label' => 'h', 'type' => 'text', 'show_in_rest' => false],
            ['key' => 'f_zero', 'name' => 'zero_note', 'label' => 'z', 'type' => 'text', 'show_in_rest' => 0],
            self::text('f_pass', 'secret', 'password'),
            self::text('f_code', 'snippet', 'code'),
            ['key' => 'f_rows', 'name' => 'embeds', 'label' => 'e', 'type' => 'repeater', 'sub_fields' => [self::text('f_rows_html', 'html', 'code')]],
            ['key' => 'f_creds', 'name' => 'creds', 'label' => 'c', 'type' => 'group', 'sub_fields' => [self::text('f_creds_pw', 'pw', 'password')]],
            ['key' => 'f_open_code', 'name' => 'public_snippet', 'label' => 'p', 'type' => 'code', 'show_in_rest' => true],
        ])]);

        $reg = $GLOBALS['pb_test_meta_reg']['post']['event'];
        $this->assertSame(['type' => 'string'], $reg['plain']['show_in_rest']['schema']);
        foreach (['hidden_note', 'zero_note', 'secret', 'snippet', 'embeds', 'creds'] as $name) {
            $this->assertFalse($reg[$name]['show_in_rest'], $name);
            $this->assertIsCallable($reg[$name]['sanitize_callback'], $name . ' is still registered');
        }
        $this->assertSame(['type' => 'string'], $reg['public_snippet']['show_in_rest']['schema'], 'an explicit opt-in wins');
    }
}
