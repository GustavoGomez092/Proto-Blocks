<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Bindings;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

/**
 * The `proto-blocks/field` Block Bindings source (WordPress 6.5+): core
 * paragraph, heading, image and button blocks read a declared field through
 * the same escaping reader as proto_field().
 *
 * Bindings are written by anyone who can edit a post and rendered for every
 * visitor, so this source applies its own policy before reading:
 *
 *   - declared, value-holding fields only; `code` and `password` never; a
 *     field with `"bindings": false` opts out;
 *   - post: hidden behind a password, or not publicly viewable and not
 *     readable by the viewer (`read_post`), gives null;
 *   - term: a non-viewable taxonomy needs `edit_term`;
 *   - user: only for viewers who can `edit_user` (user fields are profile data);
 *   - option: only fields declared on that options page.
 *
 * Values are always the reader's escaped output, never raw. Arrays are
 * mapped to the bound attribute (image/file url/alt/id, link url/title) or
 * picked with the `key` arg; anything still not scalar is null. A url
 * attribute always goes through esc_url, other non-content attributes
 * through esc_attr. Null makes core keep the block's saved content.
 */
final class FieldBindingSource
{
    public const NAME = 'proto-blocks/field';

    /** Field types that are never exposed through bindings. */
    private const NEVER = ['code', 'password'];

    /** Attributes whose value is a URL. */
    private const URL_ATTRIBUTES = ['url', 'href', 'src'];

    /** Attributes rendered as rich text (already escaped by the field type). */
    private const TEXT_ATTRIBUTES = ['content', 'text'];

    /** @var array<string, array<string, string>> type => attribute => array key */
    private const MAP = [
        'image' => ['url' => 'url', 'alt' => 'alt', 'id' => 'id'],
        'file' => ['url' => 'url', 'alt' => 'alt', 'id' => 'id'],
        'link' => ['url' => 'url', 'text' => 'title', 'content' => 'title'],
    ];

    private bool $registered = false;

    public function __construct(
        private readonly FieldReader $reader,
        private readonly GroupIndex $index,
    ) {
    }

    /**
     * Registers the source on `init` (immediately when init already ran).
     * Without the Bindings API (WordPress < 6.5) it does nothing.
     */
    public function register(): void
    {
        if (!function_exists('register_block_bindings_source')) {
            return;
        }
        if (function_exists('did_action') && did_action('init') > 0) {
            $this->registerSource();
            return;
        }
        add_action('init', function (): void {
            $this->registerSource();
        });
    }

    /**
     * get_value_callback. `$args`: `name` (required), `context` (optional,
     * the getter's grammar; default the block's `postId`), `key` (optional
     * sub-key of an array value).
     *
     * @param array<string, mixed> $args
     */
    public function value(array $args, \WP_Block $block, string $attribute): ?string
    {
        $name = $args['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        $ref = $this->ref($args, $block);
        if ($ref === null || !$this->visible($ref)) {
            return null;
        }

        $field = $this->field($ref, $name);
        if ($field === null) {
            return null;
        }

        $value = $this->reader->value($ref, $field);
        $value = $this->pick($value, $field, $args, $attribute);
        if ($value === null) {
            return null;
        }

        return $this->forAttribute($value, $attribute);
    }

    private function registerSource(): void
    {
        if ($this->registered || !function_exists('register_block_bindings_source')) {
            return;
        }
        if (function_exists('get_block_bindings_source') && get_block_bindings_source(self::NAME) !== null) {
            $this->registered = true;
            return;
        }
        register_block_bindings_source(self::NAME, [
            'label' => __('Proto-Blocks field', 'proto-blocks'),
            'get_value_callback' => [$this, 'value'],
            'uses_context' => ['postId', 'postType'],
        ]);
        $this->registered = true;
    }

    /** @param array<string, mixed> $args */
    private function ref(array $args, \WP_Block $block): ?ObjectRef
    {
        $context = $args['context'] ?? null;
        if ($context !== null) {
            // Only the getter's scalar grammar; objects/arrays from JSON never resolve.
            return is_string($context) || is_int($context) ? $this->reader->resolve($context) : null;
        }

        $postId = $block->context['postId'] ?? null;
        if (is_string($postId) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $postId) === 1) {
            $postId = (int) $postId;
        }

        return is_int($postId) && $postId > 0 ? $this->reader->resolve($postId) : null;
    }

    /** Whether the current viewer may see values of this object through a binding. */
    private function visible(ObjectRef $ref): bool
    {
        switch ($ref->kind) {
            case ObjectRef::POST:
                if ($ref->id <= 0 || $ref->subtype === '') {
                    return false; // no such post
                }
                if (function_exists('post_password_required') && post_password_required($ref->id)) {
                    return false;
                }
                return is_post_publicly_viewable($ref->id) || current_user_can('read_post', $ref->id);
            case ObjectRef::TERM:
                if ($ref->id <= 0 || $ref->subtype === '') {
                    return false;
                }
                return (function_exists('is_taxonomy_viewable') && is_taxonomy_viewable($ref->subtype))
                    || current_user_can('edit_term', $ref->id);
            case ObjectRef::USER:
                return $ref->id > 0 && current_user_can('edit_user', $ref->id);
            case ObjectRef::OPTION:
                return $ref->page !== '';
        }

        return false;
    }

    private function field(ObjectRef $ref, string $name): ?Field
    {
        $hit = $this->index->fieldFor($ref, $name);
        if ($hit === null) {
            return null;
        }
        if ($ref->kind === ObjectRef::OPTION
            && !in_array($ref->page, $this->index->subtypesFor($hit['group'], ObjectRef::OPTION), true)) {
            return null;
        }

        $field = $this->reader->field($ref, $name);
        if ($field === null || $field !== $hit['field']) {
            return null;
        }
        if (in_array($field->type, self::NEVER, true) || $field->setting('bindings') === false) {
            return null;
        }

        return $field;
    }

    /**
     * The scalar to bind: the `key` arg or the attribute mapping for arrays,
     * the value itself for scalars; null for anything else.
     *
     * @param array<string, mixed> $args
     */
    private function pick(mixed $value, Field $field, array $args, string $attribute): string|int|float|bool|null
    {
        if (array_key_exists('key', $args)) {
            $key = $args['key'];
            if (!is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $key) !== 1 || !is_array($value)) {
                return null;
            }
            $value = $value[$key] ?? null;
        } elseif (is_array($value)) {
            $key = self::MAP[$field->type][$attribute] ?? null;
            $value = $key === null ? null : ($value[$key] ?? null);
        }

        return is_scalar($value) ? $value : null;
    }

    private function forAttribute(string|int|float|bool $value, string $attribute): string
    {
        $string = is_bool($value) ? ($value ? '1' : '') : (string) $value;

        if (in_array($attribute, self::URL_ATTRIBUTES, true)) {
            return esc_url($string);
        }
        if (in_array($attribute, self::TEXT_ATTRIBUTES, true)) {
            return $string;
        }

        return esc_attr($string);
    }
}
