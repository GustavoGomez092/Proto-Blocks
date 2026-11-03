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
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../Fields/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';
require_once __DIR__ . '/stubs/Storage.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('proto_blocks_sanitize_color')) {
    require_once __DIR__ . '/../../../../includes/helpers.php';
}

final class TrustCallbacksTest extends TestCase
{
    private const ADMIN = 1;
    private const AUTHOR = 2;
    private const CONTRIBUTOR = 3;

    private TrustCallbacks $trust;
    private PostMetaStore $store;

    protected function setUp(): void
    {
        foreach (['pb_test_filters', 'pb_test_hooks', 'pb_test_meta', 'pb_test_meta_reg', 'pb_test_terms', 'pb_test_options', 'pb_test_slashed'] as $g) {
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

    /** Runs the sanitize callback the way update_metadata() does: get_object_subtype first, then sanitize_meta. */
    private function write(Field $f, mixed $value, int $postId = 11): mixed
    {
        $this->trust->hook();
        foreach ($GLOBALS['pb_test_hooks']['get_object_subtype_post'] ?? [] as $h) {
            ($h['callback'])('event', $postId);
        }

        return ($this->trust->sanitizeCallback($f, $this->store))($value, $f->name, 'post', 'event');
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

    public function testCodeWithNothingStoredStaysEmpty(): void
    {
        $this->assertSame('', $this->write(self::field('code', 'snippet'), '<script>evil()</script>'));
    }

    public function testCodeObjectUnknownStaysEmpty(): void
    {
        // sanitize_meta() called outside update_metadata(): no object, nothing to keep, nothing injected.
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $code = self::field('code', 'snippet');
        $out = ($this->trust->sanitizeCallback($code, $this->store))('<script>evil()</script>', 'snippet', 'post', 'event');
        $this->assertSame('', $out);
    }

    public function testNoticedObjectIsConsumedOnce(): void
    {
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $code = self::field('code', 'snippet');
        $this->assertSame('<script>ok()</script>', $this->write($code, 'x'));
        // A second sanitize without a new get_object_subtype must not reuse post 11.
        $this->assertSame('', ($this->trust->sanitizeCallback($code, $this->store))('x', 'snippet', 'post', 'event'));
    }

    public function testStaleNoteForAnotherSubtypeIsIgnored(): void
    {
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $this->trust->hook();
        ($GLOBALS['pb_test_hooks']['get_object_subtype_post'][0]['callback'])('event', 11);
        $code = self::field('code', 'snippet');
        $out = ($this->trust->sanitizeCallback($code, $this->store))('x', 'snippet', 'post', 'page');
        $this->assertSame('', $out, 'post 11 is an event; a write for a page must not copy its code');
    }

    public function testRevisionCopyKeepsTheParentsCode(): void
    {
        $GLOBALS['pb_test_posts'][12] = ['ID' => 12, 'post_type' => 'revision', 'post_parent' => 11];
        $GLOBALS['pb_test_meta']['post'][11]['snippet'] = ['<script>ok()</script>'];
        $code = self::field('code', 'snippet');
        $this->trust->hook();
        ($GLOBALS['pb_test_hooks']['get_object_subtype_post'][0]['callback'])('revision', 12);
        $out = ($this->trust->sanitizeCallback($code, $this->store))('<script>evil()</script>', 'snippet', 'post', 'revision');
        $this->assertSame('<script>ok()</script>', $out);

        $GLOBALS['pb_test_meta']['post'][12]['snippet'] = ['<b>own</b>'];
        ($GLOBALS['pb_test_hooks']['get_object_subtype_post'][0]['callback'])('revision', 12);
        $out = ($this->trust->sanitizeCallback($code, $this->store))('<script>evil()</script>', 'snippet', 'post', 'revision');
        $this->assertSame('<b>own</b>', $out, 'a revision that holds a value keeps its own');
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
        $this->assertSame(['embed' => ''], $this->write($group, ['embed' => '<script>evil()</script>']), 'nothing stored: the empty value');
    }

    public function testUntrustedEmptyNumberIsNotKeptAsPrevious(): void
    {
        // null from sanitize() means "empty" for number; the trust gate never reads it as "keep".
        $GLOBALS['pb_test_meta']['post'][11]['count'] = [5];
        $this->assertNull($this->write(self::field('number', 'count'), ''));
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

    public function testTermObjectNoticedForTermMeta(): void
    {
        $GLOBALS['pb_test_terms'][5] = ['term_id' => 5, 'taxonomy' => 'product_cat', 'slug' => 'a'];
        $GLOBALS['pb_test_meta']['term'][5]['snippet'] = ['<b>keep</b>'];
        $this->trust->hook();
        foreach ($GLOBALS['pb_test_hooks']['get_object_subtype_term'] ?? [] as $h) {
            ($h['callback'])('product_cat', 5);
        }
        $code = self::field('code', 'snippet');
        $out = ($this->trust->sanitizeCallback($code, new TermMetaStore()))('<script>x</script>', 'snippet', 'term', 'product_cat');
        $this->assertSame('<b>keep</b>', $out);
    }

    public function testOptionCallbackUsesItsPage(): void
    {
        $GLOBALS['pb_test_options']['theme-settings_snippet'] = '<b>keep</b>';
        $code = self::field('code', 'snippet');
        $cb = $this->trust->sanitizeCallback($code, new OptionStore(), ObjectRef::option('theme-settings'));
        // sanitize_option_{$option} passes ($value, $option, $original).
        $this->assertSame('<b>keep</b>', $cb('<script>x</script>', 'theme-settings_snippet', '<script>x</script>'));
        $this->as(self::ADMIN);
        $this->assertSame('<script>x</script>', $cb('<script>x</script>', 'theme-settings_snippet', '<script>x</script>'));
    }

    public function testHookIsIdempotentAndPassesSubtypeThrough(): void
    {
        $this->trust->hook();
        $this->trust->hook();
        $this->assertCount(1, $GLOBALS['pb_test_hooks']['get_object_subtype_post']);
        $this->assertSame('event', ($GLOBALS['pb_test_hooks']['get_object_subtype_post'][0]['callback'])('event', 3));
    }
}
