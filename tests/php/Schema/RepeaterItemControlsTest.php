<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Schema;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Schema\SchemaValidator;

/**
 * A repeater's itemControls: the sidebar half of a row.
 *
 * `fields` are bound to the canvas with data-proto-field and must be printed to
 * be editable. `itemControls` are for the values a template consumes rather than
 * prints -- read into a data attribute, parsed, passed to a style -- which have
 * no element to bind and so no way to be typed at all before this existed.
 */
final class RepeaterItemControlsTest extends TestCase
{
    /** @param array<string, mixed> $repeater */
    private function schema(array $repeater): array
    {
        return [
            'name'        => 'proto-blocks/demo',
            'protoBlocks' => ['fields' => ['rows' => $repeater]],
        ];
    }

    public function test_a_repeater_needs_no_item_controls(): void
    {
        // Every repeater written before itemControls existed declares none, and
        // must keep validating exactly as it did.
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema([
            'type'   => 'repeater',
            'fields' => ['title' => ['type' => 'text']],
        ])));
    }

    public function test_item_controls_are_accepted_beside_fields(): void
    {
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema([
            'type'         => 'repeater',
            'fields'       => ['title' => ['type' => 'text']],
            'itemControls' => [
                'phone' => ['type' => 'text', 'label' => 'Phone'],
                'tone'  => [
                    'type'    => 'select',
                    'label'   => 'Tone',
                    'options' => [['key' => 'light', 'label' => 'Light']],
                ],
            ],
        ])));
    }

    public function test_item_controls_must_be_an_object(): void
    {
        $validator = new SchemaValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($this->schema([
            'type'         => 'repeater',
            'fields'       => ['title' => ['type' => 'text']],
            'itemControls' => 'phone',
        ]));
    }

    public function test_a_row_cannot_hold_a_repeater_of_its_own(): void
    {
        // A list inside a list has nowhere to be laid out in a sidebar panel, and
        // the stored value would stop being a flat list of flat objects.
        $validator = new SchemaValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($this->schema([
            'type'         => 'repeater',
            'fields'       => ['title' => ['type' => 'text']],
            'itemControls' => [
                'nested' => ['type' => 'repeater', 'fields' => ['x' => ['type' => 'text']]],
            ],
        ]));
    }

    public function test_an_item_control_is_validated_as_a_control(): void
    {
        // A select with neither options nor an optionsSource is as wrong in a row
        // as it is in the block's own settings, and is reported the same way.
        $validator = new SchemaValidator();

        try {
            $validator->validate($this->schema([
                'type'         => 'repeater',
                'fields'       => ['title' => ['type' => 'text']],
                'itemControls' => ['pick' => ['type' => 'select', 'label' => 'Pick']],
            ]));

            $this->fail('A select with no options should not validate inside a row.');
        } catch (\InvalidArgumentException $e) {
            /* Named by its path so the author knows which row's control is wrong,
               not merely that something somewhere is. */
            $this->assertStringContainsString('rows.pick', $e->getMessage());
        }
    }
}
