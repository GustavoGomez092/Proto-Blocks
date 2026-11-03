<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\Store;

/**
 * Phase 2's `option` store for settings pages (`proto_field('x', 'option:<page>')`).
 *
 * A page declared through OptionsPagesRegistry is read and written through
 * OptionsStore, so array-mode pages (one option holding every field) and
 * per-field pages (`<page_slug>_<field_name>`) both work, with the page's
 * autoload. Any other page is delegated to the fallback store (Phase 2's
 * OptionStore by default, or the option store the bridge replaced).
 *
 * Raw values only: escaping stays in the reader's Escaper. Nothing is cached,
 * so no value read for one user is served to another. A protected option name
 * is never read or written, even when a filter protected it after load (the
 * same skip as OptionsValues::read()). has() reports existence, not truthiness:
 * a stored '' or '0' is a value, not "unset".
 */
final class OptionsFieldStore implements Store
{
    private Store $fallback;

    public function __construct(
        private readonly OptionsPagesRegistry $registry,
        private readonly OptionsStore $store,
        ?Store $fallback = null,
    ) {
        $this->fallback = $fallback ?? new OptionStore();
    }

    /** A copy serving undeclared pages from $fallback (this store itself when $fallback is null or another OptionsFieldStore). */
    public function withFallback(?Store $fallback): self
    {
        if ($fallback === null || $fallback instanceof self) {
            return $this;
        }
        $copy = clone $this;
        $copy->fallback = $fallback;

        return $copy;
    }

    public function get(ObjectRef $ref, Field $f): mixed
    {
        if (!$this->usable($ref, $f)) {
            return null;
        }
        $page = $this->registry->get($ref->page);
        if ($page === null) {
            return ProtectedOptions::isProtected(OptionStore::optionName($ref->page, $f->name)) ? null : $this->fallback->get($ref, $f);
        }
        if (ProtectedOptions::isProtected($page->optionName($f->name)) || !$this->store->has($page, $f->name)) {
            return null;
        }

        return $this->store->read($page, $f->name);
    }

    public function has(ObjectRef $ref, Field $f): bool
    {
        if (!$this->usable($ref, $f)) {
            return false;
        }
        $page = $this->registry->get($ref->page);
        if ($page === null) {
            return !ProtectedOptions::isProtected(OptionStore::optionName($ref->page, $f->name)) && $this->fallback->has($ref, $f);
        }

        return !ProtectedOptions::isProtected($page->optionName($f->name)) && $this->store->has($page, $f->name);
    }

    /**
     * @throws \DomainException when the target option is protected, or when an
     *                          array-mode page's option holds a non-array value
     *                          (probably another plugin's); nothing is written then
     */
    public function set(ObjectRef $ref, Field $f, mixed $value): void
    {
        if (!$this->usable($ref, $f)) {
            return;
        }
        $page = $this->registry->get($ref->page);
        $option = $page === null ? OptionStore::optionName($ref->page, $f->name) : $page->optionName($f->name);
        if (ProtectedOptions::isProtected($option)) {
            throw new \DomainException(sprintf('Option "%s" is reserved and cannot be written by a field.', $option));
        }
        if ($page === null) {
            $this->fallback->set($ref, $f, $value);

            return;
        }
        if (self::arrayOptionHoldsScalar($page)) {
            throw new \DomainException(sprintf('Option "%s" holds a value that is not a list of settings; it was left as it is.', $option));
        }
        $this->store->save($page, [$f->name => $value], $f->type === 'true_false' ? [$f->name] : []);
    }

    private function usable(ObjectRef $ref, Field $f): bool
    {
        if ($ref->kind !== ObjectRef::OPTION) {
            throw new \InvalidArgumentException(sprintf('The options page store cannot hold %s values.', $ref->kind));
        }

        return $ref->page !== '' && $f->name !== '';
    }

    /** Same rule as OptionsValues::save(): an existing non-empty scalar is never replaced by an array. */
    private static function arrayOptionHoldsScalar(PageDefinition $page): bool
    {
        if ($page->storageMode !== PageDefinition::MODE_ARRAY || $page->storageOption === null) {
            return false;
        }
        $missing = new \stdClass();
        $current = get_option($page->storageOption, $missing);

        return $current !== $missing && !is_array($current) && $current !== '' && $current !== false;
    }
}
