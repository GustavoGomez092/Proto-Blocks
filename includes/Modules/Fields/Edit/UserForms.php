<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Edit;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\Location;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Registry\LocationMatcher;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Types\FieldTypeInterface;
use ProtoBlocks\Modules\Fields\Types\RepeaterType;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\Modules\Fields\Validation\ValueValidator;

/**
 * Field boxes on the user screens. The `user_form` location rule decides:
 *   show_user_profile / edit_user_profile  'edit'      (edit_user for that user)
 *   user_new_form ('add-new-user' only)    'add'       (create_users)
 *   register_form (wp-login.php)           'register'  (logged-out visitors; only
 *                                           groups naming register or all explicitly)
 *
 * The add-user and registration forms start from the declared defaults; they
 * never print values stored for any user. Registration errors cannot use the
 * ErrorStore (nothing is stored for user 0): the fields are validated on
 * `registration_errors`, which stops the registration and makes core render
 * the form again in the same request, and that render shows the errors and
 * the visitor's own submitted values inline. Nothing is persisted.
 */
final class UserForms
{
    /** @var array<string, array{errors: array<string, string>, submitted: array<string, mixed>}> group key => pending registration errors */
    private array $registration = [];

    public function __construct(
        private readonly GroupIndex $index,
        private readonly BoxPayload $payload,
        private readonly TypeRegistry $types,
        private readonly ChoiceResolver $choices,
    ) {
    }

