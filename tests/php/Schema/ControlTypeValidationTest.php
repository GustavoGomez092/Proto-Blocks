<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Schema;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Schema\SchemaValidator;

final class ControlTypeValidationTest extends TestCase
{
    /** @param array<string, mixed> $controls */
    private function schema(array $controls): array
    {
        return [
            'name'         => 'proto-blocks/demo',
            'protoBlocks'  => ['controls' => $controls],
        ];
    }

    public function test_gallery_is_valid_with_nothing_but_a_label(): void
    {
        // Unlike select/multiselect, a gallery has no option list to declare:
        // its source is the media library. Requiring `options` here would make
        // the only sensible configuration invalid.
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema([
            'images' => ['type' => 'gallery', 'label' => 'Images'],
        ])));

        $this->assertSame([], $validator->getErrors());
        $this->assertSame([], $validator->getWarnings());
    }

    public function test_multiselect_with_an_options_source_is_valid_and_silent(): void
    {
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema([
            'picks' => [
                'type'          => 'multiselect',
                'label'         => 'Picks',
                'optionsSource' => 'wp:posts',
                'sourceArgs'    => ['post_type' => 'product'],
            ],
        ])));

        $this->assertSame([], $validator->getErrors());
        $this->assertSame([], $validator->getWarnings());
    }

    public function test_multiselect_with_static_options_is_valid(): void
    {
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema([
            'picks' => [
                'type'    => 'multiselect',
                'label'   => 'Picks',
                'options' => [['key' => 'a', 'label' => 'A']],
            ],
        ])));

        $this->assertSame([], $validator->getWarnings());
    }

    public function test_multiselect_without_options_or_source_is_a_hard_error(): void
    {
        $validator = new SchemaValidator();

        try {
            $validator->validate($this->schema([
                'picks' => ['type' => 'multiselect', 'label' => 'Picks'],
            ]));
            $this->fail('Expected InvalidArgumentException for a multiselect with no options.');
        } catch (\InvalidArgumentException $e) {
            $this->assertCount(1, $validator->getErrors());
            $this->assertStringContainsString('picks', $validator->getErrors()[0]);
            $this->assertStringContainsString('of type "multiselect"', $validator->getErrors()[0]);
        }
    }

    /**
     * VALID_CONTROL_TYPES listed 7 of the 12 types registerCoreControlTypes()
     * registers, so these five warned spuriously on every validate.
     *
     * @dataProvider previouslyUnlistedTypes
     */
    public function test_registered_control_types_do_not_warn(string $type): void
    {
        $validator = new SchemaValidator();
        $validator->validate($this->schema([
            'thing' => ['type' => $type, 'label' => 'Thing', 'options' => [['key' => 'a', 'label' => 'A']]],
        ]));

        $this->assertSame([], $validator->getWarnings(), "Control type '{$type}' should be recognised");
    }

    /** @return array<string, array{string}> */
    public static function previouslyUnlistedTypes(): array
    {
        return [
            'textarea'      => ['textarea'],
            'checkbox'      => ['checkbox'],
            'color-palette' => ['color-palette'],
            'radio'         => ['radio'],
            'video'         => ['video'],
        ];
    }

    /**
     * Drift guard: verify that every entry in VALID_CONTROL_TYPES is actually
     * registered in Plugin::registerCoreControlTypes(). Prevents silent bugs
     * where a type is listed as valid but produces null from the registry,
     * leading to unsanitised values with no diagnostic.
     */
    public function test_every_valid_control_type_is_actually_registered(): void
    {
        // Extract VALID_CONTROL_TYPES from SchemaValidator using reflection
        $constant = new \ReflectionClassConstant(SchemaValidator::class, 'VALID_CONTROL_TYPES');
        $validTypes = $constant->getValue();

        // Actually invoke registerCoreControlTypes() and read back what it registered,
        // rather than parsing the source. A regex over the method's source text has a
        // silent-pass mode: a commented-out registration still matches the pattern, so
        // a type could be listed as valid, fail to register, and the guard would not
        // notice. registerCoreControlTypes() is private and touches no WordPress
        // functions, so it can be invoked directly under this WP-less bootstrap.
        $plugin = \ProtoBlocks\Core\Plugin::getInstance();
        (new \ReflectionMethod(\ProtoBlocks\Core\Plugin::class, 'registerCoreControlTypes'))
            ->invoke($plugin);
        $registeredTypes = array_keys($plugin->getControlRegistry()->getAll());

        $this->assertNotEmpty($registeredTypes, 'No registered control types found in Plugin.php');

        // Sort for consistent comparison
        sort($validTypes);
        sort($registeredTypes);

        $this->assertSame(
            $validTypes,
            $registeredTypes,
            sprintf(
                "VALID_CONTROL_TYPES mismatch:\nValid list: %s\nRegistered: %s\nMissing from registration: %s\nExtra in registration: %s",
                implode(', ', $validTypes),
                implode(', ', $registeredTypes),
                implode(', ', array_diff($validTypes, $registeredTypes)),
                implode(', ', array_diff($registeredTypes, $validTypes))
            )
        );
    }
}
