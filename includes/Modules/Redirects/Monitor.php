<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects;

use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;

/**
 * Permalink monitor (spec §8). When a published post of a watched type
 * stays published but its path changes, a 301 from the old path to the new
 * one is appended to the `monitor.group` group file (created when missing);
 * `monitor.associated` adds a second rule with that suffix on both sides;
 * `monitor.trash` adds a disabled rule to `/` when a watched published post
 * is trashed. Any enabled non-regex rule, in any file group, whose `from`
 * is a new target is disabled in its own file so the move cannot loop.
 *
 * Writes go through the serializer and SchemaFileWriter, so every writer
 * rule (file mods, confinement, size, hash) holds. If any file involved is
 * not writable nothing is written and a `monitor_unwritable` warning is kept
 * in the `proto_blocks_redirects_monitor_issues` option for the screen.
 */
final class Monitor
{
    public const KIND = 'redirects';
    public const ISSUES_OPTION = 'proto_blocks_redirects_monitor_issues';
    public const DEFAULT_GROUP = 'modified-posts';
    private const ISSUES_KEPT = 20;
    private const TITLE_MAX = 200;
    private const FROM_MAX = 2000;
    /** Keys the registry adds to a rule; never written to files. */
    private const REGISTRY_KEYS = ['group', 'origin', 'source', 'index'];

    /** @var array<int, array{path: ?string, status: string}> state remembered between the pre and post hooks */
    private array $pending = [];

    /** @var \Closure(array<string, mixed>): string */
    private \Closure $serialize;

    /**
     * @param array<string, mixed> $settings  full module settings
     * @param (\Closure(array<string, mixed>): string)|null $serialize tests only; defaults to
     *        Serializer::group() against the site defaults (`settings.defaults`), so a written
     *        group repeats only the flags that differ from the site
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly SchemaFileWriter $writer,
        private readonly array $settings,
        ?\Closure $serialize = null
    ) {
        $inherited = is_array($settings['defaults'] ?? null) ? $settings['defaults'] : null;
        $this->serialize = $serialize ?? static fn(array $group): string => Serializer::group($group, $inherited);
    }

    /** Hooks in only when at least one post type is watched. */
    public function register(): void
    {
        if ($this->postTypes() === []) {
            return;
        }
        add_action('pre_post_update', [$this, 'onPrePostUpdate'], 10, 2);
        add_action('post_updated', [$this, 'onPostUpdated'], 11, 3);
        add_action('wp_trash_post', [$this, 'onPreTrash'], 10, 1);
        add_action('trashed_post', [$this, 'onTrashed'], 10, 1);
    }

    /** Remembers the permalink and status before WordPress saves the change. */
    public function onPrePostUpdate(int $postId, array $data = []): void
    {
        $this->remember($postId);
    }

    /** Appends the move rule(s) when a published post's path changed. */
    public function onPostUpdated(int $postId, mixed $after = null, mixed $before = null): void
    {
        $remembered = $this->pending[$postId] ?? null;
        unset($this->pending[$postId]);
        if ($remembered === null || !$this->active()) {
            return;
        }
        $after = is_object($after) ? $after : get_post($postId);
        $beforeStatus = is_object($before) ? (string) ($before->post_status ?? '') : $remembered['status'];
        if (!is_object($after) || $beforeStatus !== 'publish' || (string) ($after->post_status ?? '') !== 'publish') {
            return;
        }
        if (!$this->watched((string) ($after->post_type ?? ''))) {
            return;
        }

        $old = $remembered['path'];
        $new = $this->pathOf(get_permalink($postId));
        if ($old === null || $new === null || $old === '/' || $old === $new) {
            return;
        }

        $title = $this->title($after, 'moved');
        $rules = [['from' => $old, 'to' => $new, 'title' => $title, 'enabled' => true]];
        $suffix = $this->associated();
        if ($suffix !== '') {
            $rules[] = ['from' => self::withSuffix($old, $suffix), 'to' => self::withSuffix($new, $suffix), 'title' => $title, 'enabled' => true];
        }
        $this->apply($rules);
    }

