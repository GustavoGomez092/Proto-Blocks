<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Export;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Export\ApacheExporter;
use ProtoBlocks\Modules\Redirects\Export\CsvExporter;
use ProtoBlocks\Modules\Redirects\Export\JsonExporter;
use ProtoBlocks\Modules\Redirects\Export\NetlifyExporter;
use ProtoBlocks\Modules\Redirects\Import\ApacheFormat;
use ProtoBlocks\Modules\Redirects\Import\CsvFormat;
use ProtoBlocks\Modules\Redirects\Import\JsonFormat;
use ProtoBlocks\Modules\Redirects\Import\NetlifyFormat;

require_once __DIR__ . '/ExportFixtures.php';

final class RoundTripTest extends TestCase
{
    /** The fixture groups without the rule whose source holds a line break (importers refuse it). */
    private static function safeGroups(): array
    {
        $groups = ExportFixtures::groups();
        foreach ($groups as &$group) {
            $group['rules'] = array_values(array_filter($group['rules'], static fn(array $r): bool => !str_contains((string) $r['from'], "\n")));
        }
        return $groups;
    }

    public function testCsvExportParseExport(): void
    {
        $exporter = new CsvExporter();
        $first = $exporter->render(self::safeGroups(), []);

        // Re-build each group from the rows of its own slug (CSV carries the group in a column).
        $bySlug = [];
        foreach (explode("\r\n", trim($first)) as $i => $line) {
            if ($i > 0) {
                $bySlug[(string) substr($line, (int) strrpos($line, ',') + 1)][] = $line;
            }
        }
        $groups = [];
        foreach (self::safeGroups() as $original) {
            $text = implode("\r\n", array_merge([implode(',', CsvExporter::HEADER)], $bySlug[$original['slug']] ?? []));
            $rows = (new CsvFormat())->parse($text);
            $this->assertSame([], array_filter($rows, static fn(array $r): bool => $r['status'] !== 'new'), 'every exported row parses');
            $rules = [];
            foreach ($rows as $i => $row) {
                $rules[] = ExportFixtures::rule($original['slug'], $i + 1, $row['rule']);
            }
            $groups[] = ['slug' => $original['slug'], 'title' => $original['title'], 'position' => $original['position'], 'rules' => $rules];
        }

        $this->assertSame($first, $exporter->render($groups, []));
    }

    public function testJsonExportParseExport(): void
    {
        $exporter = new JsonExporter();
        $first = $exporter->render(self::safeGroups(), []);
        $parsed = (new JsonFormat())->parseGroups($first);

        $this->assertSame([], array_filter((new JsonFormat())->parse($first), static fn(array $r): bool => $r['status'] !== 'new'));
        $groups = [];
        foreach ($parsed as $slug => $group) {
            $groups[] = ['slug' => $slug] + $group;
        }
        $this->assertSame($first, $exporter->render($groups, []));
    }

    public function testApacheExportParsesBackToTheSameRules(): void
    {
        $group = ['slug' => 'g', 'rules' => [
            ExportFixtures::rule('g', 1, ['from' => '/a', 'to' => '/b', 'query' => 'ignore']),
            ExportFixtures::rule('g', 2, ['from' => '^/c/(\d+)$', 'regex' => true, 'to' => '/d/$1', 'code' => 302, 'query' => 'pass', 'ignoreCase' => false]),
            ExportFixtures::rule('g', 3, ['from' => '/e', 'action' => 'error', 'code' => 410, 'query' => 'ignore', 'ignoreTrailingSlash' => false]),
            ExportFixtures::rule('g', 4, ['from' => '/f', 'to' => '/g', 'query' => 'ignore', 'when' => ['type' => 'referrer', 'value' => 'x\.test', 'regex' => true]]),
            ExportFixtures::rule('g', 5, ['from' => '/h', 'to' => '/i%20j', 'query' => 'ignore']),
        ]];
        $rows = (new ApacheFormat())->parse((new ApacheExporter())->render([$group], []));
        $this->assertSame(['new', 'new', 'new', 'new', 'new'], array_column($rows, 'status'));
        $rules = array_column($rows, 'rule');
        $this->assertSame(['from' => '/a', 'regex' => false, 'to' => '/b', 'code' => 301, 'action' => 'url', 'query' => 'ignore', 'ignoreCase' => true, 'ignoreTrailingSlash' => true], $rules[0]);
        $this->assertSame(['from' => '^/c/(\d+)$', 'regex' => true, 'to' => '/d/$1', 'code' => 302, 'action' => 'url', 'query' => 'pass', 'ignoreCase' => false], $rules[1]);
        $this->assertSame(['from' => '/e', 'regex' => false, 'code' => 410, 'action' => 'error', 'query' => 'ignore', 'ignoreCase' => true, 'ignoreTrailingSlash' => false], $rules[2]);
        $this->assertSame(['type' => 'referrer', 'value' => 'x\.test', 'regex' => true], $rules[3]['when']);
        $this->assertSame('/i%20j', $rules[4]['to'], 'escaped % survives');
    }

    public function testNetlifyExportParsesBack(): void
    {
        $group = ['slug' => 'g', 'rules' => [
            ExportFixtures::rule('g', 1, ['from' => '/a', 'to' => '/b', 'code' => 302]),
            ExportFixtures::rule('g', 2, ['from' => '^/blog/(.*)$', 'regex' => true, 'to' => '/news/$1']),
            ExportFixtures::rule('g', 3, ['from' => '/spa', 'to' => '/index.html', 'action' => 'pass', 'code' => null]),
        ]];
        $rows = (new NetlifyFormat())->parse((new NetlifyExporter())->render([$group], []));
        $this->assertSame([
            ['from' => '/a', 'regex' => false, 'to' => '/b', 'code' => 302, 'action' => 'url'],
            ['from' => '^/blog/(.*)$', 'regex' => true, 'to' => '/news/$1', 'code' => 301, 'action' => 'url'],
            ['from' => '/spa', 'regex' => false, 'to' => '/index.html', 'action' => 'pass'],
        ], array_column($rows, 'rule'));
    }
}
