<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Edit;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

/**
 * One meta box per field group matching the edited post, mounted by the
 * React field box (assets/js/fields-box.js).
 *
 * Saving needs no JavaScript: every input carries a real `name`, so the
 * classic editor's form submit and the block editor's meta-box request (core
 * serialises `.metabox-base-form` — post_ID included — plus every meta box
 * location form into one POST to post.php, which runs save_post) both reach
 * SaveHandler with the nonce and the object marker printed in each box.
 *
 * Contexts: `normal` and `side` as declared; `acf_after_title` is `normal` /
 * `high` in the block editor (it has no after-title area) and the
 * `proto_after_title` context in the classic editor, printed on
 * `edit_form_after_title`.
 */
final class MetaBoxes
{
    public const AFTER_TITLE = 'proto_after_title';
    public const SCRIPT_HANDLE = 'proto-blocks-fields-box';
    public const PORTAL_ID = 'proto-blocks-fields-portal';

    private static bool $portalNeeded = false;
    private static bool $portalPrinted = false;

    /** @var array<string, FieldGroup> group key => group with a box on this screen */
    private array $registered = [];

    public function __construct(
        private readonly GroupIndex $index,
        private readonly BoxPayload $payload,
    ) {
    }

    /** add_meta_boxes (fires for comments and links too, with other objects). */
    public function addMetaBoxes(mixed $postType = '', mixed $post = null): void
    {
        if (is_string($postType) && $post instanceof \WP_Post) {
            $this->register($postType, $post);
        }
    }

    public function register(string $postType, \WP_Post $post): void
    {
        $id = (int) $post->ID;
        if ($id <= 0 || $postType === '' || !current_user_can('edit_post', $id)) {
            return;
        }
        $blockEditor = function_exists('use_block_editor_for_post') && use_block_editor_for_post($post);
        $afterTitle = false;
        foreach ($this->index->forRef(ObjectRef::post($id)) as $group) {
            if (!BoxPayload::renderable($group) || $group->fields === []) {
                continue;
            }
            [$context, $priority] = match ($group->position) {
                'side' => ['side', 'default'],
                'acf_after_title' => $blockEditor ? ['normal', 'high'] : [self::AFTER_TITLE, 'high'],
                default => ['normal', 'default'],
            };
            $afterTitle = $afterTitle || $context === self::AFTER_TITLE;
            $this->registered[$group->key] = $group;
            add_meta_box(
                'proto-fields-' . $group->key,
                // Core prints the box title unescaped.
                $group->title !== '' ? esc_html($group->title) : esc_html__('Fields', 'proto-blocks'),
                [$this, 'render'],
                $postType,
                $context,
                $priority,
                ['group' => $group->key]
            );
        }
        if ($afterTitle) {
            add_action('edit_form_after_title', [$this, 'renderAfterTitle']);
        }
    }

    /** edit_form_after_title (classic editor only). */
    public function renderAfterTitle(mixed $post = null): void
    {
        if ($post instanceof \WP_Post && function_exists('do_meta_boxes')) {
            do_meta_boxes(null, self::AFTER_TITLE, $post);
        }
    }

    /** @param array<string, mixed> $box */
    public function render(\WP_Post $post, array $box): void
    {
        $key = is_array($box['args'] ?? null) ? ($box['args']['group'] ?? null) : null;
        $group = is_string($key) ? $this->group($key) : null;
        $id = (int) $post->ID;
        if ($group === null || $id <= 0 || !current_user_can('edit_post', $id)) {
            return;
        }
        self::$portalNeeded = true;
        $userId = get_current_user_id();
        echo BoxPayload::formFields('post:' . $id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in formFields()
        echo BoxPayload::markup($this->payload->build($group, ObjectRef::post($id), $userId)); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX flags, escaped title
    }

    /** admin_enqueue_scripts: only on the post editor screens that have a box. */
    public function enqueue(mixed $hook = ''): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || $this->registered === []) {
            return;
        }
        self::assets(true, BoxPayload::hasType(array_values($this->registered), 'wysiwyg'));
    }

    /**
     * The box script and stylesheet (unbuilt checkout: nothing), the media
     * modal and the classic editor when asked.
     */
    public static function assets(bool $media, bool $editor): void
    {
        self::$portalNeeded = true;
        if (!defined('PROTO_BLOCKS_DIR') || !defined('PROTO_BLOCKS_URL')) {
            return;
        }
        $assetPath = PROTO_BLOCKS_DIR . 'assets/js/fields-box.asset.php';
        if (!file_exists($assetPath)) {
            return;
        }
        $asset = require $assetPath;
        $dependencies = array_values(array_unique(array_merge((array) ($asset['dependencies'] ?? []), ['wp-i18n'])));
        $version = defined('PROTO_BLOCKS_VERSION') ? PROTO_BLOCKS_VERSION : false;

        // WordPress registers react-jsx-runtime from 6.6 (see Admin\Assets).
        if (in_array('react-jsx-runtime', $dependencies, true) && !wp_script_is('react-jsx-runtime', 'registered')) {
            wp_register_script('react-jsx-runtime', PROTO_BLOCKS_URL . 'assets/admin/react-jsx-runtime-polyfill.js', ['react'], $version, true);
        }
        if ($media && function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }
        if ($editor && function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }
        wp_enqueue_style(self::SCRIPT_HANDLE, PROTO_BLOCKS_URL . 'assets/css/fields-box.css', [], $version);
        wp_enqueue_script(self::SCRIPT_HANDLE, PROTO_BLOCKS_URL . 'assets/js/fields-box.js', $dependencies, $asset['version'] ?? $version, true);
        wp_set_script_translations(self::SCRIPT_HANDLE, 'proto-blocks');
    }

    /** admin_footer / login_footer: the shared popover portal, once, on screens with a box. */
    public static function printPortal(): void
    {
        if (!self::$portalNeeded || self::$portalPrinted) {
            return;
        }
        self::$portalPrinted = true;
        echo '<div id="' . esc_attr(self::PORTAL_ID) . '" class="proto-blocks-fields"></div>';
    }

    /** Only a group register() accepted for this screen. */
    private function group(string $key): ?FieldGroup
    {
        return $this->registered[$key] ?? null;
    }
}