    /** Remembers the permalink before the post is trashed (its slug changes afterwards). */
    public function onPreTrash(int $postId): void
    {
        $this->remember($postId);
    }

    /** With `monitor.trash`, adds a disabled rule from the trashed post's path to `/`. */
    public function onTrashed(int $postId): void
    {
        $remembered = $this->pending[$postId] ?? null;
        unset($this->pending[$postId]);
        if ($remembered === null || $remembered['status'] !== 'publish' || !$this->active() || $this->monitorSettings('trash', false) !== true) {
            return;
        }
        $post = get_post($postId);
        $path = $remembered['path'];
        if (!is_object($post) || $path === null || $path === '/' || !$this->watched((string) ($post->post_type ?? ''))) {
            return;
        }
        $this->apply([['from' => $path, 'to' => '/', 'title' => $this->title($post, 'trashed'), 'enabled' => false]]);
    }

    /** @return list<Issue> the kept monitor findings, newest last */
    public function issues(): array
    {
        $out = [];
        foreach ((array) get_option(self::ISSUES_OPTION, []) as $row) {
            if (!is_array($row) || !is_string($row['code'] ?? null) || !is_string($row['message'] ?? null)) {
                continue;
            }
            $out[] = new Issue(
                (string) ($row['level'] ?? Issue::WARNING),
                $row['code'],
                $row['message'],
                (string) ($row['source'] ?? ''),
                isset($row['slug']) ? (string) $row['slug'] : null,
                self::KIND
            );
        }

        return $out;
    }

    public function clearIssues(): void
    {
        delete_option(self::ISSUES_OPTION);
    }

    // ------------------------------------------------------------------ writing

    /**
     * Adds $rules to the monitor group and disables looping rules, writing
     * every touched group file or none of them.
     *
     * @param list<array{from: string, to: string, title: string, enabled: bool}> $rules
     */
    private function apply(array $rules): void
    {
        $slug = $this->groupSlug();
        if (SchemaFileWriter::slugError(self::KIND, $slug) !== null) {
            $this->record('monitor_unwritable', sprintf(
                /* translators: %s: group slug */
                __('The permalink monitor cannot write to the group "%s": it is not a valid group slug.', 'proto-blocks'),
                $slug
            ), '', $slug);
            return;
        }

        /** @var array<string, array<string, mixed>> $groups slug => group to write */
        $groups = [$slug => $this->targetGroup($slug)];
        foreach ($rules as $rule) {
            $groups[$slug] = $this->addRule($groups[$slug], $slug, $rule);
        }

        $notices = [];
        foreach ($rules as $rule) {
            if ($rule['enabled']) {
                $groups = $this->disableLoops($groups, $rule['to'], $notices);
            }
        }

        $files = [];
        foreach (array_keys($groups) as $groupSlug) {
            $existing = $this->writer->existing(self::KIND, (string) $groupSlug);
            $target = $this->writer->target(self::KIND, (string) $groupSlug, $existing);
            if ($target['writable'] && !self::isLoadedFile($groups[$groupSlug], $existing)) {
                // The loaded group lives in a root the writer cannot write (a parent theme or a
                // proto_blocks_schema_paths root); a new copy would silently shadow it.
                $target = ['path' => (string) ($groups[$groupSlug]['source'] ?? ''), 'writable' => false, 'reason' => 'read_only'];
            }
            if (!$target['writable']) {
                $this->record('monitor_unwritable', sprintf(
                    /* translators: 1: file path, 2: reason code */
                    __('The permalink monitor could not add a redirect: %1$s is not writable (%2$s).', 'proto-blocks'),
                    $target['path'] !== '' ? $target['path'] : (string) $groupSlug,
                    (string) $target['reason']
                ), $target['path'], (string) $groupSlug);
                return;
            }
            $files[$groupSlug] = $existing;
        }

        foreach ($groups as $groupSlug => $group) {
            $existing = $files[$groupSlug];
            $hash = null;
            if ($existing !== null && is_file($existing->source)) {
                $hash = (string) hash_file('sha256', $existing->source);
            }
            try {
                $this->writer->write(self::KIND, (string) $groupSlug, ($this->serialize)(self::forFile($group)), $hash, $existing);
            } catch (WriteException $e) {
                $this->record('monitor_unwritable', sprintf(
                    /* translators: 1: group slug, 2: error message */
                    __('The permalink monitor could not write the group "%1$s": %2$s', 'proto-blocks'),
                    (string) $groupSlug,
                    $e->getMessage()
                ), $existing !== null ? $existing->source : '', (string) $groupSlug);
                return;
            }
        }

        foreach ($notices as $notice) {
            $this->record('monitor_rule_disabled', $notice['message'], $notice['source'], $notice['slug']);
        }
        $this->registry->invalidate();
    }

