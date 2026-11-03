<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Read\ContextResolver;
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
require_once __DIR__ . '/stubs/Read.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('proto_blocks_sanitize_color')) {
    require_once __DIR__ . '/../../../../includes/helpers.php';
}

final class FieldReaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../fixtures/fields';

    protected function setUp(): void
    {
        self::seed();
    }

    protected function tearDown(): void
    {
        FieldReader::setShared(null);
        unset($GLOBALS['pb_test_queried_object'], $GLOBALS['pb_test_current_post'], $GLOBALS['pb_test_in_the_loop']);
    }

    /**
     * Fixture globals shared with AliasesTest and TemplateHelperTest: post 10
     * (event) with meta stored as a trusted administrator saved it (no
     * sanitising), term 5, user 3 and the theme-settings options page.
     */
    public static function seed(): void
    {
        foreach (['pb_test_filters', 'pb_test_hooks', 'pb_test_meta_reg', 'pb_test_meta', 'pb_test_posts', 'pb_test_terms', 'pb_test_users', 'pb_test_attachments', 'pb_test_object_terms', 'pb_test_options', 'pb_test_doing_it_wrong'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_current_user'] = 0;
        $GLOBALS['pb_test_posts'][10] = ['ID' => 10, 'post_type' => 'event', 'post_status' => 'publish'];
        $GLOBALS['pb_test_posts'][11] = ['ID' => 11, 'post_type' => 'page', 'post_status' => 'publish'];
        $GLOBALS['pb_test_terms'][5] = ['term_id' => 5, 'taxonomy' => 'product_cat', 'slug' => 'a', 'name' => 'A'];
        $GLOBALS['pb_test_users'][3] = ['ID' => 3, 'roles' => ['author'], 'user_login' => 'ann'];
        $GLOBALS['pb_test_attachments'][77] = ['url' => 'https://x.test/hero.jpg', 'width' => 800, 'height' => 600, 'mime' => 'image/jpeg'];

        $post = [
            'subtitle' => '<b>Hi</b>',
            'summary' => 'Line <i>one</i>',
            'stream_url' => 'javascript:x',
            'audience' => ['a', 'b'],
            'hero' => 77,
            'accent' => '#ff0000;background:url(x)',
            'agenda' => [
                ['time' => '09:00:00', 'title' => '<script>alert(1)</script>Open', 'notes' => '<p>ok</p><script>bad()</script>'],
                ['time' => '10:00:00', 'title' => 'Talk & Q', 'notes' => '<img src=x onerror=alert(1)>'],
            ],
            'sections' => [
                ['layout' => 'quote', 'quote' => '<em>q</em>', 'cite' => 'Me'],
            ],
            'snippet' => '<script>trusted()</script>',
        ];
        foreach ($post as $key => $value) {
            $GLOBALS['pb_test_meta']['post'][10][$key] = [$value];
        }
        $GLOBALS['pb_test_meta']['term'][5]['tagline'] = ['<u>term</u>'];
        $GLOBALS['pb_test_meta']['user'][3]['bio'] = ['<u>user</u>'];
        $GLOBALS['pb_test_options']['theme-settings_phone'] = '<u>555</u>';
    }

    /** @return list<FieldGroup> */
    public static function fixtureGroups(): array
    {
        $raw = json_decode((string) file_get_contents(self::FIXTURES . '/acf-export-realistic.json'), true);
        $groups = GroupNormalizer::normalizeMany($raw, 'php')['groups'];

        $extra = [
            [
                'key' => 'group_code', 'title' => 'Code', 'menu_order' => 5,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]],
                'fields' => [
                    ['key' => 'field_snippet', 'name' => 'snippet', 'label' => 'Snippet', 'type' => 'code'],
                    ['key' => 'field_subtitle_dup', 'name' => 'subtitle', 'label' => 'Dup', 'type' => 'wysiwyg'],
                ],
            ],
            [
                'key' => 'group_opts', 'title' => 'Opts',
                'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'theme-settings']]],
                'fields' => [['key' => 'field_phone', 'name' => 'phone', 'label' => 'Phone', 'type' => 'text']],
            ],
            [
                'key' => 'group_term', 'title' => 'Term',
                'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'product_cat']]],
                'fields' => [['key' => 'field_tagline', 'name' => 'tagline', 'label' => 'Tagline', 'type' => 'text']],
            ],
            [
                'key' => 'group_user', 'title' => 'User',
                'location' => [[['param' => 'user_role', 'operator' => '==', 'value' => 'all']]],
                'fields' => [['key' => 'field_bio', 'name' => 'bio', 'label' => 'Bio', 'type' => 'text']],
            ],
        ];
        foreach ($extra as $one) {
            $group = GroupNormalizer::normalize($one, 'php')['group'];
            self::assertNotNull($group);
            $groups[] = $group;
        }

        return $groups;
    }

    public static function reader(): FieldReader
    {
        $index = new GroupIndex(self::fixtureGroups());

        return new FieldReader($index, TypeRegistry::withCoreTypes(), [
            ObjectRef::POST => new PostMetaStore(),
            ObjectRef::TERM => new TermMetaStore(),
            ObjectRef::USER => new UserMetaStore(),
            ObjectRef::OPTION => new OptionStore($index),
        ]);
    }

    // ------------------------------------------------------------ escaping per type

    public function testTextIsHtmlEscaped(): void
    {
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', self::reader()->get('subtitle', 10));
    }

    public function testRawReturnsTheStoredValue(): void
    {
        $this->assertSame('<b>Hi</b>', self::reader()->get('subtitle', 10, true));
    }

    public function testTextareaIsHtmlEscaped(): void
    {
        $this->assertSame('Line &lt;i&gt;one&lt;/i&gt;', self::reader()->get('summary', 10));
    }

    public function testTrustedJavascriptUrlIsStillEscapedAway(): void
    {
        $this->assertSame('', self::reader()->get('stream_url', 10));
        $this->assertSame('javascript:x', self::reader()->get('stream_url', 10, true));
    }

    public function testImageComesBackAsArrayWithUrl(): void
    {
        $hero = self::reader()->get('hero', 10);
        $this->assertIsArray($hero);
        $this->assertSame('https://x.test/hero.jpg', $hero['url']);
        $this->assertSame(77, self::reader()->get('hero', 10, true));
    }

    public function testColourIsSanitised(): void
    {
        $this->assertSame('', self::reader()->get('accent', 10));
    }

    public function testMultipleChoiceIsListOfEscapedStrings(): void
    {
        $this->assertSame(['a', 'b'], self::reader()->get('audience', 10));
    }

    public function testCodeIsReturnedAsStored(): void
    {
        $this->assertSame('<script>trusted()</script>', self::reader()->get('snippet', 10));
    }

    public function testDuplicateNameResolvesToTheFirstGroup(): void
    {
        // group_code (menu_order 5) also declares `subtitle` as wysiwyg; the
        // realistic group (menu_order 0) wins, so text escaping applies.
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', self::reader()->get('subtitle', 10));
    }

    // ------------------------------------------------------------ declared names, defaults

    public function testUndeclaredNameIsNull(): void
    {
        $this->assertNull(self::reader()->get('nope', 10));
        $this->assertNull(self::reader()->get('', 10));
    }

    public function testFieldOfAGroupNotMatchingTheObjectIsNull(): void
    {
        $this->assertNull(self::reader()->get('subtitle', 11));
        $this->assertNull(self::reader()->get('subtitle', 999));
    }

    public function testUnsetFieldReturnsItsDefaultNormalisedAndEscaped(): void
    {
        $this->assertFalse(self::reader()->get('is_online', 10));
        $this->assertFalse(self::reader()->get('is_online', 10, true));
        $this->assertSame('', self::reader()->get('start_date', 10));
        $this->assertSame([], self::reader()->get('photos', 10));
    }

    public function testGetterDoesNotWrite(): void
    {
        $before = $GLOBALS['pb_test_meta'];
        $optionsBefore = $GLOBALS['pb_test_options'];
        $reader = self::reader();
        $reader->get('is_online', 10);
        $reader->all(10);
        $reader->get('phone', 'option:theme-settings');
        self::toList($reader->rows('agenda', 10));
        $this->assertSame($before, $GLOBALS['pb_test_meta']);
        $this->assertSame($optionsBefore, $GLOBALS['pb_test_options']);
    }

    // ------------------------------------------------------------ rows

    public function testRowsYieldsEscapedRepeaterRows(): void
    {
        $rows = self::toList(self::reader()->rows('agenda', 10));
        $this->assertCount(2, $rows);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;Open', $rows[0]['title']);
        $this->assertSame('Talk &amp; Q', $rows[1]['title']);
        $this->assertStringNotContainsString('<script', $rows[0]['notes']);
        $this->assertStringNotContainsString('onerror', $rows[1]['notes']);
    }

    public function testRowsYieldsFlexibleLayoutRows(): void
    {
        $rows = self::toList(self::reader()->rows('sections', 10));
        $this->assertCount(1, $rows);
        $this->assertSame('quote', $rows[0]['layout']);
        $this->assertSame('&lt;em&gt;q&lt;/em&gt;', $rows[0]['quote']);
    }

    public function testRowsOfANonRowFieldIsEmpty(): void
    {
        $this->assertSame([], self::toList(self::reader()->rows('subtitle', 10)));
        $this->assertSame([], self::toList(self::reader()->rows('nope', 10)));
    }

    // ------------------------------------------------------------ all

    public function testAllReturnsEveryValueFieldEscaped(): void
    {
        $all = self::reader()->all(10);
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $all['subtitle']);
        $this->assertSame('', $all['stream_url']);
        $this->assertFalse($all['is_online']);
        $this->assertSame('<script>trusted()</script>', $all['snippet']);
        $this->assertArrayNotHasKey('', $all, 'tabs are not values');
        $this->assertCount(2, $all['agenda']);
        $this->assertArrayNotHasKey('phone', $all);

        $raw = self::reader()->all(10, true);
        $this->assertSame('<b>Hi</b>', $raw['subtitle']);
    }

    public function testAllForUnresolvableContextIsEmpty(): void
    {
        $this->assertSame([], self::reader()->all('nonsense'));
    }

    // ------------------------------------------------------------ contexts

    public function testOptionContextRoutesToOptionStore(): void
    {
        $this->assertSame('&lt;u&gt;555&lt;/u&gt;', self::reader()->get('phone', 'option:theme-settings'));
        $this->assertNull(self::reader()->get('phone', 'option:other-page'));
        $this->assertNull(self::reader()->get('phone', 10));
    }

    public function testTermContextRoutesToTermMeta(): void
    {
        $this->assertSame('&lt;u&gt;term&lt;/u&gt;', self::reader()->get('tagline', 'term:5'));
        $this->assertNull(self::reader()->get('tagline', 'term:6'));
    }

    public function testUserContextRoutesToUserMeta(): void
    {
        $this->assertSame('&lt;u&gt;user&lt;/u&gt;', self::reader()->get('bio', 'user:3'));
    }

    public function testPostContextForms(): void
    {
        $r = self::reader();
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $r->get('subtitle', 'post:10'));
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $r->get('subtitle', '10'));
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $r->get('subtitle', (object) ['ID' => 10, 'post_type' => 'event']));
    }

    public function testNullContextUsesTheQueriedObject(): void
    {
        $GLOBALS['pb_test_queried_object'] = (object) ['ID' => 10, 'post_type' => 'event', 'post_status' => 'publish'];
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', self::reader()->get('subtitle'));

        $GLOBALS['pb_test_queried_object'] = (object) ['term_id' => 5, 'taxonomy' => 'product_cat', 'slug' => 'a'];
        $this->assertSame('&lt;u&gt;term&lt;/u&gt;', self::reader()->get('tagline'));

        $GLOBALS['pb_test_queried_object'] = (object) ['ID' => 3, 'user_login' => 'ann', 'roles' => ['author']];
        $this->assertSame('&lt;u&gt;user&lt;/u&gt;', self::reader()->get('bio'));

        $GLOBALS['pb_test_queried_object'] = null;
        $this->assertNull(self::reader()->get('subtitle'));
    }

    public function testNullContextInTheLoopUsesTheLoopPost(): void
    {
        $GLOBALS['pb_test_queried_object'] = (object) ['term_id' => 5, 'taxonomy' => 'product_cat', 'slug' => 'a'];
        $GLOBALS['pb_test_current_post'] = 10;
        $GLOBALS['pb_test_in_the_loop'] = true;

        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', self::reader()->get('subtitle'));

        $GLOBALS['pb_test_in_the_loop'] = false;
        $this->assertNull(self::reader()->get('subtitle'));
    }

    /** WP_User::__isset() falls through to user meta: a `post_type` meta key must not make the user a post. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWpUserIsResolvedByClassNotByShape(): void
    {
        require __DIR__ . '/stubs/WpUserMagic.php';
        $user = new \WP_User(3, ['post_type' => 'event', 'term_id' => 5, 'taxonomy' => 'product_cat']);
        $this->assertTrue(isset($user->post_type), 'the stub mimics core\'s meta fall-through');

        $ref = ContextResolver::resolve($user);
        $this->assertSame(ObjectRef::USER, $ref?->kind);
        $this->assertSame(3, $ref?->id);
        $this->assertSame('&lt;u&gt;user&lt;/u&gt;', self::reader()->get('bio', $user));
        $this->assertNull(self::reader()->get('subtitle', $user));

        $GLOBALS['pb_test_queried_object'] = $user;
        $this->assertSame(ObjectRef::USER, ContextResolver::resolve(null)?->kind);
    }

    public function testOnlyStdClassIsDuckTyped(): void
    {
        $other = new class () {
            public int $ID = 10;
            public string $post_type = 'event';
        };
        $this->assertNull(ContextResolver::resolve($other));
        $this->assertSame(ObjectRef::POST, ContextResolver::resolve((object) ['ID' => 10, 'post_type' => 'event'])?->kind);
    }

    public function testContextGrammarIsStrict(): void
    {
        $bad = ['post:10x', "post:10\n", 'post:0', 'term:', 'term:-5', 'user:abc', 'user:0', 'option:', 'option:Bad Page',
            "option:theme-settings\n", 'options', 'widget:1', '', ' 10', '10 ', 0, -3, 1.5, true, false, [], ['ID' => 10], new \stdClass()];
        foreach ($bad as $context) {
            $this->assertNull(ContextResolver::resolve($context), var_export($context, true));
        }
        $this->assertSame('option', ContextResolver::resolve('option:theme-settings')?->kind);
        $this->assertSame('theme-settings', ContextResolver::resolve('option:theme-settings')?->page);
        $this->assertSame('product_cat', ContextResolver::resolve('term:5')?->subtype);
        $this->assertSame(3, ContextResolver::resolve('user:3')?->id);
        $this->assertSame('event', ContextResolver::resolve(10)?->subtype);
    }

    // ------------------------------------------------------------ shared instance

    public function testSharedInstance(): void
    {
        $this->assertNull(FieldReader::shared());
        $reader = self::reader();
        FieldReader::setShared($reader);
        $this->assertSame($reader, FieldReader::shared());
    }

    /** @return list<mixed> */
    private static function toList(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row;
        }

        return $out;
    }
}
