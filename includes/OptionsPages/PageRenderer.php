<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

use ProtoBlocks\Admin\AdminApp;
use ProtoBlocks\Controls\OptionsProviders;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Types\RepeaterType;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * Renders a settings page: the React admin on screen `options-page`, which
 * mounts one Phase 2 FieldsForm per field group inside a single form.
 *
 * The page data travels as JSON in a `<script type="application/json">`
 * element (JSON_HEX_* flags, so no stored value can close the element); no
 * inline JavaScript is printed for it. Values are read raw through Phase 2's
 * FieldReader for the fields the user may edit. For a user without
 * unfiltered_html, the fields OptionsValues::readOnly() lists (code, and
 * containers holding code) are left out of the form and their value is
 * null: the page shows them as hidden, and save() refuses them anyway.
 *
 * Each group's payload has the BoxPayload JSON shape (src/fields/model/types.ts).
 * It is built here because Phase 2's PHP BoxPayload is not on this branch;
 * swap to it once merged.
 */
final class PageRenderer
{
    /** id of the JSON data element OptionsPageScreen reads. */
    public const DATA_ID = 'proto-blocks-options-page-data';

    /** Phase 2's field box stylesheet (assets/css/fields-box.css). */
    public const FIELDS_STYLE = 'proto-blocks-fields-box';

    private const SCREEN = 'options-page';

    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private const MEDIA_TYPES = ['image', 'file', 'gallery', 'video'];

    public function __construct(
        private readonly OptionsValues $values,
        private readonly OptionsFieldStore $store,
        private readonly TypeRegistry $types,
        private readonly AdminApp $app,
    ) {
    }

