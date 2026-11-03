<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Template;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Template\TemplateHelper;
use ProtoBlocks\Tests\Modules\Fields\FieldReaderTest;

require_once __DIR__ . '/../Modules/Fields/stubs/Structured.php';
require_once __DIR__ . '/../Modules/Fields/stubs/Read.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('proto_blocks_sanitize_color')) {
    require_once __DIR__ . '/../../../includes/helpers.php';
}

final class TemplateHelperTest extends TestCase
{
    protected function setUp(): void
    {
        FieldReaderTest::seed();
        FieldReader::setShared(FieldReaderTest::reader());
    }

    protected function tearDown(): void
    {
        FieldReader::setShared(null);
        unset($GLOBALS['pb_test_current_post'], $GLOBALS['pb_test_queried_object']);
    }

    private static function block(array $context): \WP_Block
    {
        $block = new \WP_Block();
        $block->context = $context;

        return $block;
    }

    public function testFieldUsesTheBlockPostIdContext(): void
    {
        $helper = new TemplateHelper([], self::block(['postId' => 10]));

        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $helper->field('subtitle'));
        $this->assertSame('<b>Hi</b>', $helper->field('subtitle', true));
        $this->assertNull($helper->field('nope'));
    }

    public function testBlockContextWinsOverTheCurrentPost(): void
    {
        $GLOBALS['pb_test_current_post'] = 11;
        $helper = new TemplateHelper([], self::block(['postId' => 10]));

        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $helper->field('subtitle'));
    }

    public function testFieldFallsBackToTheCurrentPost(): void
    {
        $GLOBALS['pb_test_current_post'] = 10;

        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', (new TemplateHelper([]))->field('subtitle'));
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', (new TemplateHelper([], self::block([])))->field('subtitle'));
    }

    public function testFieldWithNoPostFallsBackToTheQueriedObject(): void
    {
        $GLOBALS['pb_test_current_post'] = false;
        $GLOBALS['pb_test_queried_object'] = (object) ['ID' => 10, 'post_type' => 'event'];

        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', (new TemplateHelper([]))->field('subtitle'));
    }

    public function testFieldIgnoresANonNumericPostIdContext(): void
    {
        $GLOBALS['pb_test_current_post'] = 10;
        $helper = new TemplateHelper([], self::block(['postId' => 'option:theme-settings']));

        // A block context value is not a context string: it cannot redirect the read.
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $helper->field('subtitle'));
        $this->assertNull($helper->field('phone'));
    }

    public function testFieldWithoutASharedReaderIsNull(): void
    {
        FieldReader::setShared(null);

        $this->assertNull((new TemplateHelper([], self::block(['postId' => 10])))->field('subtitle'));
    }

    public function testHasValueAndGetBehaveAsBefore(): void
    {
        $helper = new TemplateHelper(['title' => 'T', 'empty' => '', 'zero' => '0', 'list' => [], 'null' => null]);

        $this->assertTrue($helper->has_value('title'));
        $this->assertFalse($helper->has_value('empty'));
        $this->assertFalse($helper->has_value('zero'));
        $this->assertFalse($helper->has_value('list'));
        $this->assertFalse($helper->has_value('missing'));

        $this->assertSame('T', $helper->get('title'));
        $this->assertSame('', $helper->get('empty', 'd'));
        $this->assertSame('d', $helper->get('null', 'd'));
        $this->assertSame('d', $helper->get('missing', 'd'));
        $this->assertNull($helper->get('missing'));
    }
}
