<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Types\RepeaterType;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';

final class StructuredTypesTest extends TestCase
{
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = TypeRegistry::withCoreTypes();
        foreach (['pb_test_filters', 'pb_test_posts', 'pb_test_caps', 'pb_test_attachments', 'pb_test_terms'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_current_user'] = 0;
    }

    private static function field(string $type, array $settings = [], array $sub = [], array $layouts = [], string $name = 'x'): Field
    {
        return new Field('field_' . $name, $name, ucfirst($name), $type, $type, false, null, '', '', [], $sub, $layouts, $settings);
    }

    private function sanitize(Field $f, mixed $v): mixed
    {
        return $this->registry->for($f)->sanitize($v, $f);
    }

    private function normalize(Field $f, mixed $v): mixed
    {
        return $this->registry->for($f)->normalize($v, $f);
    }

    private function escape(Field $f, mixed $v): mixed
    {
        return $this->registry->for($f)->escape($v, $f);
    }

    private function rowField(array $settings = []): Field
    {
        return self::field('repeater', $settings, [self::field('text', [], [], [], 'title'), self::field('url', [], [], [], 'link')]);
    }

    public function testRepeaterSanitisesRowsAndDropsUnknownKeys(): void
    {
        $out = $this->sanitize($this->rowField(), [['title' => '<b>A</b>', 'link' => 'javascript:1', 'extra' => 'x']]);
        $this->assertSame([['title' => 'A', 'link' => '']], $out);
    }

    public function testRepeaterCappedByFilter(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_repeater_max_items'][] = static fn() => 3;
        $rows = array_fill(0, 5, ['title' => 'a']);
        $this->assertCount(3, $this->sanitize($this->rowField(['max' => 20]), $rows));
        $this->assertCount(2, $this->sanitize($this->rowField(['max' => 2]), $rows));
        $this->assertSame(3, RepeaterType::maxRows($this->rowField(['max' => 20])));
    }

    public function testRepeaterNormalizeCapsForEveryUser(): void
    {
        $rows = array_fill(0, 600, ['title' => '<i>a</i>']);
        $out = $this->normalize($this->rowField(), $rows);
        $this->assertCount(500, $out);
        $this->assertSame('<i>a</i>', $out[0]['title'], 'normalize does not filter content');
    }

    public function testRepeaterTenThousandRowsStayBounded(): void
    {
        $out = $this->sanitize($this->rowField(), array_fill(0, 10000, ['title' => 'a']));
        $this->assertCount(500, $out);
        $this->assertCount(500, $this->escape($this->rowField(), array_fill(0, 10000, ['title' => 'a'])));
    }

    public function testRepeaterDropsNonArrayRows(): void
    {
        $this->assertSame([['title' => 'a', 'link' => '']], $this->sanitize($this->rowField(), ['junk', 5, ['title' => 'a']]));
        $this->assertSame([], $this->sanitize($this->rowField(), 'nope'));
    }

    public function testFlexibleDropsUndeclaredLayout(): void
    {
        $layouts = [
            ['key' => 'l1', 'name' => 'hero', 'label' => 'Hero', 'subFields' => [self::field('text', [], [], [], 'heading')]],
            ['key' => 'l2', 'name' => 'cta', 'label' => 'Cta', 'subFields' => [self::field('url', [], [], [], 'href')]],
        ];
        $f = self::field('flexible_content', [], [], $layouts);
        $out = $this->sanitize($f, [
            ['layout' => 'hero', 'heading' => '<b>H</b>', 'href' => 'https://nope.test'],
            ['layout' => 'evil', 'heading' => 'x'],
            ['heading' => 'no layout'],
            ['layout' => ['hero']],
            ['layout' => 'cta', 'href' => 'javascript:1'],
        ]);
        $this->assertSame([['layout' => 'hero', 'heading' => 'H'], ['layout' => 'cta', 'href' => '']], $out);
    }

    public function testGroupEscapeRecurses(): void
    {
        $f = self::field('group', [], [self::field('wysiwyg', [], [], [], 'body'), self::field('url', [], [], [], 'link')]);
        $out = $this->escape($f, ['body' => '<p>a</p><script>b</script>', 'link' => 'javascript:alert(1)', 'extra' => 'x']);
        $this->assertSame(['body' => '<p>a</p>b', 'link' => ''], $out);
    }

    public function testUntrustedSanitizeOfNestedCodeReturnsNullTrustedKeepsValue(): void
    {
        $inner = [self::field('text', [], [], [], 't'), self::field('code', [], [], [], 'snippet')];
        $group = self::field('group', [], $inner);
        $in = ['t' => 'a', 'snippet' => '<script>evil()</script>'];
        $this->assertNull($this->sanitize($group, $in));
        $this->assertSame($in, $this->normalize($group, $in));
        $this->assertTrue($this->registry->for($group)->hasUnfilteredHtml($group));

        $nested = self::field('repeater', [], [self::field('group', [], $inner, [], 'x')], [], 'rows');
        $rows = [['x' => $in]];
        $this->assertNull($this->sanitize($nested, $rows));
        $this->assertSame($rows, $this->normalize($nested, $rows));
        $this->assertTrue($this->registry->for($nested)->hasUnfilteredHtml($nested));

        $flex = self::field('flexible_content', [], [], [['key' => 'l', 'name' => 'c', 'label' => 'C', 'subFields' => $inner]]);
        $this->assertNull($this->sanitize($flex, [['layout' => 'c'] + $in]));
        $this->assertSame([['layout' => 'c'] + $in], $this->normalize($flex, [['layout' => 'c'] + $in]));

        $clean = self::field('repeater', [], [self::field('text', [], [], [], 't')]);
        $this->assertFalse($this->registry->for($clean)->hasUnfilteredHtml($clean));
        $this->assertSame([['t' => 'a']], $this->sanitize($clean, [['t' => 'a']]));
    }

    public function testLeafHasUnfilteredHtmlFollowsRequiresUnfilteredHtml(): void
    {
        foreach (['text', 'image', 'link', 'code'] as $t) {
            $f = self::field($t);
            $type = $this->registry->for($f);
            $this->assertSame($type->requiresUnfilteredHtml(), $type->hasUnfilteredHtml($f), $t);
        }
    }

    public function testNestedRowsShareOneBudget(): void
    {
        $inner = self::field('repeater', [], [self::field('text', [], [], [], 'v')], [], 'inner');
        $outer = self::field('repeater', [], [$inner], [], 'outer');
        $rows = array_fill(0, 50, ['inner' => array_fill(0, 50, ['v' => 'a'])]);
        foreach (['sanitize', 'normalize', 'escape'] as $mode) {
            $out = $this->registry->for($outer)->$mode($rows, $outer);
            $total = count($out);
            foreach ($out as $row) {
                $total += count($row['inner']);
            }
            $this->assertSame(500, $total, $mode);
        }
        $GLOBALS['pb_test_filters']['proto_blocks_repeater_max_items'][] = static fn() => 10;
        $out = $this->sanitize($outer, $rows);
        $this->assertSame(10, count($out) + array_sum(array_map(static fn($r) => count($r['inner']), $out)));
    }

    public function testNestedTenThousandRowPayloadIsBounded(): void
    {
        $inner = self::field('repeater', [], [self::field('text', [], [], [], 'v')], [], 'inner');
        $outer = self::field('repeater', [], [$inner], [], 'outer');
        $rows = array_fill(0, 100, ['inner' => array_fill(0, 100, ['v' => 'a'])]);
        $out = $this->sanitize($outer, $rows);
        $this->assertLessThanOrEqual(500, count($out) + array_sum(array_map(static fn($r) => count($r['inner']), $out)));
    }

    public function testImageEscapeShape(): void
    {
        $GLOBALS['pb_test_attachments'][7] = [
            'url' => 'https://x.test/a.jpg"onerror=1', 'width' => 800, 'height' => 600,
            'metadata' => ['sizes' => ['thumbnail' => []]],
        ];
        $GLOBALS['pb_test_meta']['post'][7]['_wp_attachment_image_alt'] = ['a "q"'];
        $f = self::field('image');
        $out = $this->escape($f, 7);
        $this->assertSame(['id', 'url', 'alt', 'width', 'height', 'sizes'], array_keys($out));
        $this->assertSame('https://x.test/a.jpgonerror=1', $out['url']);
        $this->assertSame('a &quot;q&quot;', $out['alt']);
        $this->assertSame(['thumbnail'], array_keys($out['sizes']));
        $this->assertSame(['url', 'width', 'height'], array_keys($out['sizes']['thumbnail']));
        $this->assertNull($this->escape($f, 99));
        $this->assertNull($this->escape($f, 0));
        $this->assertSame(0, $this->sanitize($f, -4));
        $this->assertSame(7, $this->sanitize($f, '7'));
        $this->assertSame(['type' => 'integer'], $this->registry->for($f)->restSchema($f));
    }

    public function testFileEscapeShape(): void
    {
        $GLOBALS['pb_test_attachments'][8] = ['url' => 'https://x.test/up/report.pdf', 'mime' => 'application/pdf'];
        $out = $this->escape(self::field('file'), 8);
        $this->assertSame(['id', 'url', 'filename', 'mime', 'size'], array_keys($out));
        $this->assertSame('report.pdf', $out['filename']);
        $this->assertNull($this->escape(self::field('file'), 9));
    }

    public function testVideoIdOrUrl(): void
    {
        $f = self::field('video');
        $this->assertSame(12, $this->sanitize($f, '12'));
        $this->assertSame('https://v.test/a.mp4', $this->sanitize($f, ' https://v.test/a.mp4 '));
        $this->assertSame('', $this->sanitize($f, 'javascript:alert(1)'));
        $this->assertSame(['url' => 'https://v.test/a.mp4'], $this->escape($f, 'https://v.test/a.mp4'));
        $this->assertNull($this->escape($f, 'javascript:alert(1)'));
        $GLOBALS['pb_test_attachments'][12] = ['url' => 'https://x.test/v.mp4', 'mime' => 'video/mp4'];
        $GLOBALS['pb_test_attachments'][13] = ['url' => 'https://x.test/i.jpg', 'mime' => 'image/jpeg'];
        $this->assertNull($this->escape($f, 13), 'non-video attachment is not exposed');
        $this->assertSame(['id' => 12, 'url' => 'https://x.test/v.mp4'], $this->escape($f, 12));
        $this->assertSame(['type' => ['integer', 'string']], $this->registry->for($f)->restSchema($f));
    }

    public function testTaxonomyDropsTermsOfOtherTaxonomies(): void
    {
        $GLOBALS['pb_test_terms'] = [
            3 => ['term_id' => 3, 'taxonomy' => 'topic', 'name' => 'A & B', 'slug' => 'a-b'],
            4 => ['term_id' => 4, 'taxonomy' => 'secret', 'name' => 'S', 'slug' => 's'],
        ];
        $f = self::field('taxonomy', ['taxonomy' => 'topic']);
        $this->assertSame([3, 4], $this->sanitize($f, [3, 4, 3, -2]));
        $this->assertSame(
            [['id' => 3, 'name' => 'A &amp; B', 'slug' => 'a-b', 'url' => 'https://x.test/term/a-b']],
            $this->escape($f, [3, 4])
        );
        $this->assertSame([], $this->escape(self::field('taxonomy'), [3]));
        $this->assertSame(500, $this->registry->for($f)->restSchema($f)['maxItems']);
        $capped = self::field('taxonomy', ['taxonomy' => 'topic', 'max' => 2]);
        $this->assertSame(2, $this->registry->for($capped)->restSchema($capped)['maxItems']);
    }

    public function testRelationshipHidesUnreadablePosts(): void
    {
        $GLOBALS['pb_test_posts'] = [
            3 => ['ID' => 3, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Open <b>'],
            4 => ['ID' => 4, 'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Hidden'],
            5 => ['ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page'],
        ];
        $f = self::field('relationship');
        $out = $this->escape($f, [3, 4]);
        $this->assertSame([['id' => 3, 'title' => 'Open &lt;b&gt;', 'url' => 'https://x.test/?p=3', 'type' => 'post']], $out);

        $GLOBALS['pb_test_current_user'] = 9;
        $GLOBALS['pb_test_caps'][9] = ['read_post'];
        $this->assertSame([3, 4], array_column($this->escape($f, [3, 4]), 'id'));

        $this->assertSame([4, 3], $this->sanitize($f, [4, 3, '4']), 'order kept');
        $this->assertSame([3], array_column($this->escape(self::field('relationship', ['post_type' => ['post']]), [3, 5]), 'id'));
    }

    public function testRelationshipSingleReturnsItemOrNull(): void
    {
        $GLOBALS['pb_test_posts'][3] = ['ID' => 3, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'T'];
        $f = self::field('relationship', ['max' => 1]);
        $this->assertSame(3, $this->escape($f, [3])['id']);
        $this->assertNull($this->escape($f, [77]));
        $this->assertSame([3], $this->sanitize($f, [3, 4]));
    }

    public function testGalleryUniquePositiveCapped(): void
    {
        $f = self::field('gallery', ['max' => 1]);
        $this->assertSame([5], $this->sanitize($f, [5, '5', -1, 'x', 6]));
        $all = self::field('gallery');
        $this->assertSame([5, 6], $this->sanitize($all, [5, '5', -1, 'x', 6]));
        $this->assertSame([5, 6], $this->normalize($all, [5, '5', -1, 'x', 6]));
        $this->assertSame([], $this->sanitize($all, 'junk'));
        $this->assertSame([5], $this->sanitize(self::field('gallery', ['max' => 1]), array_merge([5], range(100, 5000))));
    }

    public function testRestSchemaRepeater(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_repeater_max_items'][] = static fn() => 7;
        $f = $this->rowField();
        $schema = $this->registry->for($f)->restSchema($f);
        $this->assertSame('array', $schema['type']);
        $this->assertSame(RepeaterType::maxRows($f), $schema['maxItems']);
        $this->assertSame(7, $schema['maxItems']);
        $this->assertFalse($schema['items']['additionalProperties']);
        $this->assertSame(['title', 'link'], array_keys($schema['items']['properties']));
    }

    public function testRestSchemaGroupAndFlexible(): void
    {
        $g = self::field('group', [], [self::field('text', [], [], [], 'a')]);
        $s = $this->registry->for($g)->restSchema($g);
        $this->assertSame('object', $s['type']);
        $this->assertFalse($s['additionalProperties']);

        $layouts = [['key' => 'l', 'name' => 'hero', 'label' => 'H', 'subFields' => [self::field('text', [], [], [], 'h')]]];
        $fx = self::field('flexible_content', [], [], $layouts);
        $s = $this->registry->for($fx)->restSchema($fx);
        $this->assertSame('hero', $s['items']['oneOf'][0]['properties']['layout']['const']);
        $this->assertFalse($s['items']['oneOf'][0]['additionalProperties']);
    }

    public function testEveryNewTypeIsRegistered(): void
    {
        foreach (['image', 'file', 'gallery', 'video', 'taxonomy', 'relationship', 'group', 'repeater', 'flexible_content'] as $t) {
            $this->assertSame([$t], array_values(array_intersect([$t], $this->registry->for(self::field($t))->names())));
            $this->assertTrue($this->registry->for(self::field($t))->stores());
            $this->assertFalse($this->registry->for(self::field($t))->requiresUnfilteredHtml());
        }
    }
}
