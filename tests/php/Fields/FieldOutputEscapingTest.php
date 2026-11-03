<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Fields;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Controls\Registry as ControlRegistry;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Fields\AbstractField;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Fields\Types\InnerBlocksField;
use ProtoBlocks\Fields\Types\RepeaterField;
use ProtoBlocks\Template\Renderer;


/**
 * The PHP field layer receives block attribute JSON exactly as a contributor
 * saved it (nothing on the save path runs FieldInterface::sanitize()), and the
 * legacy proto-edit / proto-repeater binding hands that JSON to the field
 * classes for DOM insertion. These tests drive the real Renderer against a
 * fixture using that binding with the audit's payloads, and pin that every
 * field makes its own value inert before it reaches the DOM.
 */
final class FieldOutputEscapingTest extends TestCase
{
    // Payloads exactly as the audit report quotes them (JSON, decoded below).
    private const F1_LINK_TEXT_JSON    = '{"link":{"url":"https://example.com","text":"<img src=x onerror=alert(document.cookie)>"}}';
    private const F2_INNER_BLOCKS_JSON = '{"content":"<svg onload=alert(1)><script>alert(2)</script>"}';
    private const F5_REL_OPENER_JSON   = '{"link":{"url":"https://example.com","target":"_blank","rel":"opener"}}';
    private const F6_VIDEO_MIME_JSON   = '{"video":{"url":"https://example.com/clip.mp4","mime":"\" onmouseover=\"alert(1)"}}';

    protected function setUp(): void
    {
        $GLOBALS['pb_test_filters'] = [];

        // Guard against another test file stubbing wp_kses_post() as identity:
        // every assertion below would then be vacuous.
        $this->assertNotSame('<script>x</script>', wp_kses_post('<script>x</script>'), 'wp_kses_post() stub must strip tags');
    }

    protected function tearDown(): void
    {
        $GLOBALS['pb_test_filters'] = [];
    }

    private static function registry(): FieldRegistry
    {
        // RepeaterField reaches the registry through the Plugin singleton, so
        // the core field types must be registered there, not on a throwaway
        // instance. registerCoreFieldTypes() touches no WordPress functions.
        $plugin   = Plugin::getInstance();
        $registry = $plugin->getFieldRegistry();
        if (!$registry->has('link')) {
            (new \ReflectionMethod(Plugin::class, 'registerCoreFieldTypes'))->invoke($plugin);
        }

        return $registry;
    }

    private function render(array $attributes): string
    {
        $renderer = new Renderer(self::registry(), new ControlRegistry());

        return $renderer->render(__DIR__ . '/fixtures/fields-block.php', $attributes, []);
    }

    /** Re-parse rendered output the way a browser would. */
    private function parse(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function element(DOMXPath $xpath, string $query): DOMElement
    {
        $node = $xpath->query($query)->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, "expected an element for {$query}");

        return $node;
    }

    private static function json(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function freshElement(string $html = '<div id="t">old</div>'): DOMElement
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        return $dom->getElementById('t');
    }

    // ---- F1: link text --------------------------------------------------

    public function test_f1_link_text_payload_cannot_become_an_img_element(): void
    {
        $html = $this->render(self::json(self::F1_LINK_TEXT_JSON));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror', $html);

        $xpath = $this->parse($html);
        $this->assertSame(0, $xpath->query('//img')->length);
        $this->assertSame('https://example.com', $this->element($xpath, '//a[@class="link"]')->getAttribute('href'));
    }

    public function test_f1_link_text_keeps_inline_formatting_but_not_block_or_script_markup(): void
    {
        $html = $this->render(['link' => [
            'url'  => 'https://example.com',
            'text' => '<strong>Bold</strong> <em>em</em> <script>alert(1)</script><div>block</div><img src=x onerror=alert(1)>',
        ]]);

        $xpath = $this->parse($html);
        $link  = $this->element($xpath, '//a[@class="link"]');

        $this->assertSame(1, $xpath->query('.//strong', $link)->length);
        $this->assertSame(1, $xpath->query('.//em', $link)->length);
        $this->assertSame(0, $xpath->query('.//script', $link)->length);
        $this->assertSame(0, $xpath->query('.//div', $link)->length, 'block-level markup is not link text');
        $this->assertSame(0, $xpath->query('.//img', $link)->length);
        $this->assertStringContainsString('Bold', $link->textContent);
    }

