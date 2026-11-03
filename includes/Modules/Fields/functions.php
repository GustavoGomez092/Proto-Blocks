<?php
/**
 * Public field getters. Required from proto-blocks.php right after the
 * autoloader, so a theme can call them at any time; before the Fields module
 * boots (or when it is off) they return null / [] / [].
 *
 * Values are escaped for their declared type unless `$raw` is true. The
 * context is a post ID or object (default: the current loop post, else the
 * queried object), 'post:<id>', 'term:<id>', 'user:<id>' or 'option:<page>'.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

use ProtoBlocks\Modules\Fields\Read\FieldReader;

if (!function_exists('proto_field')) {
    /**
     * One field value; null for a name not declared for that object.
     *
     * @param mixed $context See the file docblock.
     */
    function proto_field(string $name, $context = null, bool $raw = false): mixed
    {
        $reader = FieldReader::shared();

        return $reader === null ? null : $reader->get($name, $context, $raw);
    }
}

if (!function_exists('proto_fields')) {
    /**
     * Every value field of every group shown on that object, name => value.
     *
     * @param mixed $context See the file docblock.
     * @return array<string, mixed>
     */
    function proto_fields($context = null, bool $raw = false): array
    {
        $reader = FieldReader::shared();

        return $reader === null ? [] : $reader->all($context, $raw);
    }
}

if (!function_exists('proto_rows')) {
    /**
     * Escaped rows of a repeater or flexible content field; empty otherwise.
     *
     * @param mixed $context See the file docblock.
     * @return iterable<array<string, mixed>>
     */
    function proto_rows(string $name, $context = null): iterable
    {
        $reader = FieldReader::shared();

        return $reader === null ? [] : $reader->rows($name, $context);
    }
}
