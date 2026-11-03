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
 * values"): every meta registration and options field gets these callbacks.
 *
 * sanitize: the value is normalised by type for everyone. A writer with
 * unfiltered_html stores it as given; anyone else gets the type's sanitiser,
 * except for a field that holds a code value at any depth
 * (`hasUnfilteredHtml()`, never sanitize()'s null, which is also number's
 * empty value): then the stored value is kept whole and a refusal is
 * recorded for the save path to report. Trust is decided from the CURRENT
 * user, per write, never per post.
 *
 * WordPress gives sanitize callbacks no object ID. update_metadata() and
 * add_metadata() call get_object_subtype() for the object immediately before
 * sanitize_meta(), so hook() listens to `get_object_subtype_{type}` and the
 * callback consumes that ID once. If no object was noted, nothing is kept and
 * the empty value is returned: an untrusted writer can lose a code value
 * there but can never write one.
 *
 * auth: the kind's edit capability for the object; a field that holds code
 * also needs unfiltered_html. The incoming $allowed is ignored on purpose:
 * MetaRegistrar marks these keys protected, so core always passes false.
 */
final class TrustCallbacks
{
    public const KINDS = ['post', 'term', 'user'];

    /** @var array<string, int> meta type => object id noted by get_object_subtype */
    private array $noted = [];

    /** @var list<array{object: string, field: string, message: string}> */
    private array $refusals = [];

    private bool $hooked = false;

    public function __construct(private readonly TypeRegistry $types)
    {
    }

    /** Listens for the object of each meta write. Idempotent. */
    public function hook(): void
    {
        if ($this->hooked) {
            return;
        }
        $this->hooked = true;
        foreach (self::KINDS as $kind) {
            add_filter("get_object_subtype_{$kind}", function ($subtype, $objectId = 0) use ($kind) {
                $this->noted[$kind] = (int) $objectId;

                return $subtype;
            }, 10, 2);
        }
    }

    /**
     * For register_meta()'s sanitize_callback, called with ($value, $metaKey,
     * $objectType, $objectSubtype), or for `sanitize_option_{$option}`, called
     * with ($value, $option, $original): pass the options page as $ref then.
     */
    public function sanitizeCallback(Field $f, Store $store, ?ObjectRef $ref = null): callable
    {
        $type = $this->types->for($f);

        return function ($value, $metaKey = '', $objectType = '', ...$rest) use ($f, $store, $ref, $type) {
            $target = $ref ?? $this->takeNoted(is_string($objectType) ? $objectType : '', $rest[0] ?? null);
            $normalized = $type->normalize($value, $f);
            if (!AttributeSanitizer::shouldSanitizeForUser(get_current_user_id())) {
                return $normalized;
            }
            if ($type->hasUnfilteredHtml($f)) {
                return $this->keepPrevious($f, $store, $target, $type->requiresUnfilteredHtml());
            }

            return $type->sanitize($normalized, $f);
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
     * Refusals recorded since the last call, then cleared: the save path
     * reports them next to the field.
     *
     * @return list<array{object: string, field: string, message: string}>
     */
    public function takeRefusals(): array
    {
        $out = $this->refusals;
        $this->refusals = [];

        return $out;
    }

    /**
     * The noted object, consumed. Discarded when WordPress names another
     * subtype for this write than the noted object has (a stale note).
     */
    private function takeNoted(string $kind, mixed $subtype): ?ObjectRef
    {
        if (!isset($this->noted[$kind])) {
            return null;
        }
        $id = $this->noted[$kind];
        unset($this->noted[$kind]);
        if ($id <= 0) {
            return null;
        }
        $ref = match ($kind) {
            'post' => ObjectRef::post($id),
            'term' => $this->termRef($id),
            'user' => ObjectRef::user($id),
            default => null,
        };
        if ($ref !== null && $kind !== 'user' && is_string($subtype) && $subtype !== '' && $subtype !== $ref->subtype) {
            return null;
        }

        return $ref;
    }

    /**
     * Where the value to keep lives: the object itself, then, for a post
     * revision or autosave that holds nothing yet, its parent post (core
     * copies the parent's meta into the revision, and that copy must not
     * lose a code value an untrusted writer cannot see).
     *
     * @return list<ObjectRef>
     */
    private static function sources(?ObjectRef $target): array
    {
        if ($target === null) {
            return [];
        }
        $out = [$target];
        if ($target->kind === ObjectRef::POST && $target->subtype === 'revision') {
            $post = get_post($target->id);
            $parent = is_object($post) ? (int) ($post->post_parent ?? 0) : 0;
            if ($parent > 0 && $parent !== $target->id) {
                $out[] = ObjectRef::post($parent);
            }
        }

        return $out;
    }

    private function termRef(int $id): ?ObjectRef
    {
        $term = get_term($id);

        return is_object($term) && isset($term->taxonomy) ? ObjectRef::term($id, (string) $term->taxonomy) : null;
    }

    private function keepPrevious(Field $f, Store $store, ?ObjectRef $target, bool $leaf): mixed
    {
        $this->refusals[] = [
            'object' => $target?->key() ?? '',
            'field' => $f->name,
            'message' => $leaf
                ? __('Only users with unfiltered HTML may edit this field.', 'proto-blocks')
                : __('This field contains a code sub-field that only users with unfiltered HTML may edit.', 'proto-blocks'),
        ];
        foreach (self::sources($target) as $source) {
            if ($store->has($source, $f)) {
                return $store->get($source, $f);
            }
        }

        return $this->types->for($f)->emptyValue($f);
    }
}
