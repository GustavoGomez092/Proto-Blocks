<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Storage;

use ProtoBlocks\Modules\Fields\Definition\Field;

/** Term meta, one row per field. */
final class TermMetaStore implements Store
{
    public function get(ObjectRef $ref, Field $f): mixed
    {
        return $this->has($ref, $f) ? get_term_meta($ref->id, $f->name, true) : null;
    }

    public function set(ObjectRef $ref, Field $f, mixed $value): void
    {
        if ($this->usable($ref, $f)) {
            update_term_meta($ref->id, $f->name, wp_slash($value));
        }
    }

    public function has(ObjectRef $ref, Field $f): bool
    {
        if (!$this->usable($ref, $f)) {
            return false;
        }
        $all = get_term_meta($ref->id, $f->name, false);

        return is_array($all) && $all !== [];
    }

    private function usable(ObjectRef $ref, Field $f): bool
    {
        if ($ref->kind !== ObjectRef::TERM) {
            throw new \InvalidArgumentException(sprintf('The term meta store cannot hold %s values.', $ref->kind));
        }

        return $ref->id > 0 && $f->name !== '';
    }
}
