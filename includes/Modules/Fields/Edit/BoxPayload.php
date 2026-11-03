<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Edit;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Sources\AcfBridge;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Types\FieldTypeInterface;
use ProtoBlocks\Modules\Fields\Types\ImageType;
use ProtoBlocks\Modules\Fields\Types\RepeaterType;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * The JSON a field box mounts from (src/fields/model/types.ts `BoxPayload`),
 * and the markup that carries it.
 *
 * Shape:
 *   groupKey, title, fields (Field::toArray()), object ('post:10', 'term:4',
 *   'user:2', 'user:new', 'term:new')
 *   values    top-level name => value, normalised (ACF `Ymd` dates become
 *             Y-m-d). For a user without unfiltered_html every value is also
 *             passed through its type's sanitiser, and a field holding code
 *             (a code field, or a container with a code sub-field at any
 *             depth) is left out entirely: its stored content never reaches
 *             that browser.
 *   readOnly  name => 'unfiltered_html' for those fields (OptionsValues::readOnly() shape)
 *   display   pre-resolved data, keyed by dotted value path ('hero',
 *             'agenda.0.photo'): image {id,url,alt}; file {id,url,title,filename};
 *             video {id,url}; gallery [{id,url,alt}]; relationship
 *             [{id,title,type,status}] (only posts the user may read);
 *             taxonomy {terms: [{id,name,parent}]} at a top-level path, or
 *             {terms: '<taxonomy>'} (a reference to the shared
 *             `terms:<taxonomy>` list) at a nested path; the selection is in
 *             values. Strings are raw (React escapes). At most
 *             MAX_TERM_ENTRIES term entries (`display_truncated` says when cut).
 *             Attachments named by submitted values need `read_post`.
 *             Built after the ErrorStore override, from the final values.
 *   choices   field key => ChoiceResolver::options(), for EVERY optionsSource field
 *             at any depth (the first field of a name is also listed by name)
 *   maxRows   field key => row cap, for every repeater and flexible content field (same name fallback)
 *   overCap   name => {rows, limit} for a container whose stored rows exceed its cap (shown, never
 *             truncated; the box warns, is read-only at limit 0, and the save refuses while over)
 *   errors    path => message from the ErrorStore (taken once per object per request);
 *             the submitted values of those fields replace the stored ones
 *   canUnfiltered, restRoot, restNonce (empty for a logged-out visitor)
 */
final class BoxPayload
{
    /** Terms listed for a taxonomy field's checkbox list or select. */
    public const MAX_TERMS = 500;

    /** Term entries across every list of one payload. */
    public const MAX_TERM_ENTRIES = 2000;

    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

    /** @var array<string, array{errors: array<string, string>, submitted: array<string, mixed>}|null> */
    private array $taken = [];

    /** @var array<string, list<array{id: int, name: string, parent: int}>> taxonomy => terms */
    private array $terms = [];

    private int $termBudget = self::MAX_TERM_ENTRIES;
    private bool $truncated = false;

