<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Definition\Report;

require_once __DIR__ . '/wp-stubs.php';

final class GroupNormalizerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../fixtures/fields';

    private static function fixture(string $file): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function realistic(): FieldGroup
    {
        $result = GroupNormalizer::normalizeMany(self::fixture('acf-export-realistic.json'), 'file:acf');
        return $result['groups'][0];
    }

    /** @return list<string> */
    private static function codes(array $entries): array
    {
        return array_map(static fn(array $e): string => $e['code'], $entries);
    }

    private static function simpleGroup(array $fields, ?array $location = null): array
    {
        return [
            'key' => 'group_test',
            'title' => 'Test',
            'location' => $location ?? [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'fields' => $fields,
        ];
    }

    public function testAcfExportLoadsWithoutErrors(): void
    {
        $result = GroupNormalizer::normalizeMany(self::fixture('acf-export-realistic.json'), 'file:acf');

        $this->assertInstanceOf(Report::class, $result['report']);
        $this->assertSame([], $result['report']->errors);
        $this->assertSame([], $result['report']->warnings);
        $this->assertCount(1, $result['groups']);

        $group = $result['groups'][0];
        $this->assertSame('group_6543a1b2c3d4e', $group->key);
        $this->assertSame('Event details', $group->title);
        $this->assertSame('acf_after_title', $group->position);
        $this->assertSame('default', $group->style);
        $this->assertSame('file:acf', $group->source);
        $this->assertCount(18, $group->fields);
        $this->assertTrue($group->location->isRenderable());
        $this->assertFalse($group->location->implied);

        $this->assertSame('date', $group->field('start_date')->type);
        $this->assertSame('date_picker', $group->field('start_date')->declaredType);
        $this->assertTrue($group->field('start_date')->required);
        $this->assertSame('select', $group->field('audience')->type);
        $this->assertTrue((bool) $group->field('audience')->setting('multiple'));
        $this->assertSame('color', $group->field('accent')->type);
        $this->assertSame('tab', $group->fields[0]->type);
        $this->assertNull($group->field('nope'));
    }

    public function testAcfOnlyKeysPreserved(): void
    {
        $group = self::realistic();

        $this->assertSame('array', $group->field('hero')->setting('return_format'));
        $this->assertSame('', $group->raw['hide_on_screen']);
        $this->assertSame(['width' => '', 'class' => '', 'id' => ''], $group->field('subtitle')->setting('wrapper'));
        $this->assertSame(120, $group->field('subtitle')->setting('maxlength'));
        $this->assertSame('fallback', $group->field('subtitle')->setting('not_set', 'fallback'));
        $this->assertSame('checkbox', $group->field('topics')->setting('field_type'));
    }

    public function testNestedFieldsNormalised(): void
    {
        $group = self::realistic();

        $agenda = $group->field('agenda');
        $this->assertSame('repeater', $agenda->type);
        $this->assertCount(3, $agenda->subFields);
        $this->assertInstanceOf(Field::class, $agenda->subFields[1]);
        $this->assertSame('title', $agenda->subFields[1]->name);
        $this->assertTrue($agenda->subFields[1]->required);
        $this->assertSame('time', $agenda->subFields[0]->type);

        $sections = $group->field('sections');
        $this->assertCount(2, $sections->layouts);
        $this->assertSame('quote', $sections->layouts[1]['name']);
        $this->assertCount(2, $sections->layouts[1]['subFields']);
        $this->assertSame(3, $sections->layouts[1]['max']);

        $titleKey = $agenda->subFields[1]->key;
        $this->assertSame($agenda->subFields[1], $group->fieldByKey($titleKey));
        $cite = $sections->layouts[1]['subFields'][1];
        $this->assertSame($cite, $group->fieldByKey($cite->key));
        $this->assertNull($group->fieldByKey('field_missing'));

        $stream = $group->field('stream_url');
        $this->assertSame(
            [[['field' => $group->field('is_online')->key, 'operator' => '==', 'value' => '1']]],
            $stream->conditions
        );
        $this->assertSame([], $group->field('subtitle')->conditions);
    }

    public function testUnknownLocationRuleMakesGroupUnrenderable(): void
    {
        $result = GroupNormalizer::normalize(self::fixture('groups-invalid/unknown-rule.json'), 'file:unknown');
        $group = $result['group'];

        $this->assertInstanceOf(FieldGroup::class, $group);
        $this->assertFalse($group->location->isRenderable());
        $this->assertSame(['post_format'], $group->location->unsupported);
        $this->assertSame([], $result['report']->errors);

        $warnings = array_values(array_filter(
            $result['report']->warnings,
            static fn(array $w): bool => $w['code'] === 'unsupported_location_rule'
        ));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('post_format', $warnings[0]['message']);
    }

    public function testImpliedLocationOverridesFileLocation(): void
    {
        $raw = self::simpleGroup([['key' => 'field_a', 'name' => 'a', 'label' => 'A', 'type' => 'text']]);
        $result = GroupNormalizer::normalize($raw, 'type:product', '/t/proto-types/product/type.jsonc', ['post_type' => 'product']);
        $group = $result['group'];

        $this->assertTrue($group->location->implied);
        $this->assertSame([[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]], $group->location->or);
        $this->assertTrue($group->location->isRenderable());
        $this->assertSame('/t/proto-types/product/type.jsonc', $group->path);
        $this->assertContains('location_ignored', self::codes($result['report']->warnings));

        // No explicit location: implied applies silently.
        unset($raw['location']);
        $quiet = GroupNormalizer::normalize($raw, 'type:product', null, ['post_type' => 'product']);
        $this->assertSame([], $quiet['report']->warnings);
        $this->assertSame('product', $quiet['group']->location->or[0][0]['value']);
    }

    public function testUnknownFieldTypeSkippedWithWarning(): void
    {
        $raw = self::simpleGroup([
            ['key' => 'field_map', 'name' => 'map', 'label' => 'Map', 'type' => 'google_map'],
            ['key' => 'field_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text'],
        ]);
        $result = GroupNormalizer::normalize($raw, 'php');

        $this->assertInstanceOf(FieldGroup::class, $result['group']);
        $this->assertNull($result['group']->field('map'));
        $this->assertCount(1, $result['group']->fields);
        $this->assertSame('title', $result['group']->fields[0]->name);
        $this->assertSame([], $result['report']->errors);

        $warnings = array_values(array_filter($result['report']->warnings, static fn(array $w): bool => $w['code'] === 'unknown_type'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('google_map', $warnings[0]['message']);
    }

    public function testAliasesMapOntoCanonicalTypes(): void
    {
        $raw = self::simpleGroup([
            ['key' => 'field_cb', 'name' => 'cb', 'label' => 'Cb', 'type' => 'checkbox', 'choices' => ['a' => 'A']],
            ['key' => 'field_po', 'name' => 'po', 'label' => 'Po', 'type' => 'post_object'],
            ['key' => 'field_pl', 'name' => 'pl', 'label' => 'Pl', 'type' => 'page_link', 'multiple' => 1],
            ['key' => 'field_us', 'name' => 'us', 'label' => 'Us', 'type' => 'user'],
            ['key' => 'field_bg', 'name' => 'bg', 'label' => 'Bg', 'type' => 'button_group'],
            ['key' => 'field_oe', 'name' => 'oe', 'label' => 'Oe', 'type' => 'oembed'],
            ['key' => 'field_dt', 'name' => 'dt', 'label' => 'Dt', 'type' => 'date_time_picker'],
        ]);
        $group = GroupNormalizer::normalize($raw, 'php')['group'];

        $this->assertSame('select', $group->field('cb')->type);
        $this->assertTrue($group->field('cb')->setting('multiple'));
        $this->assertSame('checkbox', $group->field('cb')->declaredType);
        $this->assertSame('relationship', $group->field('po')->type);
        $this->assertSame(1, $group->field('po')->setting('max'));
        $this->assertSame('relationship', $group->field('pl')->type);
        $this->assertNull($group->field('pl')->setting('max'));
        $this->assertSame('select', $group->field('us')->type);
        $this->assertSame('wp:users', $group->field('us')->setting('optionsSource'));
        $this->assertSame('radio', $group->field('bg')->type);
        $this->assertSame('video', $group->field('oe')->type);
        $this->assertSame('datetime', $group->field('dt')->type);
        $this->assertSame('date', GroupNormalizer::aliases()['date_picker']);
    }

    public function testSingleObjectAndArrayBothAccepted(): void
    {
        $raw = self::simpleGroup([['key' => 'field_a', 'name' => 'a', 'label' => 'A', 'type' => 'text']]);

        $one = GroupNormalizer::normalizeMany($raw, 'php');
        $list = GroupNormalizer::normalizeMany([$raw], 'php');

        $this->assertCount(1, $one['groups']);
        $this->assertCount(1, $list['groups']);
        $this->assertSame($one['groups'][0]->toArray(), $list['groups'][0]->toArray());
    }

    public function testMixedLocationKindsWarned(): void
    {
        $fields = [['key' => 'field_a', 'name' => 'a', 'label' => 'A', 'type' => 'text']];

        $mixed = GroupNormalizer::normalize(self::simpleGroup($fields, [[
            ['param' => 'post_type', 'operator' => '==', 'value' => 'post'],
            ['param' => 'user_role', 'operator' => '==', 'value' => 'all'],
        ]]), 'php');
        $this->assertContains('mixed_location_kinds', self::codes($mixed['report']->warnings));

        $same = GroupNormalizer::normalize(self::simpleGroup($fields, [[
            ['param' => 'post_type', 'operator' => '==', 'value' => 'post'],
            ['param' => 'post_status', 'operator' => '==', 'value' => 'publish'],
        ]]), 'php');
        $this->assertNotContains('mixed_location_kinds', self::codes($same['report']->warnings));
        $this->assertSame([], $same['report']->warnings);
    }

    public function testStructuralErrorsReturnNoGroupAndNeverThrow(): void
    {
        $result = GroupNormalizer::normalize(self::fixture('groups-invalid/missing-key.json'), 'php');
        $this->assertNull($result['group']);
        $this->assertContains('missing_key', self::codes($result['report']->errors));

        $garbage = GroupNormalizer::normalizeMany(['fields' => 'nope', 'location' => 7], 'php');
        $this->assertSame([], $garbage['groups']);
        $this->assertFalse($garbage['report']->ok());
    }

    public function testToArrayShape(): void
    {
        $group = self::realistic();
        $agenda = $group->field('agenda')->toArray();

        $this->assertSame(
            ['key', 'name', 'label', 'type', 'declaredType', 'required', 'default', 'instructions', 'placeholder', 'conditions', 'subFields', 'layouts', 'settings'],
            array_keys($agenda)
        );
        $this->assertSame('title', $agenda['subFields'][1]['name']);
        $this->assertIsArray($agenda['subFields'][1]);

        $sections = $group->field('sections')->toArray();
        $this->assertSame('quote', $sections['layouts'][1]['name']);
        $this->assertSame('cite', $sections['layouts'][1]['subFields'][1]['name']);

        $this->assertSame(['key', 'title', 'position', 'style', 'fields'], array_keys($group->toArray()));
        $this->assertCount(18, $group->toArray()['fields']);
    }
}
