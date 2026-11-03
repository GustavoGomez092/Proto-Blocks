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

    /**
     * True when only users with unfiltered_html may write this type (code).
     * Task 5's trust gate must use this, never sanitize()'s return value:
     * null from sanitize() also means "empty" for number. For these types
     * escape() returns the stored content raw.
     */
    public function requiresUnfilteredHtml(): bool;

    /**
     * True when this field, or any sub-field below it, may only be written by
     * users with unfiltered_html. Leaf types return requiresUnfilteredHtml();
     * group, repeater and flexible_content recurse into their sub-fields.
     *
     * Task 5 contract for an UNTRUSTED writer: when this is true, keep the
     * stored value as it is and report the error
     * __('This field contains a code sub-field that only users with unfiltered HTML may edit.', 'proto-blocks').
     * Never attempt a partial or positional merge of the submitted value.
     * As a safety net, sanitize() of such a field returns null (= keep the
     * previous whole value) rather than a stripped copy.
     */
    public function hasUnfilteredHtml(Field $field): bool;

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
     * be written by an untrusted user (requiresUnfilteredHtml()) returns null,
     * meaning "keep the previous value". null is also number's empty value, so
     * callers decide trust from requiresUnfilteredHtml(), not from null.
     */
    public function sanitize(mixed $value, Field $field): mixed;

    /** Shape coercion (types, array keys) for every user; no content filtering. */
    public function normalize(mixed $value, Field $field): mixed;

    /** Getter output: a stored value made safe for HTML output (raw for requiresUnfilteredHtml() types). */
    public function escape(mixed $stored, Field $field): mixed;

    /** The value of an unset field. */
    public function emptyValue(Field $field): mixed;
}
