<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

use ProtoBlocks\Controls\OptionsProviders;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Edit\SaveHandler;
use ProtoBlocks\Modules\Fields\Types\FieldTypeInterface;
use ProtoBlocks\Modules\Fields\Types\RepeaterType;
use ProtoBlocks\Modules\Fields\Types\TrustMessage;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\Modules\Fields\Validation\ValueValidator;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * The values of a settings page, read and written through Phase 2's field
 * pipeline: type normalisation, the per-writer trust boundary (spec "Trust
 * for field values", the same rules as post meta), conditional visibility and
 * validation, then one OptionsStore write.
 *
 * Only the page's declared top-level stored fields (its own groups plus
 * standalone groups whose location targets the page) are ever read or
 * written; every other submitted key is ignored. Definitions are memoised per
 * page slug; values never are, so nothing read for one user is served to
 * another. A field whose option name is protected is never read or written,
 * even when a filter protected it after the page loaded.
 *
 * Password fields follow Phase 2: read() returns the stored value raw to
 * anyone who holds the page capability, and an untrusted writer's password
 * is sanitised like text (Tasks 7/9: render it as a password input and do
 * not echo it anywhere else).
 */
final class OptionsValues
{
    /** @var array<string, array{groups: list<FieldGroup>, fields: array<string, Field>, warnings: list<string>}> */
    private array $memo = [];

    /** @var list<array{field: ?string, message: string}> */
    private array $notices = [];

    /** @var \Closure(Field): ?array */
    private readonly \Closure $choiceResolver;

    /**
     * @param \Closure(string): list<FieldGroup> $extraGroups standalone groups with an options_page rule for the slug ([] when the Fields module is off)
     * @param (\Closure(Field): ?array)|null $choiceResolver allowed values for an `optionsSource` field; defaults to the plugin's options providers
     */
    public function __construct(
        private readonly OptionsPagesRegistry $registry,
        private readonly OptionsStore $store,
        private readonly TypeRegistry $types,
        private readonly \Closure $extraGroups,
        ?\Closure $choiceResolver = null,
    ) {
        $this->choiceResolver = $choiceResolver ?? static fn (Field $f): ?array => self::providerChoices($f);
    }

    /**
     * The page's own groups, normalised as Phase 2 field groups.
     *
     * @return list<FieldGroup>
     */
    public function pageGroups(PageDefinition $page): array
    {
        return $this->definitions($page)['own'];
    }

    /**
     * pageGroups() plus standalone groups targeting the page whose key is not
     * already present. A standalone field whose name the page already uses,
     * or whose option name is protected, is dropped with a warning.
     *
     * @return list<FieldGroup>
     */
    public function groupsFor(PageDefinition $page): array
    {
        return $this->definitions($page)['groups'];
    }

    /**
     * Top-level value-holding fields of groupsFor(), by name.
     *
     * @return array<string, Field>
     */
    public function fields(PageDefinition $page): array
    {
        return $this->definitions($page)['fields'];
    }

    /** @return list<string> */
    public function warnings(PageDefinition $page): array
    {
        return $this->definitions($page)['warnings'];
    }

    /**
     * Stored values of the page's fields, or each field's normalised default
     * when nothing is stored. Read fresh on every call.
     *
     * @return array<string, mixed>
     */
    public function read(PageDefinition $page): array
    {
        $out = [];
        foreach ($this->fields($page) as $name => $field) {
            // Defence in depth: a filter may have protected the name since the page loaded.
            if (ProtectedOptions::isProtected($page->optionName($name))) {
                continue;
            }
            $out[$name] = $this->store->has($page, $name)
                ? $this->store->read($page, $name)
                : $this->defaultOf($field);
        }

        return $out;
    }

