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

    public function testSlugPattern(): void
    {
        $this->assertSame(['/slug'], $this->paths(['name' => 'X', 'slug' => 'Product']));
        $this->assertSame(['/slug'], $this->paths(['name' => 'X', 'slug' => str_repeat('a', 21)]));
        $this->assertSame([], $this->paths(['name' => 'X', 'slug' => str_repeat('a', 20)]));
        $this->assertSame([], $this->paths(['name' => 'X', 'slug' => str_repeat('a', 32)], '#/$defs/taxonomy'));
    }
}
