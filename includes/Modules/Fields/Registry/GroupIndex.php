<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Registry;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupValidator;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

/**
 * Every known field group, ordered by menu_order then source order, with the
 * lookups the editor, the save path and the getter share. One instance per
 * request; matches for a ref without an explicit context are memoised.
 * Nothing here touches the filesystem.
 */
final class GroupIndex
{
    /** @var list<FieldGroup> */
    private array $groups;

    /** @var array<string, list<FieldGroup>> */
    private array $memo = [];

    /** @var list<string>|null taxonomies named by post_taxonomy rules */
    private ?array $ruleTaxonomies = null;

    /** @param list<FieldGroup> $groups */
    public function __construct(array $groups)
    {
        $decorated = [];
        foreach (array_values($groups) as $i => $group) {
            $decorated[] = [$group->menuOrder, $i, $group];
        }
        usort($decorated, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->groups = array_column($decorated, 2);
    }

    /** @return list<FieldGroup> */
    public function all(): array
    {
        return $this->groups;
    }

    /**
     * Renderable groups whose location matches the object.
     *
     * @param array<string, mixed> $ctx as LocationMatcher::matches(); built from $ref when empty
     * @return list<FieldGroup>
     */
    public function forRef(ObjectRef $ref, array $ctx = []): array
    {
        $memoKey = $ctx === [] ? $ref->key() : null;
        if ($memoKey !== null && isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        if ($ctx === []) {
            $ctx = $this->context($ref);
        }
        $ctx['kind'] = $ref->kind;

        $out = [];
        foreach ($this->groups as $group) {
            if (LocationMatcher::matches($group->location, $ctx)) {
                $out[] = $group;
            }
        }
        if ($memoKey !== null) {
            $this->memo[$memoKey] = $out;
        }

        return $out;
    }

    /**
     * The first value-holding top-level field with this name among the groups
     * matching the object (same winner as MetaRegistrar's duplicate rule).
     *
     * @return array{field: Field, group: FieldGroup}|null
     */
    public function fieldFor(ObjectRef $ref, string $name): ?array
    {
        if ($name === '') {
            return null;
        }
        foreach ($this->forRef($ref) as $group) {
            $field = $group->field($name);
            if ($field !== null && !in_array($field->type, GroupValidator::LAYOUT_ONLY_TYPES, true)) {
                return ['field' => $field, 'group' => $group];
            }
        }

        return null;
    }

    /**
     * Subtypes a group registers its fields on for one kind: post types from
     * `post_type ==` rules, taxonomies from `taxonomy ==` rules ('all' or no
     * such rule = ''), options page slugs for 'option', [''] for users. A
     * branch with no rule fixing the subtype yields '' (every subtype), which
     * then stands alone. Empty when no branch of that kind can match.
     *
     * @return list<string>
     */
    public function subtypesFor(FieldGroup $g, string $kind): array
    {
        if (!$g->location->isRenderable()) {
            return [];
        }
        $param = match ($kind) {
            'post' => 'post_type',
            'term' => 'taxonomy',
            'option' => 'options_page',
            'user' => null,
            default => false,
        };
        if ($param === false) {
            return [];
        }

        $out = [];
        foreach ($g->location->or as $branch) {
            if (LocationMatcher::branchKind($branch) !== $kind) {
                continue;
            }
            if ($param === null) {
                return [''];
            }
            $fixed = [];
            foreach ($branch as $rule) {
                if ($rule['param'] === $param && $rule['operator'] === '==') {
                    $fixed[$rule['value']] = true;
                }
            }
            if (count($fixed) > 1) {
                continue; // `post_type == a AND post_type == b` never matches.
            }
            $value = $fixed === [] ? '' : (string) array_key_first($fixed);
            if ($kind === 'term' && $value === 'all') {
                $value = '';
            }
            if ($value === '' && $kind === 'option') {
                continue; // an options page is always named; `!=` alone matches nothing useful.
            }
            if ($value === '') {
                return [''];
            }
            $out[$value] = true;
        }

        return array_map('strval', array_keys($out));
    }

    /** @return array<string, mixed> */
    private function context(ObjectRef $ref): array
    {
        switch ($ref->kind) {
            case ObjectRef::POST:
                $post = $ref->id > 0 ? get_post($ref->id) : null;
                $terms = [];
                foreach ($this->ruleTaxonomies() as $taxonomy) {
                    $found = $ref->id > 0 ? wp_get_object_terms($ref->id, $taxonomy) : [];
                    $terms[$taxonomy] = is_array($found)
                        ? array_values(array_filter(array_map(static fn($t) => is_object($t) ? (string) ($t->slug ?? '') : '', $found)))
                        : [];
                }
                return [
                    'post_type' => $ref->subtype !== '' ? $ref->subtype : (string) ($post->post_type ?? ''),
                    'post_status' => (string) ($post->post_status ?? ''),
                    'post_id' => $ref->id,
                    'page_template' => $ref->id > 0 ? (string) get_page_template_slug($ref->id) : '',
                    'terms' => $terms,
                ];
            case ObjectRef::TERM:
                return ['taxonomy' => $ref->subtype];
            case ObjectRef::USER:
                $user = $ref->id > 0 ? get_userdata($ref->id) : false;
                $roles = is_object($user) && isset($user->roles) && is_array($user->roles) ? array_values($user->roles) : [];
                return ['user_roles' => $roles, 'user_form' => $ref->id > 0 ? 'edit' : 'add'];
            case ObjectRef::OPTION:
                return ['options_page' => $ref->page];
        }

        return [];
    }

    /** @return list<string> */
    private function ruleTaxonomies(): array
    {
        if ($this->ruleTaxonomies !== null) {
            return $this->ruleTaxonomies;
        }
        $found = [];
        foreach ($this->groups as $group) {
            foreach ($group->location->or as $branch) {
                foreach ($branch as $rule) {
                    if ($rule['param'] === 'post_taxonomy' && str_contains($rule['value'], ':')) {
                        $found[explode(':', $rule['value'], 2)[0]] = true;
                    }
                }
            }
        }

        return $this->ruleTaxonomies = array_map('strval', array_keys($found));
    }
}