    public function register(): void
    {
        add_action('show_user_profile', [$this, 'renderProfile'], 10, 1);
        add_action('edit_user_profile', [$this, 'renderProfile'], 10, 1);
        add_action('user_new_form', [$this, 'renderNew'], 10, 1);
        add_action('register_form', [$this, 'renderRegister'], 10, 0);
        add_filter('registration_errors', [$this, 'validateRegistration'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('login_enqueue_scripts', [$this, 'enqueueLogin']);
        add_action('login_footer', [MetaBoxes::class, 'printPortal']);
    }

    public function renderProfile(mixed $user = null): void
    {
        $id = is_object($user) ? (int) ($user->ID ?? 0) : 0;
        if ($id <= 0 || !current_user_can('edit_user', $id)) {
            return;
        }
        $roles = is_array($user->roles ?? null) ? array_values($user->roles) : [];
        $groups = $this->groups(['kind' => ObjectRef::USER, 'user_roles' => $roles, 'user_form' => 'edit']);
        $this->print($groups, ObjectRef::user($id), 'user:' . $id, get_current_user_id());
    }

    public function renderNew(mixed $type = 'add-new-user'): void
    {
        if ($type !== 'add-new-user' || !current_user_can('create_users')) {
            return;
        }
        $groups = $this->groups(['kind' => ObjectRef::USER, 'user_roles' => [], 'user_form' => 'add']);
        $this->print($groups, ObjectRef::user(0), 'user:new', get_current_user_id());
    }

    public function renderRegister(): void
    {
        if (is_user_logged_in()) {
            return;
        }
        $this->print($this->registerGroups(), ObjectRef::user(0), 'user:new', 0, true);
    }

    /**
     * registration_errors: validates the present fields of the registration
     * groups; any error blocks the registration so the form renders again.
     */
    public function validateRegistration(mixed $errors = null, mixed $login = '', mixed $email = ''): mixed
    {
        if (!is_object($errors) || !method_exists($errors, 'add') || !$this->nonceOk()) {
            return $errors;
        }
        $marker = $_POST[SaveHandler::OBJECT_FIELD] ?? null;
        if (!is_string($marker) || wp_unslash($marker) !== 'user:new') {
            return $errors;
        }
        $input = is_array($_POST[SaveHandler::INPUT] ?? null) ? wp_unslash($_POST[SaveHandler::INPUT]) : [];
        $present = is_array($_POST[SaveHandler::PRESENT] ?? null) ? wp_unslash($_POST[SaveHandler::PRESENT]) : [];
        $this->registration = [];

        foreach ($this->registerGroups() as $group) {
            $names = [];
            foreach (is_array($present[$group->key] ?? null) ? $present[$group->key] : [] as $name) {
                if (is_string($name) && $name !== '') {
                    $names[$name] = true;
                }
            }
            if ($names === []) {
                continue;
            }
            $groupInput = is_array($input[$group->key] ?? null) ? $input[$group->key] : [];
            $values = [];
            $submitted = [];
            foreach ($group->fields as $f) {
                $type = $this->typeOf($f);
                if ($type === null || !$type->stores()) {
                    continue;
                }
                if (isset($names[$f->name]) && !$type->hasUnfilteredHtml($f)) {
                    $values[$f->name] = self::shape($type, $f, $groupInput[$f->name] ?? null);
                    $submitted[$f->name] = $values[$f->name];
                } else {
                    $values[$f->name] = $f->default === null ? $type->emptyValue($f) : $f->default;
                }
            }
            $result = ValueValidator::validate($group->fields, $values, [$this->choices, 'allowed'], [RepeaterType::class, 'maxRows']);
            $hidden = array_flip($result['hidden']);
            $found = [];
            foreach ($result['errors'] as $path => $message) {
                $top = explode('.', (string) $path, 2)[0];
                if (isset($submitted[$top]) && !isset($hidden[$top])) {
                    $found[(string) $path] = (string) $message;
                }
            }
            if ($found !== []) {
                $this->registration[$group->key] = [
                    'errors' => $found,
                    'submitted' => array_intersect_key($submitted, array_flip(array_map(static fn(string $p): string => explode('.', $p, 2)[0], array_keys($found)))),
                ];
            }
        }
        if ($this->registration !== []) {
            $errors->add('proto_fields_invalid', esc_html__('Please correct the highlighted fields.', 'proto-blocks'));
        }

        return $errors;
    }

    /** admin_enqueue_scripts on the profile, user-edit and add-user screens with groups. */
    public function enqueue(mixed $hook = ''): void
    {
        $form = match ($hook) {
            'profile.php', 'user-edit.php' => 'edit',
            'user-new.php' => 'add',
            default => null,
        };
        if ($form === null) {
            return;
        }
        $groups = $this->groupsForForm($form);
        if ($groups !== []) {
            MetaBoxes::assets(true, BoxPayload::hasType($groups, 'wysiwyg'));
        }
    }

    /** login_enqueue_scripts: only the registration screen, only with registration groups; no media modal. */
    public function enqueueLogin(): void
    {
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== 'register' || is_user_logged_in()) {
            return;
        }
        $groups = $this->registerGroups();
        if ($groups !== []) {
            MetaBoxes::assets(false, false);
        }
    }

    /**
     * @param list<FieldGroup> $groups
     */
    private function print(array $groups, ObjectRef $ref, string $marker, int $userId, bool $registering = false): void
    {
        if ($groups === []) {
            return;
        }
        echo BoxPayload::formFields($marker); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in formFields()
        foreach ($groups as $group) {
            $payload = $this->payload->build($group, $ref, $userId);
            if ($registering && isset($this->registration[$group->key])) {
                $payload = $this->payload->withErrors($payload, $group, $this->registration[$group->key]['errors'], $this->registration[$group->key]['submitted']);
            }
            echo $registering ? '<div class="proto-fields-user-group">' : '<div class="proto-fields-user-group"><h2>' . esc_html($group->title) . '</h2>';
            echo BoxPayload::markup($payload); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX flags
            echo '</div>';
        }
    }

    /**
     * Groups that may show on a form, for enqueue decisions: any matching
     * branch naming that form, or no user_form rule at all.
     *
     * @return list<FieldGroup>
     */
    private function groupsForForm(string $form): array
    {
        $out = [];
        foreach ($this->index->all() as $group) {
            if ($group->fields === [] || !BoxPayload::renderable($group) || !in_array(ObjectRef::USER, LocationMatcher::kinds($group->location), true)) {
                continue;
            }
            foreach ($group->location->or as $branch) {
                if (LocationMatcher::branchKind($branch) !== ObjectRef::USER) {
                    continue;
                }
                $ctx = ['kind' => ObjectRef::USER, 'user_form' => $form, 'user_roles' => self::rolesNamed($branch)];
                if (LocationMatcher::matches(new Location([$branch]), $ctx)) {
                    $out[] = $group;
                    break;
                }
            }
        }

        return $out;
    }

    /** @param list<array{param: string, operator: string, value: string}> $branch */
    private static function rolesNamed(array $branch): array
    {
        $roles = [];
        foreach ($branch as $rule) {
            if ($rule['param'] === 'user_role' && $rule['operator'] === '==') {
                $roles[] = $rule['value'];
            }
        }

        return $roles;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<FieldGroup>
     */
    private function groups(array $ctx): array
    {
        $out = [];
        foreach ($this->index->all() as $group) {
            if ($group->fields !== [] && BoxPayload::renderable($group) && LocationMatcher::matches($group->location, $ctx)) {
                $out[] = $group;
            }
        }

        return $out;
    }

    /**
     * Groups offered to anonymous visitors: a matching branch names
     * `user_form == register` (or `all`) explicitly, as SaveHandler requires.
     *
     * @return list<FieldGroup>
     */
    private function registerGroups(): array
    {
        $role = function_exists('get_option') ? get_option('default_role') : '';
        $ctx = ['kind' => ObjectRef::USER, 'user_roles' => is_string($role) && $role !== '' ? [$role] : [], 'user_form' => 'register'];
        $out = [];
        foreach ($this->index->all() as $group) {
            if ($group->fields === [] || !BoxPayload::renderable($group)) {
                continue;
            }
            foreach ($group->location->or as $branch) {
                $names = false;
                foreach ($branch as $rule) {
                    if ($rule['param'] === 'user_form' && $rule['operator'] === '==' && in_array($rule['value'], ['register', 'all'], true)) {
                        $names = true;
                    }
                }
                if ($names && LocationMatcher::matches(new Location([$branch]), $ctx)) {
                    $out[] = $group;
                    break;
                }
            }
        }

        return $out;
    }

    private function nonceOk(): bool
    {
        $nonce = $_POST[SaveHandler::NONCE_FIELD] ?? null;

        return is_string($nonce) && $nonce !== '' && wp_verify_nonce(wp_unslash($nonce), SaveHandler::NONCE_ACTION) !== false;
    }

    /** Shape-only, like SaveHandler: '' of an array-valued type becomes its empty value; repeater rows are reindexed. */
    private static function shape(FieldTypeInterface $type, Field $f, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            $empty = $type->emptyValue($f);

            return is_array($empty) ? $empty : $value;
        }
        if (is_array($value) && in_array($f->type, ['repeater', 'flexible_content'], true) && !array_is_list($value)) {
            foreach (array_keys($value) as $k) {
                if (!is_int($k)) {
                    return $value;
                }
            }
            ksort($value, SORT_NUMERIC);

            return array_values($value);
        }

        return $value;
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
