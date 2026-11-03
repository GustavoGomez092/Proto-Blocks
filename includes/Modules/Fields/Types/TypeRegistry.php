<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/** Maps a canonical field type name to the class that handles it. */
final class TypeRegistry
{
    /** @var array<string, FieldTypeInterface> */
    private array $byName = [];

    /**
     * @param iterable<FieldTypeInterface> $types
     * @throws \InvalidArgumentException when two types claim the same name
     */
    public function __construct(iterable $types)
    {
        foreach ($types as $type) {
            foreach ($type->names() as $name) {
                if (isset($this->byName[$name])) {
                    throw new \InvalidArgumentException(sprintf('Field type "%s" is registered twice.', $name));
                }
                $this->byName[$name] = $type;
            }
        }
    }

    /** Every core type family. */
    public static function withCoreTypes(): self
    {
        // Nested types resolve sub-field types through this same registry, lazily.
        $registry = null;
        $self = static function () use (&$registry): self {
            return $registry;
        };

        return $registry = new self([
            new TextType(),
            new WysiwygType(),
            new ChoiceType(),
            new TrueFalseType(),
            new ColorType(),
            new DateType(),
            new LinkType(),
            new CodeType(),
            new LayoutType(),
            new ImageType(), new FileType(), new GalleryType(), new VideoType(), new TaxonomyType(), new RelationshipType(),
            new GroupType($self), new RepeaterType($self), new FlexibleContentType($self),
        ]);
    }

    /** @throws \InvalidArgumentException for 'unknown' or any unregistered type */
    public function for(Field $field): FieldTypeInterface
    {
        return $this->byName[$field->type]
            ?? throw new \InvalidArgumentException(sprintf('No field type is registered for "%s".', $field->type));
    }
}
