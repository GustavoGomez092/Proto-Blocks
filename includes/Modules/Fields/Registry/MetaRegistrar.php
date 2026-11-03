<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Registry;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\Report;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\PostMetaStore;
use ProtoBlocks\Modules\Fields\Storage\Store;
use ProtoBlocks\Modules\Fields\Storage\TermMetaStore;
use ProtoBlocks\Modules\Fields\Storage\UserMetaStore;
use ProtoBlocks\Modules\Fields\Types\FieldTypeInterface;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\OptionsPages\ProtectedOptions;

/**
 * Registers one meta key per stored top-level field, per kind and subtype the
 * group's location can match, with the type's REST schema and the
 * TrustCallbacks sanitize/auth callbacks. Options page fields are not meta:
 * each gets a `sanitize_option_<page>_<name>` filter with the same trust
 * callback instead, so update_option() goes through the same boundary.
 *
 * Registered keys are marked protected (`is_protected_meta`), so core's raw
 * Custom Fields panel never lists or edits them; REST writes still work
 * because the auth callback decides on its own.
 *
 * User fields may not use core's sensitive user-meta keys (`<prefix>capabilities`,
 * `<prefix>user_level`, multisite `<base prefix><n>_capabilities`, `session_tokens`):
 * they are refused with a `protected_meta_key` warning. Post and term keys
 * need no such list: field names start with a letter (GroupValidator::
 * NAME_PATTERN), so core's underscore-prefixed keys (`_edit_lock`,
 * `_wp_page_template`, ...) cannot occur.
 *
 * A name used by two groups on the same kind and subtype (or on a subtype and
 * on "every subtype") is registered once, for the first group by menu_order
 * then source order, with a `duplicate_meta_key` warning.
 */
final class MetaRegistrar
{
    /** @var list<array{string, string, string}> */
    private array $registered = [];

    /** @var list<array{string, string, string}> */
    private array $registeredOptions = [];

    /** @var array<string, array<string, true>> meta type => names (is_protected_meta) */
    private array $protected = [];

    private Report $report;

    private bool $done = false;

    /** @var array<string, Store> */
    private array $stores;

    public function __construct(
        private readonly GroupIndex $index,
        private readonly TypeRegistry $types,
        private readonly TrustCallbacks $trust,
    ) {
        $this->report = new Report();
        $this->stores = [
            'post' => new PostMetaStore(),
            'term' => new TermMetaStore(),
            'user' => new UserMetaStore(),
            'option' => new OptionStore($index),
        ];
    }

    /** Runs on init priority 20. Idempotent. */
    public function register(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->trust->hook();

        /** @var array<string, array<string, array<string, string>>> $claimed kind => name => subtype => group key */
        $claimed = [];
        foreach ($this->index->all() as $group) {
            foreach (LocationMatcher::kinds($group->location) as $kind) {
                foreach ($this->index->subtypesFor($group, $kind) as $subtype) {
                    foreach ($group->fields as $field) {
                        $type = $this->storedType($field);
                        if ($type === null) {
                            continue;
                        }
                        if ($kind === 'user' && self::isProtectedUserKey($field->name)) {
                            $this->report->addWarning(
                                sprintf('%s.%s', $group->key, $field->name),
                                'protected_meta_key',
                                sprintf(
                                    /* translators: %s: meta key */
                                    __('The user meta key "%s" is reserved by WordPress; this field is not stored.', 'proto-blocks'),
                                    $field->name
                                )
                            );
                            continue;
                        }
                        $owner = $this->claim($claimed, $kind, $subtype, $field->name, $group);
                        if ($owner !== null) {
                            $this->report->addWarning(
                                sprintf('%s.%s', $group->key, $field->name),
                                'duplicate_meta_key',
                                sprintf(
                                    /* translators: 1: field name, 2: group key that keeps it */
                                    __('The field name "%1$s" is already used by group "%2$s" on the same screen; this copy is not stored.', 'proto-blocks'),
                                    $field->name,
                                    $owner
                                )
                            );
                            continue;
                        }
                        if ($kind === 'option') {
                            $this->registerOption($group, $subtype, $field);
                        } else {
                            $this->registerMeta($kind, $subtype, $field, $type);
                        }
                    }
                }
            }
        }

        if ($this->protected !== []) {
            add_filter('is_protected_meta', [$this, 'protectMeta'], 10, 3);
        }
    }

    /** @return list<array{string, string, string}> [kind, subtype, metaKey] */
    public function registered(): array
    {
        return $this->registered;
    }

    /** @return list<array{string, string, string}> ['option', page, optionName] */
    public function registeredOptions(): array
    {
        return $this->registeredOptions;
    }

    public function report(): Report
    {
        return $this->report;
    }

    /** @internal is_protected_meta filter */
    public function protectMeta($protected, $metaKey = '', $metaType = ''): bool
    {
        $metaKey = (string) $metaKey;
        $metaType = (string) $metaType;
        if ($metaType === '') {
            foreach ($this->protected as $names) {
                if (isset($names[$metaKey])) {
                    return true;
                }
            }

            return (bool) $protected;
        }

        return isset($this->protected[$metaType][$metaKey]) ? true : (bool) $protected;
    }