    /** A group loaded from a file must be that same file in a writable root; a new group (no source) always passes. */
    private static function isLoadedFile(array $group, ?Declaration $existing): bool
    {
        $source = (string) ($group['source'] ?? '');
        if ($source === '') {
            return true;
        }
        if ($existing === null) {
            return false;
        }
        $a = realpath($source);
        $b = realpath($existing->source);

        return $a !== false && $a === $b;
    }

    /** @return array<string, mixed> the file group $slug, or a new empty one */
    private function targetGroup(string $slug): array
    {
        $group = $this->registry->group($slug);
        if (is_array($group) && ($group['origin'] ?? 'file') === 'file') {
            return $group;
        }

        return [
            'slug' => $slug,
            'title' => ucfirst(str_replace(['-', '_'], ' ', $slug)),
            'enabled' => true,
            'position' => 10,
            'defaults' => $this->defaults(),
            'rules' => [],
            'origin' => 'file',
            'source' => '',
            'root' => null,
            'double' => null,
        ];
    }

    /**
     * Appends $rule, or updates the group's existing non-regex rule from the same path.
     *
     * @param array<string, mixed> $group
     * @param array{from: string, to: string, title: string, enabled: bool} $rule
     * @return array<string, mixed>
     */
    private function addRule(array $group, string $slug, array $rule): array
    {
        $rules = array_values((array) ($group['rules'] ?? []));
        foreach ($rules as $i => $existing) {
            if (is_array($existing) && self::samePath($existing, $rule['from'])) {
                $rules[$i]['to'] = $rule['to'];
                $rules[$i]['title'] = $rule['title'];
                $rules[$i]['enabled'] = $rule['enabled'];
                $group['rules'] = $rules;
                return $group;
            }
        }

        $defaults = is_array($group['defaults'] ?? null) ? $group['defaults'] : $this->defaults();
        $taken = array_flip(array_map(static fn($r): string => is_array($r) ? (string) ($r['id'] ?? '') : '', $rules));
        $rules[] = [
            'id' => self::ruleId($slug, $rule['from'], $taken),
            'title' => $rule['title'],
            'enabled' => $rule['enabled'],
            'from' => $rule['from'],
            'regex' => false,
            'to' => $rule['to'],
            'else' => null,
            'code' => 301,
            'action' => 'url',
            'when' => null,
            'query' => $defaults['query'] ?? 'exact',
            'ignoreCase' => $defaults['ignoreCase'] ?? true,
            'ignoreTrailingSlash' => $defaults['ignoreTrailingSlash'] ?? true,
            'log' => true,
            'headers' => [],
        ];
        $group['rules'] = $rules;

        return $group;
    }

