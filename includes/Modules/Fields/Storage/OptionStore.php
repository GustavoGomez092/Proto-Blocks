<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Storage;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\OptionsPages\ProtectedOptions;

/**
 * Options page values: one option per field, named "<page>_<name>". Autoload
 * follows the field's group: `raw['autoload'] === false` turns it off,
 * anything else (or no group found) keeps the WordPress default of on.
 * Protected option names (siteurl, *_user_roles, ...) are refused before
 * anything is written.
 */
final class OptionStore implements Store
{
    public function __construct(private readonly ?GroupIndex $index = null)
    {
    }

    public static function optionName(string $page, string $name): string
    {
        return $page . '_' . $name;
    }

    public function get(ObjectRef $ref, Field $f): mixed
    {
        if (!$this->usable($ref, $f)) {
            return null;
        }
        $missing = self::missing();
        $value = get_option(self::optionName($ref->page, $f->name), $missing);

        return $value === $missing ? null : $value;
    }

    /** @throws \DomainException when the option name is protected; nothing is written then */
    public function set(ObjectRef $ref, Field $f, mixed $value): void
    {
        if (!$this->usable($ref, $f)) {
            return;
        }
        $option = self::optionName($ref->page, $f->name);
        if (ProtectedOptions::isProtected($option)) {
            throw new \DomainException(sprintf('Option "%s" is reserved and cannot be written by a field.', $option));
        }
        update_option($option, $value, $this->autoload($f));
    }

    public function has(ObjectRef $ref, Field $f): bool
    {
        if (!$this->usable($ref, $f)) {
            return false;
        }
        $missing = self::missing();

        return get_option(self::optionName($ref->page, $f->name), $missing) !== $missing;
    }

    private function autoload(Field $f): bool
    {
        $group = $this->groupOf($f);

        return !($group !== null && ($group->raw['autoload'] ?? true) === false);
    }

    private function groupOf(Field $f): ?FieldGroup
    {
        foreach ($this->index?->all() ?? [] as $group) {
            foreach ($group->fields as $top) {
                if ($top === $f || ($top->key === $f->key && $top->name === $f->name)) {
                    return $group;
                }
            }
        }

        return null;
    }

    private function usable(ObjectRef $ref, Field $f): bool
    {
        if ($ref->kind !== ObjectRef::OPTION) {
            throw new \InvalidArgumentException(sprintf('The option store cannot hold %s values.', $ref->kind));
        }

        return $ref->page !== '' && $f->name !== '';
    }

    private static function missing(): object
    {
        static $sentinel = null;

        return $sentinel ??= new \stdClass();
    }
}
