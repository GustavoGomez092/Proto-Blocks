<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Safe Redirect Manager: `redirect_rule` posts with `_redirect_rule_*` meta.
 * Published rules import enabled, drafts disabled; order is menu_order then ID.
 * SRM matches case-insensitively by default, so rules import `ignoreCase: true`.
 */
final class SafeRedirectManagerImporter extends AbstractPluginImporter
{
    private const POST_TYPE = 'redirect_rule';
    private const META = ['_redirect_rule_from', '_redirect_rule_to', '_redirect_rule_status_code', '_redirect_rule_from_regex', '_redirect_rule_notes'];

    public function id(): string
    {
        return 'safe-redirect-manager';
    }

    public function label(): string
    {
        return 'Safe Redirect Manager';
    }

    public function available(): bool
    {
        $db = $this->db();
        return $db->get_var($db->prepare(
            "SELECT ID FROM {$db->posts} WHERE post_type = %s AND post_status IN (%s, %s) LIMIT %d",
            self::POST_TYPE,
            'publish',
            'draft',
            1,
        )) !== null;
    }

    public function preview(): array
    {
        $this->skipped = [];
        $db = $this->db();
        $results = (array) $db->get_results($db->prepare(
            "SELECT p.ID, p.post_status, p.menu_order, m.meta_key, m.meta_value FROM {$db->posts} p"
            . " INNER JOIN {$db->postmeta} m ON m.post_id = p.ID"
            . ' WHERE p.post_type = %s AND p.post_status IN (%s, %s) AND m.meta_key IN (%s, %s, %s, %s, %s)'
            . ' ORDER BY p.menu_order ASC, p.ID ASC LIMIT %d',
            self::POST_TYPE,
            'publish',
            'draft',
            ...[...self::META, self::LIMIT * count(self::META)],
        ), ARRAY_A);

        $posts = [];
        foreach ($results as $r) {
            $r = (array) $r;
            $id = (int) ($r['ID'] ?? 0);
            $posts[$id] ??= ['status' => (string) ($r['post_status'] ?? ''), 'meta' => []];
            $posts[$id]['meta'][(string) ($r['meta_key'] ?? '')] = (string) ($r['meta_value'] ?? '');
        }

        $rows = [];
        $line = 0;
        foreach ($posts as $post) {
            $line++;
            $rows[] = $this->rule($post['meta'], $post['status'], $line);
        }
        $rules = $this->keep($rows);
        return $rules === [] ? [] : ['safe-redirect-manager' => self::group('Safe Redirect Manager', $rules)];
    }

    /** @param array<string, string> $meta */
    private function rule(array $meta, string $status, int $line): array
    {
        $from = trim($meta['_redirect_rule_from'] ?? '');
        $to = $meta['_redirect_rule_to'] ?? '';
        $code = (int) (($meta['_redirect_rule_status_code'] ?? '') !== '' ? $meta['_redirect_rule_status_code'] : 302);
        $action = RawRule::actionForCode($code);
        if ($from === '') {
            return ParsedRow::invalid('empty_source', $line);
        }
        if ($action === null) {
            return ParsedRow::invalid('invalid_code', $line, ['from' => $from, 'code' => $code]);
        }

        if (RedirectionMapper::bool($meta['_redirect_rule_from_regex'] ?? false)) {
            $rule = ['from' => $from, 'regex' => true];
            if ($action === 'url') {
                $target = RawRule::target($to);
                if ($target === null) {
                    return ParsedRow::invalid(trim($to) === '' ? 'missing_target' : 'invalid_target', $line, $rule);
                }
                $rule['to'] = $target;
            }
        } elseif ($action === 'url') {
            $row = self::wildcardRule($from, $to, $line);
            if ($row['status'] === ParsedRow::INVALID) {
                return $row;
            }
            $rule = $row['rule'];
        } else {
            $source = RawRule::source($from);
            $rule = str_contains($source, '*') ? ['from' => Patterns::wildcard($source), 'regex' => true] : ['from' => $source, 'regex' => false];
        }

        $notes = trim($meta['_redirect_rule_notes'] ?? '');
        if ($notes !== '') {
            $rule['title'] = mb_substr(str_replace(['<', '>'], '', $notes), 0, 200);
        }
        if ($status !== 'publish') {
            $rule['enabled'] = false;
        }
        return self::finish($rule + ['code' => $code, 'action' => $action, 'ignoreCase' => true], $line);
    }

    public function cleanupHint(): ?string
    {
        return 'Deactivate Safe Redirect Manager once the imported rules are verified.';
    }
}
