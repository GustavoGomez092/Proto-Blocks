<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Edit;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\Location;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Registry\LocationMatcher;
use ProtoBlocks\Modules\Fields\Registry\TrustCallbacks;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\Store;
use ProtoBlocks\Modules\Fields\Types\FieldTypeInterface;
use ProtoBlocks\Modules\Fields\Types\RepeaterType;
use ProtoBlocks\Modules\Fields\Types\TrustMessage;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\Modules\Fields\Validation\ValueValidator;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * The form save path for posts, terms and users: one path for the classic
 * editor, the block editor's meta-box request and the term and user screens.
 *
 * Request shape (built by the field boxes):
 *   $_POST[NONCE_FIELD]                    wp_create_nonce(NONCE_ACTION)
 *   $_POST[INPUT][<group key>][<name>]     submitted values
 *   $_POST[PRESENT][<group key>][]         every rendered, editable top-level field
 *
 * Who may save: a valid nonce plus `edit_post` / `edit_term` / `edit_user` for
 * the object, and the form marker (OBJECT_FIELD) naming that object; core's
 * own form fields must agree with it (`post_ID`; `taxonomy` and, when editing,
 * `tag_ID`; `user_id` on profile forms). A `new` marker is honoured once per
 * request. So a second save_post, a created_term or a user_register fired
 * while another form is saved never receives that form's values. The public
 * registration form (logged out, during
 * `user_register`) needs the nonce only and saves only groups whose location
 * names `user_form == register` (or `all`) explicitly; user 0 is untrusted, so
 * every value is sanitised and code is refused.
 *
 * What is saved (save()): for each group matching the object, each top-level
 * field listed as present. Fields not present are untouched; that is how a
 * read-only code field and the fields of boxes that were not rendered survive.
 * Visibility is recomputed here from the stored values overlaid with the
 * submitted ones, never from a client flag: hidden fields are neither
 * validated nor written, even when a value was posted for them. Invalid fields
 * are not written; their errors (keyed by path, `agenda.1.title`) and their
 * submitted values go to the ErrorStore while the rest of the save goes on.
 * A field holding code (at any depth) is refused for an untrusted writer with
 * the TrustCallbacks message and the stored value is kept; its submitted
 * value is never echoed back. Repeaters and flexible content may hold at most
 * `proto_blocks_repeater_max_items` rows per top-level field across all
 * nesting levels: more is an error, never a silent truncation.
 */
final class SaveHandler
{
    public const NONCE_ACTION = 'proto_fields_save';
    public const NONCE_FIELD = '_proto_fields_nonce';
    /**
     * The form marker every field box prints: `post:<id>`, `term:<id>:<taxonomy>`,
     * `term:new:<taxonomy>` (add-term form), `user:<id>` (profile forms) or
     * `user:new` (add-user and registration forms). A save hook acts only on
     * the object its form names, so a term or user created while ANOTHER form
     * is being saved (a tag typed into the post form, a user created by a
     * plugin during save_post) never receives that form's values.
     */
    public const OBJECT_FIELD = 'proto_fields_object';
    public const INPUT = 'proto_fields';
    public const PRESENT = 'proto_fields_present';

    private const CONTAINERS = ['repeater', 'flexible_content', 'group'];

    /** The flexible row layout key the field box posts; stored rows use `layout`. */
    private const CLIENT_LAYOUT_KEY = '_layout';

    /** Paths kept in the error map of one save. */
    public const MAX_ERRORS = 500;

    /** @var array<string, true> kinds whose `new` marker was used in this request */
    private array $claimedNew = [];

    /**
     * @param array<string, Store> $stores kind (ObjectRef::POST, ...) => store
     * @param TrustCallbacks|null $trust the instance MetaRegistrar hooked, to report refusals from the metadata guards
     */
    public function __construct(
        private readonly GroupIndex $index,
        private readonly TypeRegistry $types,
        private readonly array $stores,
        private readonly ErrorStore $errors,
        private readonly ChoiceResolver $choices,
        private readonly ?TrustCallbacks $trust = null,
    ) {
    }

