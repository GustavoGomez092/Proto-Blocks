<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

/**
 * A validated settings-page declaration (from a page.jsonc file or a
 * proto_options_page() call). Construction is pure data work: the only
 * WordPress calls are sanitize_title(), __() and the protected-options filter.
 */
final class PageDefinition
{
    public const MODE_FIELDS = 'fields';
    public const MODE_ARRAY = 'array';

    public const SLUG_MAX = 64;
    public const OPTION_MAX = 191;

    private const SLUG_RE = '/^[a-z][a-z0-9-]*$/D';
    private const FIELD_NAME_RE = '/^[a-z][a-z0-9_]*$/D';
    private const CAPABILITY_RE = '/^[a-z][a-z0-9_]*$/D';
    private const MENU_RE = '/^[a-z0-9_.?=&-]+$/D';
    private const OPTION_RE = '/^[A-Za-z0-9_-]+$/D';
    private const ICON_RE = '#^(dashicons-[a-z0-9-]+|[A-Za-z0-9_-][A-Za-z0-9_/.-]*\.svg)$#D';

    /**
     * @param list<array>             $groups
     * @param list<array>             $tabs
     * @param list<PageDefinition>    $children
     * @param array<string, array>    $fieldIndex
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $menuTitle,
        public readonly string $menu,
        public readonly string $capability,
        public readonly ?string $icon,
        public readonly ?int $position,
        public readonly bool $autoload,
        public readonly string $storageMode,
        public readonly ?string $storageOption,
        public readonly array $groups,
        public readonly array $tabs,
        public readonly array $children,
        public readonly ?string $parentSlug,
        public readonly string $origin,
        public readonly ?string $source,
        public readonly ?string $root,
        private readonly array $fieldIndex,
    ) {
    }

    /**
     * @throws InvalidDeclaration
     */
    public static function fromArray(
        string $slug,
        array $data,
        string $origin,
        ?string $source = null,
        ?string $root = null,
        ?PageDefinition $parent = null
    ): self {
        $errors = [];
        $err = static function (string $path, string $message) use (&$errors): void {
            $errors[] = ['path' => $path, 'message' => $message];
        };

        // --- slug, origin
        if (!self::validSlug($slug)) {
            $err('', sprintf(
                'Page slug "%s" is invalid: use lowercase letters, digits and hyphens, starting with a letter, at most %d characters.',
                $slug,
                self::SLUG_MAX
            ));
        }
        if (!in_array($origin, ['file', 'php'], true)) {
            $err('', 'Origin must be "file" or "php".');
            $origin = 'php';
        }

        // --- title
        $title = $data['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $err('/title', 'A page needs a non-empty "title".');
            $title = '';
        }
        $menuTitle = $data['menuTitle'] ?? $title;
        if (!is_string($menuTitle) || trim($menuTitle) === '') {
            $err('/menuTitle', '"menuTitle" must be a non-empty string.');
            $menuTitle = $title;
        }

        // --- menu
        if ($parent !== null) {
            $menu = $parent->slug;
        } else {
            $menu = $data['menu'] ?? 'top';
            if (!is_string($menu) || preg_match(self::MENU_RE, $menu) !== 1) {
                $err('/menu', 'Menu must be "top" or a parent menu slug (lowercase letters, digits and . ? = & _ - only).');
                $menu = 'top';
            }
        }

        // --- capability: verbatim, never widened, never defaulted when present
        if (array_key_exists('capability', $data)) {
            $capability = $data['capability'];
            if (!is_string($capability) || preg_match(self::CAPABILITY_RE, $capability) !== 1) {
                $err('/capability', 'Capability must be a lowercase capability name such as "manage_options".');
                $capability = 'manage_options';
            }
        } else {
            $capability = $parent !== null ? $parent->capability : 'manage_options';
        }

        // --- icon, position, autoload
        $icon = $data['icon'] ?? null;
        if ($icon !== null) {
            if (!is_string($icon) || str_contains($icon, '..') || preg_match(self::ICON_RE, $icon) !== 1) {
                $err('/icon', 'Icon must be a "dashicons-*" class or a relative path to an .svg file inside the page folder.');
                $icon = null;
            }
        }
        $position = $data['position'] ?? null;
        if ($position !== null && !is_int($position)) {
            $err('/position', 'Position must be an integer.');
            $position = null;
        }
        $autoload = $data['autoload'] ?? ($parent !== null ? $parent->autoload : true);
        if (!is_bool($autoload)) {
            $err('/autoload', '"autoload" must be true or false.');
            $autoload = true;
        }

        // --- storage
        $mode = self::MODE_FIELDS;
        $storageOption = null;
        if (array_key_exists('storage', $data)) {
            $storage = $data['storage'];
            if (!is_array($storage)) {
                $err('/storage', '"storage" must be an object.');
            } else {
                $mode = $storage['mode'] ?? self::MODE_FIELDS;
                if (!in_array($mode, [self::MODE_FIELDS, self::MODE_ARRAY], true)) {
                    $err('/storage/mode', 'Storage mode must be "fields" or "array".');
                    $mode = self::MODE_FIELDS;
                }
                if ($mode === self::MODE_ARRAY) {
                    $opt = $storage['option'] ?? null;
                    if (!is_string($opt) || $opt === '') {
                        $err('/storage/option', 'Array storage needs an "option" name.');
                    } elseif (preg_match(self::OPTION_RE, $opt) !== 1 || strlen($opt) > self::OPTION_MAX) {
                        $err('/storage/option', sprintf(
                            'Storage option must use letters, digits, "_" and "-" only, at most %d characters.',
                            self::OPTION_MAX
                        ));
                    } elseif (ProtectedOptions::isProtected($opt)) {
                        $err('/storage/option', sprintf('Option "%s" is reserved by WordPress or Proto-Blocks and cannot be used.', $opt));
                    } else {
                        $storageOption = $opt;
                    }
                }
            }
        }

        // --- fields: untabbed groups, then tabs
        $counter = 0;
        $fieldIndex = [];
        $groups = [];
        $tabs = [];

        if (array_key_exists('fields', $data)) {
            $groups = self::normalizeItems($data['fields'], '/fields', $slug, $title, $counter, $fieldIndex, $err);
        }
        if (array_key_exists('tabs', $data)) {
            if (!is_array($data['tabs']) || !array_is_list($data['tabs'])) {
                $err('/tabs', '"tabs" must be a list.');
            } else {
                foreach ($data['tabs'] as $i => $tab) {
                    $path = '/tabs/' . $i;
                    if (!is_array($tab) || !is_string($tab['title'] ?? null) || trim($tab['title']) === '') {
                        $err($path . '/title', 'Each tab needs a non-empty "title".');
                        continue;
                    }
                    $tabs[] = [
                        'title' => $tab['title'],
                        'groups' => self::normalizeItems($tab['fields'] ?? [], $path . '/fields', $slug, $tab['title'], $counter, $fieldIndex, $err),
                    ];
                }
            }
        }

        // --- derived option names
        if ($mode === self::MODE_FIELDS) {
            foreach (array_keys($fieldIndex) as $name) {
                $option = $slug . '_' . $name;
                if (strlen($option) > self::OPTION_MAX) {
                    $err('', sprintf('Option name for field "%s" is longer than %d characters.', $name, self::OPTION_MAX));
                } elseif (ProtectedOptions::isProtected($option)) {
                    $err('', sprintf('Field "%s" on page "%s" would use the reserved option "%s".', $name, $slug, $option));
                }
            }
        }

        // --- children
        $children = [];
        $childList = $data['children'] ?? [];
        if ($childList !== [] && $parent !== null) {
            $err('/children', 'A child page cannot have children of its own.');
        } elseif ($childList !== []) {
            if (!is_array($childList) || !array_is_list($childList)) {
                $err('/children', '"children" must be a list.');
            }
        }

        if ($errors === [] && $childList !== [] && $parent === null) {
            $self = new self(
                $slug, $title, $menuTitle, $menu, $capability, $icon, $position, $autoload, $mode,
                $storageOption, $groups, $tabs, [], null, $origin, $source, $root, $fieldIndex
            );
            foreach ($childList as $i => $child) {
                $path = '/children/' . $i;
                $childSlug = is_array($child) ? ($child['slug'] ?? null) : null;
                if (!is_string($childSlug) || !self::validSlug($childSlug)) {
                    $errors[] = ['path' => $path . '/slug', 'message' => sprintf(
                        'Child page slug %s is invalid: use lowercase letters, digits and hyphens, starting with a letter.',
                        is_string($childSlug) ? '"' . $childSlug . '"' : '(missing)'
                    )];
                    continue;
                }
                try {
                    $children[] = self::fromArray($slug . '-' . $childSlug, $child, $origin, $source, $root, $self);
                } catch (InvalidDeclaration $e) {
                    foreach ($e->errors() as $ce) {
                        $errors[] = ['path' => $path . $ce['path'], 'message' => $ce['message']];
                    }
                }
            }
        }
        if ($errors !== []) {
            throw new InvalidDeclaration($errors);
        }

        return new self(
            $slug, $title, $menuTitle, $menu, $capability, $icon, $position, $autoload, $mode,
            $storageOption, $groups, $tabs, $children, $parent?->slug, $origin, $source, $root, $fieldIndex
        );
    }

