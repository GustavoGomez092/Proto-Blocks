<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Security;

require_once __DIR__ . '/wp-stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * The plugin's attribute trust boundary: stored block-comment JSON authored
 * by a user without `unfiltered_html` is sanitised by declared field type.
 * See tests/php/Security/wp-stubs.php for what the kses emulation covers.
 */
final class AttributeSanitizerTest extends TestCase
{
    private const IMG_PAYLOAD = '<img src=x onerror=alert(1)>';

    /** A hero-like protoBlocks config as SchemaReader normalises it. */
    private function config(): array
    {
        return [
            'fields' => [
                'title'   => ['type' => 'text'],
                'content' => ['type' => 'wysiwyg'],
                'tagline' => 'text',                               // string shorthand (raw block.json)
                'rich'    => ['type' => 'text', 'format' => 'full'],
                'link'    => ['type' => 'link'],
                'image'   => ['type' => 'image'],
                'items'   => [
                    'type'   => 'repeater',
                    'fields' => [
                        'label' => ['type' => 'text'],
                        'body'  => ['type' => 'wysiwyg'],
                        'cta'   => ['type' => 'link'],
                    ],
                ],
            ],
            'controls' => [
                'columns' => ['type' => 'number'],
                'note'    => ['type' => 'textarea'],
            ],
        ];
    }

    protected function setUp(): void
    {
        $GLOBALS['pb_test_caps'] = [];
    }

    public function test_img_onerror_payload_in_text_field_is_removed(): void
    {
        $out = AttributeSanitizer::sanitize(['title' => 'Hello ' . self::IMG_PAYLOAD . ' world'], $this->config());

        $this->assertSame('Hello  world', $out['title']);
    }

    public function test_img_payload_in_undeclared_string_is_removed(): void
    {
        $out = AttributeSanitizer::sanitize(['_unknownKey' => '<svg onload=alert(1)>x</svg>' . self::IMG_PAYLOAD], $this->config());

        $this->assertSame('x', $out['_unknownKey']);
    }

    public function test_text_field_keeps_inline_formatting_but_drops_block_markup(): void
    {
        $out = AttributeSanitizer::sanitize(
            ['title' => '<p>Hi <strong>there</strong> <a href="https://x.test" onclick="x()">go</a></p>'],
            $this->config()
        );

        $this->assertSame('Hi <strong>there</strong> <a href="https://x.test">go</a>', $out['title']);
    }

    public function test_wysiwyg_field_keeps_paragraphs_and_strong(): void
    {
        $html = '<p>Hello <strong>world</strong></p>';
        $out  = AttributeSanitizer::sanitize(['content' => $html . '<script>alert(1)</script>'], $this->config());

        $this->assertSame($html . 'alert(1)', $out['content']);
    }

    public function test_text_field_with_full_format_is_treated_like_wysiwyg(): void
    {
        $html = 'Hi <img src="https://x.test/a.png" alt="a">';
        $out  = AttributeSanitizer::sanitize(['rich' => $html, 'title' => $html], $this->config());

        $this->assertSame($html, $out['rich'], 'format:full text fields may carry the inline-image format');
        $this->assertSame('Hi ', $out['title'], 'default text fields may not');
    }

    public function test_javascript_url_in_link_object_is_emptied(): void
    {
        $out = AttributeSanitizer::sanitize(
            ['link' => ['url' => 'javascript:alert(1)', 'text' => 'Go', 'target' => '_blank']],
            $this->config()
        );

        $this->assertSame('', $out['link']['url']);
        $this->assertSame('Go', $out['link']['text']);
        $this->assertSame('_blank', $out['link']['target']);
    }

    public function test_url_keys_are_normalised_wherever_they_appear(): void
    {
        $out = AttributeSanitizer::sanitize([
            'image' => ['url' => 'https://x.test/a.png', 'alt' => '<b>alt</b>', 'sizes' => ['thumb' => ['url' => 'data:text/html,x']]],
            'video' => ['src' => ' javascript:void(0)', 'poster' => 'https://x.test/p.jpg'],
            'href'  => 'vbscript:x',
        ], $this->config());

        $this->assertSame('https://x.test/a.png', $out['image']['url']);
        $this->assertSame('<b>alt</b>', $out['image']['alt']);
        $this->assertSame('', $out['image']['sizes']['thumb']['url']);
        $this->assertSame('', $out['video']['src']);
        $this->assertSame('https://x.test/p.jpg', $out['video']['poster']);
        $this->assertSame('', $out['href']);
    }

