<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\CLI;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\CLI\FieldsCommand;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Definition\Report;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wp-cli-stub.php';

/**
 * The testable core of `wp proto-blocks fields list|validate`. No WP-CLI
 * runtime is involved.
 */
final class FieldsCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/fields';

    /** A test-local decoder: the fixtures are plain JSON. */
    public static function decode(string $text): array
    {
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function group(string $file): \ProtoBlocks\Modules\Fields\Definition\FieldGroup
    {
        $raw = self::decode((string) file_get_contents($file));
        $result = GroupNormalizer::normalizeMany($raw, 'file:' . pathinfo($file, PATHINFO_FILENAME), $file);
        self::assertCount(1, $result['groups']);

        return $result['groups'][0];
    }

    /** @param list<array{location: array}> $location */
    private static function located(array $location): \ProtoBlocks\Modules\Fields\Definition\FieldGroup
    {
        $result = GroupNormalizer::normalize([
            'key' => 'group_x',
            'title' => 'X',
            'location' => $location,
            'fields' => [['key' => 'field_a', 'name' => 'a', 'label' => 'A', 'type' => 'text']],
        ], 'php');

        return $result['group'];
    }

    public function testRowsDescribeTheRealisticGroup(): void
    {
        $group = self::group(self::FIXTURES . '/acf-export-realistic.json');
        $rows = FieldsCommand::rows([$group], []);

        $this->assertCount(1, $rows);
        $this->assertSame('file:acf-export-realistic', $rows[0]['source']);
        $this->assertSame(18, $rows[0]['fields']);
        $this->assertSame('post_type == event', $rows[0]['location']);
        $this->assertSame('active', $rows[0]['status']);
    }

    public function testRowsMarkAnUnknownRuleGroupUnrenderable(): void
    {
        $group = self::group(self::FIXTURES . '/groups-invalid/unknown-rule.json');

        $this->assertSame('unrenderable', FieldsCommand::rows([$group], [])[0]['status']);
    }

    public function testRowsListSkippedAcfGroups(): void
    {
        $rows = FieldsCommand::rows([], [['key' => 'group_acf', 'title' => 'ACF one', 'source' => 'acf-json']]);

        $this->assertSame('skipped-acf', $rows[0]['status']);
        $this->assertSame('group_acf', $rows[0]['key']);
    }

    public function testValidateInvalidFixtures(): void
    {
        $dir = self::FIXTURES . '/groups-invalid';
        $files = glob($dir . '/*.json');
        sort($files);
        $result = FieldsCommand::validatePaths($files, [self::class, 'decode']);

        $errorFiles = array_unique(array_column(array_filter($result['lines'], fn($l) => $l['level'] === 'ERROR'), 'file'));
        $this->assertCount(5, $errorFiles, 'every fixture but unknown-rule has an error');
        $this->assertGreaterThanOrEqual(5, $result['errors']);
        $this->assertSame($result['errors'], count(array_filter($result['lines'], fn($l) => $l['level'] === 'ERROR')));

        $warnings = array_filter($result['lines'], fn($l) => $l['level'] === 'WARNING');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('unknown-rule.json', array_values($warnings)[0]['file']);
        $this->assertSame('unsupported_location_rule', array_values($warnings)[0]['code']);
        $this->assertStringContainsString('post_format', array_values($warnings)[0]['message']);
    }

    public function testRealisticExportIsClean(): void
    {
        $result = FieldsCommand::validatePaths([self::FIXTURES . '/acf-export-realistic.json'], [self::class, 'decode']);

        $this->assertSame(0, $result['errors']);
        $this->assertSame(0, $result['warnings']);
        $this->assertSame([], $result['lines']);
    }

    public function testUnreadableAndMalformedFilesAreErrors(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'pbf');
        file_put_contents($bad, '{nope');
        $result = FieldsCommand::validatePaths([$bad, '/nonexistent/group.json'], [self::class, 'decode']);
        unlink($bad);

        $this->assertSame(2, $result['errors']);
        $this->assertSame(['invalid_json', 'unreadable'], array_column($result['lines'], 'code'));
    }

    public function testModuleReportIsMerged(): void
    {
        $report = new Report();
        $report->addError('/theme/proto-fields/a/group.json', 'declaration_invalid', 'bad');
        $report->addWarning('/theme/proto-fields/b/group.json', 'duplicate_group_key', 'dup');

        $result = FieldsCommand::validatePaths([], [self::class, 'decode'], $report);

        $this->assertSame(1, $result['errors']);
        $this->assertSame(1, $result['warnings']);
        $this->assertSame('/theme/proto-fields/a/group.json', $result['lines'][0]['file']);
    }

    public function testWideLocationWarning(): void
    {
        $wide = tempnam(sys_get_temp_dir(), 'pbw');
        file_put_contents($wide, (string) json_encode([
            'key' => 'group_wide',
            'title' => 'Wide',
            'location' => [[['param' => 'post_status', 'operator' => '==', 'value' => 'publish']]],
            'fields' => [['key' => 'field_a', 'name' => 'a', 'label' => 'A', 'type' => 'text']],
        ]));
        $result = FieldsCommand::validatePaths([$wide], [self::class, 'decode']);
        unlink($wide);

        $this->assertSame(0, $result['errors']);
        $this->assertSame(['wide_location'], array_column($result['lines'], 'code'));
    }

    public function testIsWide(): void
    {
        $rule = static fn(string $p, string $op, string $v): array => ['param' => $p, 'operator' => $op, 'value' => $v];

        $this->assertTrue(FieldsCommand::isWide(self::located([[$rule('post_type', '!=', 'page')]])));
        $this->assertTrue(FieldsCommand::isWide(self::located([[$rule('post_type', '==', 'event')], [$rule('post_status', '==', 'draft')]])));
        $this->assertFalse(FieldsCommand::isWide(self::located([[$rule('post_type', '==', 'event'), $rule('post_status', '==', 'draft')]])));
        $this->assertFalse(FieldsCommand::isWide(self::located([[$rule('user_role', '==', 'editor')]])));
        $this->assertFalse(FieldsCommand::isWide(self::located([[$rule('page_template', '==', 'x.php')]])));
    }
}
