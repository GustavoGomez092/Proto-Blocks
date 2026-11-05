<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Redirection (`{prefix}redirection_items` + `redirection_groups`): one raw
 * group per Redirection group, keeping title, enabled, position, flags and
 * conditions. Hit counts are not configuration and are not imported.
 */
final class RedirectionImporter extends AbstractPluginImporter
{
    public function id(): string
    {
        return 'redirection';
    }

    public function label(): string
    {
        return 'Redirection';
    }

    public function available(): bool
    {
        $db = $this->db();
        $items = $db->prefix . 'redirection_items';
        if (!$this->tableExists($items)) {
            return false;
        }
        return $db->get_var($db->prepare("SELECT id FROM `{$items}` LIMIT %d", 1)) !== null;
    }

    public function preview(): array
    {
        $this->skipped = [];
        $db = $this->db();
        $groupsTable = $db->prefix . 'redirection_groups';
        $itemsTable = $db->prefix . 'redirection_items';

        $groupRows = (array) $db->get_results($db->prepare(
            "SELECT id, name, status, position FROM `{$groupsTable}` ORDER BY position ASC, id ASC LIMIT %d",
            self::LIMIT,
        ), ARRAY_A);
        $items = (array) $db->get_results($db->prepare(
            "SELECT id, url, regex, position, group_id, status, action_type, action_code, action_data, match_type, title, match_data FROM `{$itemsTable}` ORDER BY position ASC, id ASC LIMIT %d",
            self::LIMIT,
        ), ARRAY_A);

        $taken = [];
        $slugById = [];
        $groups = [];
        foreach ($groupRows as $row) {
            $row = (array) $row;
            $name = trim((string) ($row['name'] ?? ''));
            $slug = RedirectionMapper::slug($name !== '' ? $name : 'group-' . ($row['id'] ?? ''), $taken);
            $slugById[(string) ($row['id'] ?? '')] = $slug;
            $groups[$slug] = JsonFormat::redirectionGroup($row, $name !== '' ? $name : $slug) + ['rules' => []];
        }

        $fallback = null;
        foreach (array_values($items) as $i => $item) {
            $item = (array) $item;
            $slug = $slugById[(string) ($item['group_id'] ?? '')] ?? null;
            if ($slug === null) {
                $slug = $fallback ??= RedirectionMapper::slug(JsonFormat::FALLBACK_GROUP, $taken);
                $groups[$slug] ??= self::group('Imported', []);
            }
            $rules = $this->keep([RedirectionMapper::row($item, $i + 1, true)]);
            array_push($groups[$slug]['rules'], ...$rules);
        }

        return array_filter($groups, static fn(array $g): bool => $g['rules'] !== []);
    }

    public function cleanupHint(): ?string
    {
        return 'Deactivate the Redirection plugin once the imported rules are verified; Proto-Blocks redirects stay paused while it is active.';
    }
}