    /**
     * Fields this user may not write, with the reason: `unfiltered_html` for a
     * code field, or a field holding a code sub-field, when the user lacks
     * unfiltered_html. save() applies the same check, so the form can show
     * these read-only and save() keeps their stored value.
     *
     * @return array<string, string> field name => reason
     */
    public function readOnly(PageDefinition $page, int $userId): array
    {
        $untrusted = AttributeSanitizer::shouldSanitizeForUser($userId);
        $out = [];
        foreach ($this->fields($page) as $name => $field) {
            $reason = self::readOnlyReason($this->types->for($field), $field, $untrusted);
            if ($reason !== null) {
                $out[$name] = $reason;
            }
        }

        return $out;
    }

    /**
     * Messages about the last save() that did not stop it: a code field the
     * writer may not edit (kept as stored), or an array option that holds a
     * non-array value (nothing written). `field` is null for page-level notes.
     *
     * @return list<array{field: ?string, message: string}>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * @param array<string, array<string, mixed>> $input   groupKey => name => submitted
     * @param array<string, list<string>>         $present groupKey => rendered editable names
     * @return array<string, mixed>|\WP_Error read() after a successful save
     */
    public function save(PageDefinition $page, array $input, array $present, int $userId): array|\WP_Error
    {
        $this->notices = [];
        $untrusted = AttributeSanitizer::shouldSanitizeForUser($userId);
        $stored = $this->read($page);

        $writes = [];
        $booleans = [];
        $errors = [];
        foreach ($this->groupsFor($page) as $group) {
            $submitted = is_array($input[$group->key] ?? null) ? $input[$group->key] : [];
            $names = is_array($present[$group->key] ?? null) ? $present[$group->key] : [];

            $normalized = [];
            $candidates = [];
            $booleanCandidates = [];
            foreach ($this->storedFields($group) as $name => $field) {
                if (!in_array($name, $names, true)) {
                    continue;
                }
                $type = $this->types->for($field);
                if (self::readOnlyReason($type, $field, $untrusted) !== null) {
                    $this->notices[] = ['field' => $name, 'message' => TrustMessage::for($field)];
                    continue;
                }
                // Rows above the cap are refused (422), never dropped by normalize() below.
                $capError = self::rowCapError($field, $submitted[$name] ?? null);
                if ($capError !== null) {
                    $errors[$name] = $capError;
                    continue;
                }
                $value = $type->normalize($submitted[$name] ?? null, $field);
                $normalized[$name] = $value;
                if ($untrusted) {
                    $value = $type->sanitize($value, $field) ?? $type->emptyValue($field);
                }
                $candidates[$name] = $value;
                if ($field->type === 'true_false') {
                    $booleanCandidates[$name] = true;
                }
            }
            if ($candidates === []) {
                continue;
            }

            // Validate what was submitted (an unknown choice is an error, not silently
            // emptied by the sanitiser), then, for an untrusted writer, what would be
            // stored (a required value the sanitiser emptied is an error too).
            $groupStored = array_intersect_key($stored, $this->storedFields($group));
            $runs = $untrusted ? [$normalized, $candidates] : [$candidates];
            $hidden = [];
            foreach ($runs as $values) {
                $result = ValueValidator::validate(
                    $group->fields,
                    array_replace($groupStored, $values),
                    $this->choiceResolver,
                    static fn (Field $f): ?int => RepeaterType::rowBudget($f)
                );
                $hidden = array_flip($result['hidden']);
                foreach ($result['errors'] as $path => $message) {
                    $top = explode('.', (string) $path, 2)[0];
                    if (array_key_exists($top, $candidates)) {
                        $errors[(string) $path] ??= $message;
                    }
                }
            }
            foreach ($candidates as $name => $value) {
                if (isset($hidden[$name])) {
                    continue;
                }
                $writes[$name] = $value;
                if (isset($booleanCandidates[$name])) {
                    $booleans[] = $name;
                }
            }
        }

        if ($errors !== []) {
            return new \WP_Error(
                'proto_blocks_invalid_values',
                __('Some values are not valid. Nothing was saved.', 'proto-blocks'),
                ['status' => 422, 'errors' => $errors]
            );
        }

        $protected = $this->protectedTarget($page, array_keys($writes));
        if ($protected !== null) {
            return self::protectedError($protected);
        }

        if ($writes !== [] && $this->arrayOptionHoldsScalar($page)) {
            $this->notices[] = ['field' => null, 'message' => sprintf(
                /* translators: %s: option name */
                __('The option "%s" already holds a value that is not a list of settings, probably from another plugin. It was left as it is and nothing was saved; give this page its own option.', 'proto-blocks'),
                (string) $page->storageOption
            )];

            return $this->read($page);
        }

        try {
            $this->store->save($page, $writes, $booleans);
        } catch (\DomainException) {
            return self::protectedError($page->storageOption ?? '');
        }

        return $this->read($page);
    }

