<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Edit;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Registry\LocationMatcher;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

/**
 * Field boxes on the term screens: `{taxonomy}_add_form_fields` (the add
 * form, which core submits over admin-ajax `add-tag`; the runtime remounts
 * empty boxes after a successful add) and `{taxonomy}_edit_form_fields`
 * (table rows). Shown only to users who may add terms to the taxonomy
 * (`edit_terms` cap) or edit that term (`edit_term`). The add form starts
 * from the declared defaults: nothing stored on any term is printed there.
 */
final class TermForms
{
    /** @var array<string, true> taxonomies hooked */
    private array $hooked = [];

    public function __construct(
        private readonly GroupIndex $index,
        private readonly BoxPayload $payload,
    ) {
    }

    /** load-edit-tags.php / load-term.php: hooks the add and edit forms of every taxonomy a group targets. */
    public function register(): void
    {
        foreach ($this->taxonomies() as $taxonomy) {
            if (isset($this->hooked[$taxonomy])) {
                continue;
            }
            $this->hooked[$taxonomy] = true;
            add_action($taxonomy . '_add_form_fields', [$this, 'renderAdd'], 10, 1);
            add_action($taxonomy . '_edit_form_fields', [$this, 'renderEdit'], 10, 2);
        }
        if ($this->hooked !== []) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        }
    }

    public function renderAdd(mixed $taxonomy = ''): void
    {
        if (!is_string($taxonomy) || $taxonomy === '' || !self::canAdd($taxonomy)) {
            return;
        }
        $groups = $this->groupsFor($taxonomy);
        if ($groups === []) {
            return;
        }
        $userId = get_current_user_id();
        echo BoxPayload::formFields('term:new:' . $taxonomy); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in formFields()
        foreach ($groups as $group) {
            echo '<div class="form-field proto-fields-term-group" data-proto-fields-new="1">';
            echo '<h3>' . esc_html($group->title) . '</h3>';
            echo BoxPayload::markup($this->payload->build($group, ObjectRef::term(0, $taxonomy), $userId)); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX flags
            echo '</div>';
        }
    }

    public function renderEdit(mixed $term = null, mixed $taxonomy = ''): void
    {
        $id = is_object($term) ? (int) ($term->term_id ?? 0) : 0;
        if ($id <= 0 || !is_string($taxonomy) || $taxonomy === '' || (string) ($term->taxonomy ?? $taxonomy) !== $taxonomy || !current_user_can('edit_term', $id)) {
            return;
        }
        $groups = $this->groupsFor($taxonomy);
        if ($groups === []) {
            return;
        }
        $userId = get_current_user_id();
        $first = true;
        foreach ($groups as $group) {
            echo '<tr class="form-field proto-fields-term-group"><th scope="row">' . esc_html($group->title) . '</th><td>';
            if ($first) {
                echo BoxPayload::formFields('term:' . $id . ':' . $taxonomy); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in formFields()
                $first = false;
            }
            echo BoxPayload::markup($this->payload->build($group, ObjectRef::term($id, $taxonomy), $userId)); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX flags
            echo '</td></tr>';
        }
    }

    /** admin_enqueue_scripts on edit-tags.php and term.php for a taxonomy with groups. */
    public function enqueue(mixed $hook = ''): void
    {
        if (!in_array($hook, ['edit-tags.php', 'term.php'], true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $taxonomy = is_object($screen) && is_string($screen->taxonomy ?? null) ? $screen->taxonomy : '';
        $groups = $taxonomy !== '' ? $this->groupsFor($taxonomy) : [];
        if ($groups !== []) {
            MetaBoxes::assets(true, BoxPayload::hasType($groups, 'wysiwyg'));
        }
    }

    /** @return list<FieldGroup> */
    private function groupsFor(string $taxonomy): array
    {
        $ctx = ['kind' => ObjectRef::TERM, 'taxonomy' => $taxonomy];
        $out = [];
        foreach ($this->index->all() as $group) {
            if ($group->fields !== [] && BoxPayload::renderable($group) && LocationMatcher::matches($group->location, $ctx)) {
                $out[] = $group;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function taxonomies(): array
    {
        $out = [];
        foreach ($this->index->all() as $group) {
            foreach ($this->index->subtypesFor($group, ObjectRef::TERM) as $taxonomy) {
                if ($taxonomy !== '') {
                    $out[$taxonomy] = true;
                    continue;
                }
                // Every taxonomy with an admin screen.
                $all = function_exists('get_taxonomies') ? get_taxonomies(['show_ui' => true]) : [];
                foreach (is_array($all) ? $all : [] as $name) {
                    if (is_string($name) && $name !== '') {
                        $out[$name] = true;
                    }
                }
            }
        }

        return array_map('strval', array_keys($out));
    }

    private static function canAdd(string $taxonomy): bool
    {
        $object = get_taxonomy($taxonomy);
        $cap = is_object($object) && is_object($object->cap ?? null) ? ($object->cap->edit_terms ?? null) : null;

        return is_string($cap) && $cap !== '' && current_user_can($cap);
    }
}
