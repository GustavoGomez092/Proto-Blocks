<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Read\Aliases;
use ProtoBlocks\Modules\Fields\Read\FieldReader;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';
require_once __DIR__ . '/stubs/Read.php';

require_once __DIR__ . '/../../../../includes/Modules/Fields/functions.php';

/**
 * Every test runs in its own process: the aliases are global functions that
 * can only be defined once, and one test pre-defines get_field() as ACF would.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AliasesTest extends TestCase
{
    protected function setUp(): void
    {
        FieldReaderTest::seed();
        FieldReader::setShared(FieldReaderTest::reader());
    }

    public function testLoadDefinesTheAliasesWhenAcfIsInactive(): void
    {
        $this->assertFalse(function_exists('get_field'));
        $this->assertTrue(Aliases::load(static fn(): bool => false));
        $this->assertTrue(function_exists('get_field'));
        $this->assertTrue(function_exists('the_field'));
        $this->assertTrue(function_exists('get_fields'));
        $this->assertFalse(Aliases::load(static fn(): bool => false), 'second load is a no-op');
    }

    public function testGetFieldMatchesProtoField(): void
    {
        Aliases::load(static fn(): bool => false);

        $this->assertSame('<b>Hi</b>', get_field('subtitle', 10, false));
        $this->assertSame(proto_field('subtitle', 10, true), get_field('subtitle', 10, false));
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', get_field('subtitle', 10));
        $this->assertSame(proto_field('subtitle', 10), get_field('subtitle', 10));
        $this->assertNull(get_field('nope', 10));
    }

    public function testGetFieldWithoutPostIdUsesTheQueriedObject(): void
    {
        Aliases::load(static fn(): bool => false);
        $GLOBALS['pb_test_queried_object'] = (object) ['ID' => 10, 'post_type' => 'event'];

        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', get_field('subtitle'));
    }

    public function testTheFieldEchoesEscapedValues(): void
    {
        Aliases::load(static fn(): bool => false);

        $this->expectOutputString('a, b|&lt;b&gt;Hi&lt;/b&gt;|https://x.test/hero.jpg||');
        the_field('audience', 10);
        echo '|';
        the_field('subtitle', 10);
        echo '|';
        the_field('hero', 10);
        echo '|';
        the_field('stream_url', 10);
        echo '|';
        the_field('agenda', 10); // rows: nothing
    }

    public function testGetFieldsMatchesProtoFields(): void
    {
        Aliases::load(static fn(): bool => false);

        $this->assertSame(proto_fields(10), get_fields(10));
        $this->assertSame(proto_fields(10, true), get_fields(10, false));
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', get_fields(10)['subtitle']);
    }

    public function testAcfPostIdStrings(): void
    {
        Aliases::load(static fn(): bool => false);

        $this->assertSame('&lt;u&gt;term&lt;/u&gt;', get_field('tagline', 'term_5'));
        $this->assertSame('&lt;u&gt;term&lt;/u&gt;', get_field('tagline', 'product_cat_5'));
        $this->assertNull(get_field('tagline', 'category_5'), 'taxonomy must match the term');
        $this->assertSame('&lt;u&gt;user&lt;/u&gt;', get_field('bio', 'user_3'));
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', get_field('subtitle', '10'));
        $this->assertSame([], $GLOBALS['pb_test_doing_it_wrong']);
    }

    public function testAcfOptionsStringReadsNothingAndWarns(): void
    {
        Aliases::load(static fn(): bool => false);
        // Even with a queried post, 'option' must not fall back to it.
        $GLOBALS['pb_test_queried_object'] = (object) ['ID' => 10, 'post_type' => 'event'];

        $this->assertNull(get_field('subtitle', 'option'));
        $this->assertNull(get_field('phone', 'options'));
        $this->assertSame([], get_fields('options'));
        $this->assertCount(3, $GLOBALS['pb_test_doing_it_wrong']);
    }

    public function testLoadDoesNothingWhenAcfIsActive(): void
    {
        $this->assertFalse(Aliases::load(static fn(): bool => true));
        $this->assertFalse(function_exists('get_field'));
        $this->assertFalse(function_exists('the_field'));
        $this->assertFalse(function_exists('get_fields'));
    }

    public function testLoadLeavesAnExistingGetFieldAlone(): void
    {
        require __DIR__ . '/stubs/AcfGetField.php';

        $this->assertFalse(Aliases::load(static fn(): bool => false));
        $this->assertSame('acf-stub', get_field('subtitle', 10));
        $this->assertFalse(function_exists('the_field'));
    }

    public function testFunctionsWithoutASharedReader(): void
    {
        FieldReader::setShared(null);

        $this->assertNull(proto_field('subtitle', 10));
        $this->assertSame([], proto_fields(10));
        $rows = proto_rows('agenda', 10);
        $this->assertSame([], is_array($rows) ? $rows : iterator_to_array($rows));
    }

    public function testProtoFunctionsForwardToTheSharedReader(): void
    {
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', proto_field('subtitle', 10));
        $this->assertSame('<b>Hi</b>', proto_field('subtitle', 10, true));
        $rows = proto_rows('agenda', 10);
        $this->assertCount(2, is_array($rows) ? $rows : iterator_to_array($rows));
    }

    public function testUndeclaredNameWarnsUnderWpDebug(): void
    {
        define('WP_DEBUG', true);

        $this->assertNull(proto_field('nope', 10));
        $this->assertCount(1, $GLOBALS['pb_test_doing_it_wrong']);
        $this->assertSame('proto_field', $GLOBALS['pb_test_doing_it_wrong'][0][0]);
    }

    public function testUndeclaredNameIsSilentWithoutWpDebug(): void
    {
        $this->assertNull(proto_field('nope', 10));
        $this->assertSame([], $GLOBALS['pb_test_doing_it_wrong']);
    }
}