    /**
     * Disables every enabled non-regex file rule whose `from` is $path.
     *
     * @param array<string, array<string, mixed>> $groups
     * @param list<array{message: string, source: string, slug: string}> $notices
     * @return array<string, array<string, mixed>>
     */
    private function disableLoops(array $groups, string $path, array &$notices): array
    {
        foreach ((array) $this->registry->rules() as $rule) {
            if (!is_array($rule) || ($rule['enabled'] ?? true) !== true || ($rule['regex'] ?? false) === true || !self::samePath($rule, $path)) {
                continue;
            }
            $groupSlug = (string) ($rule['group'] ?? '');
            $from = (string) ($rule['from'] ?? '');
            if (($rule['origin'] ?? 'file') !== 'file') {
                $notices[] = [
                    'message' => sprintf(
                        /* translators: 1: source path, 2: call site */
                        __('A redirect declared in PHP (%2$s) sends %1$s elsewhere and may now loop; remove or change it.', 'proto-blocks'),
                        $from,
                        (string) ($rule['source'] ?? '')
                    ),
                    'source' => (string) ($rule['source'] ?? ''),
                    'slug' => $groupSlug,
                ];
                continue;
            }
            if (!isset($groups[$groupSlug])) {
                $group = $this->registry->group($groupSlug);
                if (!is_array($group)) {
                    continue;
                }
                $groups[$groupSlug] = $group;
            }
            $groupRules = array_values((array) ($groups[$groupSlug]['rules'] ?? []));
            foreach ($groupRules as $i => $candidate) {
                $sameId = isset($rule['id'], $candidate['id']) && $candidate['id'] === $rule['id'];
                $sameIndex = !isset($rule['id']) && isset($rule['index']) && $i === (int) $rule['index'];
                if (is_array($candidate) && ($sameId || $sameIndex) && ($candidate['enabled'] ?? true) === true) {
                    $groupRules[$i]['enabled'] = false;
                    $notices[] = [
                        'message' => sprintf(
                            /* translators: 1: source path, 2: group slug */
                            __('The permalink monitor disabled the redirect from %1$s in the group "%2$s": a post moved to that address.', 'proto-blocks'),
                            $from,
                            $groupSlug
                        ),
                        'source' => (string) ($groups[$groupSlug]['source'] ?? ''),
                        'slug' => $groupSlug,
                    ];
                }
            }
            $groups[$groupSlug]['rules'] = $groupRules;
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private static function forFile(array $group): array
    {
        $group['rules'] = array_map(static function ($rule) {
            if (is_array($rule)) {
                foreach (self::REGISTRY_KEYS as $key) {
                    unset($rule[$key]);
                }
            }
            return $rule;
        }, array_values((array) ($group['rules'] ?? [])));

        return $group;
    }

    /**
     * @param array<string, int> $taken ids already in the group
     */
    private static function ruleId(string $slug, string $from, array $taken): string
    {
        $seed = $slug . "\0" . $from . "\0" . 'null' . "\0" . 'url';
        $id = 'r_' . substr(sha1($seed), 0, 8);
        for ($n = 1; isset($taken[$id]); $n++) {
            $id = 'r_' . substr(sha1($seed . "\0" . $n), 0, 8);
        }

        return $id;
    }

    /** Whether a non-regex $rule's `from` is $path under its own case and trailing-slash flags. */
    private static function samePath(array $rule, string $path): bool
    {
        if (($rule['regex'] ?? false) === true || !is_string($rule['from'] ?? null)) {
            return false;
        }
        $from = $rule['from'];
        if (($rule['ignoreTrailingSlash'] ?? true) !== false) {
            $from = rtrim($from, '/') ?: '/';
            $path = rtrim($path, '/') ?: '/';
        }
        if (($rule['ignoreCase'] ?? true) !== false) {
            return mb_strtolower($from, 'UTF-8') === mb_strtolower($path, 'UTF-8');
        }

        return $from === $path;
    }

    private static function withSuffix(string $path, string $suffix): string
    {
        return rtrim($path, '/') . '/' . ltrim($suffix, '/');
    }

    // ------------------------------------------------------------------ helpers

    private function remember(int $postId): void
    {
        if (!$this->active()) {
            return;
        }
        $post = get_post($postId);
        if (!is_object($post) || !$this->watched((string) ($post->post_type ?? ''))) {
            return;
        }
        $this->pending[$postId] = [
            'path' => $this->pathOf(get_permalink($postId)),
            'status' => (string) ($post->post_status ?? ''),
        ];
    }

    /**
     * The decoded path of $url relative to home_url() (the home path removed),
     * or null for anything not on the site or too long to be a rule source.
     */
    private function pathOf(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url());
        if (!is_array($parts) || !is_array($home)) {
            return null;
        }
        if (isset($parts['host']) && strtolower((string) $parts['host']) !== strtolower((string) ($home['host'] ?? ''))) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        $base = rtrim((string) ($home['path'] ?? ''), '/');
        if ($base !== '') {
            if ($path !== $base && !str_starts_with($path, $base . '/')) {
                return null;
            }
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim(rawurldecode($path), '/');
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1 || strlen($path) > self::FROM_MAX) {
            return null;
        }

        return $path;
    }

    private function title(object $post, string $event): string
    {
        $name = trim(str_replace(['<', '>'], '', wp_strip_all_tags((string) ($post->post_title ?? ''))));
        if ($name === '') {
            $name = sprintf('#%d', (int) ($post->ID ?? 0));
        }
        $date = gmdate('Y-m-d', (int) current_time('timestamp', true));
        $suffix = $event === 'trashed'
            /* translators: %s: date */
            ? sprintf(__('(trashed %s)', 'proto-blocks'), $date)
            /* translators: %s: date */
            : sprintf(__('(moved %s)', 'proto-blocks'), $date);

        return mb_substr($name, 0, self::TITLE_MAX - mb_strlen($suffix) - 1) . ' ' . $suffix;
    }

    private function record(string $code, string $message, string $source, string $slug): void
    {
        $kept = array_values(array_filter((array) get_option(self::ISSUES_OPTION, []), 'is_array'));
        $kept[] = ['level' => Issue::WARNING, 'code' => $code, 'message' => $message, 'source' => $source, 'slug' => $slug];
        update_option(self::ISSUES_OPTION, array_slice($kept, -self::ISSUES_KEPT), false);
    }

    private function active(): bool
    {
        $structure = get_option('permalink_structure', '');

        return $this->postTypes() !== [] && is_string($structure) && $structure !== '';
    }

    private function watched(string $type): bool
    {
        return $type !== '' && in_array($type, $this->postTypes(), true);
    }

    /** @return list<string> */
    private function postTypes(): array
    {
        $types = $this->monitorSettings('postTypes', []);

        return array_values(array_filter(is_array($types) ? $types : [], static fn($t): bool => is_string($t) && $t !== ''));
    }

    private function groupSlug(): string
    {
        $slug = $this->monitorSettings('group', self::DEFAULT_GROUP);

        return is_string($slug) && $slug !== '' ? $slug : self::DEFAULT_GROUP;
    }

    private function associated(): string
    {
        $suffix = $this->monitorSettings('associated', '');

        return is_string($suffix) && trim($suffix, '/ ') !== '' ? '/' . trim($suffix, '/ ') . '/' : '';
    }

    /** @return array{ignoreCase: bool, ignoreTrailingSlash: bool, query: string} */
    private function defaults(): array
    {
        $d = is_array($this->settings['defaults'] ?? null) ? $this->settings['defaults'] : [];

        return [
            'ignoreCase' => ($d['ignoreCase'] ?? true) !== false,
            'ignoreTrailingSlash' => ($d['ignoreTrailingSlash'] ?? true) !== false,
            'query' => is_string($d['query'] ?? null) ? $d['query'] : 'exact',
        ];
    }

    private function monitorSettings(string $key, mixed $default): mixed
    {
        $monitor = $this->settings['monitor'] ?? [];

        return is_array($monitor) && array_key_exists($key, $monitor) ? $monitor[$key] : $default;
    }
}
