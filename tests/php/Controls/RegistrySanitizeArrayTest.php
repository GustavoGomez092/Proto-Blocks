<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Controls;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Controls\Registry;

final class RegistrySanitizeArrayTest extends TestCase
{
    private function registry(): Registry
    {
        $registry = new Registry();
        $registry->register('multiselect', [
            'data_type' => 'array',
            'default'   => [],
        ]);

        return $registry;
    }

    public function test_non_array_becomes_empty_array(): void
    {
        $this->assertSame([], $this->registry()->sanitize('multiselect', 'nope'));
        $this->assertSame([], $this->registry()->sanitize('multiselect', null));
        $this->assertSame([], $this->registry()->sanitize('multiselect', 42));
    }

    public function test_clean_list_passes_through_order_intact(): void
    {
        $this->assertSame(
            ['18279', '18277', '18282'],
            $this->registry()->sanitize('multiselect', ['18279', '18277', '18282'])
        );
    }

    public function test_drops_non_scalars_and_empties_and_collapses_duplicates(): void
    {
        $this->assertSame(
            ['12', '9'],
            $this->registry()->sanitize('multiselect', ['12', ['nested'], '', '9', '12', null])
        );
    }

    public function test_reindexes_so_the_result_is_a_json_list_not_an_object(): void
    {
        $result = $this->registry()->sanitize('multiselect', [3 => 'a', 7 => 'b']);

        $this->assertSame(['a', 'b'], $result);
        $this->assertSame('["a","b"]', json_encode($result));
    }
}
