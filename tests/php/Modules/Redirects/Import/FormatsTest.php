<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Import;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Import\Formats;
use ProtoBlocks\Modules\Redirects\Import\ParsedRow;
use ProtoBlocks\Modules\Redirects\Import\Patterns;
use ProtoBlocks\Modules\Redirects\Import\RawRule;

final class FormatsTest extends TestCase
{
    private static function id(string $filename, string $text): ?string
    {
        return Formats::detect($filename, $text)?->id();
    }

    public function testDetectByFilenameFirst(): void
    {
        $this->assertSame('redirects', self::id('_redirects', 'source,target'));
        $this->assertSame('apache', self::id('.htaccess', '{}'));
        $this->assertSame('apache', self::id('C:\\sites\\old.htaccess', ''));
        $this->assertSame('csv', self::id('Export.CSV', '{"groups":{}}'));
        $this->assertSame('json', self::id('redirection.json', '/a,/b'));
    }

    public function testDetectByContentWhenTheNameDoesNotDecide(): void
    {
        $this->assertSame('json', self::id('', '{"groups":{}}'));
        $this->assertSame('json', self::id('upload.txt', "  \n{\"redirects\":[]}"));
        $this->assertSame('apache', self::id('rules.txt', "# x\nRewriteEngine On\nRewriteRule ^a$ /b [R=301]"));
        $this->assertSame('apache', self::id('', "<IfModule mod_alias.c>\nRedirect 301 /a /b\n</IfModule>"));
        $this->assertSame('redirects', self::id('', "/a /b 301\n/c/* /d/:splat\n"));
        $this->assertSame('csv', self::id('rules.txt', "source,target\n/a,/b\n"));
        $this->assertSame('csv', self::id('', "/a;/b\n"));
        $this->assertNull(self::id('', ''));
        $this->assertNull(self::id('', 'hello'));
    }

    public function testById(): void
    {
        foreach (['csv', 'json', 'apache', 'redirects'] as $id) {
            $this->assertSame($id, Formats::byId($id)?->id());
        }
        $this->assertNull(Formats::byId('nginx'), 'nginx is export-only');
    }

    public function testRowHelpers(): void
    {
        $this->assertSame(['rule' => ['from' => '/a'], 'status' => 'new', 'reason' => null, 'line' => 3], ParsedRow::ok(['from' => '/a'], 3));
        $this->assertSame('The source and the target are the same.', ParsedRow::message('self_redirect'));
        $this->assertSame('mystery', ParsedRow::message('mystery'));
        $this->assertSame([], ParsedRow::guardSize(str_repeat('a', Formats::MAX_BYTES)), 'exactly 5 MB is allowed');
    }

    public function testRawRuleOrderAndTargets(): void
    {
        $this->assertSame(['id' => 'r_1', 'from' => '/a', 'to' => '/b', 'code' => 301], RawRule::build(['code' => 301, 'to' => '/b', 'else' => null, 'from' => '/a', 'id' => 'r_1', 'extra' => 1]));
        $this->assertSame('/x', RawRule::target('x'));
        $this->assertSame('https://a.test/', RawRule::target(' https://a.test/ '));
        $this->assertNull(RawRule::target('javascript:alert(1)'));
        $this->assertNull(RawRule::target('data:text/html,x'));
        $this->assertNull(RawRule::target('\\\\evil.test'));
        $this->assertNull(RawRule::target("/a\r\nLocation: x"));
        $this->assertNull(RawRule::target(''));
        $this->assertTrue(RawRule::selfRedirect(['from' => '/A/', 'to' => '/a']));
        $this->assertFalse(RawRule::selfRedirect(['from' => '/a', 'to' => '/a', 'when' => ['type' => 'login']]));
    }

    public function testPatterns(): void
    {
        $this->assertSame(['old.page', false], Patterns::anchoredLiteral('^old\.page$'));
        $this->assertSame(['old', true], Patterns::anchoredLiteral('^old/?$'));
        $this->assertSame(['old', true], Patterns::anchoredLiteral('^old\/?$'));
        $this->assertNull(Patterns::anchoredLiteral('^old(.*)$'));
        $this->assertNull(Patterns::anchoredLiteral('^a\d$'));
        $this->assertNull(Patterns::anchoredLiteral('old$'));
        $this->assertNull(Patterns::anchoredLiteral('^old\$'));
        $this->assertSame('^/a\.b/(.*)$', Patterns::wildcard('/a.b/*'));
        $this->assertSame('/a\.b\?c', Patterns::quote('/a.b?c'));
        $this->assertTrue(Patterns::compiles('^/a~b$'), 'tilde is escaped');
        $this->assertFalse(Patterns::compiles('^/(a'));
    }
}
