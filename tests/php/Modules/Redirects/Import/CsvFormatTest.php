<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Import;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Import\CsvFormat;
use ProtoBlocks\Modules\Redirects\Import\Formats;

final class CsvFormatTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /** @return array<int, array> rows keyed by line */
    private static function byLine(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['line']] = $row;
        }
        return $out;
    }

    public function testRedirectionStyleFileWithHeader(): void
    {
        $rows = self::byLine((new CsvFormat())->parse(self::fixture('redirection.csv')));

        $this->assertArrayNotHasKey(1, $rows, 'header row skipped');
        $this->assertSame(['from' => '/old', 'regex' => false, 'to' => '/new', 'code' => 301, 'action' => 'url'], $rows[2]['rule']);
        $this->assertSame('new', $rows[2]['status']);
        $this->assertNull($rows[2]['reason']);

        $this->assertSame('/with,comma', $rows[3]['rule']['from'], 'quoted field keeps its comma');
        $this->assertSame('/to "quoted"', $rows[3]['rule']['to'], 'doubled quotes unescaped');
        $this->assertSame(302, $rows[3]['rule']['code']);

        $this->assertArrayNotHasKey(4, $rows, 'empty line skipped');

        $this->assertSame(['from' => '/gone', 'regex' => false, 'code' => 410, 'action' => 'error'], $rows[5]['rule'], '4xx is an error with no target');

        $this->assertTrue($rows[6]['rule']['regex']);
        $this->assertSame('^/blog/(\d+)$', $rows[6]['rule']['from']);
        $this->assertSame('/news/$1', $rows[6]['rule']['to']);

        $this->assertSame('invalid', $rows[7]['status']);
        $this->assertSame('self_redirect', $rows[7]['reason']);

        $this->assertSame('/=cmd', $rows[8]['rule']['from'], '[FORMULA] prefix removed');

        $this->assertSame('invalid_target', $rows[9]['reason'], 'a quoted line break is read, numbered by its first line, and refused in a target');
        $this->assertSame(['from' => '/after', 'regex' => false, 'to' => '/x', 'code' => 301, 'action' => 'url'], $rows[11]['rule'], 'empty code defaults to 301');

        $this->assertTrue($rows[12]['rule']['regex'], 'missing regex column → metacharacter sniff');
        $this->assertSame('invalid_code', $rows[13]['reason']);
        $this->assertSame('empty_source', $rows[14]['reason']);
        $this->assertSame('too_few_columns', $rows[15]['reason']);
        $this->assertCount(12, $rows);
    }

    public function testSemicolonDelimiterWithoutHeader(): void
    {
        $rows = (new CsvFormat())->parse(self::fixture('semicolon.csv'));
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['line']);
        $this->assertSame('/b', $rows[0]['rule']['to']);
        $this->assertSame(['from' => '/c', 'regex' => false, 'to' => '/d', 'code' => 301, 'action' => 'url'], $rows[1]['rule']);
    }

    public function testPipeDelimiterAndRegexSniff(): void
    {
        $rows = (new CsvFormat())->parse(self::fixture('pipe.csv'));
        $this->assertSame(307, $rows[0]['rule']['code']);
        $this->assertTrue($rows[1]['rule']['regex']);
        $this->assertSame('/(x)', $rows[1]['rule']['from']);
    }

    public function testBomAndCrlfAreTolerated(): void
    {
        $text = "\xEF\xBB\xBF" . str_replace("\n", "\r\n", self::fixture('redirection.csv'));
        $rows = self::byLine((new CsvFormat())->parse($text));
        $this->assertSame('/old', $rows[2]['rule']['from']);
        $this->assertSame('invalid_target', $rows[9]['reason']);
        $this->assertSame(11, array_keys($rows)[7], 'line numbers survive CRLF');
        $this->assertCount(12, $rows);
    }

    public function testOldMacCrLineEndings(): void
    {
        $rows = (new CsvFormat())->parse("/a,/b\r/c,/d\r");
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[1]['line']);
    }

    public function testOwnExportHeaderReadsColumnsByName(): void
    {
        $text = "source,target,regex,code,type,hits,title,status,group\n"
            . "/a,/b,0,302,url,5,Old page,disabled,legacy\n"
            . "/c,/internal,0,,pass,0,,active,legacy\n"
            . "/d,,0,,nothing,0,,active,legacy\n"
            . "/e,,0,451,error,0,,active,legacy\n";
        $rows = (new CsvFormat())->parse($text);
        $this->assertSame(['title' => 'Old page', 'enabled' => false, 'from' => '/a', 'regex' => false, 'to' => '/b', 'code' => 302, 'action' => 'url'], $rows[0]['rule']);
        $this->assertSame(['from' => '/c', 'regex' => false, 'to' => '/internal', 'action' => 'pass'], $rows[1]['rule']);
        $this->assertSame(['from' => '/d', 'regex' => false, 'action' => 'nothing'], $rows[2]['rule']);
        $this->assertSame(['from' => '/e', 'regex' => false, 'code' => 451, 'action' => 'error'], $rows[3]['rule']);
    }

    public function testTypeAndCodeMustAgree(): void
    {
        $rows = (new CsvFormat())->parse("source,target,regex,code,type\n/a,/b,0,410,url\n");
        $this->assertSame('invalid_code', $rows[0]['reason']);
    }

    public function testNonNumericCodeIsInvalid(): void
    {
        $rows = (new CsvFormat())->parse("/a,/b,0,permanent\n");
        $this->assertSame('invalid_code', $rows[0]['reason']);
    }

    public function testRelativeSourceAndTargetGetALeadingSlash(): void
    {
        $rows = (new CsvFormat())->parse("old-page,new-page\n");
        $this->assertSame('/old-page', $rows[0]['rule']['from']);
        $this->assertSame('/new-page', $rows[0]['rule']['to']);
    }

    public function testUnsafeTargetSchemeIsInvalid(): void
    {
        $rows = (new CsvFormat())->parse("/a,javascript:alert(1)\n");
        $this->assertSame('invalid_target', $rows[0]['reason']);
    }

    public function testRegexThatDoesNotCompileIsInvalid(): void
    {
        $rows = (new CsvFormat())->parse("/a(,/b,1,301\n");
        $this->assertSame('invalid_regex', $rows[0]['reason']);
    }

    public function testAbsoluteSourceIsKeptForTheNormaliser(): void
    {
        $rows = (new CsvFormat())->parse("https://example.test/a,/b,0,301\n");
        $this->assertSame('https://example.test/a', $rows[0]['rule']['from']);
    }

    public function testEmptyInputGivesNoRows(): void
    {
        $this->assertSame([], (new CsvFormat())->parse(''));
        $this->assertSame([], (new CsvFormat())->parse("\n\n\r\n"));
    }

    public function testInputOverFiveMegabytesIsRefused(): void
    {
        $rows = (new CsvFormat())->parse(str_repeat('a', Formats::MAX_BYTES + 1));
        $this->assertSame([['rule' => [], 'status' => 'invalid', 'reason' => 'too_large', 'line' => 0]], $rows);
    }

    public function testUnterminatedQuoteDoesNotThrow(): void
    {
        $rows = (new CsvFormat())->parse("/a,\"/b\n/c,/d\n");
        $this->assertCount(1, $rows);
        $this->assertSame('invalid_target', $rows[0]['reason'], 'the rest of the file is the open field');
    }

    public function testDetect(): void
    {
        $csv = new CsvFormat();
        $this->assertTrue($csv->detect('rules.csv', ''));
        $this->assertFalse($csv->detect('rules.json', '/a,/b'));
        $this->assertTrue($csv->detect('', "/a,/b\n"));
        $this->assertTrue($csv->detect('export.txt', "/a;/b\n"));
        $this->assertFalse($csv->detect('', '{"groups":{}}'));
    }
}