    /** save_post, priority 10. */
    public function savePost(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (($post->post_type ?? '') === 'revision' || wp_is_post_revision($postId) !== false || wp_is_post_autosave($postId) !== false) {
            return;
        }
        if (!self::nonceOk() || self::marker() !== 'post:' . $postId || !self::postedIdIs('post_ID', $postId) || !current_user_can('edit_post', $postId)) {
            return;
        }
        $ref = ObjectRef::post($postId);
        if ($ref->subtype === '') {
            return;
        }

        $this->fromRequest($ref, $this->index->all(), $this->postContext($ref));
    }

    /** created_term and edited_term. */
    public function saveTerm(int $termId, int $ttId, string $taxonomy): void
    {
        if ($termId <= 0 || $taxonomy === '' || !self::nonceOk()) {
            return;
        }
        if (!self::postedStringIs('taxonomy', $taxonomy)) {
            return;
        }
        $marker = self::marker();
        if ($marker === 'term:' . $termId . ':' . $taxonomy) {
            if (!self::postedIdIs('tag_ID', $termId)) {
                return; // the edit form always posts tag_ID
            }
        } elseif ($marker === 'term:new:' . $taxonomy) {
            if (array_key_exists('tag_ID', $_POST) || !$this->claimNew('term')) {
                return; // the add form has no tag_ID; only the first term created in the request
            }
        } else {
            return;
        }
        if (!current_user_can('edit_term', $termId)) {
            return;
        }

        $this->fromRequest(ObjectRef::term($termId, $taxonomy), $this->index->all(), ['kind' => ObjectRef::TERM, 'taxonomy' => $taxonomy]);
    }

    /** user_register, personal_options_update and edit_user_profile_update. */
    public function saveUser(int $userId): void
    {
        if ($userId <= 0 || !self::nonceOk()) {
            return;
        }
        $registering = did_action('user_register') > 0;
        $groups = $this->index->all();
        $marker = self::marker();
        if ($marker === 'user:new') {
            if (!$registering || !$this->claimNew('user')) {
                return; // add-user and registration forms save only the user they create
            }
        } elseif ($marker !== 'user:' . $userId || !is_user_logged_in() || !self::postedIdIs('user_id', $userId)) {
            return; // profile forms name their user and post user_id
        }

        if (!is_user_logged_in()) {
            // Only the public registration form saves without a logged-in user.
            if (!$registering) {
                return;
            }
            $ctx = $this->userContext($userId, 'register');
            $groups = array_values(array_filter($groups, static fn(FieldGroup $g): bool => self::namesRegisterForm($g, $ctx)));
        } else {
            if (!current_user_can('edit_user', $userId)) {
                return;
            }
            $ctx = $this->userContext($userId, $registering ? 'add' : 'edit');
        }

        $this->fromRequest(ObjectRef::user($userId), $groups, $ctx);
    }

