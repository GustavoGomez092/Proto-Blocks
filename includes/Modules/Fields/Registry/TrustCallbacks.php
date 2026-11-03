<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Registry;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\Store;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * The write-time trust boundary for field values (spec "Trust for field
 * values"). Trust is decided from the CURRENT user per write, never per post:
 * a writer with unfiltered_html stores values as given (normalised by type),
 * anyone else gets the type's sanitiser.
 *
 * Fields that hold a code value at any depth (`hasUnfilteredHtml()`, never
 * sanitize()'s null, which is also number's empty value) are never written by
 * an untrusted user:
 *
 * - Meta: guard() marks the key per kind and subtype; hook() adds
 *   short-circuit filters on add_/update_/delete_{type}_metadata and
 *   update_{type}_metadata_by_mid. They receive the real object, run before
 *   any row is touched, and return false (refused) for an untrusted writer,
 *   so the stored value stays exactly as it is and a refusal is recorded.
 *   WordPress gives sanitize callbacks no object ID, so the meta sanitize
 *   callback cannot know the previous value; it returns the empty value,
 *   which the guard then refuses to write. A direct sanitize_meta() caller
 *   gets the empty value: nothing untrusted, nothing from another object.
 * - Context-less writes are untrusted by design: with no current user (user
 *   0: cron, WP-CLI without --user, importers, background jobs) code-holding
 *   fields are refused like any other untrusted write; run such jobs as a
 *   user with unfiltered_html to write code values.
 * - Options: the options page is known, so the sanitize_option callback
 *   returns the stored option (or the empty value when none exists).
 *
 * Post revisions need no special case: sanitize callbacks registered for a
 * concrete post type never run for the `revision` subtype, and revisions are
 * only enabled for concrete post types.
 *
 * auth: the kind's edit capability for the object; a field holding code also
 * needs unfiltered_html. The incoming $allowed is ignored on purpose:
 * MetaRegistrar marks these keys protected, so core always passes false.
 */
final class TrustCallbacks
{
    public const KINDS = ['post', 'term', 'user'];

    /** Runs before other short-circuit filters, so nothing else writes first. */
    private const GUARD_PRIORITY = -10000;

    /** @var array<string, array<string, array<string, Field>>> kind => subtype ('' = all) => meta key => field */
    private array $guarded = [];

    /** @var array<string, array{object: string, field: string, message: string}> keyed by object|field */
    private array $refusals = [];

    private bool $hooked = false;

    public function __construct(private readonly TypeRegistry $types)
    {
    }

    /** Marks a registered meta key as code-holding when its field is. */
    public function guard(string $kind, string $subtype, Field $f): void
    {
        if (in_array($kind, self::KINDS, true) && $f->name !== '' && $this->types->for($f)->hasUnfilteredHtml($f)) {
            $this->guarded[$kind][$subtype][$f->name] = $f;
        }
    }

    /** Adds the metadata short-circuit guards. Idempotent. */
    public function hook(): void
    {
        if ($this->hooked) {
            return;
        }
        $this->hooked = true;
        foreach (self::KINDS as $kind) {
            $byObject = fn($check, $objectId = 0, $metaKey = '') => $this->guardWrite($check, $kind, (int) $objectId, (string) $metaKey);
            add_filter("add_{$kind}_metadata", $byObject, self::GUARD_PRIORITY, 3);
            add_filter("update_{$kind}_metadata", $byObject, self::GUARD_PRIORITY, 3);
            add_filter("delete_{$kind}_metadata", $byObject, self::GUARD_PRIORITY, 3);
            add_filter("update_{$kind}_metadata_by_mid", function ($check, $metaId = 0, $value = null, $metaKey = false) use ($kind) {
                // update_metadata_by_mid() passes false when the caller omits the key; core then
                // writes under the row's own key, so the guard must check that key.
                $row = $this->metaRow($kind, (int) $metaId);
                $key = is_string($metaKey) && $metaKey !== '' ? $metaKey : (string) ($row->meta_key ?? '');
                $objectId = $row !== null ? (int) ($row->{$kind . '_id'} ?? 0) : 0;

                return $this->guardWrite($check, $kind, $objectId, $key);
            }, self::GUARD_PRIORITY, 4);
        }
    }

    /**
     * For register_meta()'s sanitize_callback ($value, $metaKey, $objectType,
     * $objectSubtype), or, with the options page as $ref, for
     * `sanitize_option_{$option}` ($value, $option, $original).
     */
    public function sanitizeCallback(Field $f, Store $store, ?ObjectRef $ref = null): callable
    {
        $type = $this->types->for($f);

        return function ($value, ...$rest) use ($f, $store, $ref, $type) {
            $normalized = $type->normalize($value, $f);
            if (!self::untrusted()) {
                return $normalized;
            }
            if (!$type->hasUnfilteredHtml($f)) {
                return $type->sanitize($normalized, $f);
            }
            if ($ref !== null) {
                $this->refuse($ref->key(), $f);
                if ($store->has($ref, $f)) {
                    return $store->get($ref, $f);
                }
            }

            // Meta: the metadata guard refuses this write, so the stored value is untouched.
            return $type->emptyValue($f);
        };
    }

    /** For register_meta()'s auth_callback: ($allowed, $metaKey, $objectId, $userId, ...). */
    public function authCallback(Field $f, string $kind): callable
    {
        $type = $this->types->for($f);

        return function ($allowed, $metaKey = '', $objectId = 0, $userId = 0, ...$rest) use ($f, $kind, $type): bool {
            $userId = (int) $userId;
            if ($userId <= 0) {
                return false;
            }
            $objectId = (int) $objectId;
            $canEdit = match ($kind) {
                'post' => $objectId > 0 && user_can($userId, 'edit_post', $objectId),
                'term' => $objectId > 0 && user_can($userId, 'edit_term', $objectId),
                'user' => $objectId > 0 && user_can($userId, 'edit_user', $objectId),
                'option' => user_can($userId, 'manage_options'),
                default => false,
            };
            if (!$canEdit) {
                return false;
            }

            return !$type->hasUnfilteredHtml($f) || user_can($userId, 'unfiltered_html');
        };
    }

    /**
     * Refusals recorded since the last call (one per object and field), then
     * cleared: the save path reports them next to the field.
     *
     * @return list<array{object: string, field: string, message: string}>
     */
    public function takeRefusals(): array
    {
        $out = array_values($this->refusals);
        $this->refusals = [];

        return $out;
    }

    /** @internal the metadata short-circuit: false refuses the write, anything else passes $check through. */
    public function guardWrite($check, string $kind, int $objectId, string $metaKey)
    {
        if ($check !== null || !isset($this->guarded[$kind]) || !self::untrusted()) {
            return $check;
        }
        $subtype = $this->subtypeOf($kind, $objectId);
        $field = $this->guardedField($kind, $subtype, $metaKey);
        if ($field === null) {
            return $check;
        }
        // Same shape as ObjectRef::key().
        $this->refuse($kind . ':' . $objectId . ':' . ($subtype ?? ''), $field);

        return false;
    }

    private static function untrusted(): bool
    {
        return AttributeSanitizer::shouldSanitizeForUser(get_current_user_id());
    }

    /** The guarded field for the object's subtype; when the subtype is unknown, any subtype's (fail closed). */
    private function guardedField(string $kind, ?string $subtype, string $metaKey): ?Field
    {
        $bySubtype = $this->guarded[$kind] ?? [];
        if ($bySubtype === [] || $metaKey === '') {
            return null;
        }
        if ($subtype === null) {
            foreach ($bySubtype as $fields) {
                if (isset($fields[$metaKey])) {
                    return $fields[$metaKey];
                }
            }

            return null;
        }

        return $bySubtype[$subtype][$metaKey] ?? $bySubtype[''][$metaKey] ?? null;
    }

    /** '' for users; null when the object cannot be found. */
    private function subtypeOf(string $kind, int $objectId): ?string
    {
        if ($kind === 'user') {
            return '';
        }
        if ($objectId <= 0) {
            return null;
        }
        if ($kind === 'post') {
            $type = get_post_type($objectId);

            return is_string($type) && $type !== '' ? $type : null;
        }
        $term = get_term($objectId);

        return is_object($term) && isset($term->taxonomy) ? (string) $term->taxonomy : null;
    }

    /** The meta row (meta_key, <kind>_id, ...) or null. */
    private function metaRow(string $kind, int $metaId): ?object
    {
        if ($metaId <= 0 || !function_exists('get_metadata_by_mid')) {
            return null;
        }
        $row = get_metadata_by_mid($kind, $metaId);

        return is_object($row) ? $row : null;
    }

    private function refuse(string $object, Field $f): void
    {
        $this->refusals[$object . '|' . $f->name] = [
            'object' => $object,
            'field' => $f->name,
            'message' => $this->types->for($f)->requiresUnfilteredHtml()
                ? __('Only users with unfiltered HTML may edit this field.', 'proto-blocks')
                : __('This field contains a code sub-field that only users with unfiltered HTML may edit.', 'proto-blocks'),
        ];
    }
}
