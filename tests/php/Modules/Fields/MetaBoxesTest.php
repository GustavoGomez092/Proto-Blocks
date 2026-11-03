<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Edit\BoxPayload;
use ProtoBlocks\Modules\Fields\Edit\ChoiceResolver;
use ProtoBlocks\Modules\Fields\Edit\ErrorStore;
use ProtoBlocks\Modules\Fields\Edit\MetaBoxes;
use ProtoBlocks\Modules\Fields\Edit\SaveHandler;
use ProtoBlocks\Modules\Fields\Edit\TermForms;
use ProtoBlocks\Modules\Fields\Edit\UserForms;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\PostMetaStore;
use ProtoBlocks\Modules\Fields\Storage\TermMetaStore;
use ProtoBlocks\Modules\Fields\Storage\UserMetaStore;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';
require_once __DIR__ . '/../../ContentModel/stubs/hooks.php';

/**
 * Each test runs in its own process: register() and render() take a WP_Post,
 * whose stand-in must never leak into the shared process, and one test loads
 * the ACF stand-in (ACF then counts as active for the rest of the process).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MetaBoxesTest extends FieldsTestCase
{
    private const AUTHOR = 2;
    private const EVENT_GROUP = 'group_6543a1b2c3d4e';

    /** @var list<FieldGroup> */
    private array $groups = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../API/stubs/PreviewPost.php';
        foreach (['pb_test_filters', 'pb_test_hooks', 'pb_test_meta', 'pb_test_meta_reg', 'pb_test_meta_boxes', 'pb_test_transients', 'pb_test_actions'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_caps'] = [self::AUTHOR => ['edit_posts', 'edit_post']];
        $GLOBALS['pb_test_posts'] = [10 => ['ID' => 10, 'post_type' => 'event', 'post_status' => 'draft', 'post_author' => self::AUTHOR]];
        $GLOBALS['pb_test_current_user'] = self::AUTHOR;
        $GLOBALS['pb_test_block_editor'] = true;
        $raw = json_decode((string) file_get_contents(__DIR__ . '/../../../fixtures/fields/acf-export-realistic.json'), true);
        $this->groups = GroupNormalizer::normalizeMany($raw, 'file:acf-export')['groups'];
    }

    /** @param array<string, mixed> $raw */
    private function addGroup(array $raw): void
    {
        $out = GroupNormalizer::normalize($raw, 'php');
        $this->assertTrue($out['report']->ok(), (string) json_encode($out['report']->errors));
        $this->groups[] = $out['group'];
    }

    private function boxes(): MetaBoxes
    {
        [$index, $payload] = $this->services();

        return new MetaBoxes($index, $payload);
    }

    private function termForms(): TermForms
    {
        [$index, $payload] = $this->services();

        return new TermForms($index, $payload);
    }

    private function userForms(): UserForms
    {
        [$index, $payload] = $this->services();

        return new UserForms($index, $payload, TypeRegistry::withCoreTypes(), new ChoiceResolver(static fn(): array => []));
    }

    /** @return array{0: GroupIndex, 1: BoxPayload} */
    private function services(): array
    {
        $index = new GroupIndex($this->groups);
        $types = TypeRegistry::withCoreTypes();
        $stores = [
            ObjectRef::POST => new PostMetaStore(),
            ObjectRef::TERM => new TermMetaStore(),
            ObjectRef::USER => new UserMetaStore(),
            ObjectRef::OPTION => new OptionStore($index),
        ];
        $payload = new BoxPayload($types, new FieldReader($index, $types, $stores), new ErrorStore(), new ChoiceResolver(static fn(): array => []));

        return [$index, $payload];
    }

    /** @return list<array<string, mixed>> the payloads printed in $html */
    private static function payloads(string $html): array
    {
        preg_match_all('/<script type="application\/json">(.*?)<\/script>/sD', $html, $m);

        return array_map(static fn(string $json): array => json_decode($json, true), $m[1]);
    }

    private static function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    private function termGroup(): void
    {
        $this->addGroup(['key' => 'group_cat', 'title' => 'Category extras', 'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]], 'fields' => [
            ['key' => 'f_colour', 'name' => 'colour', 'type' => 'text', 'default_value' => 'none'],
        ]]);
        $GLOBALS['pb_test_taxonomies']['category'] = (object) ['name' => 'category', 'cap' => (object) ['edit_terms' => 'manage_categories']];
        $GLOBALS['pb_test_terms'][4] = ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Four', 'slug' => 'four'];
        $GLOBALS['pb_test_meta']['term'][4]['colour'] = ['red'];
    }

    private function userGroups(): void
    {
        $this->addGroup(['key' => 'group_profile', 'title' => 'Profile', 'location' => [[['param' => 'user_role', 'operator' => '==', 'value' => 'all']]], 'fields' => [
            ['key' => 'f_bio', 'name' => 'bio', 'type' => 'text'],
        ]]);
        $this->addGroup(['key' => 'group_signup', 'title' => 'Signup', 'location' => [[['param' => 'user_form', 'operator' => '==', 'value' => 'register']]], 'fields' => [
            ['key' => 'f_company', 'name' => 'company', 'type' => 'text', 'required' => 1],
            ['key' => 'f_size', 'name' => 'size', 'type' => 'number'],
        ]]);
        $GLOBALS['pb_test_users'][5] = ['ID' => 5, 'roles' => ['editor']];
        $GLOBALS['pb_test_meta']['user'][5]['bio'] = ['Five\'s bio'];
        $GLOBALS['pb_test_meta']['user'][self::AUTHOR]['bio'] = ['My bio'];
    }

    private static function post(): \WP_Post
    {
        return new \WP_Post(['ID' => 10, 'post_type' => 'event']);
    }

    /** @return array<string, array<string, mixed>> id => add_meta_box call */
    private static function registered(): array
    {
        return array_column($GLOBALS['pb_test_meta_boxes'] ?? [], null, 'id');
    }

    public function testAfterTitleGroupInTheBlockEditorIsNormalHigh(): void
    {
        $this->boxes()->register('event', self::post());

        $boxes = self::registered();
        $this->assertSame(['proto-fields-' . self::EVENT_GROUP], array_keys($boxes));
        $box = $boxes['proto-fields-' . self::EVENT_GROUP];
        $this->assertSame('normal', $box['context']);
        $this->assertSame('high', $box['priority']);
        $this->assertSame('event', $box['screen']);
        $this->assertSame('Event details', $box['title']);
        $this->assertArrayNotHasKey('edit_form_after_title', $GLOBALS['pb_test_hooks']);
    }

    public function testAfterTitleGroupInTheClassicEditorUsesItsOwnContext(): void
    {
        $GLOBALS['pb_test_block_editor'] = false;
        $boxes = $this->boxes();
        $boxes->register('event', self::post());

        $box = self::registered()['proto-fields-' . self::EVENT_GROUP];
        $this->assertSame(MetaBoxes::AFTER_TITLE, $box['context']);
        $this->assertSame('proto_after_title', $box['context']);
        $this->assertSame(10, has_action('edit_form_after_title', [$boxes, 'renderAfterTitle']));
    }

    public function testSideGroupUsesTheSideContext(): void
    {
        $this->addGroup(['key' => 'group_side', 'title' => 'Side', 'position' => 'side', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_s', 'name' => 'side_note', 'type' => 'text'],
        ]]);
        $this->boxes()->register('event', self::post());

        $this->assertSame('side', self::registered()['proto-fields-group_side']['context']);
    }

    public function testUnsupportedLocationRegistersNothing(): void
    {
        $this->groups = [];
        $this->addGroup(['key' => 'group_odd', 'title' => 'Odd', 'location' => [[['param' => 'post_format', 'operator' => '==', 'value' => 'aside']]], 'fields' => [
            ['key' => 'f_o', 'name' => 'odd', 'type' => 'text'],
        ]]);
        $this->boxes()->register('event', self::post());

        $this->assertSame([], self::registered());
    }

    public function testGroupAcfOwnsRegistersNothing(): void
    {
        require_once __DIR__ . '/stubs/Acf.php';
        $GLOBALS['pb_test_acf_groups'][self::EVENT_GROUP] = ['key' => self::EVENT_GROUP, 'title' => 'Event details'];
        $this->boxes()->register('event', self::post());

        $this->assertSame([], self::registered());
    }

    public function testUserWhoCannotEditThePostGetsNoBox(): void
    {
        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts'];
        $this->boxes()->register('event', self::post());

        $this->assertSame([], self::registered());
    }

    public function testRenderPrintsOneRootThePayloadAndTheFormFields(): void
    {
        $GLOBALS['pb_test_caps'][self::AUTHOR][] = 'unfiltered_html'; // a trusted user gets the value back unchanged
        $GLOBALS['pb_test_meta']['post'][10]['subtitle'] = ['</script><script>alert(1)</script>'];
        $boxes = $this->boxes();
        $boxes->register('event', self::post());
        $box = self::registered()['proto-fields-' . self::EVENT_GROUP];

        ob_start();
        $boxes->render(self::post(), ['id' => $box['id'], 'args' => $box['callback_args']]);
        $html = (string) ob_get_clean();

        $this->assertSame(1, substr_count($html, 'data-proto-fields-box'));
        $this->assertSame(1, substr_count($html, 'name="' . SaveHandler::NONCE_FIELD . '"'));
        $this->assertSame(1, substr_count($html, '_proto_fields_nonce'));
        $this->assertStringContainsString('name="' . BoxPayload::objectField() . '" value="post:10"', $html);
        $this->assertStringContainsString('\\u003C/script\\u003E', $html);
        $this->assertSame(1, preg_match('/<script type="application\/json">(.*?)<\/script>/sD', $html, $m));
        $this->assertStringNotContainsString('</script>', $m[1]);
        $this->assertSame(1, substr_count($html, '</script>'), 'no literal </script> before the payload closes');
        $payload = json_decode($m[1], true);
        $this->assertSame('</script><script>alert(1)</script>', $payload['values']['subtitle']);
        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringNotContainsString('<input type="text"', $html, 'no inputs without JavaScript');
    }

    public function testRenderOfAnUnknownGroupPrintsNothing(): void
    {
        ob_start();
        $this->boxes()->render(self::post(), ['id' => 'proto-fields-nope', 'args' => ['group' => 'nope']]);
        $this->assertSame('', ob_get_clean());
    }

    public function testTermEditFormNeedsEditTermAndNamesItsTerm(): void
    {
        $this->termGroup();
        $term = (object) ['term_id' => 4, 'taxonomy' => 'category'];

        $this->assertSame('', self::capture(fn() => $this->termForms()->renderEdit($term, 'category')), 'no edit_term, no row');

        $GLOBALS['pb_test_caps'][self::AUTHOR][] = 'edit_term';
        $html = self::capture(fn() => $this->termForms()->renderEdit($term, 'category'));
        $this->assertStringContainsString('<tr class="form-field', $html);
        $this->assertStringContainsString('name="' . SaveHandler::OBJECT_FIELD . '" value="term:4:category"', $html);
        $this->assertSame(1, substr_count($html, SaveHandler::NONCE_FIELD));
        $this->assertSame('red', self::payloads($html)[0]['values']['colour']);
        $this->assertSame('', self::capture(fn() => $this->termForms()->renderEdit($term, 'post_tag')), 'taxonomy must match the term');
    }

    public function testTermAddFormStartsFromDefaults(): void
    {
        $this->termGroup();
        $this->assertSame('', self::capture(fn() => $this->termForms()->renderAdd('category')), 'needs the edit_terms cap');

        $GLOBALS['pb_test_caps'][self::AUTHOR][] = 'manage_categories';
        $html = self::capture(fn() => $this->termForms()->renderAdd('category'));
        $this->assertStringContainsString('value="term:new:category"', $html);
        $payload = self::payloads($html)[0];
        $this->assertSame(['colour' => 'none'], $payload['values']);
        $this->assertSame('term:new', $payload['object']);
    }

    public function testTermFormsHookOnlyTargetedTaxonomies(): void
    {
        $this->termGroup();
        $GLOBALS['pb_test_taxonomies']['post_tag'] = (object) ['name' => 'post_tag'];

        $_GET = ['taxonomy' => 'post_tag'];
        $forms = $this->termForms();
        $forms->register();
        $this->assertSame([], $GLOBALS['pb_test_hooks'], 'no group targets post_tag');

        $_GET = ['taxonomy' => 'nope'];
        $forms->register();
        $this->assertSame([], $GLOBALS['pb_test_hooks'], 'unknown taxonomy');

        $_GET = ['taxonomy' => 'category'];
        $forms->register();
        $forms->register();
        $this->assertSame(10, has_action('category_add_form_fields', [$forms, 'renderAdd']));
        $this->assertCount(1, $GLOBALS['pb_test_hooks']['category_add_form_fields']);
        $this->assertSame(2, $GLOBALS['pb_test_hooks']['category_edit_form_fields'][0]['args']);
        $this->assertFalse(has_action('post_tag_add_form_fields'));
        $_GET = [];
    }

    public function testProfileFormShowsOnlyThatUsersValues(): void
    {
        $this->userGroups();
        $user = (object) ['ID' => 5, 'roles' => ['editor']];
        $this->assertSame('', self::capture(fn() => $this->userForms()->renderProfile($user)), 'needs edit_user');

        $GLOBALS['pb_test_caps'][self::AUTHOR][] = 'edit_user';
        $html = self::capture(fn() => $this->userForms()->renderProfile($user));
        $this->assertStringContainsString('value="user:5"', $html);
        $payloads = self::payloads($html);
        $this->assertSame(['group_profile'], array_column($payloads, 'groupKey'), 'the register-only group is not on the profile');
        $this->assertSame("Five's bio", $payloads[0]['values']['bio']);
    }

    public function testAddUserFormNeverPrintsStoredValues(): void
    {
        $this->userGroups();
        $this->assertSame('', self::capture(fn() => $this->userForms()->renderNew('add-new-user')), 'needs create_users');

        $GLOBALS['pb_test_caps'][self::AUTHOR][] = 'create_users';
        $html = self::capture(fn() => $this->userForms()->renderNew('add-new-user'));
        $this->assertStringContainsString('value="user:new"', $html);
        $this->assertStringNotContainsString('My bio', $html);
        $this->assertStringNotContainsString('Five', $html);
        $this->assertSame('', self::capture(fn() => $this->userForms()->renderNew('add-existing-user')));
    }

    public function testRegistrationFormShowsOnlyRegisterGroupsAndItsOwnErrors(): void
    {
        $this->userGroups();
        $GLOBALS['pb_test_current_user'] = 0;
        $forms = $this->userForms();

        $html = self::capture(fn() => $forms->renderRegister());
        $payloads = self::payloads($html);
        $this->assertSame(['group_signup'], array_column($payloads, 'groupKey'));
        $this->assertSame('', $payloads[0]['restNonce']);
        $this->assertStringContainsString('value="user:new"', $html);

        $_POST = [
            SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION),
            SaveHandler::OBJECT_FIELD => 'user:new',
            SaveHandler::INPUT => ['group_signup' => ['company' => '', 'size' => '<b>12</b>']],
            SaveHandler::PRESENT => ['group_signup' => ['company', 'size']],
        ];
        $errors = $forms->validateRegistration(new \WP_Error(), 'login', 'a@example.test');
        $this->assertSame(['proto_fields_invalid'], $errors->get_error_codes());

        $again = self::payloads(self::capture(fn() => $forms->renderRegister()))[0];
        $this->assertArrayHasKey('company', $again['errors']);
        $this->assertArrayHasKey('size', $again['errors']);
        $this->assertSame('<b>12</b>', $again['values']['size'], 'the visitor\'s own value, as JSON data');
        $this->assertSame([], $GLOBALS['pb_test_transients'], 'nothing persisted for an anonymous visitor');

        $_POST[SaveHandler::NONCE_FIELD] = 'bad';
        $this->assertSame([], $forms->validateRegistration(new \WP_Error(), 'login', 'a@example.test')->get_error_codes(), 'no nonce, no blocking');
        $_POST = [];
    }

    public function testRegistrationFormIsNotShownToALoggedInUser(): void
    {
        $this->userGroups();
        $this->assertSame('', self::capture(fn() => $this->userForms()->renderRegister()));
    }

    public function testBoxTitleIsEscaped(): void
    {
        $this->addGroup(['key' => 'group_xss', 'title' => '<img src=x onerror=alert(1)>', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_x', 'name' => 'x', 'type' => 'text'],
        ]]);
        $this->boxes()->register('event', self::post());

        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', self::registered()['proto-fields-group_xss']['title']);
    }
}