    /**
     * @return array{slug: string, title: string, groups: list<array<string, mixed>>, tabs: list<array{title: string, groups: list<array<string, mixed>>}>, trusted: bool, editUrl: ?string, readOnly: array<string, string>}
     */
    public function pageData(PageDefinition $page, int $userId): array
    {
        $groups = $this->values->groupsFor($page);
        $byKey = [];
        foreach ($groups as $group) {
            $byKey[$group->key] = $group;
        }
        $own = [];
        foreach ($this->values->pageGroups($page) as $group) {
            $own[$group->key] = true;
        }

        $reader = new FieldReader(new GroupIndex($groups), $this->types, [ObjectRef::OPTION => $this->store]);
        $ref = ObjectRef::option($page->slug);
        $stored = $this->values->fields($page);
        $trusted = !AttributeSanitizer::shouldSanitizeForUser($userId);
        $readOnly = $this->values->readOnly($page, $userId);
        $build = fn (FieldGroup $g): array => $this->payload($page, $g, $ref, $reader, $stored, $trusted, $readOnly);

        // Untabbed page groups first, then standalone groups, then tabs.
        $top = [];
        foreach ($page->groups as $raw) {
            $key = is_string($raw['key'] ?? null) ? $raw['key'] : '';
            if (isset($byKey[$key])) {
                $top[] = $build($byKey[$key]);
            }
        }
        foreach ($groups as $group) {
            if (!isset($own[$group->key])) {
                $top[] = $build($group);
            }
        }
        $tabs = [];
        foreach ($page->tabs as $tab) {
            $tabGroups = [];
            foreach ($tab['groups'] as $raw) {
                $key = is_string($raw['key'] ?? null) ? $raw['key'] : '';
                if (isset($byKey[$key])) {
                    $tabGroups[] = $build($byKey[$key]);
                }
            }
            $tabs[] = ['title' => (string) $tab['title'], 'groups' => $tabGroups];
        }

        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'groups' => $top,
            'tabs' => $tabs,
            'trusted' => $trusted,
            'editUrl' => user_can($userId, 'manage_options')
                ? esc_url_raw(admin_url('admin.php?page=proto-blocks-options-pages&slug=' . rawurlencode($page->slug)))
                : null,
            'readOnly' => $readOnly,
        ];
    }

    /**
     * The menu callback. Refuses users without the page capability (they
     * never see the menu item, but may type the URL).
     */
    public function render(PageDefinition $page): void
    {
        if (!current_user_can($page->capability)) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'proto-blocks'), '', ['response' => 403]);
            return;
        }

        $data = $this->pageData($page, get_current_user_id());
        $this->enqueue($page);

        $this->app->renderScreen(self::SCREEN, $page->capability);
        printf(
            '<script type="application/json" id="%1$s">%2$s</script>',
            esc_attr(self::DATA_ID),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_* flags inside a non-executing script element.
            (string) wp_json_encode($data, self::JSON_FLAGS)
        );
    }

    private function enqueue(PageDefinition $page): void
    {
        $url = defined('PROTO_BLOCKS_URL') ? (string) \PROTO_BLOCKS_URL : '';
        $version = defined('PROTO_BLOCKS_VERSION') ? (string) \PROTO_BLOCKS_VERSION : false;
        wp_enqueue_style(self::FIELDS_STYLE, $url . 'assets/css/fields-box.css', [], $version);

        $types = [];
        foreach ($this->values->groupsFor($page) as $group) {
            self::collectTypes($group->fields, $types);
        }
        if (array_intersect(self::MEDIA_TYPES, array_keys($types)) !== []) {
            wp_enqueue_media();
        }
        if (isset($types['wysiwyg'])) {
            wp_enqueue_editor();
        }
    }

    /**
     * @param list<Field>          $fields
     * @param array<string, true>  $types
     */
    private static function collectTypes(array $fields, array &$types): void
    {
        foreach ($fields as $field) {
            $types[$field->type] = true;
            self::collectTypes($field->subFields, $types);
            foreach ($field->layouts as $layout) {
                self::collectTypes($layout['subFields'] ?? [], $types);
            }
        }
    }

    /**
     * One group as a BoxPayload, plus `hidden`: the fields this user may
     * neither see nor edit (code, or a container holding code, without
     * unfiltered_html).
     *
     * `fields` and `values` follow OptionsValues' rules: a value field is
     * listed only when the page reads and writes it (a known type, not
     * shadowed by an earlier field of the same name, not under a protected
     * option name), and every listed value field has its value (stored, or
     * its default). Layout-only fields (tab, accordion, message) are listed
     * without a value. A hidden field is not listed and its value is null,
     * so no code value reaches the page for an untrusted user.
     *
     * @param array<string, Field>  $stored   the page's top-level stored fields by name
     * @param array<string, string> $readOnly field name => reason
     * @return array<string, mixed>
     */
    private function payload(PageDefinition $page, FieldGroup $group, ObjectRef $ref, FieldReader $reader, array $stored, bool $trusted, array $readOnly): array
    {
        $fields = [];
        $values = [];
        $choices = [];
        $maxRows = [];
        $hidden = [];
        foreach ($group->fields as $field) {
            try {
                $stores = $this->types->for($field)->stores();
            } catch (\InvalidArgumentException) {
                continue; // an unknown type is neither read nor written
            }
            if (!$stores) {
                $fields[] = $field->toArray();
                continue;
            }
            if (($stored[$field->name] ?? null) !== $field) {
                continue; // shadowed by an earlier field of the same name, or dropped
            }
            // Same skip as OptionsValues::read(): a filter may have protected the name since load.
            if (ProtectedOptions::isProtected($page->optionName($field->name))) {
                continue;
            }
            if (isset($readOnly[$field->name])) {
                $values[$field->name] = null;
                $hidden[] = [
                    'name' => $field->name,
                    'label' => $field->label !== '' ? $field->label : $field->name,
                    'reason' => $readOnly[$field->name],
                ];
                continue;
            }
            $fields[] = $field->toArray();
            $values[$field->name] = $reader->value($ref, $field, true);
            $options = self::providerOptions($field);
            if ($options !== null) {
                $choices[$field->name] = $options;
            }
            if (in_array($field->type, ['repeater', 'flexible_content', 'gallery'], true)) {
                $maxRows[$field->name] = RepeaterType::maxRows($field);
            }
        }

        return [
            'groupKey' => $group->key,
            'fields' => $fields,
            // Objects, so empty maps encode as {} like the TS type expects.
            'values' => (object) $values,
            'display' => (object) [],
            'choices' => (object) $choices,
            'errors' => (object) [],
            'canUnfiltered' => $trusted,
            'maxRows' => (object) $maxRows,
            'restRoot' => esc_url_raw(rest_url('proto-blocks/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'object' => 'option:' . $ref->page,
            'hidden' => $hidden,
        ];
    }

    /**
     * Resolved choices of an optionsSource field, or null when it has none.
     *
     * @return list<array{key: string, label: string}>|null
     */
    private static function providerOptions(Field $field): ?array
    {
        $source = $field->setting('optionsSource');
        if (!is_string($source) || $source === '' || !class_exists(Plugin::class)) {
            return null;
        }
        try {
            $providers = Plugin::getInstance()->getOptionsProviders();
            if (!$providers instanceof OptionsProviders || !$providers->has($source)) {
                return [];
            }
            $args = $field->setting('sourceArgs');
            $options = $providers->resolve($source, is_array($args) ? $args : []);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($options as $option) {
            if (is_array($option) && isset($option['key'])) {
                $out[] = ['key' => (string) $option['key'], 'label' => (string) ($option['label'] ?? $option['key'])];
            }
        }

        return $out;
    }
}