    public function test_f1_link_text_with_an_ampersand_and_a_stray_bracket_is_not_double_encoded(): void
    {
        $html = $this->render(['link' => ['url' => 'https://example.com', 'text' => 'Fish & Chips <3']]);

        $xpath = $this->parse($html);
        $this->assertSame('Fish & Chips <3', $this->element($xpath, '//a[@class="link"]')->textContent);
        $this->assertSame(0, $xpath->query('//a[@class="link"]/*')->length, 'no child elements');

        // No markup at all: must take the text-node path, where kses's "&amp;"
        // normalisation would otherwise be encoded a second time.
        $html = $this->render(['link' => ['url' => 'https://example.com', 'text' => 'Terms & Conditions']]);
        $this->assertStringContainsString('>Terms &amp; Conditions<', $html);
        $this->assertSame('Terms & Conditions', $this->element($this->parse($html), '//a[@class="link"]')->textContent);
    }

    // ---- F2: inner blocks ------------------------------------------------

    public function test_f2_inner_blocks_attribute_fallback_is_filtered(): void
    {
        $html = $this->render(self::json(self::F2_INNER_BLOCKS_JSON));

        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onload', $html);

        $xpath = $this->parse($html);
        $this->assertSame(0, $xpath->query('//svg')->length);
        $this->assertSame(0, $xpath->query('//script')->length);
    }

    public function test_f2_untrusted_inner_blocks_value_is_filtered_even_when_called_directly(): void
    {
        $el = $this->freshElement();
        InnerBlocksField::updateElement($el, '<p>ok</p><iframe src="https://evil.example"></iframe><script>alert(1)</script>');

        $out = $el->ownerDocument->saveHTML($el);
        $this->assertStringContainsString('<p>ok</p>', $out);
        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function test_f2_renderer_can_vouch_for_core_rendered_inner_block_markup(): void
    {
        // The trusted path (WordPress-rendered $content, e.g. an embed block's
        // iframe) is preserved only when the caller says so explicitly.
        $el = $this->freshElement();
        InnerBlocksField::updateElement($el, '<figure><iframe src="https://www.youtube.com/embed/x"></iframe></figure>', ['trustedInnerContent' => true]);

        $this->assertStringContainsString('<iframe src="https://www.youtube.com/embed/x">', $el->ownerDocument->saveHTML($el));
    }

    // ---- F3 / F7: the shared helper is safe by default ---------------------

    public function test_f3_set_html_content_filters_markup_unless_told_it_is_trusted(): void
    {
        $method = new \ReflectionMethod(AbstractField::class, 'setHtmlContent');
        $method->setAccessible(true);

        $el = $this->freshElement();
        $method->invoke(null, $el, '<img src=x onerror=alert(1)><script>alert(2)</script><b>b</b>');
        $out = $el->ownerDocument->saveHTML($el);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('<b>b</b>', $out);

        $el = $this->freshElement();
        $method->invoke(null, $el, '<script>alert(2)</script>', true);
        $this->assertStringContainsString('<script>alert(2)</script>', $el->ownerDocument->saveHTML($el));
    }

    public function test_f3_set_html_content_plain_text_path_is_unchanged(): void
    {
        $method = new \ReflectionMethod(AbstractField::class, 'setHtmlContent');
        $method->setAccessible(true);

        $el = $this->freshElement();
        $method->invoke(null, $el, 'a &lt;b&gt; & c');
        $this->assertSame('<div id="t">a &amp;lt;b&amp;gt; &amp; c</div>', $el->ownerDocument->saveHTML($el));

        $el = $this->freshElement();
        $method->invoke(null, $el, '0');
        $this->assertSame('0', $el->textContent, 'the string "0" is content, not emptiness');
    }

    // ---- F4: repeater cap ----------------------------------------------

    public function test_f4_repeater_renders_at_most_the_default_cap(): void
    {
        $items = array_fill(0, RepeaterField::DEFAULT_MAX_ITEMS + 700, []);
        $html  = $this->render(['items' => $items]);

        $this->assertSame(RepeaterField::DEFAULT_MAX_ITEMS, $this->parse($html)->query('//li[@class="item"]')->length);
    }

    public function test_f4_repeater_cap_counts_rendered_items_and_skips_non_objects(): void
    {
        $this->assertTrue(defined('PB_FIELDS_TEST_APPLY_FILTERS'), 'another stub of apply_filters() is active; consolidate it in bootstrap.php');
        $GLOBALS['pb_test_filters']['proto_blocks_repeater_max_items'][] = function ($max, $field, $config) {
            $this->assertSame(RepeaterField::DEFAULT_MAX_ITEMS, $max);
            $this->assertSame('items', $field);
            $this->assertIsArray($config);

            return 2;
        };

        $html  = $this->render(['items' => ['junk', ['title' => 'one'], null, ['title' => 'two'], ['title' => 'three']]]);
        $xpath = $this->parse($html);

        $this->assertSame(2, $xpath->query('//li[@class="item"]')->length);
        $titles = array_map(static fn ($n) => $n->textContent, iterator_to_array($xpath->query('//span[@class="item-title"]')));
        $this->assertSame(['one', 'two'], $titles);
    }

    public function test_f4_field_config_max_wins_over_the_default(): void
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"?><ul id="t" proto-repeater="rows"><li><span proto-edit="title">T</span></li></ul>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $ul = $dom->getElementById('t');
        self::registry();

        RepeaterField::updateElement($ul, array_fill(0, 50, ['title' => 'x']), ['max' => 3, 'fields' => []]);

        $this->assertSame(3, $ul->getElementsByTagName('li')->length);
    }

