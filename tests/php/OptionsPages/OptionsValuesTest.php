<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../../includes/OptionsPages/functions.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\API\OptionsValuesRoutes;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\OptionsStore;
use ProtoBlocks\OptionsPages\OptionsValues;
use ProtoBlocks\OptionsPages\PageDefinition;

/**
 * Options page values through the Phase 2 field pipeline (normalise, trust,
 * validate) and the `proto-blocks/v1/options/<slug>` routes.
 */
final class OptionsValuesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/options-pages';
    private const G = 'group_cadco_display_settings_1';
    private const CADCO = 'cadco-display-settings';
    private const OPTION = 'cadco_display_settings';

    private string $theme = '';

    /** @var list<FieldGroup> */
    private array $extra = [];

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_autoload'] = [];
        $GLOBALS['pb_test_routes'] = [];
        $GLOBALS['pb_test_caps'] = [
            1 => ['manage_woocommerce'],
            2 => ['manage_woocommerce', 'unfiltered_html'],
            3 => ['manage_options'],
        ];
        $GLOBALS['pb_test_current_user'] = 1;
        $tmp = sys_get_temp_dir() . '/pb-options-values-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->theme = (string) realpath($tmp);
        foreach ([self::CADCO, 'theme-settings'] as $slug) {
            $this->place($slug, (string) file_get_contents(self::FIXTURES . '/' . $slug . '/page.jsonc'));
        }
        $GLOBALS['pb_test_theme'] = ['template' => $this->theme, 'stylesheet' => $this->theme];
        $this->extra = [];
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_caps'] = [];
        $GLOBALS['pb_test_current_user'] = 0;
        unset($GLOBALS['pb_test_theme']);
        self::removeTree($this->theme);
    }

    // ------------------------------------------------------------ helpers

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

    private function values(?OptionsPagesRegistry $registry = null): OptionsValues
    {
        return new OptionsValues(
            $registry ?? $this->registry(),
            new OptionsStore(),
            TypeRegistry::withCoreTypes(),
            fn (string $slug): array => array_values(array_filter(
                $this->extra,
                static function (FieldGroup $g) use ($slug): bool {
                    foreach ($g->location->or as $branch) {
                        foreach ($branch as $rule) {
                            if ($rule['param'] === 'options_page' && $rule['value'] === $slug) {
                                return true;
                            }
                        }
                    }
                    return false;
                }
            ))
        );
    }

    private function page(string $slug): PageDefinition
    {
        $page = $this->registry()->get($slug);
        self::assertNotNull($page, $slug);

        return $page;
    }

    /** @param array<string, mixed> $fields */
    private static function standalone(string $key, string $page, array $fields): FieldGroup
    {
        $result = GroupNormalizer::normalize([
            'key' => $key,
            'title' => 'Standalone',
            'fields' => $fields,
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => $page]]],
        ], 'php');
        self::assertNotNull($result['group'], json_encode($result['report']->errors));

        return $result['group'];
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        $value = $GLOBALS['pb_test_options'][self::OPTION] ?? [];

        return is_array($value) ? $value : [];
    }

    private function cadcoSave(array $fields, ?array $present = null, int $user = 1, ?OptionsValues $values = null): array|\WP_Error
    {
        $values ??= $this->values();

        return $values->save($this->page(self::CADCO), [self::G => $fields], [self::G => $present ?? array_keys($fields)], $user);
    }

    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------ permission

    public function testPermissionUsesPageCapability(): void
    {
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());
        $GLOBALS['pb_test_current_user'] = 1;

        self::assertTrue($routes->permission(new \WP_REST_Request(['slug' => self::CADCO])));
        self::assertFalse($routes->permission(new \WP_REST_Request(['slug' => 'theme-settings'])));

        $missing = $routes->permission(new \WP_REST_Request(['slug' => 'nope']));
        self::assertInstanceOf(\WP_Error::class, $missing);
        self::assertSame('proto_blocks_options_page_not_found', $missing->get_error_code());
        self::assertSame(404, $missing->get_error_data()['status']);

        $GLOBALS['pb_test_current_user'] = 3;
        self::assertTrue($routes->permission(new \WP_REST_Request(['slug' => 'theme-settings'])));
        self::assertFalse($routes->permission(new \WP_REST_Request(['slug' => self::CADCO])), 'manage_options does not imply the page capability');
    }

    public function testPermissionDeniedForLoggedOut(): void
    {
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());
        $GLOBALS['pb_test_current_user'] = 0;
        // Even a capability every user holds does not let a logged-out request in.
        $GLOBALS['pb_test_caps'][0] = ['exist', 'read'];
        $this->place('everyone', '{"title": "Everyone", "capability": "exist", "fields": [{"name": "note", "type": "text"}]}');

        foreach ([self::CADCO, 'theme-settings', 'theme-settings-footer', 'everyone'] as $slug) {
            self::assertFalse($routes->permission(new \WP_REST_Request(['slug' => $slug])), $slug);
        }
    }

    public function testUnknownCapabilityFailsClosed(): void
    {
        $this->place('typo', '{"title": "Typo", "capability": "manage_woocomerce", "fields": [{"name": "note", "type": "text"}]}');
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());

        foreach ([1, 3] as $user) {
            $GLOBALS['pb_test_current_user'] = $user;
            self::assertFalse($routes->permission(new \WP_REST_Request(['slug' => 'typo'])), 'user ' . $user);
        }
    }

    // ------------------------------------------------------------ save pipeline

    public function testSaveValidValues(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['hide_children' => '', 'legacy' => 'kept'];

        $result = $this->cadcoSave(['hide_children' => '1', 'children_placement' => 'section', 'children_heading' => 'More']);

        self::assertIsArray($result);
        $stored = self::stored();
        self::assertSame('1', $stored['hide_children'], 'stored in the existing 1/"" format');
        self::assertSame('section', $stored['children_placement']);
        self::assertSame('More', $stored['children_heading']);
        self::assertSame('kept', $stored['legacy'], 'undeclared keys of the array option are kept');
        self::assertSame('More', $result['children_heading']);
        self::assertTrue($GLOBALS['pb_test_autoload'][self::OPTION]);
    }

    public function testReadFallsBackToDefaults(): void
    {
        $values = $this->values()->read($this->page(self::CADCO));

        self::assertSame(['hide_children', 'children_placement', 'children_heading'], array_keys($values));
        self::assertSame('related', $values['children_placement']);
    }

    public function testInvalidChoiceRejectedAndNothingSaved(): void
    {
        $before = ['children_placement' => 'related', 'children_heading' => 'Old'];
        $GLOBALS['pb_test_options'][self::OPTION] = $before;

        $result = $this->cadcoSave(['hide_children' => '1', 'children_placement' => 'sidebar', 'children_heading' => 'x']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('proto_blocks_invalid_values', $result->get_error_code());
        self::assertSame(422, $result->get_error_data()['status']);
        self::assertSame('Choose one of the listed options.', $result->get_error_data()['errors']['children_placement']);
        self::assertSame($before, $GLOBALS['pb_test_options'][self::OPTION]);
    }

    public function testHiddenFieldNeitherValidatedNorWritten(): void
    {
        $text = (string) file_get_contents(self::FIXTURES . '/' . self::CADCO . '/page.jsonc');
        $text = str_replace('"type": "text",', '"type": "text", "required": true,', $text);
        self::assertStringContainsString('"required": true', $text);
        $this->place(self::CADCO, $text);
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_heading' => 'Old'];

        $result = $this->cadcoSave(['children_placement' => 'related', 'children_heading' => '']);

        self::assertIsArray($result);
        self::assertSame('Old', self::stored()['children_heading']);
        self::assertSame('related', self::stored()['children_placement']);
    }

    public function testRequiredValueEmptiedBySanitiserRejected(): void
    {
        $text = (string) file_get_contents(self::FIXTURES . '/' . self::CADCO . '/page.jsonc');
        $this->place(self::CADCO, str_replace('"type": "text",', '"type": "text", "required": true,', $text));
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_heading' => 'Old'];

        $result = $this->cadcoSave(['children_placement' => 'section', 'children_heading' => '<script>x</script>'], null, 1);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('This field is required.', $result->get_error_data()['errors']['children_heading']);
        self::assertSame(['children_heading' => 'Old'], $GLOBALS['pb_test_options'][self::OPTION]);

        // A trusted writer stores the same value as given.
        self::assertIsArray($this->cadcoSave(['children_placement' => 'section', 'children_heading' => '<script>x</script>'], null, 2));
    }

    public function testFieldsNotPresentUntouched(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_placement' => 'section', 'children_heading' => 'Old'];

        $result = $this->cadcoSave(['children_placement' => 'section', 'children_heading' => 'New'], ['children_placement']);

        self::assertIsArray($result);
        self::assertSame('Old', self::stored()['children_heading']);
    }

    public function testUntrustedValuesAreSanitised(): void
    {
        $this->cadcoSave(['children_placement' => 'section', 'children_heading' => '<script>x</script>Hi'], null, 1);

        self::assertStringNotContainsString('<script', (string) self::stored()['children_heading']);
        self::assertSame('Hi', self::stored()['children_heading']);
    }

    public function testTrustedValuesStoredAsGiven(): void
    {
        $this->cadcoSave(['children_placement' => 'section', 'children_heading' => '<script>x</script>Hi'], null, 2);

        self::assertSame('<script>x</script>Hi', self::stored()['children_heading']);
    }

    public function testUntrustedCodeFieldUntouched(): void
    {
        $GLOBALS['pb_test_options']['theme-settings_head_scripts'] = '<script>ok()</script>';
        $values = $this->values();
        $page = $this->page('theme-settings');
        $group = self::groupKeyOf($values, $page, 'head_scripts');

        $result = $values->save($page, [$group => ['head_scripts' => '<img onerror=x>']], [$group => ['head_scripts']], 3);

        self::assertIsArray($result);
        self::assertSame('<script>ok()</script>', $GLOBALS['pb_test_options']['theme-settings_head_scripts']);
        self::assertSame('<script>ok()</script>', $result['head_scripts']);
        $notices = $values->notices();
        self::assertCount(1, $notices);
        self::assertSame('head_scripts', $notices[0]['field']);
        self::assertSame('Only users with unfiltered HTML may edit this field.', $notices[0]['message']);
    }

    public function testTrustedCodeFieldWritten(): void
    {
        $GLOBALS['pb_test_caps'][3][] = 'unfiltered_html';
        $values = $this->values();
        $page = $this->page('theme-settings');
        $group = self::groupKeyOf($values, $page, 'head_scripts');

        $values->save($page, [$group => ['head_scripts' => '<script>new()</script>']], [$group => ['head_scripts']], 3);

        self::assertSame('<script>new()</script>', $GLOBALS['pb_test_options']['theme-settings_head_scripts']);
        self::assertFalse($GLOBALS['pb_test_autoload']['theme-settings_head_scripts'], 'page autoload false');
        self::assertSame([], $values->notices());
    }

    public function testUndeclaredKeysDropped(): void
    {
        $result = $this->cadcoSave(
            ['children_placement' => 'section', 'admin_email' => 'a@b.c'],
            ['children_placement', 'admin_email']
        );
        self::assertIsArray($result);
        self::assertArrayNotHasKey('admin_email', self::stored());
        self::assertArrayNotHasKey('admin_email', $GLOBALS['pb_test_options']);

        $values = $this->values();
        $page = $this->page('theme-settings');
        $values->save($page, ['group_unknown' => ['admin_email' => 'a@b.c'], '' => ['phone' => '1']], ['group_unknown' => ['admin_email'], '' => ['phone']], 3);
        foreach (array_keys($GLOBALS['pb_test_options']) as $name) {
            self::assertStringNotContainsString('admin_email', (string) $name);
        }
        self::assertArrayNotHasKey('theme-settings_phone', $GLOBALS['pb_test_options']);
    }

    public function testValuesFromAnotherGroupKeyIgnored(): void
    {
        $values = $this->values();
        $page = $this->page('theme-settings');
        $phoneGroup = self::groupKeyOf($values, $page, 'phone');
        $codeGroup = self::groupKeyOf($values, $page, 'head_scripts');
        self::assertNotSame($phoneGroup, $codeGroup);

        $values->save($page, [$codeGroup => ['phone' => '555']], [$codeGroup => ['phone']], 3);

        self::assertArrayNotHasKey('theme-settings_phone', $GLOBALS['pb_test_options']);
    }

    public function testStandaloneGroupAttachesByLocation(): void
    {
        $this->extra = [
            self::standalone('group_extra', 'theme-settings', [['key' => 'field_extra_fax', 'name' => 'fax', 'label' => 'Fax', 'type' => 'text']]),
            self::standalone('group_other', 'other-page', [['key' => 'field_other_x', 'name' => 'x', 'label' => 'X', 'type' => 'text']]),
        ];
        $values = $this->values();
        $page = $this->page('theme-settings');

        $fields = $values->fields($page);
        self::assertArrayHasKey('fax', $fields);
        self::assertArrayNotHasKey('x', $fields);
        self::assertSame(['phone', 'head_scripts', 'fax'], array_keys($fields));

        $result = $values->save($page, ['group_extra' => ['fax' => '123']], ['group_extra' => ['fax']], 3);
        self::assertIsArray($result);
        self::assertSame('123', $GLOBALS['pb_test_options']['theme-settings_fax']);
        self::assertSame('123', $result['fax']);
    }

    public function testStandaloneGroupWithPageGroupKeyNotDuplicated(): void
    {
        $values = $this->values();
        $page = $this->page('theme-settings');
        $this->extra = $values->pageGroups($page);

        self::assertCount(count($values->pageGroups($page)), $this->values()->groupsFor($page));
    }

    public function testStandaloneFieldReusingPageNameDropped(): void
    {
        $this->extra = [self::standalone('group_extra', 'theme-settings', [['key' => 'field_extra_phone', 'name' => 'phone', 'label' => 'Phone 2', 'type' => 'text']])];
        $values = $this->values();
        $page = $this->page('theme-settings');

        self::assertSame('field_theme_settings_phone', $values->fields($page)['phone']->key);
        self::assertNotSame([], array_filter($values->warnings($page), static fn (string $w): bool => str_contains($w, '"phone"')));
    }

    public function testStandaloneFieldDerivingProtectedNameDropped(): void
    {
        $page = PageDefinition::fromArray('users', ['title' => 'Users', 'fields' => [['name' => 'note', 'type' => 'text']]], 'php');
        $this->extra = [self::standalone('group_extra', 'users', [['key' => 'field_users_reg', 'name' => 'can_register', 'label' => 'Can register', 'type' => 'true_false']])];
        $values = $this->values();

        self::assertArrayNotHasKey('can_register', $values->fields($page));
        self::assertArrayHasKey('note', $values->fields($page));
        self::assertNotSame([], array_filter($values->warnings($page), static fn (string $w): bool => str_contains($w, 'users_can_register')));

        $values->save($page, ['group_extra' => ['can_register' => '1']], ['group_extra' => ['can_register']], 3);
        self::assertArrayNotHasKey('users_can_register', $GLOBALS['pb_test_options']);
    }

    public function testProtectedOptionRefusedAtSave(): void
    {
        $values = $this->values();
        $page = $this->page(self::CADCO);
        $before = ['children_placement' => 'related'];
        $GLOBALS['pb_test_options'][self::OPTION] = $before;
        $GLOBALS['pb_test_filters']['proto_blocks_protected_options'][] = static fn (array $list): array => [...$list, self::OPTION];

        $result = $values->save($page, [self::G => ['children_placement' => 'section']], [self::G => ['children_placement']], 1);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('proto_blocks_protected_option', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
        self::assertSame($before, $GLOBALS['pb_test_options'][self::OPTION]);
    }

    public function testArrayModeRefusesToReplaceNonArrayOption(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = 'another plugin owns this';
        $values = $this->values();

        $result = $this->cadcoSave(['children_placement' => 'section'], null, 1, $values);

        self::assertIsArray($result);
        self::assertSame('another plugin owns this', $GLOBALS['pb_test_options'][self::OPTION]);
        $notices = $values->notices();
        self::assertCount(1, $notices);
        self::assertNull($notices[0]['field']);
        self::assertStringContainsString(self::OPTION, $notices[0]['message']);
    }

    // ------------------------------------------------------------ routes

    public function testRoutesRegistered(): void
    {
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());
        $routes->registerRoutes();

        self::assertCount(1, $GLOBALS['pb_test_routes']);
        $route = $GLOBALS['pb_test_routes'][0];
        self::assertSame('proto-blocks/v1', $route['namespace']);
        self::assertSame('/options/(?P<slug>[a-z][a-z0-9-]{0,63})', $route['route']);
        $methods = [];
        foreach ($route['args'] as $endpoint) {
            $methods[] = $endpoint['methods'];
            self::assertSame([$routes, 'permission'], $endpoint['permission_callback']);
        }
        self::assertSame(['GET', 'POST'], $methods);

        // Core matches routes as '@^' . $route . '$@i': case-insensitive, and `$`
        // accepts a trailing newline, so the route only keeps out other path
        // shapes. permission()'s slug recheck is the real guard (next test).
        $core = '@^' . $route['route'] . '$@i';
        self::assertSame(1, preg_match($core, '/options/' . self::CADCO));
        foreach (['../x', 'a/b', '', '-x'] as $bad) {
            self::assertSame(0, preg_match($core, '/options/' . $bad), $bad);
        }
    }

    public function testSlugRecheckRejectsWhatCoreRoutingLetsThrough(): void
    {
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());
        $GLOBALS['pb_test_current_user'] = 1;

        foreach (['CADCO-DISPLAY-SETTINGS', 'A', 'x' . "\n", self::CADCO . "\n"] as $slug) {
            $result = $routes->permission(new \WP_REST_Request(['slug' => $slug]));
            self::assertInstanceOf(\WP_Error::class, $result, json_encode($slug));
            self::assertSame('proto_blocks_options_page_not_found', $result->get_error_code());
            self::assertSame(404, $result->get_error_data()['status']);
            self::assertInstanceOf(\WP_Error::class, $routes->get(new \WP_REST_Request(['slug' => $slug])));
            self::assertInstanceOf(\WP_Error::class, $routes->post(new \WP_REST_Request(['slug' => $slug])));
        }
    }

    public function testReadOnlyListsCodeFieldsForUntrustedWriters(): void
    {
        $this->extra = [self::standalone('group_extra', 'theme-settings', [[
            'key' => 'field_extra_tracking',
            'name' => 'tracking',
            'label' => 'Tracking',
            'type' => 'group',
            'sub_fields' => [
                ['key' => 'field_extra_tracking_id', 'name' => 'id', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'field_extra_tracking_snippet', 'name' => 'snippet', 'label' => 'Snippet', 'type' => 'code'],
            ],
        ]])];
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());

        $GLOBALS['pb_test_current_user'] = 3; // manage_options, no unfiltered_html
        $data = $routes->get(new \WP_REST_Request(['slug' => 'theme-settings']))->get_data();
        self::assertEquals((object) ['head_scripts' => 'unfiltered_html', 'tracking' => 'unfiltered_html'], $data['readOnly']);

        $GLOBALS['pb_test_caps'][3][] = 'unfiltered_html';
        $data = $routes->get(new \WP_REST_Request(['slug' => 'theme-settings']))->get_data();
        self::assertSame('{}', json_encode($data['readOnly']));
        self::assertSame([], $this->values()->readOnly($this->page('theme-settings'), 3));
    }

    public function testReadSkipsProtectedNames(): void
    {
        $GLOBALS['pb_test_options']['theme-settings_phone'] = '555';
        $GLOBALS['pb_test_options']['theme-settings_head_scripts'] = '<script>ok()</script>';
        $values = $this->values();
        $page = $this->page('theme-settings');
        $GLOBALS['pb_test_filters']['proto_blocks_protected_options'][] = static fn (array $list): array => [...$list, 'theme-settings_phone'];

        $read = $values->read($page);

        self::assertArrayNotHasKey('phone', $read);
        self::assertSame('<script>ok()</script>', $read['head_scripts']);
    }

    public function testGetReturnsValuesAndTrust(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_heading' => 'Stored'];
        $GLOBALS['pb_test_options']['siteurl'] = 'http://secret.test';
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());

        $GLOBALS['pb_test_current_user'] = 1;
        $data = $routes->get(new \WP_REST_Request(['slug' => self::CADCO]))->get_data();
        self::assertSame('Stored', $data['values']['children_heading']);
        self::assertFalse($data['trusted']);
        self::assertSame(['hide_children', 'children_placement', 'children_heading'], array_keys($data['values']));
        self::assertStringNotContainsString('secret', (string) json_encode($data));

        $GLOBALS['pb_test_current_user'] = 2;
        self::assertTrue($routes->get(new \WP_REST_Request(['slug' => self::CADCO]))->get_data()['trusted']);
    }

    public function testPostSavesAsCurrentUser(): void
    {
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());
        $GLOBALS['pb_test_current_user'] = 1;

        $response = $routes->post(new \WP_REST_Request([
            'slug' => self::CADCO,
            'fields' => [self::G => ['children_placement' => 'section', 'children_heading' => '<b>Hi</b>']],
            'present' => [self::G => ['children_placement', 'children_heading']],
        ]));

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame('Hi', $response->get_data()['values']['children_heading']);
        self::assertSame([], $response->get_data()['notices']);
        self::assertSame('Hi', self::stored()['children_heading']);

        $error = $routes->post(new \WP_REST_Request([
            'slug' => self::CADCO,
            'fields' => [self::G => ['children_placement' => 'sidebar']],
            'present' => [self::G => ['children_placement']],
        ]));
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame(422, $error->get_error_data()['status']);
    }

    public function testResponsesMaskCodeValuesForUntrustedUsers(): void
    {
        $this->extra = [self::standalone('group_extra', 'theme-settings', [[
            'key' => 'field_extra_tracking',
            'name' => 'tracking',
            'label' => 'Tracking',
            'type' => 'group',
            'sub_fields' => [
                ['key' => 'field_extra_tracking_id', 'name' => 'id', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'field_extra_tracking_snippet', 'name' => 'snippet', 'label' => 'Snippet', 'type' => 'code'],
            ],
        ]])];
        $GLOBALS['pb_test_options']['theme-settings_head_scripts'] = '<script>raw-head-marker()</script>';
        $GLOBALS['pb_test_options']['theme-settings_tracking'] = ['id' => 'G-1', 'snippet' => '<script>raw-group-marker()</script>'];
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());
        $post = static fn (): \WP_REST_Response => $routes->post(new \WP_REST_Request([
            'slug' => 'theme-settings',
            'fields' => ['group_theme_settings_1' => ['phone' => '555']],
            'present' => ['group_theme_settings_1' => ['phone']],
        ]));

        // Untrusted: manage_options without unfiltered_html.
        $GLOBALS['pb_test_current_user'] = 3;
        $get = $routes->get(new \WP_REST_Request(['slug' => 'theme-settings']))->get_data();
        $saved = $post();
        self::assertInstanceOf(\WP_REST_Response::class, $saved);
        foreach (['GET' => $get, 'POST' => $saved->get_data()] as $method => $data) {
            self::assertArrayHasKey('head_scripts', $data['values'], $method);
            self::assertNull($data['values']['head_scripts'], $method);
            self::assertNull($data['values']['tracking'], $method);
            $encoded = (string) json_encode($data);
            self::assertStringNotContainsString('raw-head-marker', $encoded, $method);
            self::assertStringNotContainsString('raw-group-marker', $encoded, $method);
        }
        self::assertSame('555', $saved->get_data()['values']['phone']);
        self::assertEquals((object) ['head_scripts' => 'unfiltered_html', 'tracking' => 'unfiltered_html'], $get['readOnly']);
        // Masking is a response concern: the stored values are untouched.
        self::assertSame('<script>raw-head-marker()</script>', $GLOBALS['pb_test_options']['theme-settings_head_scripts']);

        // Trusted: the values are present.
        $GLOBALS['pb_test_caps'][3][] = 'unfiltered_html';
        $get = $routes->get(new \WP_REST_Request(['slug' => 'theme-settings']))->get_data();
        $saved = $post()->get_data();
        foreach (['GET' => $get, 'POST' => $saved] as $method => $data) {
            self::assertSame('<script>raw-head-marker()</script>', $data['values']['head_scripts'], $method);
            self::assertSame('<script>raw-group-marker()</script>', $data['values']['tracking']['snippet'], $method);
        }
    }

    public function testPostIgnoresMalformedBody(): void
    {
        $routes = new OptionsValuesRoutes($this->registry(), $this->values());
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_placement' => 'related'];

        foreach ([
            ['fields' => 'x', 'present' => 'y'],
            ['fields' => [self::G => 'x'], 'present' => [self::G => 'children_placement']],
            ['fields' => [self::G => ['children_placement' => 'section']], 'present' => [self::G => [['children_placement']]]],
        ] as $body) {
            $response = $routes->post(new \WP_REST_Request(['slug' => self::CADCO] + $body));
            self::assertInstanceOf(\WP_REST_Response::class, $response);
        }
        self::assertSame(['children_placement' => 'related'], $GLOBALS['pb_test_options'][self::OPTION]);
    }

    private static function groupKeyOf(OptionsValues $values, PageDefinition $page, string $name): string
    {
        foreach ($values->groupsFor($page) as $group) {
            if ($group->field($name) !== null) {
                return $group->key;
            }
        }
        self::fail('no group for ' . $name);
    }
}
