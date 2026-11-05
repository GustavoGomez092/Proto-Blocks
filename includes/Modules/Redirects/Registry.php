<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;
use ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer;
use ProtoBlocks\Modules\Redirects\Definition\Settings;
use ProtoBlocks\Modules\Redirects\Definition\SettingsValidator;
use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations;

/**
 * Every redirect group and rule of the site: group files and the settings
 * file (Sources\GroupFiles), plus proto_redirect()/proto_redirect_group()
 * (Sources\PhpDeclarations), validated, normalised and merged.
 *
 * Precedence: a file group replaces a PHP group of the same slug whole
 * (`double` on the group, `double_declaration` warning); a PHP rule whose
 * source + condition equal a file rule's is dropped (`double_declaration`,
 * naming the call site). A broken file also drops the PHP group of its slug
 * (fail closed). A group-level error skips the group; a rule-level error
 * drops that rule only. Evaluation order: groups by position, then slug;
 * rules by their position in the group. Built once until invalidate().
 */
final class Registry
{
    public const KIND = GroupFiles::KIND;
    public const DEFAULT_POSITION = 10;

    /** Registry-added rule keys, never part of a file. */
    private const RUNTIME_KEYS = ['group', 'origin', 'source', 'index'];

    /** Codes from GroupFiles meaning the slug's file exists but could not be used. */
    private const BROKEN_CODES = ['invalid_jsonc', 'file_too_large', 'unreadable', 'path_outside_root', 'broken_override'];

    private readonly \Closure $filesFactory;
    private ?GroupFiles $files = null;

    /** @var array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string|int, mixed>}|null */
    private ?array $injected = null;

    /** @var array{groups: list<array<string, mixed>>, settings: array<string, mixed>, issues: list<Issue>}|null */
    private ?array $built = null;

    /** @param (\Closure(): GroupFiles)|null $files factory for the file source (default: the theme roots) */
    public function __construct(?\Closure $files = null)
    {
        $this->filesFactory = $files ?? static fn(): GroupFiles => new GroupFiles();
    }

    /**
     * A registry over given groups instead of the theme and PHP calls
     * (tests, previews). Groups may be raw (group-file keys plus `slug`,
     * optional `source`, `root`) or normalised; rules are re-normalised.
     *
     * @param list<array<string, mixed>> $fileGroups
     * @param list<array<string, mixed>> $phpGroups
     * @param array<string|int, mixed>   $settings raw settings-file data
     */
    public static function fromArrays(array $fileGroups, array $phpGroups, array $settings): self
    {
        $registry = new self(static fn(): GroupFiles => new GroupFiles(static fn(): array => []));
        $registry->injected = [array_values($fileGroups), array_values($phpGroups), $settings];

        return $registry;
    }

    /** @return list<array<string, mixed>> every usable group (disabled ones included), in evaluation order */
    public function groups(): array
    {
        return $this->build()['groups'];
    }