    /**
     * The shared core (also the options pages' save path).
     *
     * @param list<FieldGroup> $groups candidates; only those matching $ctx are saved
     * @param array<string, mixed> $input group key => name => value (unslashed)
     * @param array<string, mixed> $present group key => list of present top-level names
     * @param array<string, mixed> $ctx LocationMatcher context for the object
     * @return array{written: list<string>, errors: array<string, string>}
     */
    public function save(ObjectRef $ref, array $groups, array $input, array $present, array $ctx): array
    {
        $out = ['written' => [], 'errors' => []];
        $store = $this->stores[$ref->kind] ?? null;
        if (!$store instanceof Store) {
            return $out;
        }
        $ctx['kind'] = $ref->kind;
        $untrusted = AttributeSanitizer::shouldSanitizeForUser(get_current_user_id());
        $this->trust?->takeRefusals(); // drop anything recorded before this save

        $submittedForErrors = [];
        $handled = [];
        foreach ($groups as $group) {
            if (!$group instanceof FieldGroup || !LocationMatcher::matches($group->location, $ctx)) {
                continue;
            }
            $names = self::presentNames($present[$group->key] ?? null);
            if ($names === []) {
                continue;
            }
            $groupInput = is_array($input[$group->key] ?? null) ? $input[$group->key] : [];

            // Validator input: stored values overlaid with the submitted present values.
            $values = [];
            $submitted = [];
            foreach ($group->fields as $f) {
                $type = $this->typeOf($f);
                if ($type === null || !$type->stores()) {
                    continue;
                }
                if (isset($names[$f->name]) && !isset($handled[$f->name])) {
                    $values[$f->name] = $this->prepare($f, $groupInput[$f->name] ?? null);
                    $submitted[$f->name] = $f;
                } else {
                    $values[$f->name] = $store->has($ref, $f) ? $store->get($ref, $f) : $f->default;
                }
            }
            if ($submitted === []) {
                continue;
            }

            $result = ValueValidator::validate($group->fields, $values, [$this->choices, 'allowed'], [RepeaterType::class, 'maxRows']);
            $hidden = array_flip($result['hidden']);
            $errorsByField = [];
            foreach ($result['errors'] as $path => $message) {
                $errorsByField[explode('.', (string) $path, 2)[0]][(string) $path] = $message;
            }

            foreach ($submitted as $name => $f) {
                $name = (string) $name;
                $handled[$name] = true;
                $type = $this->typeOf($f);
                if ($type === null || isset($hidden[$name])) {
                    continue; // hidden: not validated, not written
                }
                if ($untrusted && $type->hasUnfilteredHtml($f)) {
                    $out['errors'][$name] = TrustMessage::for($f);
                    continue;
                }
                if (isset($errorsByField[$name])) {
                    $out['errors'] += $errorsByField[$name];
                    if (!TrustMessage::holds($f, 'password')) {
                        $submittedForErrors[$name] = $values[$name]; // a submitted password never reaches the ErrorStore
                    }
                    continue;
                }
                if (!array_key_exists($name, $result['valid'])) {
                    continue;
                }
                $budgetError = self::rowBudgetError($f, $values[$name]);
                if ($budgetError !== null) {
                    $out['errors'][$name] = $budgetError;
                    if (!TrustMessage::holds($f, 'password')) {
                        $submittedForErrors[$name] = $values[$name]; // a submitted password never reaches the ErrorStore
                    }
                    continue;
                }

                $value = $type->normalize($result['valid'][$name], $f);
                if ($untrusted) {
                    $value = $type->sanitize($value, $f);
                }
                $store->set($ref, $f, $value);
                $refused = $this->refusalFor($name);
                if ($refused !== null) {
                    $out['errors'][$name] = $refused;
                    continue;
                }
                $out['written'][] = $name;
                if ($f->type === 'taxonomy') {
                    $this->assignTerms($ref, $f, is_array($value) ? $value : []);
                }
            }
        }

        if (count($out['errors']) > self::MAX_ERRORS) {
            $out['errors'] = array_slice($out['errors'], 0, self::MAX_ERRORS, true);
        }
        $this->errors->put($ref, get_current_user_id(), $out['errors'], $submittedForErrors);

        return $out;
    }

    /**
     * @param list<FieldGroup> $groups
     * @param array<string, mixed> $ctx
     */
    private function fromRequest(ObjectRef $ref, array $groups, array $ctx): void
    {
        $input = $_POST[self::INPUT] ?? null;
        $present = $_POST[self::PRESENT] ?? null;
        if (!is_array($present) || $present === []) {
            return;
        }
        $input = is_array($input) ? wp_unslash($input) : [];
        $present = wp_unslash($present);

        $this->save($ref, $groups, is_array($input) ? $input : [], is_array($present) ? $present : [], $ctx);
    }

    private static function nonceOk(): bool
    {
        $nonce = $_POST[self::NONCE_FIELD] ?? null;

        return is_string($nonce) && $nonce !== '' && wp_verify_nonce(wp_unslash($nonce), self::NONCE_ACTION) !== false;
    }

    /** The form posts $key, and it is exactly $id. */
    private static function postedIdIs(string $key, int $id): bool
    {
        $posted = $_POST[$key] ?? null;

        return is_scalar($posted) && preg_match('/^[1-9][0-9]{0,18}\z/D', (string) $posted) === 1 && (int) $posted === $id;
    }

