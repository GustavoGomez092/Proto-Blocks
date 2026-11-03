<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Bindings\FieldBindingSource;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\PostMetaStore;
use ProtoBlocks\Modules\Fields\Storage\TermMetaStore;
use ProtoBlocks\Modules\Fields\Storage\UserMetaStore;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/FieldReaderTest.php';
require_once __DIR__ . '/stubs/Bindings.php';

final class FieldBindingSourceTest extends TestCase
{
    private const VISITOR = 0;
    private const ADMIN = 1;
    private const READER = 2;

    protected function setUp(): void
    {
        FieldReaderTest::seed();
        foreach (['pb_test_bindings', 'pb_test_password_required', 'pb_test_hidden_taxonomies'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_caps'][self::READER] = ['read_post'];
        $GLOBALS['pb_test_caps'][self::ADMIN] = ['read_post', 'unfiltered_html', 'manage_options', 'edit_user', 'edit_term'];

        // Post 11: a private event with the same subtitle as public post 10.
        $GLOBALS['pb_test_posts'][11] = ['ID' => 11, 'post_type' => 'event', 'post_status' => 'private'];
        $GLOBALS['pb_test_meta']['post'][11]['subtitle'] = ['<b>Hi</b>'];
        $GLOBALS['pb_test_meta']['post'][10]['internal'] = ['staff only'];
        $GLOBALS['pb_test_meta']['post'][10]['secret'] = ['hunter2'];
        $GLOBALS['pb_test_meta']['post'][10]['register_link'] = [['url' => 'javascript:alert(1)', 'title' => 'Go <b>now</b>', 'target' => '_blank']];
        $GLOBALS['pb_test_meta']['post'][77]['_wp_attachment_image_alt'] = ['A "hero" <img>'];

        // Term 5 is a category for these tests.
        $GLOBALS['pb_test_terms'][5] = ['term_id' => 5, 'taxonomy' => 'category', 'slug' => 'news', 'name' => 'News'];
        $GLOBALS['pb_test_meta']['term'][5]['kicker'] = ['<i>Breaking</i>'];

        $GLOBALS['pb_test_options']['site-settings_smtp'] = [
            'host' => '<b>mail.test</b>',
            'password' => 'hunter2',
            'script' => '<script>secret()</script>',
            'internal' => 'staff only',
            'nested' => ['deep' => 'deep value'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_public_types']);
        $GLOBALS['pb_test_current_user'] = 0;
        unset($GLOBALS['pb_test_caps'][self::READER], $GLOBALS['pb_test_caps'][self::ADMIN]);
    }

    private function source(): FieldBindingSource
    {
        $extra = [
            [
                'key' => 'group_bind', 'title' => 'Bindings', 'menu_order' => 6,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]],
                'fields' => [
                    ['key' => 'field_internal', 'name' => 'internal', 'label' => 'Internal', 'type' => 'text', 'bindings' => false],
                    ['key' => 'field_secret', 'name' => 'secret', 'label' => 'Secret', 'type' => 'password'],
                ],
            ],
            [
                'key' => 'group_site', 'title' => 'Site settings',
                'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'site-settings']]],
                'fields' => [[
                    'key' => 'field_smtp', 'name' => 'smtp', 'label' => 'SMTP', 'type' => 'group',
                    'sub_fields' => [
                        ['key' => 'field_smtp_host', 'name' => 'host', 'label' => 'Host', 'type' => 'text'],
                        ['key' => 'field_smtp_password', 'name' => 'password', 'label' => 'Password', 'type' => 'password'],
                        ['key' => 'field_smtp_script', 'name' => 'script', 'label' => 'Script', 'type' => 'code'],
                        ['key' => 'field_smtp_internal', 'name' => 'internal', 'label' => 'Internal', 'type' => 'text', 'bindings' => false],
                        [
                            'key' => 'field_smtp_nested', 'name' => 'nested', 'label' => 'Nested', 'type' => 'group',
                            'sub_fields' => [['key' => 'field_smtp_deep', 'name' => 'deep', 'label' => 'Deep', 'type' => 'text']],
                        ],
                    ],
                ]],
            ],
            [
                'key' => 'group_cat', 'title' => 'Category',
                'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
                'fields' => [['key' => 'field_kicker', 'name' => 'kicker', 'label' => 'Kicker', 'type' => 'text']],
            ],
        ];
        $groups = FieldReaderTest::fixtureGroups();
        foreach ($extra as $one) {
            $group = GroupNormalizer::normalize($one, 'php')['group'];
            self::assertNotNull($group);
            $groups[] = $group;
        }
        $index = new GroupIndex($groups);
        $reader = new FieldReader($index, TypeRegistry::withCoreTypes(), [
            ObjectRef::POST => new PostMetaStore(),
            ObjectRef::TERM => new TermMetaStore(),
            ObjectRef::USER => new UserMetaStore(),
            ObjectRef::OPTION => new OptionStore($index),
        ]);

        return new FieldBindingSource($reader, $index);
    }

    /** @param array<string, mixed> $context */
    private static function block(array $context = ['postId' => 10, 'postType' => 'event']): \WP_Block
    {
        $block = new \WP_Block();
        $block->context = $context;

        return $block;
    }

    private static function actAs(int $user): void
    {
        $GLOBALS['pb_test_current_user'] = $user;
    }

    // ------------------------------------------------------------ registration

    public function testRegisterRecordsTheSourceOnInit(): void
    {
        $source = $this->source();
        $source->register();

        self::assertArrayNotHasKey(FieldBindingSource::NAME, $GLOBALS['pb_test_bindings']);
        $hooks = $GLOBALS['pb_test_hooks']['init'] ?? [];
        self::assertCount(1, $hooks);
        ($hooks[0]['callback'])();
        ($hooks[0]['callback'])(); // a second init run must not re-register

        $props = $GLOBALS['pb_test_bindings']['proto-blocks/field'] ?? null;
        self::assertIsArray($props);
        self::assertSame('Proto-Blocks field', $props['label']);
        self::assertSame(['postId', 'postType'], $props['uses_context']);
        self::assertSame([$source, 'value'], $props['get_value_callback']);
    }

    // ------------------------------------------------------------ escaping and visibility

    public function testTextBoundToContentIsEscaped(): void
    {
        self::assertSame('&lt;b&gt;Hi&lt;/b&gt;', $this->source()->value(['name' => 'subtitle'], self::block(), 'content'));
    }

    public function testPrivatePostHiddenFromVisitorsShownToReaders(): void
    {
        $source = $this->source();
        $block = self::block(['postId' => 11, 'postType' => 'event']);

        self::actAs(self::VISITOR);
        self::assertNull($source->value(['name' => 'subtitle'], $block, 'content'));
        // A block on public post 10 naming the private post explicitly.
        self::assertNull($source->value(['name' => 'subtitle', 'context' => 'post:11'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'subtitle', 'context' => 11], self::block(), 'content'));

        self::actAs(self::READER);
        self::assertSame('&lt;b&gt;Hi&lt;/b&gt;', $source->value(['name' => 'subtitle'], $block, 'content'));
        self::assertSame('&lt;b&gt;Hi&lt;/b&gt;', $source->value(['name' => 'subtitle', 'context' => 'post:11'], self::block(), 'content'));
    }

    public function testPublishedPostOfNonPublicTypeHiddenFromVisitors(): void
    {
        $GLOBALS['pb_test_public_types'] = ['page'];
        $source = $this->source();

        self::actAs(self::VISITOR);
        self::assertNull($source->value(['name' => 'subtitle'], self::block(), 'content'));

        self::actAs(self::READER);
        self::assertSame('&lt;b&gt;Hi&lt;/b&gt;', $source->value(['name' => 'subtitle'], self::block(), 'content'));
    }

    // ------------------------------------------------------------ key on group sub-fields

    public function testKeyOnGroupAppliesThePolicyToTheSubField(): void
    {
        $source = $this->source();
        $args = static fn(string $key): array => ['name' => 'smtp', 'key' => $key, 'context' => 'option:site-settings'];

        foreach ([self::VISITOR, self::ADMIN] as $user) {
            self::actAs($user);
            self::assertNull($source->value($args('password'), self::block(), 'content'), 'password sub-field');
            self::assertNull($source->value($args('script'), self::block(), 'content'), 'code sub-field');
            self::assertNull($source->value($args('internal'), self::block(), 'content'), 'bindings:false sub-field');
            self::assertNull($source->value($args('nested'), self::block(), 'content'), 'nested group');
            self::assertNull($source->value($args('nested.deep'), self::block(), 'content'), 'dotted key');
            self::assertNull($source->value($args('missing'), self::block(), 'content'), 'unknown sub-field');
            self::assertSame('&lt;b&gt;mail.test&lt;/b&gt;', $source->value($args('host'), self::block(), 'content'));
        }
        // A group without key has no mapping.
        self::assertNull($source->value(['name' => 'smtp', 'context' => 'option:site-settings'], self::block(), 'content'));
    }

    public function testKeyOnlyIndexesAllowedTypes(): void
    {
        $source = $this->source();
        self::assertNull($source->value(['name' => 'agenda', 'key' => 'title'], self::block(), 'content'), 'repeater');
        self::assertNull($source->value(['name' => 'sections', 'key' => 'quote'], self::block(), 'content'), 'flexible content');
        self::assertNull($source->value(['name' => 'audience', 'key' => 'a'], self::block(), 'content'), 'multi select');
        self::assertSame('https://x.test/hero.jpg', $source->value(['name' => 'hero', 'key' => 'url'], self::block(), 'content'), 'image');
    }

    public function testMissingPostIsNull(): void
    {
        self::actAs(self::ADMIN);
        self::assertNull($this->source()->value(['name' => 'subtitle', 'context' => 'post:999'], self::block(), 'content'));
    }

    public function testPasswordProtectedPostIsHidden(): void
    {
        $GLOBALS['pb_test_password_required'][10] = true;
        self::actAs(self::ADMIN);
        self::assertNull($this->source()->value(['name' => 'subtitle'], self::block(), 'content'));
    }

    public function testCodeFieldNeverBound(): void
    {
        self::actAs(self::ADMIN);
        self::assertNull($this->source()->value(['name' => 'snippet'], self::block(), 'content'));
    }

    public function testPasswordFieldNeverBound(): void
    {
        self::actAs(self::ADMIN);
        self::assertNull($this->source()->value(['name' => 'secret'], self::block(), 'content'));
    }

    public function testBindingsFalseOptsOut(): void
    {
        self::actAs(self::ADMIN);
        self::assertNull($this->source()->value(['name' => 'internal'], self::block(), 'content'));
    }

    // ------------------------------------------------------------ attribute mapping

    public function testImageMapsUrlAltAndId(): void
    {
        $source = $this->source();
        self::assertSame('https://x.test/hero.jpg', $source->value(['name' => 'hero'], self::block(), 'url'));
        self::assertSame('A &quot;hero&quot; &lt;img&gt;', $source->value(['name' => 'hero'], self::block(), 'alt'));
        self::assertSame('77', $source->value(['name' => 'hero'], self::block(), 'id'));
        self::assertNull($source->value(['name' => 'hero'], self::block(), 'content'));
    }

    public function testKeyPicksAScalarSubValue(): void
    {
        $source = $this->source();
        self::assertSame('800', $source->value(['name' => 'hero', 'key' => 'width'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'hero', 'key' => 'sizes'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'hero', 'key' => 'missing'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'hero', 'key' => '../x'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'hero', 'key' => ['url']], self::block(), 'content'));
        // A key on a scalar has nothing to pick.
        self::assertNull($source->value(['name' => 'subtitle', 'key' => 'url'], self::block(), 'content'));
    }

    public function testLinkMapsUrlAndTextAndBlocksJavascriptUrls(): void
    {
        $source = $this->source();
        self::assertNull($source->value(['name' => 'register_link'], self::block(), 'url'));
        self::assertSame('Go &lt;b&gt;now&lt;/b&gt;', $source->value(['name' => 'register_link'], self::block(), 'text'));
        self::assertSame('Go &lt;b&gt;now&lt;/b&gt;', $source->value(['name' => 'register_link'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'register_link'], self::block(), 'alt'));
        // A key naming a URL part bound to a url attribute still goes through esc_url.
        self::assertNull($source->value(['name' => 'register_link', 'key' => 'url'], self::block(), 'url'));
    }

    public function testTextFieldBoundToUrlGoesThroughEscUrl(): void
    {
        $GLOBALS['pb_test_meta']['post'][10]['subtitle'] = ['javascript:alert(1)'];
        $source = $this->source();
        self::assertNull($source->value(['name' => 'subtitle'], self::block(), 'url'));
        self::assertNull($source->value(['name' => 'stream_url'], self::block(), 'url'));

        $GLOBALS['pb_test_meta']['post'][10]['subtitle'] = ['https://ok.test/?a=1'];
        self::assertSame('https://ok.test/?a=1', $source->value(['name' => 'subtitle'], self::block(), 'url'));
    }

    public function testTextBoundToOtherAttributeIsAttributeEscaped(): void
    {
        $GLOBALS['pb_test_meta']['post'][10]['summary'] = ['"><script>x</script>'];
        self::assertSame('&quot;&gt;&lt;script&gt;x&lt;/script&gt;', $this->source()->value(['name' => 'summary'], self::block(), 'title'));
    }

    public function testArraysWithoutMappingAreNullWithoutNotice(): void
    {
        $source = $this->source();
        self::assertNull($source->value(['name' => 'agenda'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'audience'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'photos'], self::block(), 'url'));
    }

    // ------------------------------------------------------------ contexts

    public function testTermContext(): void
    {
        self::assertSame('&lt;i&gt;Breaking&lt;/i&gt;', $this->source()->value(['name' => 'kicker', 'context' => 'term:5'], self::block(), 'content'));
    }

    public function testTermOfHiddenTaxonomyNeedsEditTerm(): void
    {
        $GLOBALS['pb_test_hidden_taxonomies'] = ['category'];
        $source = $this->source();
        self::assertNull($source->value(['name' => 'kicker', 'context' => 'term:5'], self::block(), 'content'));
        self::actAs(self::ADMIN);
        self::assertSame('&lt;i&gt;Breaking&lt;/i&gt;', $source->value(['name' => 'kicker', 'context' => 'term:5'], self::block(), 'content'));
    }

    public function testOptionsContextOnlyReadsFieldsOfThatPage(): void
    {
        $source = $this->source();
        self::assertSame('&lt;u&gt;555&lt;/u&gt;', $source->value(['name' => 'phone', 'context' => 'option:theme-settings'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'subtitle', 'context' => 'option:theme-settings'], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'phone', 'context' => 'option:other'], self::block(), 'content'));
    }

    public function testUserContextOnlyForViewersWhoCanEditTheUser(): void
    {
        $source = $this->source();
        self::assertNull($source->value(['name' => 'bio', 'context' => 'user:3'], self::block(), 'content'));
        self::actAs(self::ADMIN);
        self::assertSame('&lt;u&gt;user&lt;/u&gt;', $source->value(['name' => 'bio', 'context' => 'user:3'], self::block(), 'content'));
    }

    public function testUndeclaredOrMalformedArgsAreNullWithoutNotice(): void
    {
        $GLOBALS['pb_test_doing_it_wrong'] = [];
        $source = $this->source();
        self::assertNull($source->value(['name' => 'nope'], self::block(), 'content'));
        self::assertNull($source->value([], self::block(), 'content'));
        self::assertNull($source->value(['name' => ''], self::block(), 'content'));
        self::assertNull($source->value(['name' => ['subtitle']], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'subtitle', 'context' => "post:10\n"], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'subtitle', 'context' => ['post:10']], self::block(), 'content'));
        self::assertNull($source->value(['name' => 'subtitle'], self::block([]), 'content'));
        self::assertNull($source->value(['name' => 'subtitle'], self::block(['postId' => 'x']), 'content'));
        self::assertNull($source->value(['name' => 'subtitle'], self::block(['postId' => -10]), 'content'));
        self::assertSame([], $GLOBALS['pb_test_doing_it_wrong']);
    }

    public function testDigitStringPostIdContext(): void
    {
        self::assertSame('&lt;b&gt;Hi&lt;/b&gt;', $this->source()->value(['name' => 'subtitle'], self::block(['postId' => '10']), 'content'));
    }

    // ------------------------------------------------------------ WordPress floor

    public function testVersionGuard(): void
    {
        $root = dirname(__DIR__, 4);
        $main = (string) file_get_contents($root . '/proto-blocks.php');
        self::assertStringContainsString('Requires at least: 6.5', $main);
        self::assertStringContainsString("'6.5', '<'", $main);
        self::assertStringNotContainsString("'6.3', '<'", $main);
        self::assertStringContainsString('WordPress 6.5 or higher', $main);
        self::assertStringContainsString('- WordPress 6.5+', (string) file_get_contents($root . '/README.md'));

        $updater = (string) file_get_contents($root . '/includes/Updater/GitHubUpdater.php');
        self::assertMatchesRegularExpression("/'requires'\\s*=>\\s*'6\\.5',/", $updater);
        self::assertMatchesRegularExpression("/'requires_php'\\s*=>\\s*'8\\.1',/", $updater);
    }
}
