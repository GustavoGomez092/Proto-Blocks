<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Registry\TrustCallbacks;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\PostMetaStore;
use ProtoBlocks\Modules\Fields\Storage\TermMetaStore;
use ProtoBlocks\Modules\Fields\Storage\UserMetaStore;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';

/**
 * Writes go through the stores and the Fields meta stubs, which follow core's
 * update_metadata() order (subtype, unslash, sanitize_callback, then the
 * update_{type}_metadata short-circuit), so assertions are on what is STORED.
 */
final class TrustCallbacksTest extends TestCase
{
    private const ADMIN = 1;
    private const AUTHOR = 2;
    private const CONTRIBUTOR = 3;

    private TrustCallbacks $trust;
    private PostMetaStore $store;

    protected function setUp(): void
    {
        foreach (['pb_test_filters', 'pb_test_hooks', 'pb_test_meta', 'pb_test_meta_rows', 'pb_test_meta_reg', 'pb_test_terms', 'pb_test_options', 'pb_test_users'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_caps'] = [
            self::ADMIN => ['edit_posts', 'edit_post', 'unfiltered_html', 'edit_term', 'manage_options'],
            self::AUTHOR => ['edit_posts', 'edit_post'],
            self::CONTRIBUTOR => ['edit_posts', 'edit_post'],
        ];
        $GLOBALS['pb_test_posts'] = [
            10 => ['ID' => 10, 'post_type' => 'event', 'post_status' => 'draft', 'post_author' => self::CONTRIBUTOR],
            11 => ['ID' => 11, 'post_type' => 'event', 'post_status' => 'publish', 'post_author' => self::AUTHOR],
            12 => ['ID' => 12, 'post_type' => 'event', 'post_status' => 'publish', 'post_author' => self::AUTHOR],
            20 => ['ID' => 20, 'post_type' => 'venue', 'post_status' => 'publish', 'post_author' => self::AUTHOR],
        ];
        $GLOBALS['pb_test_current_user'] = self::AUTHOR;
        $this->trust = new TrustCallbacks(TypeRegistry::withCoreTypes());
        $this->store = new PostMetaStore();
    }

    protected function tearDown(): void
    {
        $GLOBALS['pb_test_current_user'] = 0;
    }

    private static function field(string $type, string $name = 'x', array $sub = [], array $settings = []): Field
    {
        return new Field('field_' . $name, $name, ucfirst($name), $type, $type, false, null, '', '', [], $sub, [], $settings);
    }

    private function as(int $user): void
    {
        $GLOBALS['pb_test_current_user'] = $user;
    }

    /** What MetaRegistrar does for one post field. */
    private function register(Field $f, string $subtype = 'event'): void
    {
        $GLOBALS['pb_test_meta_reg']['post'][$subtype][$f->name] = ['sanitize_callback' => $this->trust->sanitizeCallback($f, $this->store)];
        $this->trust->guard('post', $subtype, $f);
        $this->trust->hook();
    }

    /** Registers, writes through PostMetaStore, returns the stored value (null when no row). */
    private function write(Field $f, mixed $value, int $postId = 11): mixed
    {
        $this->register($f);
        $this->store->set(ObjectRef::post($postId), $f, $value);

        return $GLOBALS['pb_test_meta']['post'][$postId][$f->name][0] ?? null;
    }

    private const WYSIWYG = '<p>a</p><img src="x" onerror="alert(1)">';
    private const LINK = ['url' => 'javascript:alert(1)', 'title' => '<i>t</i>', 'target' => '_blank'];

    public function testAuthorTextScriptStripped(): void
    {
        $this->assertSame('Hi', $this->write(self::field('text', 'subtitle'), '<b>Hi</b><script>x()</script>'));
    }

    public function testAuthorWysiwygOnerrorStripped(): void
    {
        $out = $this->write(self::field('wysiwyg', 'body'), self::WYSIWYG);
        $this->assertStringContainsString('<p>a</p>', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testAuthorUrlAndLinkJavascriptEmptied(): void
    {
        $this->assertSame('', $this->write(self::field('url', 'site'), 'javascript:alert(1)'));
        $this->assertSame(
            ['url' => '', 'title' => 't', 'target' => '_blank'],
            $this->write(self::field('link', 'cta'), self::LINK),
        );
    }

    public function testTrustedStoredAsGiven(): void
    {
        $this->as(self::ADMIN);
        $this->assertSame(self::WYSIWYG, $this->write(self::field('wysiwyg', 'body'), self::WYSIWYG));
        $this->assertSame('javascript:alert(1)', $this->write(self::field('url', 'site'), 'javascript:alert(1)'));
        $this->assertSame(self::LINK, $this->write(self::field('link', 'cta'), self::LINK));
    }

    public function testTrustedCodeWithBackslashesSurvivesTheRoundTrip(): void
    {
        $this->as(self::ADMIN);
        $code = "<script>/\\d+\\/x/.test('a\\'b');</script>";
        $this->assertSame($code, $this->write(self::field('code', 'snippet'), $code));
        $this->assertSame($code, $this->store->get(ObjectRef::post(11), self::field('code', 'snippet')));
    }

    public function testCodeSanitizeKeepsPrevious(): void
    {
        $code = self::field('code', 'snippet');
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];

        $this->assertSame('<script>ok()</script>', $this->write($code, '<script>evil()</script>'));
        $this->assertSame(
            [['object' => 'post:11:event', 'field' => 'snippet', 'message' => 'Only users with unfiltered HTML may edit this field.']],
            $this->trust->takeRefusals(),
        );
        $this->assertSame([], $this->trust->takeRefusals(), 'take empties the list');

        $this->as(self::ADMIN);
        $this->assertSame('<script>evil()</script>', $this->write($code, '<script>evil()</script>'));
        $this->assertSame([], $this->trust->takeRefusals());
    }

    public function testCodeSanitizeCallbackNeverReturnsUntrustedOrForeignValues(): void
    {
        // A direct sanitize_meta() caller gets the empty value: not the input, not any stored value.
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $code = self::field('code', 'snippet');
        $out = ($this->trust->sanitizeCallback($code, $this->store))('<script>evil()</script>', 'snippet', 'post', 'event');
        $this->assertSame('', $out);
    }

    public function testDirectUntrustedMetaWritesOnCodeAreRefused(): void
    {
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $this->register(self::field('code', 'snippet'));

        $this->assertFalse(update_post_meta(11, 'snippet', '<script>evil()</script>'));
        $this->assertSame(['<script>ok()</script>'], $GLOBALS['pb_test_meta']['post'][11]['snippet']);
        $this->assertFalse(update_post_meta(12, 'snippet', 'x'), 'no row yet: still refused, nothing blanked or created');
        $this->assertArrayNotHasKey(12, $GLOBALS['pb_test_meta']['post']);

        $this->assertFalse(pb_test_apply_hooks('add_post_metadata', null, 11, 'snippet', 'x', false));
        $this->assertFalse(pb_test_apply_hooks('delete_post_metadata', null, 11, 'snippet', '', false));
        $this->assertFalse(pb_test_apply_hooks('update_post_metadata_by_mid', null, 99, 'x', 'snippet'), 'unknown object: fail closed');
        $this->assertNull(pb_test_apply_hooks('update_post_metadata', null, 11, 'unrelated', 'x', ''));
        $this->assertSame('handled', pb_test_apply_hooks('update_post_metadata', 'handled', 11, 'snippet', 'x', ''), 'an earlier decision passes through');
        $this->assertSame(['post:11:event', 'post:12:event', 'post:0:'], array_column($this->trust->takeRefusals(), 'object'), 'one refusal per object and field');

        $this->as(self::ADMIN);
        $this->assertNull(pb_test_apply_hooks('update_post_metadata', null, 11, 'snippet', 'x', ''));
    }

    public function testKeylessUpdateByMidIsGuardedByTheRowsKey(): void
    {
        // update_metadata_by_mid('post', $mid, $value) passes $meta_key === false; core writes under the row's key.
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $GLOBALS['pb_test_meta_rows'] = [
            501 => (object) ['meta_id' => 501, 'post_id' => 11, 'meta_key' => 'snippet', 'meta_value' => '<script>ok()</script>'],
            502 => (object) ['meta_id' => 502, 'post_id' => 11, 'meta_key' => 'subtitle', 'meta_value' => 'x'],
        ];
        $this->register(self::field('code', 'snippet'));

        $this->assertFalse(update_metadata_by_mid('post', 501, '<script>evil()</script>'));
        $this->assertSame(['<script>ok()</script>'], $GLOBALS['pb_test_meta']['post'][11]['snippet']);
        $this->assertSame('post:11:event', $this->trust->takeRefusals()[0]['object']);
        $this->assertNull(pb_test_apply_hooks('update_post_metadata_by_mid', null, 502, 'y', false), 'other keys pass');

        $this->as(self::ADMIN);
        $this->assertTrue(update_metadata_by_mid('post', 501, '<script>new()</script>'));
        $this->assertSame(['<script>new()</script>'], $GLOBALS['pb_test_meta']['post'][11]['snippet']);
    }

    public function testUpdateByMidRenameOfACodeRowIsRefused(): void
    {
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $GLOBALS['pb_test_meta']['post'][11]['subtitle'] = ['Hi'];
        $GLOBALS['pb_test_meta_rows'] = [
            501 => (object) ['meta_id' => 501, 'post_id' => 11, 'meta_key' => 'snippet', 'meta_value' => '<script>ok()</script>'],
            502 => (object) ['meta_id' => 502, 'post_id' => 11, 'meta_key' => 'subtitle', 'meta_value' => 'Hi'],
        ];
        $this->register(self::field('code', 'snippet'));
        $this->register(self::field('text', 'subtitle'));

        // Renaming the code row away (it would vanish) and renaming a text row onto the code key.
        $this->assertFalse(update_metadata_by_mid('post', 501, 'x', 'subtitle'));
        $this->assertFalse(update_metadata_by_mid('post', 502, '<script>evil()</script>', 'snippet'));
        $this->assertSame(['<script>ok()</script>'], $GLOBALS['pb_test_meta']['post'][11]['snippet']);
        $this->assertSame(['Hi'], $GLOBALS['pb_test_meta']['post'][11]['subtitle']);
        $this->assertSame('snippet', $GLOBALS['pb_test_meta_rows'][501]->meta_key);
        $this->assertSame('subtitle', $GLOBALS['pb_test_meta_rows'][502]->meta_key);

        $this->as(self::ADMIN);
        $this->assertTrue(update_metadata_by_mid('post', 501, '<script>ok()</script>', 'old_snippet'));
        $this->assertSame('old_snippet', $GLOBALS['pb_test_meta_rows'][501]->meta_key);
        $this->assertArrayNotHasKey('snippet', $GLOBALS['pb_test_meta']['post'][11]);
        $this->assertSame(['<script>ok()</script>'], $GLOBALS['pb_test_meta']['post'][11]['old_snippet']);
    }

    public function testNoValueFromAnotherObjectLeaks(): void
    {
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $code = self::field('code', 'snippet');
        // Core notes post 11 (e.g. a capability check), then a write for post 12 happens.
        pb_test_apply_hooks('get_object_subtype_post', 'event', 11);
        $this->assertNull($this->write($code, '<script>evil()</script>', 12));
        $this->assertSame('', ($this->trust->sanitizeCallback($code, $this->store))('x', 'snippet', 'post', 'event'));
    }

    public function testGuardIsPerSubtype(): void
    {
        // `snippet` is code on venues and text on events: events stay writable (sanitised).
        $this->register(self::field('code', 'snippet'), 'venue');
        $this->assertSame('Hi', $this->write(self::field('text', 'snippet'), '<b>Hi</b>', 12));
        $GLOBALS['pb_test_meta']['post'][20]['snippet'] = ['<script>ok()</script>'];
        $this->assertFalse(update_post_meta(20, 'snippet', 'x'));
        $this->assertSame('<script>ok()</script>', $GLOBALS['pb_test_meta']['post'][20]['snippet'][0]);
    }

    public function testNestedCodeSubFieldKeepsPreviousAndReports(): void
    {
        $rep = self::field('repeater', 'rows', [self::field('text', 'title'), self::field('code', 'embed')]);
        $previous = [['title' => 'A', 'embed' => '<script>ok()</script>']];
        $GLOBALS['pb_test_meta']['post'][11]['rows'] = [$previous];

        $out = $this->write($rep, [['title' => 'B', 'embed' => '<script>evil()</script>'], ['title' => 'C']]);
        $this->assertSame($previous, $out, 'the whole stored value is kept, no partial merge');
        $this->assertSame(
            'This field contains a code sub-field that only users with unfiltered HTML may edit.',
            $this->trust->takeRefusals()[0]['message'],
        );

        $group = self::field('group', 'box', [self::field('code', 'embed')]);
        $this->assertNull($this->write($group, ['embed' => '<script>evil()</script>']), 'nothing stored, nothing written');
    }

    public function testUntrustedEmptyNumberIsNotKeptAsPrevious(): void
    {
        // null from sanitize() means "empty" for number; the trust gate never reads it as "keep".
        $GLOBALS['pb_test_meta']['post'][11]['count'] = [5];
        $this->assertNull($this->write(self::field('number', 'count'), ''));
        $this->assertArrayHasKey('count', $GLOBALS['pb_test_meta']['post'][11]);
    }

    public function testContributorRestTextSanitised(): void
    {
        $text = self::field('text', 'subtitle');
        $this->assertTrue(($this->trust->authCallback($text, 'post'))(false, 'subtitle', 10, self::CONTRIBUTOR));
        $this->as(self::CONTRIBUTOR);
        $this->assertSame('Hi', $this->write($text, 'Hi<script>x()</script>', 10));
    }

    public function testContributorRestCodeDenied(): void
    {
        $auth = $this->trust->authCallback(self::field('code', 'snippet'), 'post');
        $this->assertFalse($auth(true, 'snippet', 10, self::AUTHOR));
        $this->assertFalse($auth(true, 'snippet', 10, self::CONTRIBUTOR));
        $this->assertTrue($auth(false, 'snippet', 10, self::ADMIN));

        $nested = self::field('repeater', 'rows', [self::field('code', 'embed')]);
        $this->assertFalse(($this->trust->authCallback($nested, 'post'))(true, 'rows', 10, self::CONTRIBUTOR));
    }

    public function testAuthNeedsTheEditCapOfTheKind(): void
    {
        $text = self::field('text', 'subtitle');
        $this->assertFalse(($this->trust->authCallback($text, 'post'))(true, 'subtitle', 10, 99), 'no caps');
        $this->assertFalse(($this->trust->authCallback($text, 'term'))(true, 'subtitle', 5, self::AUTHOR));
        $this->assertTrue(($this->trust->authCallback($text, 'term'))(false, 'subtitle', 5, self::ADMIN));
        $this->assertFalse(($this->trust->authCallback($text, 'user'))(true, 'subtitle', 5, self::ADMIN));
        $this->assertTrue(($this->trust->authCallback($text, 'option'))(false, 'subtitle', 0, self::ADMIN));
        $this->assertFalse(($this->trust->authCallback($text, 'option'))(true, 'subtitle', 0, self::AUTHOR));
        $this->assertFalse(($this->trust->authCallback($text, 'comment'))(true, 'subtitle', 5, self::ADMIN), 'unknown kind');
    }

    public function testTrustedRepeaterStillCapped(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_repeater_max_items'][] = static fn() => 3;
        $this->as(self::ADMIN);
        $rep = self::field('repeater', 'rows', [self::field('text', 'title')]);
        $out = $this->write($rep, array_fill(0, 4, ['title' => '<b>a</b>']));
        $this->assertCount(3, $out);
        $this->assertSame('<b>a</b>', $out[0]['title']);
    }

    public function testLoggedOutWriterIsUntrusted(): void
    {
        $this->as(0);
        $this->assertSame('Hi', $this->write(self::field('text', 'subtitle'), '<b>Hi</b>'));
    }

    public function testTermAndUserCodeWritesRefused(): void
    {
        $GLOBALS['pb_test_terms'][5] = ['term_id' => 5, 'taxonomy' => 'product_cat', 'slug' => 'a'];
        $GLOBALS['pb_test_meta']['term'][5]['snippet'] = ['<b>keep</b>'];
        $GLOBALS['pb_test_meta']['user'][3]['snippet'] = ['<b>keep</b>'];
        $code = self::field('code', 'snippet');
        $GLOBALS['pb_test_meta_reg']['term']['product_cat']['snippet'] = ['sanitize_callback' => $this->trust->sanitizeCallback($code, new TermMetaStore())];
        $GLOBALS['pb_test_meta_reg']['user']['']['snippet'] = ['sanitize_callback' => $this->trust->sanitizeCallback($code, new UserMetaStore())];
        $this->trust->guard('term', 'product_cat', $code);
        $this->trust->guard('user', '', $code);
        $this->trust->hook();

        (new TermMetaStore())->set(ObjectRef::term(5, 'product_cat'), $code, '<script>x</script>');
        (new UserMetaStore())->set(ObjectRef::user(3), $code, '<script>x</script>');
        $this->assertSame('<b>keep</b>', $GLOBALS['pb_test_meta']['term'][5]['snippet'][0]);
        $this->assertSame('<b>keep</b>', $GLOBALS['pb_test_meta']['user'][3]['snippet'][0]);
        $this->assertSame(['term:5:product_cat', 'user:3:'], array_column($this->trust->takeRefusals(), 'object'));
    }

    public function testOptionCallbackUsesItsPage(): void
    {
        $GLOBALS['pb_test_options']['theme-settings_snippet'] = '<b>keep</b>';
        $code = self::field('code', 'snippet');
        $cb = $this->trust->sanitizeCallback($code, new OptionStore(), ObjectRef::option('theme-settings'));
        // sanitize_option_{$option} passes ($value, $option, $original).
        $this->assertSame('<b>keep</b>', $cb('<script>x</script>', 'theme-settings_snippet', '<script>x</script>'));
        $this->assertSame('option:theme-settings', $this->trust->takeRefusals()[0]['object']);
        $cb2 = $this->trust->sanitizeCallback($code, new OptionStore(), ObjectRef::option('other'));
        $this->assertSame('', $cb2('<script>x</script>', 'other_snippet', '<script>x</script>'), 'nothing stored: empty');
        $this->as(self::ADMIN);
        $this->assertSame('<script>x</script>', $cb('<script>x</script>', 'theme-settings_snippet', '<script>x</script>'));
    }

    public function testHookIsIdempotent(): void
    {
        $this->trust->hook();
        $this->trust->hook();
        foreach (['add', 'update', 'delete'] as $op) {
            $this->assertCount(1, $GLOBALS['pb_test_hooks']["{$op}_post_metadata"]);
        }
        $this->assertCount(1, $GLOBALS['pb_test_hooks']['update_user_metadata_by_mid']);
    }
}