    public function __construct(
        private readonly TypeRegistry $types,
        private readonly FieldReader $reader,
        private readonly ErrorStore $errors,
        private readonly ChoiceResolver $choices,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(FieldGroup $g, ObjectRef $ref, int $userId): array
    {
        $untrusted = AttributeSanitizer::shouldSanitizeForUser($userId);
        $payload = [
            'groupKey' => $g->key,
            'title' => $g->title,
            'fields' => array_map(static fn(Field $f): array => $f->toArray(), $g->fields),
            'values' => [],
            'display' => [],
            'display_truncated' => false,
            'choices' => [],
            'errors' => [],
            'readOnly' => [],
            'canUnfiltered' => !$untrusted,
            'maxRows' => [],
            'restRoot' => function_exists('rest_url') ? (string) rest_url() : '',
            'restNonce' => $userId > 0 && function_exists('wp_create_nonce') ? (string) wp_create_nonce('wp_rest') : '',
            'object' => self::objectName($ref),
        ];

        foreach ($g->fields as $f) {
            $type = $this->typeOf($f);
            if ($type === null || !$type->stores()) {
                continue;
            }
            if ($untrusted && $type->hasUnfilteredHtml($f)) {
                $payload['readOnly'][$f->name] = 'unfiltered_html';
                continue; // never sent
            }
            $value = $ref->id > 0 ? $this->reader->value($ref, $f, true) : ($f->default === null ? $type->emptyValue($f) : $f->default);
            $payload['values'][$f->name] = $this->forDisplay($type, $f, $value, $untrusted);
            $over = self::overCap($f, $payload['values'][$f->name]);
            if ($over !== null) {
                $payload['overCap'][$f->name] = $over;
            }
        }
        $this->collect($g->fields, $payload);

        $entry = $this->take($ref, $userId);
        if ($entry !== null) {
            return $this->withErrors($payload, $g, $entry['errors'], $entry['submitted']); // display built there, from the final values
        }
        $this->displayFor($g, $payload, []);

        return $payload;
    }

    /**
     * Puts errors (path => message) for this group's fields into the payload;
     * a submitted value replaces the stored one (it is only ever used as a
     * controlled input value). Read-only fields keep nothing from $submitted.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    public function withErrors(array $payload, FieldGroup $g, array $errors, array $submitted): array
    {
        $names = [];
        foreach ($g->fields as $f) {
            if ($f->name !== '') {
                $names[$f->name] = $f;
            }
        }
        $fromRequest = [];
        foreach ($errors as $path => $message) {
            $top = explode('.', (string) $path, 2)[0];
            if (!isset($names[$top])) {
                continue;
            }
            $payload['errors'][(string) $path] = (string) $message;
            if (array_key_exists($top, $submitted) && !isset($payload['readOnly'][$top])) {
                $payload['values'][$top] = $submitted[$top];
                $fromRequest[$top] = true;
            }
        }
        $this->displayFor($g, $payload, $fromRequest);

        return $payload;
    }

    /** The JSON printed into the box's `<script type="application/json">`. */
    public static function json(array $payload): string
    {
        $json = wp_json_encode($payload, self::JSON_FLAGS);

        return is_string($json) ? $json : '{}';
    }

    /**
     * The box markup: one root element the runtime mounts on, the payload,
     * and a noscript note with no inputs (a no-JS submit lists no field as
     * present, so nothing is overwritten).
     *
     * @param array<string, mixed> $payload
     */
    public static function markup(array $payload): string
    {
        $title = is_string($payload['title'] ?? null) ? $payload['title'] : '';

        return '<div class="proto-blocks-fields" data-proto-fields-box>'
            . '<script type="application/json">' . self::json($payload) . '</script>'
            . '<noscript><p>' . ($title !== '' ? '<strong>' . esc_html($title) . '</strong> ' : '')
            . esc_html__('JavaScript is required to edit these fields.', 'proto-blocks') . '</p></noscript>'
            . '</div>';
    }

    /** The nonce and the object marker, printed once in every form a box sits in (no id attributes, so repeats stay valid). */
    public static function formFields(string $object): string
    {
        $nonce = wp_create_nonce(SaveHandler::NONCE_ACTION);

        return '<input type="hidden" name="' . esc_attr(SaveHandler::NONCE_FIELD) . '" value="' . esc_attr($nonce) . '" />'
            . '<input type="hidden" name="' . esc_attr(self::objectField()) . '" value="' . esc_attr($object) . '" />';
    }

    /** The posted per-form object marker (SaveHandler::OBJECT_FIELD). */
    public static function objectField(): string
    {
        return SaveHandler::OBJECT_FIELD;
    }

    /** Whether a group can be shown: renderable location and not owned by ACF (checked again here: ACF may register a key after the index loaded). */
    public static function renderable(FieldGroup $g): bool
    {
        if (!$g->location->isRenderable()) {
            return false;
        }
        if (AcfBridge::active() && function_exists('acf_get_field_group')) {
            $known = acf_get_field_group($g->key);
            if (is_array($known) && $known !== []) {
                return false;
            }
        }

        return true;
    }

    /** True when any field of the groups (at any depth) is a wysiwyg. */
    public static function hasType(array $groups, string $type): bool
    {
        $walk = static function (array $fields) use (&$walk, $type): bool {
            foreach ($fields as $f) {
                if (!$f instanceof Field) {
                    continue;
                }
                if ($f->type === $type || $walk($f->subFields)) {
                    return true;
                }
                foreach ($f->layouts as $layout) {
                    if ($walk($layout['subFields'] ?? [])) {
                        return true;
                    }
                }
            }
            return false;
        };
        foreach ($groups as $g) {
            if ($g instanceof FieldGroup && $walk($g->fields)) {
                return true;
            }
        }

        return false;
    }

    /** 'post:10' (the fields/errors route's object format); 'user:new' / 'term:new' for an object not created yet. */
    private static function objectName(ObjectRef $ref): string
    {
        if ($ref->kind === ObjectRef::OPTION) {
            return 'option:' . $ref->page;
        }

        return $ref->kind . ':' . ($ref->id > 0 ? (string) $ref->id : 'new');
    }

    /** @return array{errors: array<string, string>, submitted: array<string, mixed>}|null */
    private function take(ObjectRef $ref, int $userId): ?array
    {
        if ($ref->id <= 0 || $userId <= 0) {
            return null;
        }
        $key = $ref->kind . ':' . $ref->id . ':' . $userId;
        if (!array_key_exists($key, $this->taken)) {
            // One-shot: every box of the object shares the single read.
            $this->taken[$key] = $this->errors->take($ref, $userId);
        }

        return $this->taken[$key];
    }

    private function forDisplay(FieldTypeInterface $type, Field $f, mixed $value, bool $untrusted): mixed
    {
        // Uncapped: rows stored above a lowered cap are shown (with overCap), never
        // dropped here, so the next save cannot silently truncate them.
        return RepeaterType::uncapped(static function () use ($type, $f, $value, $untrusted): mixed {
            $value = $type->normalize($value, $f);

            return $untrusted ? $type->sanitize($value, $f) : $value;
        });
    }

    /**
     * {rows, limit} when a container holds more rows than its cap allows
     * (the field's own rows against maxRows, else all nested rows against the
     * shared row budget); null when it fits.
     *
     * @return array{rows: int, limit: int}|null
     */
    private static function overCap(Field $f, mixed $value): ?array
    {
        if (!in_array($f->type, ['repeater', 'flexible_content', 'group'], true) || !is_array($value)) {
            return null;
        }
        if ($f->type !== 'group' && count($value) > RepeaterType::maxRows($f)) {
            return ['rows' => count($value), 'limit' => RepeaterType::maxRows($f)];
        }
        $total = SaveHandler::rows($f, $value);
        $budget = RepeaterType::rowBudget($f);

        return $total > $budget ? ['rows' => $total, 'limit' => $budget] : null;
    }

    /**
     * choices and maxRows for every field at any depth, keyed by field key.
     * The first field of a name is also listed under its name, for readers
     * that still look entries up by name.
     *
     * @param list<Field> $fields
     * @param array<string, mixed> $payload
     */
    private function collect(array $fields, array &$payload): void
    {
        foreach ($fields as $f) {
            if (!$f instanceof Field || $f->name === '') {
                continue;
            }
            $options = $this->choices->options($f);
            if ($options !== null) {
                self::put($payload['choices'], $f, $options);
            }
            if ($f->type === 'repeater' || $f->type === 'flexible_content') {
                $max = RepeaterType::maxRows($f);
                $budget = RepeaterType::rowBudget($f);
                self::put($payload['maxRows'], $f, $budget > 0 ? min($max, $budget) : $max);
            }
            $this->collect($f->subFields, $payload);
            foreach ($f->layouts as $layout) {
                $this->collect($layout['subFields'] ?? [], $payload);
            }
        }
    }

    /** @param array<string, mixed> $map */
    private static function put(array &$map, Field $f, mixed $entry): void
    {
        if ($f->key !== '') {
            $map[$f->key] = $entry;
        }
        if (!array_key_exists($f->name, $map)) {
            $map[$f->name] = $entry;
        }
    }

    /**
     * Display data for the final values of the group's top-level fields,
     * walked once from the top so a path always names the field it came
     * from (a nested `photo` image never reads a top-level `photo`).
     *
     * Term lists: a top-level taxonomy field carries its list inline
     * (`{terms: [...]}`); any nested one carries a reference
     * (`{terms: '<taxonomy>'}`) to one shared `terms:<taxonomy>` list, so a
     * list is never copied into every row. Every nested taxonomy field gets
     * its shared list even with no row yet (rows added in the browser use
     * it). At most MAX_TERM_ENTRIES term entries per payload; past that the
     * lists are cut and `display_truncated` is true.
     *
     * Values from the request (a failed save's submitted values, names in
     * $fromRequest) resolve an attachment only when the user may
     * `read_post` it: otherwise anyone could submit an id and read back the
     * URL and title of a private file. Stored values resolve as before.
     *
     * @param array<string, mixed> $payload
     * @param array<string, true> $fromRequest
     */
    private function displayFor(FieldGroup $g, array &$payload, array $fromRequest): void
    {
        $this->termBudget = self::MAX_TERM_ENTRIES;
        $this->truncated = false;
        $out = [];
        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        foreach ($g->fields as $f) {
            if ($f->name !== '' && array_key_exists($f->name, $values)) {
                $this->display($f, $values[$f->name], $f->name, $out, isset($fromRequest[$f->name]));
            }
        }
        foreach ($g->fields as $f) {
            $this->sharedTerms($f->subFields, $out);
            foreach ($f->layouts as $layout) {
                $this->sharedTerms($layout['subFields'] ?? [], $out);
            }
        }
        $payload['display'] = $out;
        $payload['display_truncated'] = $this->truncated;
    }

    /**
     * Shared lists for nested taxonomy fields at any depth.
     *
     * @param list<Field> $fields
     * @param array<string, mixed> $out
     */
    private function sharedTerms(array $fields, array &$out): void
    {
        foreach ($fields as $f) {
            if (!$f instanceof Field) {
                continue;
            }
            if ($f->type === 'taxonomy') {
                $this->shareTerms($f, $out);
            }
            $this->sharedTerms($f->subFields, $out);
            foreach ($f->layouts as $layout) {
                $this->sharedTerms($layout['subFields'] ?? [], $out);
            }
        }
    }

    /**
     * Adds `terms:<taxonomy>` once; returns the taxonomy ('' for none).
     *
     * @param array<string, mixed> $out
     */
    private function shareTerms(Field $f, array &$out): string
    {
        $taxonomy = $f->setting('taxonomy');
        if (!is_string($taxonomy) || $taxonomy === '') {
            return '';
        }
        if (!array_key_exists('terms:' . $taxonomy, $out)) {
            $out['terms:' . $taxonomy] = $this->budgeted($this->terms($f));
        }

        return $taxonomy;
    }

    /**
     * @param list<array{id: int, name: string, parent: int}> $terms
     * @return list<array{id: int, name: string, parent: int}>
     */
    private function budgeted(array $terms): array
    {
        if (count($terms) > $this->termBudget) {
            $terms = array_slice($terms, 0, max(0, $this->termBudget));
            $this->truncated = true;
        }
        $this->termBudget -= count($terms);

        return $terms;
    }

    /** The user may read the attachment (for ids that came from the request). */
    private static function readable(int $id, bool $fromRequest): bool
    {
        return $id > 0 && (!$fromRequest || current_user_can('read_post', $id));
    }

    /** @param array<string, mixed> $out */
    private function display(Field $f, mixed $value, string $path, array &$out, bool $fromRequest = false): void
    {
        switch ($f->type) {
            case 'image':
                $id = ImageType::id($value);
                $image = self::readable($id, $fromRequest) ? self::image($id) : null;
                if ($image !== null) {
                    $out[$path] = $image;
                }
                return;
            case 'gallery':
                $items = [];
                foreach (ImageType::ids($value, 0) as $id) {
                    $image = self::readable($id, $fromRequest) ? self::image($id) : null;
                    if ($image !== null) {
                        $items[] = $image;
                    }
                }
                $out[$path] = $items;
                return;
            case 'file':
            case 'video':
                $id = ImageType::id($value);
                $url = self::readable($id, $fromRequest) ? wp_get_attachment_url($id) : false;
                if (is_string($url) && $url !== '') {
                    $item = ['id' => $id, 'url' => $url];
                    if ($f->type === 'file') {
                        $post = get_post($id);
                        $item['title'] = is_object($post) ? (string) ($post->post_title ?? '') : '';
                        $item['filename'] = basename((string) (parse_url($url, PHP_URL_PATH) ?? ''));
                    }
                    $out[$path] = $item;
                }
                return;
            case 'relationship':
                $out[$path] = self::posts($f, $value);
                return;
            case 'taxonomy':
                if (!str_contains($path, '.')) {
                    $out[$path] = ['terms' => $this->budgeted($this->terms($f))];
                } else {
                    $taxonomy = $this->shareTerms($f, $out);
                    $out[$path] = ['terms' => $taxonomy];
                }
                return;
            case 'group':
                $value = is_array($value) ? $value : [];
                foreach ($f->subFields as $sub) {
                    if ($sub->name !== '') {
                        $this->display($sub, $value[$sub->name] ?? null, $path . '.' . $sub->name, $out, $fromRequest);
                    }
                }
                return;
            case 'repeater':
            case 'flexible_content':
                if (!is_array($value)) {
                    return;
                }
                $layouts = [];
                foreach ($f->layouts as $layout) {
                    $layouts[(string) ($layout['name'] ?? '')] = $layout['subFields'] ?? [];
                }
                foreach (array_values($value) as $i => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $subs = $f->type === 'repeater' ? $f->subFields : ($layouts[(string) ($row['layout'] ?? $row['_layout'] ?? '')] ?? []);
                    foreach ($subs as $sub) {
                        if ($sub instanceof Field && $sub->name !== '') {
                            $this->display($sub, $row[$sub->name] ?? null, $path . '.' . $i . '.' . $sub->name, $out, $fromRequest);
                        }
                    }
                }
                return;
        }
    }

    /** @return array{id: int, url: string, alt: string}|null */
    private static function image(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $src = wp_get_attachment_image_src($id, 'medium');
        if (!is_array($src) || !is_string($src[0] ?? null) || $src[0] === '') {
            return null;
        }

        return ['id' => $id, 'url' => $src[0], 'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true)];
    }

    /** @return list<array{id: int, title: string, type: string, status: string}> selected posts the user may read */
    private static function posts(Field $f, mixed $value): array
    {
        $out = [];
        foreach (ImageType::ids($value, (int) $f->setting('max', 0)) as $id) {
            $post = get_post($id);
            if (!is_object($post)) {
                continue;
            }
            if (!is_post_publicly_viewable($post) && !current_user_can('read_post', $id)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'title' => (string) ($post->post_title ?? ''),
                'type' => (string) ($post->post_type ?? ''),
                'status' => (string) ($post->post_status ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<array{id: int, name: string, parent: int}> every choosable term (raw names), once per taxonomy per request */
    private function terms(Field $f): array
    {
        $taxonomy = $f->setting('taxonomy');
        if (!is_string($taxonomy) || $taxonomy === '' || !function_exists('get_terms')) {
            return [];
        }
        if (isset($this->terms[$taxonomy])) {
            return $this->terms[$taxonomy];
        }
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => self::MAX_TERMS, 'orderby' => 'name']);
        $out = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            if (is_object($term) && isset($term->term_id)) {
                $out[] = ['id' => (int) $term->term_id, 'name' => (string) ($term->name ?? ''), 'parent' => (int) ($term->parent ?? 0)];
            }
        }

        return $this->terms[$taxonomy] = $out;
    }

    private function typeOf(Field $f): ?FieldTypeInterface
    {
        if ($f->name === '') {
            return null;
        }
        try {
            return $this->types->for($f);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
