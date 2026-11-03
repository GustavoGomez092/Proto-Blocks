<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Edit\ChoiceResolver;
use ProtoBlocks\Modules\Fields\Edit\ErrorStore;
use ProtoBlocks\Modules\Fields\Edit\SaveHandler;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Registry\MetaRegistrar;
use ProtoBlocks\Modules\Fields\Registry\TrustCallbacks;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\PostMetaStore;
use ProtoBlocks\Modules\Fields\Storage\TermMetaStore;
use ProtoBlocks\Modules\Fields\Storage\UserMetaStore;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';
require_once __DIR__ . '/stubs/Edit.php';
require_once __DIR__ . '/../../ContentModel/stubs/hooks.php';

/**
 * Each test runs in its own process: savePost() takes a WP_Post, and the
 * WP_Post stand-in must never leak into the shared process (Core\Plugin
 * branches on instanceof WP_Post).
 *
 * Writes go through the real stores, MetaRegistrar and TrustCallbacks and
 * the core-ordered meta stubs, so assertions are on what is STORED.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SaveHandlerTest extends FieldsTestCase
{
    private const ADMIN = 1;
    private const AUTHOR = 2;
    private const EVENT_GROUP = 'group_6543a1b2c3d4e';

    /** @var list<FieldGroup> */
    private array $groups = [];

    /** @var array<string, int> meta key => update_post_meta calls */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../API/stubs/PreviewPost.php';
        foreach (['pb_test_filters', 'pb_test_hooks', 'pb_test_meta', 'pb_test_meta_reg', 'pb_test_terms', 'pb_test_users', 'pb_test_transients', 'pb_test_set_terms', 'pb_test_object_terms', 'pb_test_actions', 'pb_test_taxonomies'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_caps'] = [
            self::ADMIN => ['edit_posts', 'edit_post', 'unfiltered_html', 'edit_term', 'edit_user', 'assign_categories'],
            self::AUTHOR => ['edit_posts', 'edit_post', 'edit_term', 'edit_user', 'assign_categories'],
        ];
        $GLOBALS['pb_test_posts'] = [
            10 => ['ID' => 10, 'post_type' => 'event', 'post_status' => 'draft', 'post_author' => self::AUTHOR],
        ];
        $GLOBALS['pb_test_terms'] = [
            4 => ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Four', 'slug' => 'four'],
            5 => ['term_id' => 5, 'taxonomy' => 'category', 'name' => 'Five', 'slug' => 'five'],
        ];
        $GLOBALS['pb_test_taxonomies']['category'] = (object) [
            'name' => 'category',
            'object_type' => ['post', 'event'],
            'cap' => (object) ['assign_terms' => 'assign_categories'],
        ];
        $GLOBALS['pb_test_users'] = [
            self::AUTHOR => ['ID' => self::AUTHOR, 'roles' => ['author']],
            7 => ['ID' => 7, 'roles' => ['subscriber']],
        ];
        $GLOBALS['pb_test_current_user'] = self::AUTHOR;
        $GLOBALS['pb_test_filters']['update_post_metadata'][] = function ($check, $id = 0, $key = '') {
            $this->writes[$key] = ($this->writes[$key] ?? 0) + 1;
            return $check;
        };

        $raw = json_decode((string) file_get_contents(__DIR__ . '/../../../fixtures/fields/acf-export-realistic.json'), true);
        $this->groups = GroupNormalizer::normalizeMany($raw, 'file:acf-export')['groups'];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $GLOBALS['pb_test_current_user'] = 0;
        parent::tearDown();
    }

    /** @param array<string, mixed> $raw */
    private function addGroup(array $raw): void
    {
        $out = GroupNormalizer::normalize($raw, 'php');
        $this->assertTrue($out['report']->ok(), json_encode($out['report']->errors));
        $this->groups[] = $out['group'];
    }

    /** @param bool $registered false: no meta registration, no metadata guards (the handler alone decides) */
    private function handler(?ChoiceResolver $choices = null, bool $registered = true): SaveHandler
    {
        $index = new GroupIndex($this->groups);
        $types = TypeRegistry::withCoreTypes();
        $trust = new TrustCallbacks($types);
        if ($registered) {
            (new MetaRegistrar($index, $types, $trust))->register();
        }
        $stores = [
            ObjectRef::POST => new PostMetaStore(),
            ObjectRef::TERM => new TermMetaStore(),
            ObjectRef::USER => new UserMetaStore(),
            ObjectRef::OPTION => new OptionStore($index),
        ];

        return new SaveHandler($index, $types, $stores, new ErrorStore(), $choices ?? new ChoiceResolver(static fn(): array => []), $registered ? $trust : null);
    }

    /**
     * @param array<string, mixed> $values  name => submitted value (all present)
     * @param list<string>|null    $present defaults to every key of $values
     */
    private function post(array $values, ?array $present = null, string $group = self::EVENT_GROUP, int $postId = 10): void
    {
        $_POST[SaveHandler::NONCE_FIELD] = wp_create_nonce(SaveHandler::NONCE_ACTION);
        $_POST[SaveHandler::OBJECT_FIELD] = 'post:' . $postId;
        $_POST['post_ID'] = (string) $postId;
        $_POST[SaveHandler::INPUT][$group] = $values;
        $_POST[SaveHandler::PRESENT][$group] = $present ?? array_keys($values);
    }

    private function savePost(?SaveHandler $handler = null, int $postId = 10): void
    {
        ($handler ?? $this->handler())->savePost($postId, new \WP_Post(['ID' => $postId, 'post_type' => 'event']));
    }

    private static function stored(string $key, int $id = 10, string $type = 'post'): mixed
    {
        return $GLOBALS['pb_test_meta'][$type][$id][$key][0] ?? null;
    }

    private function errors(int $user = self::AUTHOR, ?ObjectRef $ref = null): ?array
    {
        return (new ErrorStore())->peek($ref ?? ObjectRef::post(10), $user);
    }

    public function testValidSubmissionWritesEachFieldOnce(): void
    {
        $this->post(['subtitle' => 'Hello', 'start_date' => '2026-11-02', 'is_online' => '1', 'stream_url' => 'https://live.test/x', 'audience' => ['students']]);
        $this->savePost();

        $this->assertSame(['subtitle' => 1, 'start_date' => 1, 'is_online' => 1, 'stream_url' => 1, 'audience' => 1], $this->writes);
        $this->assertSame('Hello', self::stored('subtitle'));
        $this->assertSame('2026-11-02', self::stored('start_date'));
        $this->assertTrue(self::stored('is_online'));
        $this->assertSame('https://live.test/x', self::stored('stream_url'));
        $this->assertSame(['students'], self::stored('audience'));
        $this->assertNull($this->errors());
    }

    public function testInputIsUnslashedOnce(): void
    {
        $this->post(['subtitle' => wp_slash('It\'s a "C:\\path"')]);
        $this->savePost();

        $this->assertSame('It\'s a "C:\\path"', html_entity_decode((string) self::stored('subtitle'), ENT_QUOTES));
    }

    public function testInvalidFieldNotWrittenPostStillSaved(): void
    {
        $this->post(['subtitle' => 'Hello', 'start_date' => '']);
        $this->savePost();

        $this->assertArrayNotHasKey('start_date', $this->writes);
        $this->assertSame('Hello', self::stored('subtitle'));
        $entry = $this->errors();
        $this->assertSame(['start_date' => 'This field is required.'], $entry['errors']);
        $this->assertSame(['start_date' => ''], $entry['submitted']);
    }

    public function testNestedErrorsCarryTheFieldPath(): void
    {
        $this->post(['agenda' => [['time' => '', 'title' => 'Opening', 'notes' => ''], ['time' => '', 'title' => '', 'notes' => '']]]);
        $this->savePost();

        $this->assertArrayNotHasKey('agenda', $this->writes);
        $this->assertSame(['agenda.1.title' => 'This field is required.'], $this->errors()['errors']);
    }

    public function testRowsWithGapsAreReindexed(): void
    {
        $this->post(['agenda' => [2 => ['title' => 'B'], 0 => ['title' => 'A']]]);
        $this->savePost();

        $rows = self::stored('agenda');
        $this->assertSame(['A', 'B'], array_column($rows, 'title'));
    }

    public function testHiddenFieldSkipped(): void
    {
        update_post_meta(10, 'stream_url', 'https://old.test');
        $this->writes = [];
        $this->post(['is_online' => '0', 'stream_url' => 'not a url']);
        $this->savePost();

        $this->assertNull($this->errors());
        $this->assertArrayNotHasKey('stream_url', $this->writes);
        $this->assertSame('https://old.test', self::stored('stream_url'));
        $this->assertFalse(self::stored('is_online'));
    }

    public function testConditionalBypassIgnored(): void
    {
        update_post_meta(10, 'stream_url', 'https://old.test');
        $this->writes = [];
        $this->post(['is_online' => '0', 'stream_url' => 'https://evil.test']);
        $this->savePost();

        $this->assertArrayNotHasKey('stream_url', $this->writes);
        $this->assertSame('https://old.test', self::stored('stream_url'));
    }

    public function testVisibilityUsesStoredSiblingWhenNotSubmitted(): void
    {
        update_post_meta(10, 'is_online', true);
        $this->post(['stream_url' => 'not a url']);
        $this->savePost();
        $this->assertSame(['stream_url' => 'Enter a valid URL.'], $this->errors()['errors']);

        (new ErrorStore())->take(ObjectRef::post(10), self::AUTHOR);
        update_post_meta(10, 'is_online', false);
        $this->writes = [];
        $this->post(['stream_url' => 'not a url']);
        $this->savePost();
        $this->assertNull($this->errors());
        $this->assertArrayNotHasKey('stream_url', $this->writes);
    }

    public function testFieldsNotPresentUntouched(): void
    {
        update_post_meta(10, 'summary', 'Kept');
        $this->writes = [];
        $this->post(['subtitle' => 'New', 'summary' => 'Overwritten?'], ['subtitle']);
        $this->savePost();

        $this->assertSame('Kept', self::stored('summary'));
        $this->assertSame(['subtitle' => 1], $this->writes);
    }

    public function testGroupWhoseLocationDoesNotMatchIsIgnored(): void
    {
        $this->addGroup(['key' => 'group_page', 'title' => 'Page', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]], 'fields' => [['key' => 'f_hero_note', 'name' => 'hero_note', 'type' => 'text']]]);
        $this->post(['hero_note' => 'sneaky'], null, 'group_page');
        $this->savePost();

        $this->assertSame([], $this->writes);
        $this->assertNull(self::stored('hero_note'));
    }

    public function testUnknownPresentNamesAreIgnored(): void
    {
        $this->post(['_edit_lock' => '1', 'session_tokens' => 'x', 'subtitle' => 'ok']);
        $this->savePost();

        $this->assertSame(['subtitle' => 1], $this->writes);
    }

    public function testOptionsSourceChoiceEnforced(): void
    {
        $this->addGroup(['key' => 'group_venue', 'title' => 'Venue', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_venue', 'name' => 'venue', 'type' => 'select', 'optionsSource' => 'wp:posts', 'sourceArgs' => ['post_type' => 'venue']],
        ]]);
        $calls = [];
        $choices = new ChoiceResolver(static function (string $source, array $args) use (&$calls): array {
            $calls[] = [$source, $args];
            return [['key' => '3', 'label' => 'Hall'], ['key' => '4', 'label' => 'Park']];
        });

        $this->post(['venue' => '4'], null, 'group_venue');
        $this->savePost($this->handler($choices));
        $this->assertSame('4', self::stored('venue'));
        $this->assertSame([['wp:posts', ['post_type' => 'venue']]], $calls);

        $this->post(['venue' => '99'], null, 'group_venue');
        $this->savePost($this->handler($choices));
        $this->assertSame('4', self::stored('venue'));
        $this->assertSame(['venue' => 'Choose one of the listed options.'], $this->errors()['errors']);
    }

    public function testRegistrationFormSavesSanitised(): void
    {
        $this->addGroup(['key' => 'group_signup', 'title' => 'Signup', 'location' => [[['param' => 'user_form', 'operator' => '==', 'value' => 'register']]], 'fields' => [
            ['key' => 'f_tagline', 'name' => 'tagline', 'type' => 'text'],
        ]]);
        $this->addGroup(['key' => 'group_profile', 'title' => 'Profile', 'location' => [[['param' => 'user_role', 'operator' => '==', 'value' => 'all']]], 'fields' => [
            ['key' => 'f_internal', 'name' => 'internal_note', 'type' => 'text'],
        ]]);
        $GLOBALS['pb_test_current_user'] = 0;
        do_action('user_register', 7);
        $_POST[SaveHandler::OBJECT_FIELD] = 'user:new';
        $_POST[SaveHandler::NONCE_FIELD] = wp_create_nonce(SaveHandler::NONCE_ACTION);
        $_POST[SaveHandler::INPUT] = [
            'group_signup' => ['tagline' => '<script>alert(1)</script>Hi'],
            'group_profile' => ['internal_note' => 'set by a visitor'],
        ];
        $_POST[SaveHandler::PRESENT] = ['group_signup' => ['tagline'], 'group_profile' => ['internal_note']];

        $this->handler()->saveUser(7);

        $tagline = self::stored('tagline', 7, 'user');
        $this->assertIsString($tagline);
        $this->assertStringNotContainsString('<script', $tagline);
        $this->assertStringContainsString('Hi', $tagline);
        $this->assertNull(self::stored('internal_note', 7, 'user'));
    }

    public function testRegistrationWithoutNonceSavesNothing(): void
    {
        $this->addGroup(['key' => 'group_signup', 'title' => 'Signup', 'location' => [[['param' => 'user_form', 'operator' => '==', 'value' => 'register']]], 'fields' => [
            ['key' => 'f_tagline', 'name' => 'tagline', 'type' => 'text'],
        ]]);
        $GLOBALS['pb_test_current_user'] = 0;
        do_action('user_register', 7);
        $_POST[SaveHandler::OBJECT_FIELD] = 'user:new';
        $_POST[SaveHandler::INPUT] = ['group_signup' => ['tagline' => 'Hi']];
        $_POST[SaveHandler::PRESENT] = ['group_signup' => ['tagline']];

        $this->handler()->saveUser(7);
        $this->assertNull(self::stored('tagline', 7, 'user'));
    }

    public function testLoggedOutOutsideRegistrationSavesNothing(): void
    {
        $this->addGroup(['key' => 'group_signup', 'title' => 'Signup', 'location' => [[['param' => 'user_form', 'operator' => '==', 'value' => 'all']]], 'fields' => [
            ['key' => 'f_tagline', 'name' => 'tagline', 'type' => 'text'],
        ]]);
        $GLOBALS['pb_test_current_user'] = 0;
        $_POST[SaveHandler::NONCE_FIELD] = wp_create_nonce(SaveHandler::NONCE_ACTION);
        $_POST[SaveHandler::OBJECT_FIELD] = 'user:new'; // but no user_register is running
        $_POST[SaveHandler::INPUT] = ['group_signup' => ['tagline' => 'Hi']];
        $_POST[SaveHandler::PRESENT] = ['group_signup' => ['tagline']];

        $this->handler()->saveUser(7);
        $this->assertNull(self::stored('tagline', 7, 'user'));
    }

    public function testUntrustedSaveLeavesCodeUntouched(): void
    {
        $this->addGroup(['key' => 'group_code', 'title' => 'Code', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_tracking', 'name' => 'tracking', 'type' => 'code'],
        ]]);
        $handler = $this->handler();
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        update_post_meta(10, 'tracking', '<script>ok()</script>');
        $GLOBALS['pb_test_current_user'] = self::AUTHOR;
        $this->writes = [];

        $this->post(['tracking' => '<script>evil()</script>'], null, 'group_code');
        $this->savePost($handler);

        $this->assertSame('<script>ok()</script>', self::stored('tracking'));
        $entry = $this->errors();
        $this->assertSame(['tracking' => 'Only users with unfiltered HTML may edit this field.'], $entry['errors']);
        $this->assertSame([], $entry['submitted'], 'an untrusted code value is never echoed back');
    }

    public function testUntrustedCodeIsRefusedByTheHandlerItself(): void
    {
        $this->addGroup(['key' => 'group_code', 'title' => 'Code', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_tracking', 'name' => 'tracking', 'type' => 'code'],
        ]]);
        update_post_meta(10, 'tracking', '<script>ok()</script>');
        $this->writes = [];

        $this->post(['tracking' => '<script>evil()</script>'], null, 'group_code');
        $this->savePost($this->handler(null, false));

        $this->assertSame([], $this->writes, 'no write is even attempted');
        $this->assertSame('<script>ok()</script>', self::stored('tracking'));
        $this->assertSame(['tracking' => 'Only users with unfiltered HTML may edit this field.'], $this->errors()['errors']);
    }

    public function testUntrustedSaveLeavesNestedCodeUntouched(): void
    {
        $this->addGroup(['key' => 'group_code', 'title' => 'Code', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_embeds', 'name' => 'embeds', 'type' => 'repeater', 'sub_fields' => [['key' => 'f_embed_html', 'name' => 'html', 'type' => 'code']]],
        ]]);
        $handler = $this->handler();
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        update_post_meta(10, 'embeds', [['html' => '<iframe src="https://ok.test"></iframe>']]);
        $GLOBALS['pb_test_current_user'] = self::AUTHOR;

        $this->post(['embeds' => [['html' => '<script>evil()</script>']]], null, 'group_code');
        $this->savePost($handler);

        $this->assertSame([['html' => '<iframe src="https://ok.test"></iframe>']], self::stored('embeds'));
        $this->assertSame(
            ['embeds' => 'This field contains a code sub-field that only users with unfiltered HTML may edit.'],
            $this->errors()['errors']
        );
    }

    public function testEditorOfAnotherPostCannotSaveThisOne(): void
    {
        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts', 'edit_post' => [11 => true]];
        $this->post(['subtitle' => 'x']);
        $this->savePost();
        $this->assertSame([], $this->writes, 'edit_post is checked against post 10, not any post');
        $this->assertNull(self::stored('subtitle'));

        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts', 'edit_post' => [10 => true]];
        $this->post(['subtitle' => 'x']);
        $this->savePost();
        $this->assertSame('x', self::stored('subtitle'), 'the per-object grant for post 10 saves');
    }

    public function testUntrustedPasswordIsRefusedAndNeverEchoed(): void
    {
        $this->addGroup(['key' => 'group_pw', 'title' => 'Keys', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_key', 'name' => 'stream_key', 'type' => 'password'],
            ['key' => 'f_creds', 'name' => 'creds', 'type' => 'group', 'sub_fields' => [['key' => 'f_creds_pw', 'name' => 'pw', 'type' => 'password']]],
        ]]);
        $handler = $this->handler();
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        update_post_meta(10, 'stream_key', 'sk-live-1');
        $GLOBALS['pb_test_current_user'] = self::AUTHOR;

        $this->post(['stream_key' => 'sk-evil', 'creds' => ['pw' => 'evil']], null, 'group_pw');
        $this->savePost($handler);

        $this->assertSame('sk-live-1', self::stored('stream_key'));
        $this->assertNull(self::stored('creds'));
        $entry = $this->errors();
        $this->assertSame([
            'stream_key' => 'Only users with unfiltered HTML may edit this field.',
            'creds' => 'This field contains a password sub-field that only users with unfiltered HTML may edit.',
        ], $entry['errors']);
        $this->assertSame([], $entry['submitted']);
    }

    public function testTrustedPasswordWithAnErrorIsNotParkedInTheErrorStore(): void
    {
        $this->addGroup(['key' => 'group_pw', 'title' => 'Keys', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_key', 'name' => 'stream_key', 'type' => 'password', 'maxlength' => 4],
            ['key' => 'f_creds', 'name' => 'creds', 'type' => 'group', 'sub_fields' => [
                ['key' => 'f_creds_pw', 'name' => 'pw', 'type' => 'password'],
                ['key' => 'f_creds_user', 'name' => 'user', 'type' => 'text', 'maxlength' => 2],
            ]],
            ['key' => 'f_note', 'name' => 'note', 'type' => 'text', 'maxlength' => 2],
        ]]);
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        $this->post(['stream_key' => 'sk-live-secret', 'creds' => ['pw' => 'hunter2', 'user' => 'too long'], 'note' => 'too long'], null, 'group_pw');
        $this->savePost();

        $entry = $this->errors(self::ADMIN);
        $this->assertArrayHasKey('stream_key', $entry['errors']);
        $this->assertArrayHasKey('creds.user', $entry['errors']);
        $this->assertSame(['note' => 'too long'], $entry['submitted'], 'only non-password values are kept');
        $this->assertStringNotContainsString('hunter2', (string) json_encode($GLOBALS['pb_test_transients']));
        $this->assertStringNotContainsString('sk-live-secret', (string) json_encode($GLOBALS['pb_test_transients']));
    }

    public function testTrustedSaveWritesCode(): void
    {
        $this->addGroup(['key' => 'group_code', 'title' => 'Code', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_tracking', 'name' => 'tracking', 'type' => 'code'],
        ]]);
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        $this->post(['tracking' => '<script>ok()</script>'], null, 'group_code');
        $this->savePost();

        $this->assertSame('<script>ok()</script>', self::stored('tracking'));
        $this->assertNull($this->errors(self::ADMIN));
    }

    public function testRepeaterOverCapReportsError(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_repeater_max_items'][] = static fn() => 3;
        $row = ['time' => '', 'title' => 'x', 'notes' => ''];
        $this->post(['agenda' => [$row, $row, $row, $row]]);
        $this->savePost();

        $this->assertArrayNotHasKey('agenda', $this->writes);
        $this->assertSame(['agenda' => 'Add no more than 3 rows.'], $this->errors()['errors']);
    }

    public function testTenThousandAndOneRowsAreRefusedForAnEditorToo(): void
    {
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        $this->addGroup(['key' => 'group_big', 'title' => 'Big', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [['key' => 'f_rows_t', 'name' => 't', 'type' => 'text']]],
        ]]);
        $this->post(['rows' => array_fill(0, 10001, ['t' => 'x'])], null, 'group_big');
        $this->savePost();

        $this->assertArrayNotHasKey('rows', $this->writes);
        $entry = $this->errors(self::ADMIN);
        $this->assertSame(['rows' => 'Add no more than 500 rows.'], $entry['errors']);
        $this->assertArrayNotHasKey('rows', $entry['submitted'], 'oversized submissions are not copied into the transient');
    }

    public function testNestedRowsShareOneBudget(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_repeater_max_items'][] = static fn() => 6;
        $this->addGroup(['key' => 'group_nested', 'title' => 'Nested', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_days', 'name' => 'days', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'f_slots', 'name' => 'slots', 'type' => 'repeater', 'sub_fields' => [['key' => 'f_slot_t', 'name' => 't', 'type' => 'text']]],
            ]],
        ]]);
        // 3 outer rows + 3 x 2 inner rows = 9 rows > 6, while each level alone is under the cap.
        $day = ['slots' => [['t' => 'a'], ['t' => 'b']]];
        $this->post(['days' => [$day, $day, $day]], null, 'group_nested');
        $this->savePost();

        $this->assertArrayNotHasKey('days', $this->writes);
        $this->assertSame(['days' => 'Add no more than 6 rows.'], $this->errors()['errors']);
    }

    public function testSaveTermsAssignsTerms(): void
    {
        $this->post(['topics' => ['4', '5']]);
        $this->savePost();

        $this->assertSame([4, 5], self::stored('topics'));
        $this->assertSame([['object_id' => 10, 'terms' => [4, 5], 'taxonomy' => 'category', 'append' => false]], $GLOBALS['pb_test_set_terms']);
    }

    public function testSaveTermsNeedsAssignCapAndKnownTerms(): void
    {
        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts', 'edit_post'];
        $this->post(['topics' => ['4']]);
        $this->savePost();
        $this->assertSame([], $GLOBALS['pb_test_set_terms'], 'no assign_terms capability');

        $GLOBALS['pb_test_caps'][self::AUTHOR][] = 'assign_categories';
        $this->post(['topics' => ['4', '99']]);
        $this->savePost();
        $this->assertSame([4], $GLOBALS['pb_test_set_terms'][0]['terms'], 'unknown term ids are never assigned');
    }

    public function testNonceAndCapsGuard(): void
    {
        $this->post(['subtitle' => 'x']);
        $_POST[SaveHandler::NONCE_FIELD] = 'forged';
        $this->savePost();
        $this->assertSame([], $this->writes, 'bad nonce');

        $this->post(['subtitle' => 'x']);
        unset($_POST[SaveHandler::NONCE_FIELD]);
        $this->savePost();
        $this->assertSame([], $this->writes, 'missing nonce');

        $this->post(['subtitle' => 'x']);
        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts'];
        $this->savePost();
        $this->assertSame([], $this->writes, 'no edit_post');
        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts', 'edit_post'];

        $this->post(['subtitle' => 'x']);
        $GLOBALS['pb_test_autosaves'][10] = true;
        $this->savePost();
        $this->assertSame([], $this->writes, 'autosave');
        unset($GLOBALS['pb_test_autosaves'][10]);

        $this->post(['subtitle' => 'x']);
        $GLOBALS['pb_test_revisions'][10] = 9;
        $this->savePost();
        $this->assertSame([], $this->writes, 'revision');
        unset($GLOBALS['pb_test_revisions'][10]);

        $this->post(['subtitle' => 'x']);
        define('DOING_AUTOSAVE', true);
        $this->savePost();
        $this->assertSame([], $this->writes, 'DOING_AUTOSAVE');
    }

    public function testPostFormWithoutPostIdSavesNothing(): void
    {
        $this->post(['subtitle' => 'x']);
        unset($_POST['post_ID']);
        $this->savePost();

        $this->assertSame([], $this->writes);
    }

    public function testSaveForAnotherPostInTheSameRequestIsIgnored(): void
    {
        $GLOBALS['pb_test_posts'][11] = ['ID' => 11, 'post_type' => 'event', 'post_status' => 'draft'];
        $this->post(['subtitle' => 'for post 10']);
        $this->savePost(null, 11);

        $this->assertNull(self::stored('subtitle', 11));
    }

    public function testTermAndUserPaths(): void
    {
        $this->addGroup(['key' => 'group_cat', 'title' => 'Category', 'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]], 'fields' => [
            ['key' => 'f_cat_note', 'name' => 'cat_note', 'type' => 'text'],
        ]]);
        $this->addGroup(['key' => 'group_profile', 'title' => 'Profile', 'location' => [[['param' => 'user_role', 'operator' => '==', 'value' => 'all']]], 'fields' => [
            ['key' => 'f_twitter', 'name' => 'twitter', 'type' => 'text'],
        ]]);
        $handler = $this->handler();

        $_POST = [SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION), 'tag_ID' => '5', 'taxonomy' => 'category', SaveHandler::OBJECT_FIELD => 'term:5:category'];
        $_POST[SaveHandler::INPUT] = ['group_cat' => ['cat_note' => 'Hello']];
        $_POST[SaveHandler::PRESENT] = ['group_cat' => ['cat_note']];
        $handler->saveTerm(5, 5, 'category');
        $this->assertSame('Hello', self::stored('cat_note', 5, 'term'));

        $_POST = [SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION), 'user_id' => '2', SaveHandler::OBJECT_FIELD => 'user:2'];
        $_POST[SaveHandler::INPUT] = ['group_profile' => ['twitter' => '@me']];
        $_POST[SaveHandler::PRESENT] = ['group_profile' => ['twitter']];
        $handler->saveUser(self::AUTHOR);
        $this->assertSame('@me', self::stored('twitter', self::AUTHOR, 'user'));
    }

    public function testTermAndUserGuards(): void
    {
        $this->addGroup(['key' => 'group_cat', 'title' => 'Category', 'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]], 'fields' => [
            ['key' => 'f_cat_note', 'name' => 'cat_note', 'type' => 'text'],
        ]]);
        $this->addGroup(['key' => 'group_profile', 'title' => 'Profile', 'location' => [[['param' => 'user_role', 'operator' => '==', 'value' => 'all']]], 'fields' => [
            ['key' => 'f_twitter', 'name' => 'twitter', 'type' => 'text'],
        ]]);
        $handler = $this->handler();
        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts'];

        $termForm = [SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION), SaveHandler::OBJECT_FIELD => 'term:5:category', 'tag_ID' => '5', 'taxonomy' => 'category'];
        $userForm = [SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION), SaveHandler::OBJECT_FIELD => 'user:7', 'user_id' => '7'];
        $values = [
            SaveHandler::INPUT => ['group_cat' => ['cat_note' => 'x'], 'group_profile' => ['twitter' => 'x']],
            SaveHandler::PRESENT => ['group_cat' => ['cat_note'], 'group_profile' => ['twitter']],
        ];
        $_POST = $termForm + $values;
        $handler->saveTerm(5, 5, 'category');
        $_POST = $userForm + $values;
        $handler->saveUser(7);
        $this->assertNull(self::stored('cat_note', 5, 'term'), 'no edit_term');
        $this->assertNull(self::stored('twitter', 7, 'user'), 'no edit_user');

        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_term', 'edit_user'];
        $_POST = [SaveHandler::NONCE_FIELD => 'forged'] + $termForm + $values;
        $handler->saveTerm(5, 5, 'category');
        $_POST = [SaveHandler::NONCE_FIELD => 'forged'] + $userForm + $values;
        $handler->saveUser(7);
        $this->assertNull(self::stored('cat_note', 5, 'term'), 'bad nonce');
        $this->assertNull(self::stored('twitter', 7, 'user'), 'bad nonce');
    }

    public function testSaveCoreReturnsWrittenAndErrors(): void
    {
        $handler = $this->handler();
        $result = $handler->save(
            ObjectRef::post(10),
            $this->groups,
            [self::EVENT_GROUP => ['subtitle' => 'A', 'start_date' => 'nope']],
            [self::EVENT_GROUP => ['subtitle', 'start_date']],
            ['kind' => 'post', 'post_type' => 'event', 'post_status' => 'draft', 'post_id' => 10]
        );

        $this->assertSame(['subtitle'], $result['written']);
        $this->assertSame(['start_date' => 'Enter a valid date.'], $result['errors']);
    }

    public function testMalformedInputShapesAreIgnored(): void
    {
        $_POST = [
            SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION),
            SaveHandler::OBJECT_FIELD => 'post:10',
            'post_ID' => '10',
            SaveHandler::INPUT => 'not an array',
            SaveHandler::PRESENT => [self::EVENT_GROUP => 'subtitle'],
        ];
        $this->savePost();
        $this->assertSame([], $this->writes);

        $_POST[SaveHandler::INPUT] = [self::EVENT_GROUP => 'x'];
        $_POST[SaveHandler::PRESENT] = [self::EVENT_GROUP => [['nested']]];
        $this->savePost();
        $this->assertSame([], $this->writes);
    }

    public function testPostFormWithoutOrWithAnotherMarkerSavesNothing(): void
    {
        $this->post(['subtitle' => 'x']);
        unset($_POST[SaveHandler::OBJECT_FIELD]);
        $this->savePost();
        $this->assertSame([], $this->writes, 'no marker');

        $this->post(['subtitle' => 'x']);
        $_POST[SaveHandler::OBJECT_FIELD] = 'post:11';
        $this->savePost();
        $this->assertSame([], $this->writes, 'marker names another post');
    }

    /** A group shown on events, on post_tag terms and on every user form. */
    private function addWideGroup(): void
    {
        $this->addGroup(['key' => 'group_wide', 'title' => 'Wide', 'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'event']],
            [['param' => 'taxonomy', 'operator' => '==', 'value' => 'post_tag']],
            [['param' => 'user_form', 'operator' => '==', 'value' => 'all']],
        ], 'fields' => [['key' => 'f_wide_note', 'name' => 'wide_note', 'type' => 'text']]]);
    }

    public function testCreatedTermDuringAPostSaveGetsNothing(): void
    {
        $this->addWideGroup();
        $GLOBALS['pb_test_terms'][30] = ['term_id' => 30, 'taxonomy' => 'post_tag', 'name' => 'New tag', 'slug' => 'new-tag'];
        $handler = $this->handler();
        $this->post(['wide_note' => 'for the post'], null, 'group_wide');
        $_POST['tax_input'] = ['post_tag' => 'New tag'];

        $handler->saveTerm(30, 30, 'post_tag'); // created_term while the post form is processed
        $this->assertNull(self::stored('wide_note', 30, 'term'));

        $_POST['taxonomy'] = 'post_tag'; // even a posted taxonomy does not make it a term form
        $handler->saveTerm(30, 30, 'post_tag');
        $this->assertNull(self::stored('wide_note', 30, 'term'));

        $this->savePost($handler);
        $this->assertSame('for the post', self::stored('wide_note'));
    }

    public function testUserRegisterDuringAPostSaveGetsNothing(): void
    {
        $this->addWideGroup();
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        $GLOBALS['pb_test_users'][8] = ['ID' => 8, 'roles' => ['subscriber']];
        $handler = $this->handler();
        $this->post(['wide_note' => 'for the post'], null, 'group_wide');
        $_POST['user_id'] = '8';

        do_action('user_register', 8); // a plugin creates a user during save_post
        $handler->saveUser(8);
        $this->assertNull(self::stored('wide_note', 8, 'user'));
    }

    public function testAddTermFormSavesTheNewTermOnly(): void
    {
        $this->addWideGroup();
        $GLOBALS['pb_test_terms'][30] = ['term_id' => 30, 'taxonomy' => 'post_tag', 'name' => 'A', 'slug' => 'a'];
        $GLOBALS['pb_test_terms'][31] = ['term_id' => 31, 'taxonomy' => 'post_tag', 'name' => 'B', 'slug' => 'b'];
        $handler = $this->handler();
        $_POST = [
            SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION),
            SaveHandler::OBJECT_FIELD => 'term:new:post_tag',
            'action' => 'add-tag',
            'taxonomy' => 'post_tag',
            SaveHandler::INPUT => ['group_wide' => ['wide_note' => 'tag note']],
            SaveHandler::PRESENT => ['group_wide' => ['wide_note']],
        ];

        $handler->saveTerm(30, 30, 'post_tag');
        $handler->saveTerm(31, 31, 'post_tag'); // a second term created in the same request
        $this->assertSame('tag note', self::stored('wide_note', 30, 'term'));
        $this->assertNull(self::stored('wide_note', 31, 'term'));
    }

    public function testTermFormMustPostItsTaxonomyAndId(): void
    {
        $this->addWideGroup();
        $GLOBALS['pb_test_terms'][30] = ['term_id' => 30, 'taxonomy' => 'post_tag', 'name' => 'A', 'slug' => 'a'];
        $handler = $this->handler();
        $base = [
            SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION),
            SaveHandler::OBJECT_FIELD => 'term:30:post_tag',
            SaveHandler::INPUT => ['group_wide' => ['wide_note' => 'x']],
            SaveHandler::PRESENT => ['group_wide' => ['wide_note']],
        ];

        foreach ([['tag_ID' => '30'], ['taxonomy' => 'post_tag'], ['tag_ID' => '31', 'taxonomy' => 'post_tag'], ['tag_ID' => '30', 'taxonomy' => 'category']] as $extra) {
            $_POST = $base + $extra;
            $handler->saveTerm(30, 30, 'post_tag');
            $this->assertNull(self::stored('wide_note', 30, 'term'), (string) json_encode($extra));
        }

        $_POST = $base + ['tag_ID' => '30', 'taxonomy' => 'post_tag'];
        $handler->saveTerm(30, 30, 'post_tag');
        $this->assertSame('x', self::stored('wide_note', 30, 'term'));
    }

    public function testAddUserFormSavesTheNewUser(): void
    {
        $this->addWideGroup();
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        $GLOBALS['pb_test_users'][8] = ['ID' => 8, 'roles' => ['subscriber']];
        $handler = $this->handler();
        $_POST = [
            SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION),
            SaveHandler::OBJECT_FIELD => 'user:new',
            'action' => 'createuser',
            SaveHandler::INPUT => ['group_wide' => ['wide_note' => 'new user note']],
            SaveHandler::PRESENT => ['group_wide' => ['wide_note']],
        ];

        $handler->saveUser(8);
        $this->assertNull(self::stored('wide_note', 8, 'user'), 'user:new outside user_register');

        do_action('user_register', 8);
        $handler->saveUser(8);
        $this->assertSame('new user note', self::stored('wide_note', 8, 'user'));
    }

    public function testProfileFormMustNameItsUser(): void
    {
        $this->addWideGroup();
        $handler = $this->handler();
        $base = [
            SaveHandler::NONCE_FIELD => wp_create_nonce(SaveHandler::NONCE_ACTION),
            SaveHandler::INPUT => ['group_wide' => ['wide_note' => 'x']],
            SaveHandler::PRESENT => ['group_wide' => ['wide_note']],
        ];
        foreach ([[SaveHandler::OBJECT_FIELD => 'user:2'], ['user_id' => '2'], [SaveHandler::OBJECT_FIELD => 'user:7', 'user_id' => '2'], [SaveHandler::OBJECT_FIELD => 'user:2', 'user_id' => '7']] as $extra) {
            $_POST = $base + $extra;
            $handler->saveUser(self::AUTHOR);
            $this->assertNull(self::stored('wide_note', self::AUTHOR, 'user'), (string) json_encode($extra));
        }

        $_POST = $base + [SaveHandler::OBJECT_FIELD => 'user:2', 'user_id' => '2'];
        $handler->saveUser(self::AUTHOR);
        $this->assertSame('x', self::stored('wide_note', self::AUTHOR, 'user'));
    }

    public function testZeroRowBudgetMeansNoRows(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_repeater_max_items'][] = static fn() => 0;
        $this->post(['agenda' => [['time' => '', 'title' => 'x', 'notes' => '']]]);
        $this->savePost();

        $this->assertArrayNotHasKey('agenda', $this->writes);
        $this->assertSame(['agenda' => 'Add no more than 0 rows.'], $this->errors()['errors']);
    }

    public function testErrorMapIsCapped(): void
    {
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        $this->addGroup(['key' => 'group_big', 'title' => 'Big', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [['key' => 'f_rows_t', 'name' => 't', 'type' => 'text', 'required' => 1]]],
        ]]);
        $this->post(['rows' => array_fill(0, 499, ['t' => ''])], null, 'group_big');
        $this->post(['agenda' => array_fill(0, 20, ['title' => ''])]);
        $result = $this->handler()->save(ObjectRef::post(10), $this->groups, $_POST[SaveHandler::INPUT], $_POST[SaveHandler::PRESENT], ['post_type' => 'event', 'post_status' => 'draft', 'post_id' => 10]);

        $this->assertCount(SaveHandler::MAX_ERRORS, $result['errors']);
        $this->assertCount(ErrorStore::MAX_ERRORS, $this->errors(self::ADMIN)['errors']);
    }

    public function testClientLayoutKeyIsStoredAsLayoutAtEveryDepth(): void
    {
        $GLOBALS['pb_test_current_user'] = self::ADMIN;
        $this->addGroup(['key' => 'group_flex', 'title' => 'Flex', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_pages', 'name' => 'pages', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'f_blocks', 'name' => 'blocks', 'type' => 'flexible_content', 'layouts' => [
                    ['key' => 'l_quote', 'name' => 'quote', 'label' => 'Quote', 'sub_fields' => [['key' => 'f_q', 'name' => 'q', 'type' => 'text']]],
                ]],
            ]],
        ]]);
        $this->post(['pages' => ['0' => ['blocks' => ['0' => ['_layout' => 'quote', 'q' => 'Hi']]]]], null, 'group_flex');
        $this->savePost();

        $this->assertNull($this->errors(self::ADMIN));
        $stored = self::stored('pages');
        $this->assertSame('quote', $stored[0]['blocks'][0]['layout']);
        $this->assertSame('Hi', $stored[0]['blocks'][0]['q']);
        $this->assertArrayNotHasKey('_layout', $stored[0]['blocks'][0]);
    }
}