    /**
     * Page slug from a title: sanitize_title, underscores to hyphens; null when
     * the result does not satisfy the slug grammar.
     */
    public static function slugFromTitle(string $title): ?string
    {
        $slug = str_replace('_', '-', sanitize_title($title));

        return self::validSlug($slug) ? $slug : null;
    }

    /**
     * Every top-level raw field across groups and tabs, in order, keyed by name.
     *
     * @return array<string, array>
     */
    public function fields(): array
    {
        return $this->fieldIndex;
    }

    public function optionName(string $field): string
    {
        if ($this->storageMode === self::MODE_ARRAY && $this->storageOption !== null) {
            return $this->storageOption;
        }

        return $this->slug . '_' . $field;
    }

    /**
     * JSON-safe shape for the admin client. Never includes source or root.
     */
    public function toClientArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'menuTitle' => $this->menuTitle,
            'menu' => $this->menu,
            'capability' => $this->capability,
            'icon' => $this->icon,
            'position' => $this->position,
            'autoload' => $this->autoload,
            'storage' => ['mode' => $this->storageMode, 'option' => $this->storageOption],
            'groups' => $this->groups,
            'tabs' => $this->tabs,
            'children' => array_map(static fn(PageDefinition $c): array => $c->toClientArray(), $this->children),
            'parentSlug' => $this->parentSlug,
            'origin' => $this->origin,
        ];
    }

    private static function validSlug(string $slug): bool
    {
        return strlen($slug) <= self::SLUG_MAX && preg_match(self::SLUG_RE, $slug) === 1;
    }

    /**
     * Turn a mixed list of ACF groups and bare fields into a list of groups,
     * wrapping consecutive bare fields in an implicit group.
     *
     * @param mixed                $items
     * @param array<string, array> $index
     * @param callable             $err
     * @return list<array>
     */
    private static function normalizeItems(
        mixed $items,
        string $path,
        string $slug,
        string $containerTitle,
        int &$counter,
        array &$index,
        callable $err
    ): array {
        if (!is_array($items) || !array_is_list($items)) {
            $err($path, 'Expected a list of fields or field groups.');
            return [];
        }

        $under = str_replace('-', '_', $slug);
        $groups = [];
        $pending = [];

        $flush = function () use (&$pending, &$groups, &$counter, $under, $containerTitle): void {
            if ($pending === []) {
                return;
            }
            $counter++;
            $groups[] = [
                'key' => 'group_' . $under . '_' . $counter,
                'title' => $containerTitle,
                'fields' => $pending,
            ];
            $pending = [];
        };

        foreach ($items as $i => $item) {
            $itemPath = $path . '/' . $i;
            if (!is_array($item)) {
                $err($itemPath, 'A field or group must be an object.');
                continue;
            }

            if (array_key_exists('type', $item)) {
                $field = self::normalizeField($item, $itemPath, $under, $slug, $index, $err);
                if ($field !== null) {
                    $pending[] = $field;
                }
                continue;
            }

            if (array_key_exists('fields', $item)) {
                $flush();
                if (!is_array($item['fields']) || !array_is_list($item['fields'])) {
                    $err($itemPath . '/fields', 'Group "fields" must be a list.');
                    continue;
                }
                $fields = [];
                foreach ($item['fields'] as $j => $raw) {
                    if (!is_array($raw)) {
                        $err($itemPath . '/fields/' . $j, 'A field must be an object.');
                        continue;
                    }
                    $field = self::normalizeField($raw, $itemPath . '/fields/' . $j, $under, $slug, $index, $err);
                    if ($field !== null) {
                        $fields[] = $field;
                    }
                }
                if (!isset($item['key']) || !is_string($item['key']) || $item['key'] === '') {
                    $counter++;
                    $item['key'] = 'group_' . $under . '_' . $counter;
                }
                if (!isset($item['title']) || !is_string($item['title'])) {
                    $item['title'] = $containerTitle;
                }
                $item['fields'] = $fields;
                $item['location'] ??= [[['param' => 'options_page', 'operator' => '==', 'value' => $slug]]];
                $groups[] = $item;
                continue;
            }

            $err($itemPath, 'Entry is neither a field (has "type") nor a group (has "fields").');
        }
        $flush();

        return $groups;
    }

    /**
     * @param array<string, array> $index
     */
    private static function normalizeField(array $field, string $path, string $under, string $slug, array &$index, callable $err): ?array
    {
        $name = $field['name'] ?? null;
        if (!is_string($name) || preg_match(self::FIELD_NAME_RE, $name) !== 1) {
            $err($path . '/name', 'A field needs a "name" of lowercase letters, digits and underscores, starting with a letter.');
            return null;
        }
        if (!isset($field['type']) || !is_string($field['type']) || $field['type'] === '') {
            $err($path . '/type', sprintf('Field "%s" needs a "type".', $name));
            return null;
        }
        if (isset($index[$name])) {
            $err($path . '/name', sprintf('Field name "%s" is used twice on page "%s".', $name, $slug));
            return null;
        }
        if (!isset($field['key']) || !is_string($field['key']) || $field['key'] === '') {
            $field['key'] = 'field_' . $under . '_' . $name;
        }
        $index[$name] = $field;

        return $field;
    }
}