    /** The form posts $key, and it is exactly $value. */
    private static function postedStringIs(string $key, string $value): bool
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) && wp_unslash($_POST[$key]) === $value;
    }

    /** The posted form marker, or '' when absent or malformed. */
    private static function marker(): string
    {
        $marker = $_POST[self::OBJECT_FIELD] ?? null;

        return is_string($marker) ? wp_unslash($marker) : '';
    }

    /** A `new` marker names one object: the first one created in this request. */
    private function claimNew(string $kind): bool
    {
        if (isset($this->claimedNew[$kind])) {
            return false;
        }
        $this->claimedNew[$kind] = true;

        return true;
    }

    /** @return array<string, true> */
    private static function presentNames(mixed $names): array
    {
        if (!is_array($names)) {
            return [];
        }
        $out = [];
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                $out[$name] = true;
            }
        }

        return $out;
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

    /**
     * Shape-only preparation of a submitted value before validation: '' or a
     * missing value of an array-valued type becomes its empty value, and
     * repeater / flexible content rows posted with integer keys (form arrays,
     * possibly with gaps after a row was removed) become an ordered list.
     * Nothing is coerced into validity here.
     */
    private function prepare(Field $f, mixed $value): mixed
    {
        $type = $this->typeOf($f);
        if ($type === null) {
            return $value;
        }
        if ($value === null || $value === '') {
            $empty = $type->emptyValue($f);

            return is_array($empty) ? $empty : $value;
        }
        if (!is_array($value)) {
            return $value;
        }

        switch ($f->type) {
            case 'group':
                return $this->prepareRow($f->subFields, $value);
            case 'repeater':
                $rows = self::reindex($value);
                foreach ($rows as $i => $row) {
                    if (is_array($row)) {
                        $rows[$i] = $this->prepareRow($f->subFields, $row);
                    }
                }
                return $rows;
            case 'flexible_content':
                $rows = self::reindex($value);
                $layouts = [];
                foreach ($f->layouts as $layout) {
                    $layouts[$layout['name']] = $layout['subFields'];
                }
                foreach ($rows as $i => $row) {
                    if (is_array($row) && array_key_exists(self::CLIENT_LAYOUT_KEY, $row)) {
                        // The box posts the layout as `_layout` (src/fields/box/names.ts LAYOUT_KEY).
                        $row['layout'] = $row[self::CLIENT_LAYOUT_KEY];
                        unset($row[self::CLIENT_LAYOUT_KEY]);
                        $rows[$i] = $row;
                    }
                    $name = is_array($row) ? ($row['layout'] ?? null) : null;
                    if (is_string($name) && isset($layouts[$name])) {
                        $rows[$i] = $this->prepareRow($layouts[$name], $row);
                    }
                }
                return $rows;
        }

        return $value;
    }

    /**
     * @param list<Field> $fields
     * @param array<string|int, mixed> $row
     * @return array<string|int, mixed>
     */
    private function prepareRow(array $fields, array $row): array
    {
        foreach ($fields as $sub) {
            if ($sub->name !== '' && array_key_exists($sub->name, $row)) {
                $row[$sub->name] = $this->prepare($sub, $row[$sub->name]);
            }
        }

        return $row;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private static function reindex(array $value): array
    {
        if (array_is_list($value)) {
            return $value;
        }
        foreach (array_keys($value) as $k) {
            if (!is_int($k)) {
                return $value; // not form rows: the validator reports the shape
            }
        }
        ksort($value, SORT_NUMERIC);

        return array_values($value);
    }

    /** The nested row budget: total rows across all levels of one top-level field. */
    public static function rowBudgetError(Field $f, mixed $value): ?string
    {
        if (!in_array($f->type, self::CONTAINERS, true)) {
            return null;
        }
        $budget = RepeaterType::rowBudget($f); // 0 means no rows at all (fail closed), as in maxRows()
        if (self::rows($f, $value) <= $budget) {
            return null;
        }

        /* translators: %d: maximum number of rows */
        return sprintf(__('Add no more than %d rows.', 'proto-blocks'), $budget);
    }

    /** Rows of repeaters and flexible content in $value, at every depth. */
    public static function rows(Field $f, mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }
        switch ($f->type) {
            case 'group':
                return self::rowsIn($f->subFields, $value);
            case 'repeater':
                $n = 0;
                foreach ($value as $row) {
                    $n += 1 + (is_array($row) ? self::rowsIn($f->subFields, $row) : 0);
                }
                return $n;
            case 'flexible_content':
                $layouts = [];
                foreach ($f->layouts as $layout) {
                    $layouts[$layout['name']] = $layout['subFields'];
                }
                $n = 0;
                foreach ($value as $row) {
                    $name = is_array($row) ? ($row['layout'] ?? null) : null;
                    $n += 1 + (is_string($name) && isset($layouts[$name]) ? self::rowsIn($layouts[$name], $row) : 0);
                }
                return $n;
        }

        return 0;
    }

    /**
     * @param list<Field> $fields
     * @param array<string|int, mixed> $row
     */
    private static function rowsIn(array $fields, array $row): int
    {
        $n = 0;
        foreach ($fields as $sub) {
            if (in_array($sub->type, self::CONTAINERS, true) && $sub->name !== '' && isset($row[$sub->name])) {
                $n += self::rows($sub, $row[$sub->name]);
            }
        }

        return $n;
    }

    /** A refusal the metadata guards recorded for this field during the last write. */
    private function refusalFor(string $name): ?string
    {
        if ($this->trust === null) {
            return null;
        }
        $message = null;
        foreach ($this->trust->takeRefusals() as $refusal) {
            if ($refusal['field'] === $name) {
                $message = $refusal['message'];
            }
        }

        return $message;
    }

    /**
     * `save_terms`: sets the post's terms to the field's term ids, when the
     * taxonomy is registered for the post's type and the user may assign its
     * terms. Ids that are not terms of that taxonomy are dropped.
     *
     * @param array<int|string, mixed> $ids
     */
    private function assignTerms(ObjectRef $ref, Field $f, array $ids): void
    {
        $save = $f->setting('save_terms');
        if ($ref->kind !== ObjectRef::POST || !($save === true || $save === 1 || $save === '1')) {
            return;
        }
        $taxonomy = $f->setting('taxonomy');
        $object = is_string($taxonomy) && $taxonomy !== '' ? get_taxonomy($taxonomy) : false;
        if (!is_object($object) || !in_array($ref->subtype, (array) ($object->object_type ?? []), true)) {
            return;
        }
        $cap = is_object($object->cap ?? null) ? ($object->cap->assign_terms ?? null) : null;
        if (!is_string($cap) || $cap === '' || !current_user_can($cap)) {
            return;
        }
        $terms = [];
        foreach ($ids as $id) {
            $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : 0);
            if ($id > 0 && is_object(get_term($id, $taxonomy))) {
                $terms[] = $id;
            }
        }
        wp_set_object_terms($ref->id, $terms, $taxonomy);
    }

    /** @return array<string, mixed> */
    private function postContext(ObjectRef $ref): array
    {
        $post = get_post($ref->id);
        $terms = [];
        foreach ($this->ruleTaxonomies() as $taxonomy) {
            $found = wp_get_object_terms($ref->id, $taxonomy);
            $terms[$taxonomy] = is_array($found)
                ? array_values(array_filter(array_map(static fn($t) => is_object($t) ? (string) ($t->slug ?? '') : '', $found)))
                : [];
        }

        return [
            'kind' => ObjectRef::POST,
            'post_type' => $ref->subtype,
            'post_status' => is_object($post) ? (string) ($post->post_status ?? '') : '',
            'post_id' => $ref->id,
            'page_template' => (string) get_page_template_slug($ref->id),
            'terms' => $terms,
        ];
    }

    /** @return array<string, mixed> */
    private function userContext(int $userId, string $form): array
    {
        $user = get_userdata($userId);
        $roles = is_object($user) && isset($user->roles) && is_array($user->roles) ? array_values($user->roles) : [];

        return ['kind' => ObjectRef::USER, 'user_roles' => $roles, 'user_form' => $form];
    }

    /** Taxonomies named by post_taxonomy rules (terms read fresh: this request may have just changed them). */
    private function ruleTaxonomies(): array
    {
        $found = [];
        foreach ($this->index->all() as $group) {
            foreach ($group->location->or as $branch) {
                foreach ($branch as $rule) {
                    if ($rule['param'] === 'post_taxonomy' && str_contains($rule['value'], ':')) {
                        $found[explode(':', $rule['value'], 2)[0]] = true;
                    }
                }
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * A matching branch of the group names the registration form explicitly
     * (`user_form == register` or `== all`); a group shown "for every role"
     * is not offered to anonymous visitors.
     *
     * @param array<string, mixed> $ctx
     */
    private static function namesRegisterForm(FieldGroup $group, array $ctx): bool
    {
        if (!$group->location->isRenderable()) {
            return false;
        }
        foreach ($group->location->or as $branch) {
            $names = false;
            foreach ($branch as $rule) {
                if ($rule['param'] === 'user_form' && $rule['operator'] === '==' && in_array($rule['value'], ['register', 'all'], true)) {
                    $names = true;
                }
            }
            if ($names && LocationMatcher::matches(new Location([$branch]), $ctx)) {
                return true;
            }
        }

        return false;
    }
}
