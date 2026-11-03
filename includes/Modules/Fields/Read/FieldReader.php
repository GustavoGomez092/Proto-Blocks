<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Read;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\GroupValidator;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\Store;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

/**
 * The one read path behind proto_field(), the ACF aliases,
 * $template->field() and the block bindings source.
 *
 * A name is read only when it is declared by a group whose location matches
 * the object (GroupIndex::fieldFor(), first group wins); anything else is
 * null. Values are escaped for their type unless `$raw` is set; an unset
 * field yields its default. Reading never writes: stores are asked with
 * has()/get() only. Nothing is cached here, because escaped output can
 * depend on the viewer.
 *
 * For a server-side resolver (Block Bindings) use resolve() + field() to
 * apply its own policy (code fields, readable posts) before value().
 */
final class FieldReader
{
    private static ?FieldReader $shared = null;

    private readonly Escaper $escaper;

    /** @param array<string, Store> $stores kind (ObjectRef::POST, ...) => store */
    public function __construct(
        private readonly GroupIndex $index,
        private readonly TypeRegistry $types,
        private readonly array $stores,
    ) {
        $this->escaper = new Escaper($types);
    }

    public static function setShared(?FieldReader $reader): void
    {
        self::$shared = $reader;
    }

    public static function shared(): ?FieldReader
    {
        return self::$shared;
    }

    /** The object a context names; null when it names none. */
    public function resolve(mixed $context): ?ObjectRef
    {
        return ContextResolver::resolve($context);
    }

    /** The declared value field of that name for the object, or null. */
    public function field(ObjectRef $ref, string $name): ?Field
    {
        $hit = $this->index->fieldFor($ref, $name);
        if ($hit === null) {
            return null;
        }
        $field = $hit['field'];

        return $this->readable($field) ? $field : null;
    }

    /** The value of a declared field for the object, escaped unless $raw. */
    public function value(ObjectRef $ref, Field $field, bool $raw = false): mixed
    {
        $store = $this->stores[$ref->kind] ?? null;
        if (!$store instanceof Store) {
            return null;
        }
        if ($store->has($ref, $field)) {
            return $this->escaper->stored($field, $store->get($ref, $field), $raw);
        }

        return $this->escaper->unset($field, $raw);
    }

    public function get(string $name, mixed $context = null, bool $raw = false): mixed
    {
        $ref = $this->resolve($context);
        if ($ref === null) {
            return null;
        }
        $field = $this->field($ref, $name);
        if ($field === null) {
            self::undeclared($name);
            return null;
        }

        return $this->value($ref, $field, $raw);
    }

    /** @return array<string, mixed> every value field of every matching group, first declaration of a name wins */
    public function all(mixed $context = null, bool $raw = false): array
    {
        $ref = $this->resolve($context);
        if ($ref === null) {
            return [];
        }
        $out = [];
        foreach ($this->index->forRef($ref) as $group) {
            foreach ($group->fields as $field) {
                if ($field->name === '' || array_key_exists($field->name, $out) || !$this->readable($field)) {
                    continue;
                }
                $out[$field->name] = $this->value($ref, $field, $raw);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> escaped rows of a repeater or flexible content field; [] otherwise */
    public function rows(string $name, mixed $context = null): iterable
    {
        $ref = $this->resolve($context);
        if ($ref === null) {
            return [];
        }
        $field = $this->field($ref, $name);
        if ($field === null) {
            self::undeclared($name);
            return [];
        }
        if ($field->type !== 'repeater' && $field->type !== 'flexible_content') {
            return [];
        }
        $rows = $this->value($ref, $field);

        return is_array($rows) ? array_values($rows) : [];
    }

    private function readable(Field $field): bool
    {
        if (in_array($field->type, GroupValidator::LAYOUT_ONLY_TYPES, true)) {
            return false;
        }
        try {
            return $this->types->for($field)->stores();
        } catch (\InvalidArgumentException) {
            return false; // unknown type: never read
        }
    }

    private static function undeclared(string $name): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('_doing_it_wrong')) {
            _doing_it_wrong(
                'proto_field',
                sprintf('No field named "%s" is declared for this object.', esc_html($name)),
                defined('PROTO_BLOCKS_VERSION') ? (string) PROTO_BLOCKS_VERSION : ''
            );
        }
    }
}