    public function test_nested_repeater_items_are_sanitised_by_sub_field_type(): void
    {
        $out = AttributeSanitizer::sanitize([
            'items' => [
                ['label' => 'A ' . self::IMG_PAYLOAD, 'body' => '<p>ok</p>' . self::IMG_PAYLOAD, 'cta' => ['url' => 'javascript:1']],
                ['label' => '<em>B</em>', 'extra' => ['deep' => ['deeper' => self::IMG_PAYLOAD]]],
                'not-a-row <b>x</b>',
            ],
        ], $this->config());

        $this->assertSame('A ', $out['items'][0]['label']);
        $this->assertSame('<p>ok</p><img src="x">', $out['items'][0]['body'], 'wysiwyg keeps img but loses the handler');
        $this->assertSame('', $out['items'][0]['cta']['url']);
        $this->assertSame('<em>B</em>', $out['items'][1]['label']);
        $this->assertSame('', $out['items'][1]['extra']['deep']['deeper']);
        $this->assertSame('not-a-row <b>x</b>', $out['items'][2]);
    }

    public function test_rel_and_target_are_normalised(): void
    {
        $out = AttributeSanitizer::sanitize([
            'link'  => ['rel' => 'Noopener  nofollow "onx', 'target' => 'javascript:x'],
            'other' => ['rel' => 'sponsored', 'target' => '_top'],
        ], $this->config());

        $this->assertSame('noopener nofollow', $out['link']['rel']);
        $this->assertSame('', $out['link']['target']);
        $this->assertSame('sponsored', $out['other']['rel']);
        $this->assertSame('_top', $out['other']['target']);
    }

    public function test_anchor_is_restricted_and_classname_is_left_to_the_renderer(): void
    {
        $out = AttributeSanitizer::sanitize([
            'anchor'    => 'a" onmouseover="alert(1)',
            'className' => 'x"><script>',
        ], $this->config());

        $this->assertSame('aonmouseoveralert1', $out['anchor']);
        $this->assertSame('x"><script>', $out['className']);
    }

    public function test_numbers_booleans_and_null_are_untouched(): void
    {
        $in  = ['columns' => 3, 'ratio' => 1.5, 'on' => true, 'off' => false, 'none' => null, 'numericString' => '42'];
        $out = AttributeSanitizer::sanitize($in, $this->config());

        $this->assertSame($in, $out);
    }

    public function test_sanitize_is_idempotent(): void
    {
        $in = [
            'title'   => 'a <b>b</b> ' . self::IMG_PAYLOAD . ' & c < d',
            'content' => '<p>x</p><script>y</script>',
            'link'    => ['url' => 'javascript:1', 'rel' => 'A B', 'target' => 'x'],
            'items'   => [['label' => self::IMG_PAYLOAD, 'cta' => ['url' => 'x.test/p']]],
            'anchor'  => 'a b',
        ];

        $once  = AttributeSanitizer::sanitize($in, $this->config());
        $twice = AttributeSanitizer::sanitize($once, $this->config());

        $this->assertSame($once, $twice);
    }

    /**
     * Documented behaviour: a bare "<" that does not open a tag is entity
     * encoded by kses (wp_pre_kses_less_than), exactly as core does for
     * post_content written by a contributor. Templates that esc_html() the
     * value will emit "&amp;lt;" -- i.e. the visible text "&lt;" -- so this
     * is a release-note item, not a bug in the sanitizer.
     */
    public function test_bare_less_than_in_plain_string_becomes_entity(): void
    {
        $out = AttributeSanitizer::sanitize(['title' => 'a < b'], $this->config());

        $this->assertSame('a &lt; b', $out['title']);
    }

    public function test_depth_cap_drops_runaway_nesting_instead_of_passing_it_through(): void
    {
        $value = self::IMG_PAYLOAD;
        for ($i = 0; $i < AttributeSanitizer::MAX_DEPTH + 2; $i++) {
            $value = ['n' => $value];
        }

        $out = AttributeSanitizer::sanitize(['deep' => $value], $this->config());

        $this->assertStringNotContainsString('onerror', json_encode($out));
    }

    public function test_should_sanitize_for_user_mirrors_unfiltered_html(): void
    {
        $GLOBALS['pb_test_caps'] = [7 => ['unfiltered_html'], 8 => ['edit_posts']];

        $this->assertTrue(AttributeSanitizer::shouldSanitizeForUser(0), 'no user never has unfiltered_html');
        $this->assertFalse(AttributeSanitizer::shouldSanitizeForUser(7));
        $this->assertTrue(AttributeSanitizer::shouldSanitizeForUser(8));
    }
}
