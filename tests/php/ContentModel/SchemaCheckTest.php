<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\SchemaCheck;

final class SchemaCheckTest extends TestCase
{
    /** @return list<string> */
    private function paths(mixed $data, string $ref = '#/$defs/type'): array
    {
        $errors = SchemaCheck::validate($data, SchemaCheck::schema(), $ref);
        return array_map(static fn(array $e): string => $e['path'], $errors);
    }

    public function testMinimalTypeIsValid(): void
    {
        $this->assertSame([], SchemaCheck::validate(['name' => 'Product'], SchemaCheck::schema(), '#/$defs/type'));
    }

    public function testWrongTypesReported(): void
    {
        $paths = $this->paths(['name' => 'X', 'public' => 'yes', 'menu_position' => '22']);
        sort($paths);
        $this->assertSame(['/menu_position', '/public'], $paths);
    }

    public function testHasArchiveAcceptsBoolOrSlug(): void
    {
        foreach ([true, false, 'resources'] as $v) {
            $this->assertSame([], $this->paths(['name' => 'X', 'has_archive' => $v]));
        }
        $this->assertSame(['/has_archive'], $this->paths(['name' => 'X', 'has_archive' => 'Bad Slug']));
    }

    public function testRewriteFalseOrObject(): void
    {
        $this->assertSame([], $this->paths(['name' => 'X', 'rewrite' => false]));
        $this->assertSame([], $this->paths(['name' => 'X', 'rewrite' => ['slug' => 'resources', 'with_front' => false]]));
        $this->assertSame(['/rewrite'], $this->paths(['name' => 'X', 'rewrite' => 'resources']));
    }

    public function testInlineTaxonomyChecked(): void
    {
        $paths = $this->paths(['name' => 'X', 'taxonomies' => ['category', ['name' => 'Kind', 'hierarchical' => 'no']]]);
        $this->assertSame(['/taxonomies/1/hierarchical'], $paths);
    }

    public function testUnknownKeysPass(): void
    {
        $this->assertSame([], $this->paths(['name' => 'X', 'delete_with_user' => true]));
    }

    public function testTrailingNewlineFailsAnchoredPattern(): void
    {
        $this->assertSame(['/slug'], $this->paths(['name' => 'X', 'slug' => "abc\n"]));
    }

    public function testCapabilityTypeArrayMustHaveTwoStrings(): void
    {
        $this->assertSame(['/capability_type'], $this->paths(['name' => 'X', 'capability_type' => ['a']]));
        $this->assertSame([], $this->paths(['name' => 'X', 'capability_type' => ['a', 'b']]));
        $this->assertSame(['/capability_type'], $this->paths(['name' => 'X', 'capability_type' => ['a', 'b', 'c']]));
        $this->assertSame([], $this->paths(['name' => 'X', 'capability_type' => 'product']));
    }

    public function testInlineSchemaKeywords(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['a'],
            'additionalProperties' => false,
            'properties' => [
                'a' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'e' => ['enum' => ['x', 'y']],
            ],
        ];
        $run = static fn(mixed $d): array => array_column(SchemaCheck::validate($d, $schema), 'path');

        $this->assertSame([], $run(['a' => 3, 'e' => 'x']));
        $this->assertSame(['/a'], $run([]));
        $this->assertSame(['/z'], $run(['a' => 3, 'z' => 1]));
        $this->assertSame(['/e'], $run(['a' => 3, 'e' => 'q']));
        $this->assertSame(['/a'], $run(['a' => 0]));
        $this->assertSame(['/a'], $run(['a' => 6]));
    }

    public function testAllOfReportsEveryBranch(): void
    {
        $schema = ['allOf' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
            ['required' => ['b']],
        ]];
        $run = static fn(mixed $d): array => array_column(SchemaCheck::validate($d, $schema), 'path');

        $this->assertSame([], $run(['a' => 1, 'b' => 2]));
        $this->assertSame(['/b'], $run(['a' => 1]));
        $this->assertSame(['/a', '/b'], $run(['a' => 'x']));
    }

    public function testNameIsOptionalForOverrides(): void
    {
        $this->assertSame([], $this->paths(['template' => [['core/paragraph']]]));
        $this->assertSame([], $this->paths(['labels' => ['menu_name' => 'Topics']], '#/$defs/taxonomy'));
    }

    public function testInlineTaxonomyRequiresName(): void
    {
        $this->assertSame(['/taxonomies/0/name'], $this->paths(['taxonomies' => [['hierarchical' => true]]]));
    }

    public function testSlugPattern(): void
    {
        $this->assertSame(['/slug'], $this->paths(['name' => 'X', 'slug' => 'Product']));
        $this->assertSame(['/slug'], $this->paths(['name' => 'X', 'slug' => str_repeat('a', 21)]));
        $this->assertSame([], $this->paths(['name' => 'X', 'slug' => str_repeat('a', 20)]));
        $this->assertSame([], $this->paths(['name' => 'X', 'slug' => str_repeat('a', 32)], '#/$defs/taxonomy'));
    }

    /** Same inputs and paths as the "keys naming PHP code" case in src/admin/__tests__/content-model/schema.test.ts. */
    public function testFalsePropertySchemaAndLabelMarkupParity(): void
    {
        $keys = ['register_meta_box_cb', 'meta_box_cb', 'meta_box_sanitize_cb', 'update_count_callback', 'rest_controller_class',
            'autosave_rest_controller_class', 'revisions_rest_controller_class', '_builtin', '_edit_link'];
        foreach ($keys as $key) {
            $this->assertSame(['/' . $key], $this->paths(['name' => 'X', $key => 'phpinfo']), $key);
            $this->assertSame(['/' . $key], $this->paths(['name' => 'X', $key => 'phpinfo'], '#/$defs/taxonomy'), $key);
            $this->assertSame(['/' . $key], $this->paths(['title' => 'X', $key => 'phpinfo'], '#/$defs/options'), $key);
        }
        $this->assertSame(['/taxonomies/0/meta_box_cb'], $this->paths(['name' => 'X', 'taxonomies' => [['name' => 'Kind', 'meta_box_cb' => 'x']]]));
        $this->assertSame(['/labels/menu_name'], $this->paths(['name' => 'X', 'labels' => ['menu_name' => '<b>X</b>']]));
        $this->assertSame(['/name'], $this->paths(['name' => 'X>'], '#/$defs/taxonomy'));
        $this->assertSame(['/tabs/0/title'], $this->paths(['title' => 'X', 'tabs' => [['title' => '<x']]], '#/$defs/options'));
        $this->assertSame([], $this->paths(['name' => 'Q&A', 'labels' => ['menu_name' => 'Shop "new" & more!']]));
        $this->assertSame([], $this->paths(['name' => 'X', 'my_cb' => 'phpinfo']), 'unknown keys still pass the schema');
    }
}
