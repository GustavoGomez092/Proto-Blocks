<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Edit;

use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

/**
 * Validation errors that survive a save: the save request redirects (classic
 * editor) or returns nothing the box can read (the block editor's meta-box
 * request), so the errors and the submitted values of the invalid fields are
 * kept for five minutes per object AND per user, and the box reads them once
 * (EditRoutes `fields/errors`, or the box payload) to show them in place.
 *
 * Keys are field paths as ValueValidator reports them (`agenda.1.title`).
 * Submitted values are copied only while they fit MAX_SUBMITTED_BYTES in
 * total (JSON): an oversized submission keeps its error but not its value, so
 * a crafted 10,000-row post cannot park megabytes in the options table.
 * Nothing is stored for user 0 (nobody could read it back). At most
 * MAX_ERRORS paths are kept.
 *
 * Submitted values are stored RAW, exactly as the user posted them (only
 * unslashed): they never went through a sanitiser because they were not
 * saved. Consumers must hand them back only as controlled input values
 * (React state, `value=` through esc_attr / esc_textarea) and never render
 * them as HTML.
 */
final class ErrorStore
{
    public const TTL = 300;

    /** Upper bound on the JSON size of the submitted values kept per entry. */
    public const MAX_SUBMITTED_BYTES = 65536;

    /** Upper bound on the number of error paths kept per entry. */
    public const MAX_ERRORS = 500;

    private const PREFIX = 'proto_fields_err_';

    /**
     * Replaces the entry; no errors deletes it (a clean save clears stale errors).
     *
     * @param array<string, string> $errors path => message
     * @param array<string, mixed> $submitted top-level field name => submitted value, raw (render as input values only)
     */
    public function put(ObjectRef $ref, int $userId, array $errors, array $submitted): void
    {
        if ($userId <= 0) {
            return;
        }
        $key = self::key($ref, $userId);
        if ($errors === []) {
            delete_transient($key);
            return;
        }

        $clean = [];
        foreach ($errors as $path => $message) {
            if (count($clean) >= self::MAX_ERRORS) {
                break;
            }
            $clean[(string) $path] = (string) $message;
        }
        $kept = [];
        $budget = self::MAX_SUBMITTED_BYTES - 2; // the enclosing {}
        foreach ($submitted as $name => $value) {
            $json = function_exists('wp_json_encode') ? wp_json_encode([(string) $name => $value]) : json_encode([(string) $name => $value]);
            $size = is_string($json) ? strlen($json) - 1 : PHP_INT_MAX; // minus braces, plus a comma
            if ($size > $budget) {
                continue;
            }
            $budget -= $size;
            $kept[(string) $name] = $value;
        }

        set_transient($key, ['errors' => $clean, 'submitted' => $kept], self::TTL);
    }

    /**
     * Reads and deletes the entry.
     *
     * @return array{errors: array<string, string>, submitted: array<string, mixed>}|null
     */
    public function take(ObjectRef $ref, int $userId): ?array
    {
        $entry = $this->peek($ref, $userId);
        if ($userId > 0) {
            delete_transient(self::key($ref, $userId));
        }

        return $entry;
    }

    /** @return array{errors: array<string, string>, submitted: array<string, mixed>}|null */
    public function peek(ObjectRef $ref, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $entry = get_transient(self::key($ref, $userId));
        if (!is_array($entry) || !is_array($entry['errors'] ?? null) || $entry['errors'] === []) {
            return null;
        }

        return [
            'errors' => $entry['errors'],
            'submitted' => is_array($entry['submitted'] ?? null) ? $entry['submitted'] : [],
        ];
    }

    /** `proto_fields_err_<kind>_<id|page>_<user>`. */
    public static function key(ObjectRef $ref, int $userId): string
    {
        $object = $ref->kind === ObjectRef::OPTION ? $ref->page : (string) $ref->id;

        return self::PREFIX . $ref->kind . '_' . $object . '_' . $userId;
    }
}
