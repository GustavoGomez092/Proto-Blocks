<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Sources;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;

/**
 * Coexistence with Advanced Custom Fields.
 *
 * When ACF is active and already knows a group key (its local JSON, its
 * database groups, or acf_add_local_field_group()), Proto-Blocks drops its own
 * copy so a box never renders twice and the meta keys are not registered
 * twice; the skipped groups are listed for an admin notice. ACF's own groups
 * are listed read-only for the Fields screen. ACF's `acf-json` folders are
 * ACF's business: Proto-Blocks never reads them, so they cannot change the
 * trust rules of a Proto-Blocks field.
 */
final class AcfBridge
{
    /** @var list<array{key: string, title: string, source: string}> */
    private array $skipped = [];

    /** ACF (or ACF Pro) is loaded: its main class, its `acf()` accessor or its version constant. */
    public static function active(): bool
    {
        return class_exists('ACF', false) || function_exists('acf') || defined('ACF_VERSION');
    }

    /**
     * Drops the groups whose key ACF already registers and records them.
     * Without ACF (or without acf_get_field_group()) nothing is dropped.
     *
     * @param list<FieldGroup> $groups
     * @return list<FieldGroup>
     */
    public function filterDuplicates(array $groups): array
    {
        if (!self::active() || !function_exists('acf_get_field_group')) {
            return $groups;
        }
        $out = [];
        foreach ($groups as $group) {
            $known = acf_get_field_group($group->key);
            if (is_array($known) && $known !== []) {
                $this->skipped[] = ['key' => $group->key, 'title' => $group->title, 'source' => $group->path ?? $group->source];
                continue;
            }
            $out[] = $group;
        }

        return $out;
    }

    /** @return list<array{key: string, title: string, source: string}> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * ACF's own groups for the Fields list, read-only.
     *
     * @return list<array{key: string, title: string, locationSummary: string}>
     */
    public function acfGroups(): array
    {
        if (!self::active() || !function_exists('acf_get_field_groups')) {
            return [];
        }
        $groups = acf_get_field_groups();
        if (!is_array($groups)) {
            return [];
        }
        $out = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !is_string($group['key'] ?? null)) {
                continue;
            }
            $out[] = [
                'key' => $group['key'],
                'title' => is_scalar($group['title'] ?? null) ? (string) $group['title'] : '',
                'locationSummary' => self::locationSummary($group['location'] ?? []),
            ];
        }

        return $out;
    }

    /** "post_type == event or options_page == settings and ..." */
    public static function locationSummary(mixed $location): string
    {
        if (!is_array($location)) {
            return '';
        }
        $or = [];
        foreach ($location as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $and = [];
            foreach ($branch as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $and[] = trim(sprintf(
                    '%s %s %s',
                    is_scalar($rule['param'] ?? null) ? (string) $rule['param'] : '',
                    is_scalar($rule['operator'] ?? null) ? (string) $rule['operator'] : '',
                    is_scalar($rule['value'] ?? null) ? (string) $rule['value'] : ''
                ));
            }
            if ($and !== []) {
                $or[] = implode(' and ', $and);
            }
        }

        return implode(' or ', $or);
    }

    /**
     * admin_notices: one notice per skipped group, on Proto-Blocks screens and
     * post edit screens only.
     */
    public function renderNotice(): void
    {
        if ($this->skipped === [] || !self::onRelevantScreen()) {
            return;
        }
        foreach ($this->skipped as $group) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %s: field group title */
                    __('Proto-Blocks skipped the field group "%s" because Advanced Custom Fields already registers the same key.', 'proto-blocks'),
                    $group['title'] !== '' ? $group['title'] : $group['key']
                ))
            );
        }
    }

    private static function onRelevantScreen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!is_object($screen)) {
            return false;
        }
        $id = (string) ($screen->id ?? '');
        $base = (string) ($screen->base ?? '');

        return $base === 'post' || str_contains($id, 'proto-blocks');
    }
}
