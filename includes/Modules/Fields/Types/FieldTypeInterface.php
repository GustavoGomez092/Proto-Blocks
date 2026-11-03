<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * One field type family: how its value is sanitised, shaped, exposed over REST
 * and escaped on output. Trust is the caller's job: sanitize() is the
 * untrusted-user path (users without unfiltered_html); normalize() is the
 * shape-only path used for everyone.
 */
interface FieldTypeInterface
{
    /**
     * Canonical field type names this class handles.
     *
     * @return list<string>
     */
    public function names(): array;

    /** False for presentation-only types (tab, accordion, message). */
    public function stores(): bool;

    /**
     * JSON Schema for show_in_rest and the register_meta 'type'.
     *
     * @return array<string, mixed>
     */
    public function restSchema(Field $field): array;

    /**
     * Untrusted-user sanitiser; returns the storable shape. A type that cannot
     * be written by an untrusted user (code) returns null, the "refuse"
     * sentinel.
     */
    public function sanitize(mixed $value, Field $field): mixed;

    /** Shape coercion (types, array keys) for every user; no content filtering. */
    public function normalize(mixed $value, Field $field): mixed;

    /** Getter output: a stored value made safe for HTML output. */
    public function escape(mixed $stored, Field $field): mixed;

    /** The value of an unset field. */
    public function emptyValue(Field $field): mixed;
}
