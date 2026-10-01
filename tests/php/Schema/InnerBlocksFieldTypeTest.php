<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Schema;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Schema\SchemaValidator;

final class InnerBlocksFieldTypeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(string $type): array
    {
        return [
            'name'        => 'proto-blocks/demo',
            'protoBlocks' => ['fields' => ['body' => ['type' => $type]]],
        ];
    }

    public function test_inner_blocks_is_canonical_and_silent(): void
    {
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema('inner-blocks')));
        $this->assertSame([], $validator->getErrors());
        $this->assertSame([], $validator->getWarnings());
    }

    public function test_legacy_innerblocks_is_accepted_with_a_warning_suggesting_the_canonical_spelling(): void
    {
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema('innerblocks')));
        $this->assertSame([], $validator->getErrors());
        $this->assertCount(1, $validator->getWarnings());
        $this->assertStringContainsString('"innerblocks"', $validator->getWarnings()[0]);
        $this->assertStringContainsString('use "inner-blocks"', $validator->getWarnings()[0]);
    }

    public function test_legacy_alias_inside_a_repeater_warns_too(): void
    {
        $validator = new SchemaValidator();
        $validator->validate([
            'name'        => 'proto-blocks/demo',
            'protoBlocks' => ['fields' => ['items' => [
                'type'   => 'repeater',
                'fields' => ['inner' => ['type' => 'innerblocks']],
            ]]],
        ]);

        $legacy = array_filter(
            $validator->getWarnings(),
            static fn (string $w): bool => str_contains($w, 'use "inner-blocks"')
        );
        $this->assertCount(1, $legacy);
    }
}
