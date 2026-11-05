<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Export\ApacheExporter;
use ProtoBlocks\Modules\Redirects\Export\CsvExporter;
use ProtoBlocks\Modules\Redirects\Export\Exporters;
use ProtoBlocks\Modules\Redirects\Export\JsonExporter;
use ProtoBlocks\Modules\Redirects\Export\NetlifyExporter;
use ProtoBlocks\Modules\Redirects\Export\NginxExporter;
use ProtoBlocks\Modules\Redirects\Export\Support;

require_once __DIR__ . '/ExportFixtures.php';

final class ExportersTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function formats(): array
    {
        return ['csv' => ['csv'], 'json' => ['json'], 'apache' => ['apache'], 'nginx' => ['nginx'], 'redirects' => ['redirects']];
    }

    #[DataProvider('formats')]
    public function testGoldenFile(string $format): void
    {
        $actual = Exporters::byFormat($format)->render(ExportFixtures::groups(), ExportFixtures::hits());
        $expected = (string) file_get_contents(ExportFixtures::EXPECTED . $format . '.txt');
        // Compare with LF so a checkout's line-ending conversion cannot fail the test; CSV's CRLF is asserted separately.
        $this->assertSame(str_replace("\r\n", "\n", $expected), str_replace("\r\n", "\n", $actual));
    }

    #[DataProvider('formats')]
    public function testOutputDoesNotDependOnInputOrder(string $format): void
    {
        $exporter = Exporters::byFormat($format);
        $groups = ExportFixtures::groups();
        $shuffled = array_reverse($groups);
        foreach ($shuffled as &$group) {
            $group['rules'] = array_reverse($group['rules']); // `index` decides the order
        }
        unset($group);
        $this->assertSame($exporter->render($groups, ExportFixtures::hits()), $exporter->render($shuffled, ExportFixtures::hits()));
    }

    #[DataProvider('formats')]
    public function testNoGroups(string $format): void
    {
        $out = Exporters::byFormat($format)->render([], []);
        $this->assertNotSame('', $out);
        if ($format === 'json') {
            $this->assertSame("{\n  \"groups\": {}\n}\n", $out);
        } elseif ($format === 'csv') {
            $this->assertSame("source,target,regex,code,type,hits,title,status,group\r\n", $out);
        } else {
            $this->assertStringStartsWith(Support::BEGIN . "\n", $out);
            $this->assertStringEndsWith(Support::END . "\n", $out);
        }
    }

    public function testRegistryAndMetadata(): void
    {
        $this->assertSame(
            [['csv', 'text/csv', 'csv'], ['json', 'application/json', 'json'], ['apache', 'text/plain', 'htaccess'], ['nginx', 'text/plain', 'conf'], ['redirects', 'text/plain', '']],
            array_map(static fn($e) => [$e->format(), $e->mime(), $e->extension()], Exporters::all()),
        );
        $this->assertInstanceOf(NetlifyExporter::class, Exporters::byFormat('redirects'));
        $this->assertNull(Exporters::byFormat('xml'));
    }

    // ------------------------------------------------------------------ CSV

    public function testCsvUsesCrlfAndRfc4180Quoting(): void
    {
        $out = (new CsvExporter())->render(ExportFixtures::groups(), ExportFixtures::hits());
        $this->assertStringContainsString("\r\n/about-us,/about,0,301,url,42,,active,legacy-site\r\n", $out, 'hits from the map');
        $this->assertStringContainsString('"[FORMULA] =HYPERLINK(""http://evil.test"")"', $out, 'quotes doubled, field quoted');
        $this->assertStringContainsString("\"/inject\nRewriteRule", $out, 'a line break stays inside a quoted field');
        $this->assertStringNotContainsString('/members', $out, 'conditional rules are not exported to CSV');
        $this->assertStringContainsString(',disabled,off', $out, 'rules of a disabled group are listed as disabled');
        $this->assertStringContainsString('/cart,/basket,0,301,url,0,,disabled,shop', $out);
    }

    public function testFormulaEscaping(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx", "\u{FF1D}1", "\u{FF0B}1", "\u{FF0D}1", "\u{FF20}x"] as $cell) {
            $this->assertSame('[FORMULA] ' . $cell, Support::formula($cell), json_encode($cell));
        }
        foreach (['', '/a', 'a=1', ' =1', '1-2'] as $cell) {
            $this->assertSame($cell, Support::formula($cell));
        }
    }

    public function testFormulaEscapingAppliesToEveryColumn(): void
    {
        $group = ['slug' => 'g', 'rules' => [ExportFixtures::rule('g', 1, ['from' => '/a', 'to' => '=evil()', 'title' => '@x'])]];
        $out = (new CsvExporter())->render([$group], []);
        $this->assertStringContainsString('/a,[FORMULA] =evil(),0,301,url,0,[FORMULA] @x,active,g', $out);
    }

    // ------------------------------------------------------------------ JSON

    public function testJsonDropsRegistryKeysAndHits(): void
    {
        $data = json_decode((new JsonExporter())->render(ExportFixtures::groups(), ExportFixtures::hits()), true);
        $this->assertSame(['off', 'shop', 'legacy-site'], array_keys($data['groups']), 'evaluation order');
        $rule = $data['groups']['legacy-site']['rules'][0];
        foreach (['group', 'origin', 'source', 'index', 'hits', 'headers', 'else', 'when'] as $key) {
            $this->assertArrayNotHasKey($key, $rule, $key);
        }
        $this->assertArrayNotHasKey('origin', $data['groups']['legacy-site']);
        $this->assertArrayNotHasKey('settings', $data);
    }

    public function testJsonCarriesSettingsWhenGiven(): void
    {
        $out = (new JsonExporter(['trackHits' => false, 'log' => ['ip' => 'none']]))->render([], []);
        $this->assertSame(['groups' => [], 'settings' => ['trackHits' => false, 'log' => ['ip' => 'none']]], json_decode($out, true));
    }

    // ------------------------------------------------------------------ Apache

    public function testApacheBlockAndSafety(): void
    {
        $out = (new ApacheExporter())->render(ExportFixtures::groups(), []);
        $this->assertStringStartsWith("# Proto-Blocks redirects\n<IfModule mod_rewrite.c>\n", $out);
        $this->assertStringEndsWith("</IfModule>\n# End Proto-Blocks redirects\n", $out);
        $this->assertStringNotContainsString('/off', $out, 'disabled group');
        $this->assertStringNotContainsString('/cart', $out, 'disabled rule');
        $this->assertStringNotContainsString("\nRewriteRule ^ http://evil.test", $out, 'no directive injection');
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertMatchesRegularExpression('/^(#.*|<\/?IfModule.*|RewriteEngine On|RewriteCond .+|RewriteRule .+|)$/', $line);
        }
    }

    public function testApacheErrorCodesAndCondition(): void
    {
        $rules = [
            ExportFixtures::rule('g', 1, ['from' => '/a', 'action' => 'error', 'code' => 404, 'ignoreCase' => false, 'ignoreTrailingSlash' => false]),
            ExportFixtures::rule('g', 2, ['from' => '/b', 'to' => '/c', 'when' => ['type' => 'agent', 'value' => 'bot "x"', 'regex' => true], 'ignoreCase' => false]),
            ExportFixtures::rule('g', 3, ['from' => '/q?a=1&b=2', 'to' => '/d', 'query' => 'ignore', 'ignoreCase' => false]),
            ExportFixtures::rule('g', 4, ['from' => '^/x\?y=1$', 'regex' => true, 'to' => '/d']),
            ExportFixtures::rule('g', 5, ['from' => '/', 'to' => '/home', 'ignoreCase' => false]),
        ];
        $out = (new ApacheExporter())->render([['slug' => 'g', 'title' => 'g', 'rules' => $rules]], []);
        $this->assertStringContainsString("\n# g\nRewriteRule ^/?a$ - [R=404,L]\n", $out, 'group comment without a repeated title');
        $this->assertStringContainsString("RewriteCond %{HTTP_USER_AGENT} \"bot \\\"x\\\"\" [NC]\nRewriteRule ^/?b/?$ /c [R=301,L]\n", $out);
        $this->assertStringContainsString("RewriteCond %{QUERY_STRING} (^|&)a=1(&|$)\nRewriteCond %{QUERY_STRING} (^|&)b=2(&|$)\nRewriteRule ^/?q/?$ /d [R=301,L]\n", $out);
        $this->assertStringContainsString('# skipped: r_00000004 ^/x\?y=1$: the regex matches the query string', $out);
        $this->assertStringContainsString("RewriteRule ^/?$ /home [R=301,L]\n", $out, 'root');
    }

    // ------------------------------------------------------------------ nginx

    public function testNginxEdgeCases(): void
    {
        $rules = [
            ExportFixtures::rule('g', 1, ['from' => '/exact', 'action' => 'error', 'code' => 410, 'ignoreCase' => false, 'ignoreTrailingSlash' => false]),
            ExportFixtures::rule('g', 2, ['from' => '/var', 'to' => '/$host']),
            ExportFixtures::rule('g', 3, ['from' => '/p', 'to' => '/t', 'code' => 307, 'query' => 'pass']),
            ExportFixtures::rule('g', 4, ['from' => '/q?a=1&b=2', 'to' => '/d', 'query' => 'ignore']),
            ExportFixtures::rule('g', 5, ['from' => '/q?a=1', 'to' => '/d', 'query' => 'pass']),
            ExportFixtures::rule('g', 6, ['from' => '/br{a}', 'to' => '/x']),
            ExportFixtures::rule('g', 7, ['from' => '^/c/(.*)$', 'regex' => true, 'to' => '/d/${1}']),
            ExportFixtures::rule('g', 8, ['from' => '/e', 'action' => 'error', 'code' => 410, 'when' => ['type' => 'server', 'value' => 'a.test']]),
            ExportFixtures::rule('g', 9, ['from' => '/f', 'to' => '/g', 'code' => 308, 'when' => ['type' => 'server', 'value' => 'a.test']]),
        ];
        $out = (new NginxExporter())->render([['slug' => 'g', 'rules' => $rules]], []);
        $this->assertStringContainsString("location = /exact { return 410; }\n", $out);
        $this->assertStringContainsString('# skipped: r_00000002 /var: the target holds a "$" nginx would read as a variable', $out);
        $this->assertStringContainsString("location ~* ^/p/?$ { return 307 /t\$is_args\$args; }\n", $out);
        $this->assertStringContainsString('# skipped: r_00000004 /q?a=1&b=2: several query parameters', $out);
        $this->assertStringContainsString("if (\$arg_a = \"1\") { rewrite (?i)^/q/?$ /d permanent; }\n", $out);
        $this->assertStringContainsString("rewrite \"(?i)^/br\\{a\\}/?$\" /x? permanent;\n", $out, 'braces force quoting');
        $this->assertStringContainsString("rewrite (?i)^/c/(.*)$ /d/\$1? permanent;\n", $out, '${1} → $1');
        $this->assertStringContainsString('# skipped: r_00000008 /e: conditional error responses', $out);
        $this->assertStringContainsString('# skipped: r_00000009 /f: a conditional 308', $out);
    }

    // ------------------------------------------------------------------ Netlify

    public function testNetlifyRegexForms(): void
    {
        $rules = [
            ExportFixtures::rule('g', 1, ['from' => '^/exact$', 'regex' => true, 'to' => '/x']),
            ExportFixtures::rule('g', 2, ['from' => '^/a/(.*)$', 'regex' => true, 'to' => '/b/$1/$2']),
            ExportFixtures::rule('g', 3, ['from' => '/star*', 'to' => '/x']),
            ExportFixtures::rule('g', 4, ['from' => '^/p/(.*)$', 'regex' => true, 'to' => '/q/${1}', 'action' => 'pass', 'code' => null]),
        ];
        $out = (new NetlifyExporter())->render([['slug' => 'g', 'rules' => $rules]], []);
        $this->assertStringContainsString("\n/exact /x 301\n", $out);
        $this->assertStringContainsString('# skipped: r_00000002 ^/a/(.*)$: the target uses a capture other than the splat', $out);
        $this->assertStringContainsString('# skipped: r_00000003 /star*: the source holds a character _redirects reads as a placeholder', $out);
        $this->assertStringContainsString("\n/p/* /q/:splat 200\n", $out);
    }

    public function testCommentsNeverCarryLineBreaks(): void
    {
        $group = ['slug' => 'g', 'title' => "Evil\nRewriteRule ^ /x", 'rules' => [ExportFixtures::rule('g', 1, ['id' => "r_1\nx", 'from' => "/a\nb", 'to' => '/c'])]];
        foreach ([new ApacheExporter(), new NginxExporter(), new NetlifyExporter()] as $exporter) {
            $out = $exporter->render([$group], []);
            foreach (explode("\n", trim($out)) as $line) {
                $this->assertFalse(str_starts_with($line, 'RewriteRule ^ /x') || str_starts_with($line, 'b'), $exporter->format() . ': ' . $line);
            }
            $this->assertStringContainsString('# g: Evil RewriteRule ^ /x', $out);
        }
    }
}
