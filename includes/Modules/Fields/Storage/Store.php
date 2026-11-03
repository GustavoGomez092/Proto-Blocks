<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Storage;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * Raw value storage for one kind of object: one row (meta row or option) per
 * top-level field, keyed by the field name. A store writes exactly what it is
 * given through the WordPress write function, so the sanitize callback
 * registered for that key (TrustCallbacks) decides what is kept. Stores never
 * filter or escape values themselves.
 *
 * Meta stores slash values before update_*_meta(), which unslashes them, so
 * backslashes in code and text survive the write.
 */
interface Store
{
    /** The stored value, or null when nothing is stored for this field. */
    public function get(ObjectRef $ref, Field $f): mixed;

    /** @throws \InvalidArgumentException when $ref is of another kind */
    public function set(ObjectRef $ref, Field $f, mixed $value): void;

    public function has(ObjectRef $ref, Field $f): bool;
}
