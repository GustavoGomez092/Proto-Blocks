<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

require_once __DIR__ . '/../Security/wp-stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\API\AjaxHandler;

/**
 * AjaxHandler::sanitizePreviewAttributes() is the single sanitiser shared by
 * the admin-ajax preview and the REST /preview route.
 */
final class PreviewAttributeSanitizationTest extends TestCase
{
    private const IMG_PAYLOAD = '<img src=x onerror=alert(1)>';

    private function schema(): array
    {
        return ['protoBlocks' => ['fields' => ['title' => 'text', 'content' => 'wysiwyg', 'link' => 'link']]];
    }

    protected function setUp(): void
    {
        $GLOBALS['pb_test_caps']         = [7 => ['unfiltered_html']];
        $GLOBALS['pb_test_current_user'] = 0;
    }

    public function test_user_without_unfiltered_html_gets_kses_plus_type_keyed_pass(): void
    {
        $GLOBALS['pb_test_current_user'] = 8;

        $out = AjaxHandler::sanitizePreviewAttributes([
            'title'   => 'T ' . self::IMG_PAYLOAD,
            'content' => '<p>C</p><script>x</script>',
            'link'    => ['url' => 'javascript:alert(1)'],
            'bad key' => 'v',
            'n'       => 2,
            'b'       => true,
        ], $this->schema());

        $this->assertSame('T ', $out['title'], 'text field: img stripped entirely');
        $this->assertSame('<p>C</p>x', $out['content']);
        $this->assertSame('', $out['link']['url'], 'javascript: url emptied');
        $this->assertArrayHasKey('badkey', $out, 'key filtering from the AJAX path is preserved');
        $this->assertSame(2, $out['n']);
        $this->assertTrue($out['b']);
    }

    public function test_user_with_unfiltered_html_still_gets_wp_kses_post(): void
    {
        $GLOBALS['pb_test_current_user'] = 7;

        $out = AjaxHandler::sanitizePreviewAttributes([
            'title' => 'T ' . self::IMG_PAYLOAD,
            'link'  => ['url' => 'javascript:alert(1)'],
        ], $this->schema());

        $this->assertSame('T <img src="x">', $out['title'], 'kses_post keeps img, drops the handler');
        $this->assertSame('javascript:alert(1)', $out['link']['url'], 'unfiltered_html: the type-keyed pass is skipped');
    }

    public function test_no_user_is_treated_as_unprivileged(): void
    {
        $out = AjaxHandler::sanitizePreviewAttributes(['title' => self::IMG_PAYLOAD], $this->schema());

        $this->assertSame('', $out['title']);
    }

    public function test_missing_proto_config_still_sanitises_by_key_and_type(): void
    {
        $out = AjaxHandler::sanitizePreviewAttributes(['x' => self::IMG_PAYLOAD, 'url' => 'javascript:1'], []);

        $this->assertSame('', $out['x']);
        $this->assertSame('', $out['url']);
    }
}
