<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Inflector;

final class InflectorTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function cases(): array
    {
        $pairs = [
            'Resource' => 'Resources', 'Case Study' => 'Case Studies', 'Day' => 'Days', 'Box' => 'Boxes',
            'Church' => 'Churches', 'Bus' => 'Buses', 'Person' => 'People', 'Child' => 'Children',
            'Media' => 'Media', 'News' => 'News', 'Series' => 'Series', 'Staff' => 'Staff',
            'Media Type' => 'Media Types', 'Leaf' => 'Leaves', 'Hero' => 'Heroes', 'Photo' => 'Photos',
        ];
        $out = [];
        foreach ($pairs as $in => $expected) {
            $out[$in] = [$in, $expected];
        }
        return $out;
    }

    #[DataProvider('cases')]
    public function testPlural(string $singular, string $expected): void
    {
        $this->assertSame($expected, Inflector::plural($singular));
    }
}