    /**
     * Core user-meta keys that grant roles, levels or sessions: exactly
     * `session_tokens`, `<blog prefix>capabilities|user_level` and, for
     * multisite, `<base prefix>[<n>_]capabilities|user_level` (main site and every site). Prefixes come
     * from $wpdb (default `wp_`). Other names ending in "capabilities" are
     * ordinary keys.
     */
    public static function isProtectedUserKey(string $name): bool
    {
        if ($name === 'session_tokens') {
            return true;
        }
        $wpdb = $GLOBALS['wpdb'] ?? null;
        $blog = is_object($wpdb) && method_exists($wpdb, 'get_blog_prefix') ? (string) $wpdb->get_blog_prefix() : '';
        $base = is_object($wpdb) && isset($wpdb->base_prefix) && is_string($wpdb->base_prefix) ? $wpdb->base_prefix : '';
        $blog = $blog !== '' ? $blog : ($base !== '' ? $base : 'wp_');
        $base = $base !== '' ? $base : 'wp_';

        return (bool) preg_match('/^' . preg_quote($blog, '/') . '(?:capabilities|user_level)\z/D', $name)
            || (bool) preg_match('/^' . preg_quote($base, '/') . '(?:[0-9]+_)?(?:capabilities|user_level)\z/D', $name);
    }

    private function storedType(Field $field): ?FieldTypeInterface
    {
        if ($field->name === '') {
            return null;
        }
        try {
            $type = $this->types->for($field);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $type->stores() ? $type : null;
    }

    /**
     * Claims (kind, subtype, name) for the group. Returns the key of the group
     * that already owns it, or null when the claim succeeded.
     *
     * @param array<string, array<string, array<string, string>>> $claimed
     */
    private function claim(array &$claimed, string $kind, string $subtype, string $name, FieldGroup $group): ?string
    {
        foreach ($claimed[$kind][$name] ?? [] as $owned => $owner) {
            $owned = (string) $owned;
            if ($owned === $subtype || $owned === '' || $subtype === '') {
                return $owner === $group->key ? null : $owner;
            }
        }
        $claimed[$kind][$name][$subtype] = $group->key;

        return null;
    }

    private function registerMeta(string $kind, string $subtype, Field $field, FieldTypeInterface $type): void
    {
        $schema = $type->restSchema($field);
        $metaType = self::metaType($schema);
        $args = [
            'type' => $metaType,
            'single' => true,
            'default' => self::defaultFor($field, $type, $metaType),
            'show_in_rest' => ['schema' => $schema],
            'sanitize_callback' => $this->trust->sanitizeCallback($field, $this->stores[$kind]),
            'auth_callback' => $this->trust->authCallback($field, $kind),
        ];

        if ($kind === 'post') {
            $args['revisions_enabled'] = $subtype !== '' && self::revisionsSupported() && post_type_supports($subtype, 'revisions');
            if ($subtype !== '' && !post_type_supports($subtype, 'custom-fields')) {
                add_post_type_support($subtype, 'custom-fields');
            }
            register_post_meta($subtype, $field->name, $args);
        } elseif ($kind === 'term') {
            register_term_meta($subtype, $field->name, $args);
        } else {
            register_meta('user', $field->name, $args);
        }

        $this->trust->guard($kind, $subtype, $field);
        $this->registered[] = [$kind, $subtype, $field->name];
        $this->protected[$kind][$field->name] = true;
    }

    private function registerOption(FieldGroup $group, string $page, Field $field): void
    {
        $option = OptionStore::optionName($page, $field->name);
        if (ProtectedOptions::isProtected($option)) {
            $this->report->addWarning(
                sprintf('%s.%s', $group->key, $field->name),
                'protected_option',
                sprintf(
                    /* translators: %s: option name */
                    __('The option "%s" is reserved by WordPress; this field is not stored.', 'proto-blocks'),
                    $option
                )
            );

            return;
        }
        $callback = $this->trust->sanitizeCallback($field, $this->stores['option'], ObjectRef::option($page));
        add_filter("sanitize_option_{$option}", $callback, 10, 3);
        $this->registeredOptions[] = ['option', $page, $option];
    }

    /**
     * register_meta()'s scalar type from a REST schema: the first non-null
     * entry of a type list, the first branch of an anyOf, else string.
     *
     * @param array<string, mixed> $schema
     */
    private static function metaType(array $schema): string
    {
        $allowed = ['string', 'boolean', 'integer', 'number', 'array', 'object'];
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $type = array_values(array_filter($type, static fn($t): bool => $t !== 'null'))[0] ?? null;
        }
        if ($type === null && isset($schema['anyOf'][0]) && is_array($schema['anyOf'][0])) {
            return self::metaType($schema['anyOf'][0]);
        }

        return is_string($type) && in_array($type, $allowed, true) ? $type : 'string';
    }

    /** The declared default shaped by the type; the empty value when it does not fit the meta type. */
    private static function defaultFor(Field $field, FieldTypeInterface $type, string $metaType): mixed
    {
        $default = $type->normalize($field->default, $field);
        $fits = match ($metaType) {
            'array', 'object' => is_array($default),
            'boolean' => is_bool($default),
            'integer' => is_int($default) || $default === null,
            'number' => is_int($default) || is_float($default) || $default === null,
            default => is_string($default),
        };

        return $fits ? $default : $type->emptyValue($field);
    }

    /** revisions_enabled exists from WordPress 6.4. */
    private static function revisionsSupported(): bool
    {
        $version = $GLOBALS['wp_version'] ?? get_bloginfo('version');

        return is_string($version) && $version !== '' && version_compare($version, '6.4', '>=');
    }
}