    /**
     * @return array{own: list<FieldGroup>, groups: list<FieldGroup>, fields: array<string, Field>, warnings: list<string>}
     */
    private function definitions(PageDefinition $page): array
    {
        return $this->memo[$page->slug] ??= $this->build($page);
    }

    /**
     * @return array{own: list<FieldGroup>, groups: list<FieldGroup>, fields: array<string, Field>, warnings: list<string>}
     */
    private function build(PageDefinition $page): array
    {
        $warnings = [];
        $own = [];
        $raws = $page->groups;
        foreach ($page->tabs as $tab) {
            array_push($raws, ...$tab['groups']);
        }
        foreach ($raws as $raw) {
            $result = GroupNormalizer::normalize($raw, 'options:' . $page->slug, $page->source, ['options_page' => $page->slug]);
            foreach ($result['report']->errors as $e) {
                $warnings[] = self::reportLine($raw['key'] ?? '', $e);
            }
            foreach ($result['report']->warnings as $w) {
                $warnings[] = self::reportLine($raw['key'] ?? '', $w);
            }
            if ($result['group'] !== null) {
                $own[] = $result['group'];
            }
        }

        $groups = $own;
        $keys = [];
        $fields = [];
        foreach ($own as $group) {
            $keys[$group->key] = true;
            foreach ($this->storedFields($group) as $name => $field) {
                $fields[$name] ??= $field;
            }
        }

        foreach (($this->extraGroups)($page->slug) as $group) {
            if (!$group instanceof FieldGroup || isset($keys[$group->key])) {
                continue;
            }
            $keys[$group->key] = true;
            $kept = [];
            foreach ($group->fields as $field) {
                $stores = $this->storesValue($field);
                if ($stores && isset($fields[$field->name])) {
                    $warnings[] = sprintf(
                        /* translators: 1: field name, 2: field group key, 3: page slug */
                        __('The field "%1$s" of group "%2$s" was not added to the page "%3$s", which already has a field with that name.', 'proto-blocks'),
                        $field->name,
                        $group->key,
                        $page->slug
                    );
                    continue;
                }
                if ($stores && ProtectedOptions::isProtected($page->optionName($field->name))) {
                    $warnings[] = sprintf(
                        /* translators: 1: field name, 2: field group key, 3: option name */
                        __('The field "%1$s" of group "%2$s" would store its value in the reserved option "%3$s" and was not added.', 'proto-blocks'),
                        $field->name,
                        $group->key,
                        $page->optionName($field->name)
                    );
                    continue;
                }
                if ($stores && strlen($page->optionName($field->name)) > PageDefinition::OPTION_MAX) {
                    $warnings[] = sprintf(
                        /* translators: 1: field name, 2: maximum length */
                        __('The option name for the field "%1$s" is longer than %2$d characters; the field was not added.', 'proto-blocks'),
                        $field->name,
                        PageDefinition::OPTION_MAX
                    );
                    continue;
                }
                $kept[] = $field;
                if ($stores) {
                    $fields[$field->name] = $field;
                }
            }
            if ($kept === []) {
                continue;
            }
            $groups[] = count($kept) === count($group->fields) ? $group : new FieldGroup(
                key: $group->key,
                title: $group->title,
                fields: $kept,
                location: $group->location,
                position: $group->position,
                style: $group->style,
                menuOrder: $group->menuOrder,
                source: $group->source,
                path: $group->path,
                raw: $group->raw,
            );
        }

        return ['own' => $own, 'groups' => $groups, 'fields' => $fields, 'warnings' => $warnings];
    }

