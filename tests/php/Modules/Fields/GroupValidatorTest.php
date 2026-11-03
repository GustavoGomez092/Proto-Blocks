<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\GroupValidator;

require_once __DIR__ . '/wp-stubs.php';

final class GroupValidatorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../fixtures/fields';

    private static function fixture(string $file): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{string, string, string}> file, expected code, level */
    public static function invalidGroups(): array
    {
        return [
            'missing-key' => ['missing-key.json', 'missing_key', 'error'],
            'bad-name' => ['bad-name.json', 'invalid_name', 'error'],
            'duplicate-name' => ['duplicate-name.json', 'duplicate_name', 'error'],
            'unknown-rule' => ['unknown-rule.json', 'unsupported_location_rule', 'warning'],
            'layouts-on-text' => ['layouts-on-text.json', 'layouts_not_allowed', 'error'],
            'repeater-without-subfields' => ['repeater-without-subfields.json', 'sub_fields_required', 'error'],
        ];
    }

    public function testProviderCoversEveryInvalidFixture(): void
    {
        $files = array_map('basename', glob(self::FIXTURES . '/groups-invalid/*.json') ?: []);
        sort($files);
        $listed = array_column(self::invalidGroups(), 0);
        sort($listed);
        $this->assertSame($files, $listed);
    }

    #[DataProvider('invalidGroups')]
    public function testInvalidFixtureReportsCode(string $file, string $code, string $level): void
    {
        $report = GroupValidator::validate(self::fixture('groups-invalid/' . $file), false);
        $errorCodes = array_column($report->errors, 'code');
        $warningCodes = array_column($report->warnings, 'code');

        if ($level === 'error') {
            $this->assertContains($code, $errorCodes);
            $this->assertFalse($report->ok());
        } else {
            $this->assertContains($code, $warningCodes);
            $this->assertSame([], $report->errors);
            $this->assertTrue($report->ok());
        }
        foreach (array_merge($report->errors, $report->warnings) as $entry) {
            $this->assertSame(['path', 'code', 'message'], array_keys($entry));
        }
    }

    public function testBadNameFlagsBothFields(): void
    {
        $report = GroupValidator::validate(self::fixture('groups-invalid/bad-name.json'), false);
        $paths = array_column(array_filter($report->errors, static fn(array $e): bool => $e['code'] === 'invalid_name'), 'path');
        $this->assertSame(['fields[0].name', 'fields[1].name'], array_values($paths));
    }

    public function testRealisticExportValid(): void
    {
        $report = GroupValidator::validate(self::fixture('acf-export-realistic.json'), false);
        $this->assertSame([], $report->errors);
        $this->assertSame([], $report->warnings);

        $perf = GroupValidator::validate(self::fixture('perf-50.json'), false);
        $this->assertSame([], $perf->errors);
    }

    public function testImpliedLocationSkipsLocationChecks(): void
    {
        $report = GroupValidator::validate(self::fixture('groups-invalid/unknown-rule.json'), true);
        $this->assertSame([], $report->warnings);
        $this->assertSame([], $report->errors);
    }

    public function testEmptyNameAllowedOnlyForLayoutTypes(): void
    {
        $group = [
            'key' => 'group_x', 'title' => 'X',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'fields' => [
                ['key' => 'field_tab', 'name' => '', 'label' => 'Tab', 'type' => 'tab'],
                ['key' => 'field_msg', 'label' => 'Note', 'type' => 'message'],
                ['key' => 'field_text', 'name' => '', 'label' => 'Text', 'type' => 'text'],
            ],
        ];
        $errors = GroupValidator::validate($group, false)->errors;
        $this->assertSame(['fields[2].name'], array_column($errors, 'path'));
    }

    public function testNameWithTrailingNewlineRejected(): void
    {
        $group = [
            'key' => 'group_x', 'title' => 'X',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'fields' => [['key' => 'field_a', 'name' => "title\n", 'label' => 'A', 'type' => 'text']],
        ];
        $this->assertContains('invalid_name', array_column(GroupValidator::validate($group, false)->errors, 'code'));
        $this->assertSame(0, preg_match(GroupValidator::NAME_PATTERN, "ok\n"));
    }

    public function testDuplicateKeyAtAnyDepthIsError(): void
    {
        $group = [
            'key' => 'group_x', 'title' => 'X',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'fields' => [
                ['key' => 'field_a', 'name' => 'a', 'label' => 'A', 'type' => 'text'],
                ['key' => 'field_rows', 'name' => 'rows', 'label' => 'Rows', 'type' => 'repeater', 'sub_fields' => [
                    ['key' => 'field_a', 'name' => 'inner', 'label' => 'Inner', 'type' => 'text'],
                ]],
            ],
        ];
        $errors = GroupValidator::validate($group, false)->errors;
        $this->assertSame(['duplicate_key'], array_column($errors, 'code'));
        $this->assertSame('fields[1].sub_fields[0].key', $errors[0]['path']);
    }

    public function testEmptyLocationBranchWarned(): void
    {
        $group = ['key' => 'group_x', 'title' => 'X', 'location' => [[]], 'fields' => []];
        $report = GroupValidator::validate($group, false);
        $this->assertSame([], $report->errors);
        $this->assertSame(['empty_location_branch'], array_column($report->warnings, 'code'));
        $this->assertSame([], GroupValidator::validate($group, true)->warnings);
    }

    public function testInvalidOperatorAndShapeAreErrors(): void
    {
        $group = [
            'key' => 'group_x', 'title' => 'X',
            'location' => [[['param' => 'post_type', 'operator' => '>=', 'value' => 'post']]],
            'fields' => [],
        ];
        $this->assertContains('invalid_location', array_column(GroupValidator::validate($group, false)->errors, 'code'));
        $this->assertContains('missing_title', array_column(GroupValidator::validate(['key' => 'k', 'fields' => []], false)->errors, 'code'));
        $this->assertContains('missing_fields', array_column(GroupValidator::validate(['key' => 'k', 'title' => 't'], false)->errors, 'code'));
    }
}
