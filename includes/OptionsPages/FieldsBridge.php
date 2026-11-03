<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\Location;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\Store;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

/**
 * Lets the Fields module read settings pages: `proto_blocks_field_groups`
 * adds every declared page's own groups to the group index, and
 * `proto_blocks_field_stores` puts OptionsFieldStore in front of the `option`
 * kind. Hooked by the OptionsPages module only when the Fields module is on.
 * The stores filter must be added before the Fields module boots: its reader
 * takes the stores map at boot.
 *
 * The page groups come from a private OptionsValues with no standalone
 * groups. filterGroups() runs while the Fields index is being built; asking
 * that index for standalone groups then would see it empty, and a shared
 * OptionsValues would stay memoised without them for the rest of the request.
 */
final class FieldsBridge
{
    private readonly OptionsValues $pages;

    public function __construct(
        private readonly OptionsPagesRegistry $registry,
        private readonly OptionsFieldStore $store,
        ?TypeRegistry $types = null,
    ) {
        $this->pages = new OptionsValues($registry, new OptionsStore(), $types ?? TypeRegistry::withCoreTypes(), static fn (): array => []);
    }

    /**
     * Appends each page's own groups whose key is not already present, with
     * the location pinned to `options_page == <slug>`, so a page group never
     * matches a post, term or user screen whatever its declaration says.
     *
     * @param mixed $groups list<FieldGroup> from the Fields module
     * @return list<FieldGroup>
     */
    public function filterGroups(mixed $groups): array
    {
        $out = [];
        $keys = [];
        foreach (is_array($groups) ? $groups : [] as $group) {
            if ($group instanceof FieldGroup) {
                $out[] = $group;
                $keys[$group->key] = true;
            }
        }
        foreach ($this->registry->all() as $page) {
            foreach ($this->pages->pageGroups($page) as $group) {
                if (isset($keys[$group->key])) {
                    continue;
                }
                $keys[$group->key] = true;
                $out[] = self::pinned($group, $page->slug);
            }
        }

        return $out;
    }

    /**
     * The options page store in front of the `option` kind; the store it
     * replaces keeps serving pages Proto-Blocks does not declare.
     *
     * @param mixed $stores array<string, Store> kind => store
     * @return array<string, Store>
     */
    public function filterStores(mixed $stores): array
    {
        $stores = is_array($stores) ? $stores : [];
        $previous = $stores[ObjectRef::OPTION] ?? null;

        return [ObjectRef::OPTION => $this->store->withFallback($previous instanceof Store ? $previous : null)] + $stores;
    }

    private static function pinned(FieldGroup $group, string $slug): FieldGroup
    {
        return new FieldGroup(
            key: $group->key,
            title: $group->title,
            fields: $group->fields,
            location: new Location([[['param' => 'options_page', 'operator' => '==', 'value' => $slug]]], true),
            position: $group->position,
            style: $group->style,
            menuOrder: $group->menuOrder,
            source: $group->source,
            path: $group->path,
            raw: $group->raw,
        );
    }
}
