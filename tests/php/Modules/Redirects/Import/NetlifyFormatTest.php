<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Import;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Import\Formats;
use ProtoBlocks\Modules\Redirects\Import\NetlifyFormat;

final class NetlifyFormatTest extends TestCase
{
    /** @var array<int, array> */
    private static array $rows;

    public static function setUpBeforeClass(): void
    {
        $rows = (new NetlifyFormat())->parse((string) file_get_contents(__DIR__ . '/fixtures/_redirects'));
        self::$rows = array_column($rows, null, 'line');
    }

    public function testMatrix(): void
    {
        $r = self::$rows;
        $this->assertSame(['from' => '/home', 'regex' => false, 'to' => '/', 'code' => 301, 'action' => 'url'], $r[2]['rule'], 'default status 301');
        $this->assertSame(['from' => '^/blog/(.*)$', 'regex' => true, 'to' => '/news/$1', 'code' => 301, 'action' => 'url'], $r[3]['rule'], '* and :splat');
        $this->assertSame(302, $r[4]['rule']['code'], '! force tolerated');
        $this->assertSame(['from' => '^/spa/(.*)$', 'regex' => true, 'to' => '/index.html', 'action' => 'pass'], $r[5]['rule'], '200 is a rewrite');
        $this->assertSame(['from' => '/gone', 'regex' => false, 'code' => 410, 'action' => 'error'], $r[6]['rule']);
        $this->assertSame('unsupported_placeholder', $r[7]['reason']);
        $this->assertSame('unsupported_condition', $r[8]['reason'], 'query parameter matching');
        $this->assertSame('unsupported_condition', $r[9]['reason'], 'Country= condition');
        $this->assertSame(
            ['from' => '^/(.*)$', 'regex' => true, 'to' => 'https://new.example.com/$1', 'code' => 301, 'action' => 'url', 'when' => ['type' => 'server', 'value' => 'old.example.com']],
            $r[10]['rule'],
            'absolute source → server condition',
        );
        $this->assertSame('unsupported_action', $r[11]['reason'], '200 to another host is a proxy');
        $this->assertSame('invalid_code', $r[12]['reason']);
        $this->assertSame('self_redirect', $r[13]['reason']);
        $this->assertSame('too_few_columns', $r[14]['reason']);
        $this->assertSame('/done', $r[16]['rule']['to']);
        $this->assertArrayNotHasKey(1, $r, 'comment');
        $this->assertArrayNotHasKey(15, $r, 'blank line');
        $this->assertCount(14, $r);
    }

    public function testSplatWithoutWildcardIsDropped(): void
    {
        $row = (new NetlifyFormat())->parse("/a /b/:splat\n")[0];
        $this->assertSame('/b/', $row['rule']['to']);
    }

    public function testCrlfAndBom(): void
    {
        $rows = (new NetlifyFormat())->parse("\xEF\xBB\xBF/a /b\r\n/c /d 307\r\n");
        $this->assertSame([1, 2], array_column($rows, 'line'));
        $this->assertSame('/a', $rows[0]['rule']['from']);
    }

    public function testTooLarge(): void
    {
        $this->assertSame('too_large', (new NetlifyFormat())->parse(str_repeat('#', Formats::MAX_BYTES + 1))[0]['reason']);
    }

    public function testDetect(): void
    {
        $netlify = new NetlifyFormat();
        $this->assertTrue($netlify->detect('_redirects', ''));
        $this->assertTrue($netlify->detect('/site/public/_redirects', ''));
        $this->assertTrue($netlify->detect('', "# c\n/a /b 301\n/c /d\n"));
        $this->assertFalse($netlify->detect('', "/a,/b\n"));
        $this->assertFalse($netlify->detect('', 'Redirect 301 /a /b'));
        $this->assertFalse($netlify->detect('rules.csv', '/a /b'));
    }
}
