<?php
/**
 * Public field getters. Required from proto-blocks.php right after the
 * autoloader, so a theme can call them at any time; before the Fields module
 * boots (or when it is off) they return null / [] / [].
 *
 * The context is a post ID or object (default: the current loop post, else the
 * queried object), 'post:<id>', 'term:<id>', 'user:<id>' or 'option:<page>'.
 *
 * Trust model -- read before using these in a template:
 *
 * - NO read-permission check. The getter reads whatever object the context
 *   names: 'post:<id>' of a draft or private post, 'user:<id>' of any user,
 *   any declared options page. Never build $context from visitor input
 *   ($_GET, $_POST, query vars, block attributes a contributor controls).
 *   Only the declared-field rule applies: undeclared names return null.
 * - Values are escaped for their declared type by default, EXCEPT `code`
 *   fields, which are returned exactly as stored. That includes a meta row of
 *   the same name written before the plugin existed or by other code: the
 *   plugin does not check who wrote a value when it reads it.
 * - $raw = true returns the stored value; escaping is then the caller's job.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

use ProtoBlocks\Modules\Fields\Read\FieldReader;

if (!function_exists('proto_field')) {
    /**
     * One field value; null for a name not declared for that object.
     * No read-permission check; code fields and $raw = true are unescaped
     * (see the file docblock).
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
     * No read-permission check; code fields and $raw = true are unescaped
     * (see the file docblock).
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
     * No read-permission check; code sub-fields come back as stored (see the
     * file docblock).
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
