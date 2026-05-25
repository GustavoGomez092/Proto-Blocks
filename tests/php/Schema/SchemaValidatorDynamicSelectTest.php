<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Schema;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Schema\SchemaValidator;

final class SchemaValidatorDynamicSelectTest extends TestCase
{
    private function baseSchema(array $controls): array
    {
        return [
            'name' => 'proto-blocks/demo',
            'protoBlocks' => [
                'version' => '1.0',
                'template' => 'template.php',
                'controls' => $controls,
            ],
        ];
    }

    public function test_select_with_options_source_is_valid(): void
    {
        $validator = new SchemaValidator();

        $valid = $validator->validate($this->baseSchema([
            'relatedPage' => [
                'type' => 'select',
                'optionsSource' => 'wp:posts',
            ],
        ]));

        $this->assertTrue($valid);
    }

    public function test_select_without_options_or_source_throws(): void
    {
        $validator = new SchemaValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($this->baseSchema([
            'broken' => ['type' => 'select'],
        ]));
    }
}