    public function test_f4_large_item_count_completes_and_is_truncated(): void
    {
        // The DoS PoC shape: thousands of empty objects, each of which would
        // otherwise clone the whole item template.
        $html = $this->render(['items' => array_fill(0, 20000, [])]);

        $this->assertSame(RepeaterField::DEFAULT_MAX_ITEMS, substr_count($html, '<li class="item">'));
    }

    // ---- nested repeater fields go through the same field classes ---------

    public function test_link_text_inside_a_repeater_item_is_filtered_too(): void
    {
        $html = $this->render(['items' => [[
            'title' => 'ok',
            'link'  => ['url' => 'https://example.com', 'text' => '<img src=x onerror=alert(1)>', 'target' => '_blank', 'rel' => 'opener'],
        ]]]);

        $xpath = $this->parse($html);
        $this->assertSame(0, $xpath->query('//img')->length);
        $link = $this->element($xpath, '//a[@class="item-link"]');
        $tokens = explode(' ', $link->getAttribute('rel'));
        $this->assertContains('noopener', $tokens);
        $this->assertNotContains('opener', $tokens);
    }

    // ---- F5: rel / target ------------------------------------------------

    public function test_f5_blank_target_always_gets_noopener_and_drops_opener(): void
    {
        $html = $this->render(self::json(self::F5_REL_OPENER_JSON));
        $link = $this->element($this->parse($html), '//a[@class="link"]');

        $tokens = explode(' ', $link->getAttribute('rel'));
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertContains('noopener', $tokens);
        $this->assertNotContains('opener', $tokens);
    }

    public function test_f5_noopener_is_merged_into_an_existing_rel_not_substituted(): void
    {
        $html = $this->render(['link' => ['url' => 'https://example.com', 'target' => '_blank', 'rel' => 'nofollow sponsored']]);
        $link = $this->element($this->parse($html), '//a[@class="link"]');

        $this->assertSame('nofollow sponsored noopener', $link->getAttribute('rel'));
    }

    public function test_f5_blank_target_with_no_rel_keeps_the_noopener_noreferrer_default(): void
    {
        $html = $this->render(['link' => ['url' => 'https://example.com', 'target' => '_blank']]);
        $link = $this->element($this->parse($html), '//a[@class="link"]');

        $this->assertSame('noopener noreferrer', $link->getAttribute('rel'));
    }

    public function test_f5_rel_is_reduced_to_lowercase_letter_tokens_and_target_is_allowlisted(): void
    {
        $html = $this->render(['link' => ['url' => 'https://example.com', 'target' => 'evilwindow', 'rel' => "NoFollow onclick=\"alert(1)\" x=1 opener"]]);
        $link = $this->element($this->parse($html), '//a[@class="link"]');

        $this->assertFalse($link->hasAttribute('target'), 'unknown target names are dropped');
        $this->assertFalse($link->hasAttribute('onclick'));
        $this->assertMatchesRegularExpression('/^[a-z-]+( [a-z-]+)*$/', $link->getAttribute('rel'));
        $this->assertSame('nofollow', $link->getAttribute('rel'), 'only well-formed tokens survive; opener and attribute-shaped junk are dropped');
    }

    // ---- F6: video mime --------------------------------------------------

    public function test_f6_video_mime_payload_never_reaches_the_type_attribute(): void
    {
        $html = $this->render(self::json(self::F6_VIDEO_MIME_JSON));

        $this->assertStringNotContainsString('onmouseover', $html);

        $source = $this->element($this->parse($html), '//source');
        $this->assertSame('https://example.com/clip.mp4', $source->getAttribute('src'));
        $this->assertFalse($source->hasAttribute('type'));
    }

    public function test_f6_video_mime_must_be_a_video_type(): void
    {
        $html = $this->render(['video' => ['url' => 'https://example.com/clip.mp4', 'mime' => 'text/html']]);
        $this->assertFalse($this->element($this->parse($html), '//source')->hasAttribute('type'));

        $html = $this->render(['video' => ['url' => 'https://example.com/clip.mp4', 'mime' => 'Video/MP4']]);
        $this->assertSame('video/mp4', $this->element($this->parse($html), '//source')->getAttribute('type'));

        $html = $this->render(['video' => ['url' => 'https://example.com/clip.mp4', 'mime' => ['video/mp4']]]);
        $this->assertFalse($this->element($this->parse($html), '//source')->hasAttribute('type'), 'non-string mime is ignored, not fatal');
    }
}