    /**
     * Top-level value-holding fields of one group, by name (first wins).
     *
     * @return array<string, Field>
     */
    private function storedFields(FieldGroup $group): array
    {
        $out = [];
        foreach ($group->fields as $field) {
            if ($this->storesValue($field)) {
                $out[$field->name] ??= $field;
            }
        }

        return $out;
    }

    private function storesValue(Field $field): bool
    {
        if ($field->name === '') {
            return false;
        }
        try {
            return $this->types->for($field)->stores();
        } catch (\InvalidArgumentException) {
            return false; // an unknown type is neither read nor written
        }
    }

    private function defaultOf(Field $field): mixed
    {
        $type = $this->types->for($field);

        return $field->default === null ? $type->emptyValue($field) : $type->normalize($field->default, $field);
    }

    /**
     * The first protected option among the writes' targets (defence in depth:
     * the declaration was checked at load, a filter may have changed since).
     *
     * @param list<string> $names
     */
    private function protectedTarget(PageDefinition $page, array $names): ?string
    {
        foreach ($names as $name) {
            $option = $page->optionName((string) $name);
            if (ProtectedOptions::isProtected($option)) {
                return $option;
            }
        }

        return null;
    }

    private function arrayOptionHoldsScalar(PageDefinition $page): bool
    {
        if ($page->storageMode !== PageDefinition::MODE_ARRAY || $page->storageOption === null) {
            return false;
        }
        $missing = new \stdClass();
        $current = get_option($page->storageOption, $missing);

        return $current !== $missing && !is_array($current) && $current !== '' && $current !== false;
    }

    private static function protectedError(string $option): \WP_Error
    {
        return new \WP_Error(
            'proto_blocks_protected_option',
            sprintf(
                /* translators: %s: option name */
                __('The option "%s" is reserved by WordPress or Proto-Blocks and cannot be written by a settings page.', 'proto-blocks'),
                $option
            ),
            ['status' => 403]
        );
    }

    /** "Add no more than N rows." when $value holds more rows than the field's cap or the shared row budget. */
    private static function rowCapError(Field $field, mixed $value): ?string
    {
        if (in_array($field->type, ['repeater', 'flexible_content'], true) && is_array($value) && count($value) > RepeaterType::maxRows($field)) {
            /* translators: %d: maximum number of rows */
            return sprintf(__('Add no more than %d rows.', 'proto-blocks'), RepeaterType::maxRows($field));
        }

        return SaveHandler::rowBudgetError($field, $value);
    }

    /** The one write-trust check shared by readOnly() and save(). */
    private static function readOnlyReason(FieldTypeInterface $type, Field $field, bool $untrusted): ?string
    {
        return $untrusted && $type->hasUnfilteredHtml($field) ? 'unfiltered_html' : null;
    }

    /** @param array{path: string, code: string, message: string} $entry */
    private static function reportLine(mixed $groupKey, array $entry): string
    {
        $where = is_string($groupKey) && $groupKey !== '' ? $groupKey : '?';

        return $where . ($entry['path'] !== '' ? '.' . $entry['path'] : '') . ': ' . $entry['message'];
    }

    /**
     * Allowed values of an `optionsSource` field from the registered options
     * providers; null (every value refused) when the source cannot be resolved.
     *
     * @return list<string>|null
     */
    private static function providerChoices(Field $field): ?array
    {
        $source = $field->setting('optionsSource');
        if (!is_string($source) || $source === '' || !class_exists(Plugin::class)) {
            return null;
        }
        try {
            $providers = Plugin::getInstance()->getOptionsProviders();
            if (!$providers instanceof OptionsProviders || !$providers->has($source)) {
                return null;
            }
            $args = $field->setting('sourceArgs');
            $options = $providers->resolve($source, is_array($args) ? $args : []);
        } catch (\Throwable) {
            return null;
        }

        return array_values(array_map(static fn (array $o): string => (string) $o['key'], $options));
    }
}