    /** @return array<string, mixed>|null */
    public function group(string $slug): ?array
    {
        foreach ($this->groups() as $group) {
            if ($group['slug'] === $slug) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Rules of enabled groups in evaluation order; disabled rules are
     * included with `enabled => false` so callers decide.
     *
     * @return list<array<string, mixed>>
     */
    public function rules(): array
    {
        $out = [];
        foreach ($this->groups() as $group) {
            if ($group['enabled'] === true) {
                array_push($out, ...$group['rules']);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> rules() without the disabled ones */
    public function enabledRules(): array
    {
        return array_values(array_filter($this->rules(), static fn(array $rule): bool => $rule['enabled'] === true));
    }

    /** @return array<string, mixed> fully defaulted settings */
    public function settings(): array
    {
        return $this->build()['settings'];
    }

    /** The settings file in effect, or null (defaults). */
    public function settingsPath(): ?string
    {
        return $this->injected === null ? ($this->files()->settingsFile()['path'] ?? null) : null;
    }

    /** @return list<Issue> discovery, declaration, validation, precedence and chain findings */
    public function issues(): array
    {
        return $this->build()['issues'];
    }

    /** Forget everything read: the next call reads the files again. */
    public function invalidate(): void
    {
        $this->built = null;
        $this->files = null;
    }

    /** sha256 over every group and settings file's path and content (or over the injected arrays). */
    public function filesHash(): string
    {
        if ($this->injected !== null) {
            return hash('sha256', (string) json_encode($this->injected, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $context = hash_init('sha256');
        foreach ($this->files()->paths() as $path) {
            hash_update($context, $path . "\0" . (is_file($path) ? (string) hash_file('sha256', $path) : '') . "\0");
        }

        return hash_final($context);
    }

    private function files(): GroupFiles
    {
        return $this->files ??= ($this->filesFactory)();
    }

    /** @return array{groups: list<array<string, mixed>>, settings: array<string, mixed>, issues: list<Issue>} */
    private function build(): array
    {
        if ($this->built !== null) {
            return $this->built;
        }
        $issues = [];

        if ($this->injected !== null) {
            [$fileInput, $phpInput, $rawSettings] = $this->injected;
            $settingsSource = '';
            $fileEntries = array_map(fn(array $g): array => $this->entryFromArray($g, 'file'), $fileInput);
            $phpEntries = array_map(fn(array $g): array => $this->entryFromArray($g, 'php'), $phpInput);
            $broken = [];
        } else {
            $files = $this->files();
            array_push($issues, ...$files->issues(), ...PhpDeclarations::issues());
            $settingsFile = $files->settingsFile();
            $rawSettings = $settingsFile['data'] ?? [];
            $settingsSource = $settingsFile['path'] ?? '';
            $fileEntries = [];
            foreach ($files->groups() as $file) {
                $fileEntries[] = ['slug' => $file['slug'], 'data' => $file['data'], 'origin' => 'file', 'source' => $file['path'], 'root' => $file['root'], 'ruleSources' => []];
            }
            $phpEntries = $this->phpEntries();
            $broken = [];
            foreach ($files->issues() as $issue) {
                if ($issue->level === Issue::ERROR && $issue->kind === self::KIND && $issue->slug !== null && in_array($issue->code, self::BROKEN_CODES, true)) {
                    $broken[$issue->slug] = true;
                }
            }
        }

        foreach (SettingsValidator::validate($rawSettings) as $level => $findings) {
            foreach ($findings as $finding) {
                $issues[] = self::issue($finding, $settingsSource, GroupFiles::SETTINGS_SLUG, GroupFiles::SETTINGS_KIND);
            }
        }
        $settings = Settings::merge($rawSettings);
        $inherited = $settings['defaults'];

        $fileGroups = [];
        foreach ($fileEntries as $entry) {
            $group = $this->buildGroup($entry, $inherited, $issues);
            if ($group === null) {
                $broken[$entry['slug']] = true;
            } else {
                $fileGroups[$group['slug']] = $group;
            }
        }

        // File rules by identity, for dropping PHP rules declaring the same thing.
        $fileKeys = [];
        foreach ($fileGroups as $group) {
            foreach ($group['rules'] as $rule) {
                $fileKeys[self::identity($rule)] ??= $rule;
            }
        }

        $phpGroups = [];
        foreach ($phpEntries as $entry) {
            $slug = $entry['slug'];
            if (isset($fileGroups[$slug])) {
                $fileGroups[$slug]['double'] = ['file' => $fileGroups[$slug]['source'], 'php' => $entry['source']];
                $issues[] = new Issue(Issue::WARNING, 'double_declaration', sprintf(
                    /* translators: 1: group slug, 2: file, 3: PHP call site */
                    __('The redirect group "%1$s" is declared in %2$s and in PHP at %3$s. The file is used; remove the PHP declaration.', 'proto-blocks'),
                    $slug,
                    $fileGroups[$slug]['source'],
                    $entry['source']
                ), $entry['source'], $slug, self::KIND);
                continue;
            }
            if (isset($broken[$slug])) {
                $issues[] = new Issue(Issue::ERROR, 'broken_override', sprintf(
                    /* translators: 1: group slug, 2: PHP call site */
                    __('The redirect group file "%1$s" is broken, so the PHP group of the same slug (%2$s) was NOT loaded either.', 'proto-blocks'),
                    $slug,
                    $entry['source']
                ), $entry['source'], $slug, self::KIND);
                continue;
            }
            $group = $this->buildGroup($entry, $inherited, $issues);
            if ($group === null) {
                continue;
            }
            $kept = [];
            foreach ($group['rules'] as $rule) {
                $file = $fileKeys[self::identity($rule)] ?? null;
                if ($file === null) {
                    $kept[] = $rule;
                    continue;
                }
                $issues[] = new Issue(Issue::WARNING, 'double_declaration', sprintf(
                    /* translators: 1: source path, 2: PHP call site, 3: file group, 4: file */
                    __('The redirect from %1$s declared in PHP at %2$s is also in the file group "%3$s" (%4$s). The file rule is used; remove the PHP call.', 'proto-blocks'),
                    (string) ($rule['from'] ?? '404'),
                    $rule['source'],
                    $file['group'],
                    $file['source']
                ), $rule['source'], $file['group'], self::KIND, $file['id']);
            }
            $group['rules'] = $kept;
            $phpGroups[$slug] = $group;
        }

        $groups = array_values($fileGroups + $phpGroups);
        usort($groups, static fn(array $a, array $b): int => [$a['position'], $a['slug']] <=> [$b['position'], $b['slug']]);

        $enabled = [];
        foreach ($groups as $group) {
            if ($group['enabled'] === true) {
                foreach ($group['rules'] as $rule) {
                    if ($rule['enabled'] === true) {
                        $enabled[] = $rule;
                    }
                }
            }
        }
        foreach (GroupValidator::chains($enabled, []) as [$rule, $next]) {
            $issues[] = new Issue(Issue::WARNING, 'redirect_chain', GroupValidator::chainMessage($rule, $next), $rule['source'], $rule['group'], self::KIND, $rule['id']);
        }

        return $this->built = ['groups' => $groups, 'settings' => $settings, 'issues' => $issues];
    }

    /**
     * @param array{slug: string, data: array<string|int, mixed>, origin: string, source: string, root: ?string, ruleSources: array<int, string>} $entry
     * @param array<string, mixed> $inherited
     * @param list<Issue>          $issues
     * @return array<string, mixed>|null
     */
    private function buildGroup(array $entry, array $inherited, array &$issues): ?array
    {
        $slug = $entry['slug'];
        $data = $entry['data'];
        $sourceOf = static function (string $path) use ($entry): string {
            if (preg_match('~^/rules/(\d+)~', $path, $m) === 1 && isset($entry['ruleSources'][(int) $m[1]])) {
                return $entry['ruleSources'][(int) $m[1]];
            }
            return $entry['source'];
        };

        $analysis = GroupValidator::analyse($data, $slug, $inherited, null);
        foreach ([...$analysis['errors'], ...$analysis['warnings']] as $finding) {
            $issues[] = self::issue($finding, $sourceOf($finding['path']), $slug, self::KIND);
        }
        if (!$analysis['groupValid']) {
            return null;
        }

        $rules = [];
        foreach ($analysis['rules'] as $index => $rule) {
            $rules[] = $rule + [
                'group' => $slug,
                'origin' => $entry['origin'],
                'source' => $entry['ruleSources'][$index] ?? $entry['source'],
                'index' => $index,
            ];
        }
        $title = $data['title'] ?? null;
        $position = $data['position'] ?? null;

        return [
            'slug' => $slug,
            'title' => is_string($title) && $title !== '' ? $title : $slug,
            'enabled' => ($data['enabled'] ?? true) !== false,
            'position' => is_int($position) ? $position : self::DEFAULT_POSITION,
            'defaults' => $analysis['defaults'],
            'rules' => $rules,
            'origin' => $entry['origin'],
            'source' => $entry['source'],
            'root' => $entry['root'],
            'double' => null,
        ];
    }

    /** @return list<array{slug: string, data: array<string|int, mixed>, origin: string, source: string, root: ?string, ruleSources: array<int, string>}> */
    private function phpEntries(): array
    {
        $entries = [];
        foreach (PhpDeclarations::groups() as $group) {
            $entries[] = ['slug' => $group['slug'], 'data' => $group['data'], 'origin' => 'php', 'source' => $group['source'], 'root' => null, 'ruleSources' => []];
        }
        $rules = PhpDeclarations::rules();
        if ($rules !== []) {
            $entries[] = [
                'slug' => PhpDeclarations::VIRTUAL_SLUG,
                'data' => [
                    'title' => __('Declared in PHP', 'proto-blocks'),
                    'position' => PhpDeclarations::VIRTUAL_POSITION,
                    'rules' => array_column($rules, 'rule'),
                ],
                'origin' => 'php',
                'source' => $rules[0]['source'],
                'root' => null,
                'ruleSources' => array_column($rules, 'source'),
            ];
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $group raw or normalised Group
     * @return array{slug: string, data: array<string|int, mixed>, origin: string, source: string, root: ?string, ruleSources: array<int, string>}
     */
    private function entryFromArray(array $group, string $origin): array
    {
        $data = [];
        foreach (['title', 'enabled', 'position', 'defaults', 'rules'] as $key) {
            if (array_key_exists($key, $group)) {
                $data[$key] = $group[$key];
            }
        }
        $sources = [];
        if (is_array($data['rules'] ?? null)) {
            foreach ($data['rules'] as $i => $rule) {
                if (is_array($rule)) {
                    if (is_string($rule['source'] ?? null) && $origin === 'php') {
                        $sources[$i] = $rule['source'];
                    }
                    foreach (self::RUNTIME_KEYS as $key) {
                        unset($rule[$key]);
                    }
                    // Normalised rules carry nulls the schema does not take.
                    if (is_array($rule['when'] ?? null)) {
                        $rule['when'] = array_filter($rule['when'], static fn(mixed $v): bool => $v !== null);
                    }
                    $data['rules'][$i] = array_filter($rule, static fn(mixed $v): bool => $v !== null);
                }
            }
        }
        $slug = (string) ($group['slug'] ?? '');

        return [
            'slug' => $slug,
            'data' => $data,
            'origin' => $origin,
            'source' => is_string($group['source'] ?? null) ? $group['source'] : ($origin === 'php' ? 'php' : $slug),
            'root' => is_string($group['root'] ?? null) ? $group['root'] : null,
            'ruleSources' => $sources,
        ];
    }

    /** What makes a PHP rule "the same" as a file rule: regex flag, source key, condition. */
    private static function identity(array $rule): string
    {
        $from = $rule['from'] ?? null;
        if (is_string($from) && ($rule['regex'] ?? false) !== true) {
            $from = RuleNormalizer::pathKey($from);
        }

        return (($rule['regex'] ?? false) === true ? 'r' : 'p') . "\0" . ($from ?? '') . "\0"
            . (string) json_encode($rule['when'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** @param array{path: string, code: string, message: string, level: string} $finding */
    private static function issue(array $finding, string $source, string $slug, string $kind): Issue
    {
        return new Issue(
            $finding['level'] === 'warning' ? Issue::WARNING : Issue::ERROR,
            $finding['code'],
            ($finding['path'] === '' ? '' : $finding['path'] . ': ') . $finding['message'],
            $source,
            $slug,
            $kind
        );
    }
}
