<?php
/**
 * Main Plugin Class
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Core;

use ProtoBlocks\Schema\SchemaReader;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;
use ProtoBlocks\Controls\OptionsProviders;
use ProtoBlocks\Template\Engine;
use ProtoBlocks\Template\Cache;
use ProtoBlocks\Blocks\Registrar;
use ProtoBlocks\Blocks\Discovery;
use ProtoBlocks\Blocks\Category;
use ProtoBlocks\API\RestAPI;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\API\AjaxHandler;
use ProtoBlocks\Admin\AdminApp;
use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\Admin\PreviewCapture;
use ProtoBlocks\Admin\Assets;
use ProtoBlocks\Admin\SetupWizard;
use ProtoBlocks\Tailwind\Manager as TailwindManager;
use ProtoBlocks\Tailwind\AdminSettings as TailwindAdminSettings;
use ProtoBlocks\Updater\GitHubUpdater;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * Main plugin singleton class
 */
final class Plugin
{
    /**
     * Singleton instance
     */
    private static ?Plugin $instance = null;

    /**
     * Container for services
     *
     * @var array<string, object>
     */
    private array $services = [];

    /**
     * Whether plugin has been booted
     */
    private bool $booted = false;

    /**
     * Private constructor (singleton)
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot the plugin
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // Register core field types
        $this->registerCoreFieldTypes();

        // Register core control types
        $this->registerCoreControlTypes();

        // Register built-in options providers for dynamic controls
        $this->registerCoreOptionsProviders();

        // Allow extensions to register custom options providers.
        //
        // Deferred to after_setup_theme rather than fired here: boot() runs on
        // plugins_loaded, which is before WordPress loads the active theme's
        // functions.php, so a theme could never hook this action in time and its
        // optionsSource would 400 as an unknown source. Plugins are unaffected —
        // they are already loaded and their callback is registered either way.
        //
        // Nothing resolves a provider this early; the registry is read by the
        // REST controls/options route and by block rendering, both far later.
        add_action('after_setup_theme', function (): void {
            do_action('proto_blocks_register_options_providers', $this->getOptionsProviders());
        }, 0);

        // Fire init action for extensions to register field types
        do_action('proto_blocks_init', $this);

        // Initialize services
        $this->initializeServices();

        // Register hooks
        $this->registerHooks();
    }

    /**
     * Register core field types
     */
    private function registerCoreFieldTypes(): void
    {
        $registry = $this->getFieldRegistry();

        $registry->register('text', [
            'php_class' => \ProtoBlocks\Fields\Types\TextField::class,
            'attribute_schema' => ['type' => 'string', 'default' => ''],
        ]);

        $registry->register('image', [
            'php_class' => \ProtoBlocks\Fields\Types\ImageField::class,
            'attribute_schema' => ['type' => 'object', 'default' => []],
        ]);

        $registry->register('video', [
            'php_class' => \ProtoBlocks\Fields\Types\VideoField::class,
            'attribute_schema' => ['type' => 'object', 'default' => []],
        ]);

        $registry->register('link', [
            'php_class' => \ProtoBlocks\Fields\Types\LinkField::class,
            'attribute_schema' => ['type' => 'object', 'default' => []],
        ]);

        $registry->register('wysiwyg', [
            'php_class' => \ProtoBlocks\Fields\Types\WysiwygField::class,
            'attribute_schema' => ['type' => 'string', 'default' => ''],
        ]);

        $registry->register('repeater', [
            'php_class' => \ProtoBlocks\Fields\Types\RepeaterField::class,
            'attribute_schema' => ['type' => 'array', 'default' => []],
        ]);

        // 'inner-blocks' is canonical (matches the editor); 'innerblocks' is a legacy alias.
        foreach (['inner-blocks', 'innerblocks'] as $innerBlocksType) {
            $registry->register($innerBlocksType, [
                'php_class' => \ProtoBlocks\Fields\Types\InnerBlocksField::class,
                'attribute_schema' => ['type' => 'string', 'default' => ''],
            ]);
        }
    }

    /**
     * Register core control types
     */
    private function registerCoreControlTypes(): void
    {
        $registry = $this->getControlRegistry();

        $registry->register('text', [
            'data_type' => 'string',
            'default' => '',
        ]);

        $registry->register('textarea', [
            'data_type' => 'string',
            'default' => '',
        ]);

        $registry->register('select', [
            'data_type' => 'string',
            'default' => '',
        ]);

        // Same config contract as `select` -- static `options` or a server
        // `optionsSource` -- but stores an ordered list of keys rather than one.
        $registry->register('multiselect', [
            'data_type' => 'array',
            'default' => [],
        ]);

        $registry->register('toggle', [
            'data_type' => 'boolean',
            'default' => false,
        ]);

        $registry->register('checkbox', [
            'data_type' => 'boolean',
            'default' => false,
        ]);

        $registry->register('range', [
            'data_type' => 'number',
            'default' => 0,
        ]);

        $registry->register('number', [
            'data_type' => 'number',
            'default' => 0,
        ]);

        $registry->register('color', [
            'data_type' => 'string',
            'default' => '',
        ]);

        $registry->register('color-palette', [
            'data_type' => 'string',
            'default' => '',
        ]);

        $registry->register('radio', [
            'data_type' => 'string',
            'default' => '',
        ]);

        $registry->register('image', [
            'data_type' => 'object',
            'default' => [],
        ]);

        $registry->register('video', [
            'data_type' => 'object',
            'default' => [],
        ]);

        // `image`'s multiple-value counterpart: an ordered list of
        // { id, url, alt } objects rather than one of them.
        $registry->register('gallery', [
            'data_type' => 'array',
            'default' => [],
        ]);

        // Any attachment, not only an image: { id, url, filename, mime }.
        // `image` and `video` filter the media library to their own kind, which
        // leaves no way to pick a CSV, a PDF or a font from a control.
        $registry->register('file', [
            'data_type' => 'object',
            'default' => [],
        ]);

        // A repeatable group of controls, held in the sidebar: an ordered list
        // of objects, one per row, each keyed by the names in the control's own
        // `fields`. The `repeater` FIELD puts its editing UI in the canvas,
        // which is right when the repeated thing is content the visitor sees
        // and wrong when it is configuration — a list of tabs, a set of
        // breakpoints, a table pasted as CSV.
        $registry->register('repeater', [
            'data_type' => 'array',
            'default' => [],
        ]);
    }

    /**
     * Register built-in options providers (WP relationships).
     */
    private function registerCoreOptionsProviders(): void
    {
        $providers = $this->getOptionsProviders();

        $providers->register('wp:posts', function (array $args): array {
            // Only post types a visitor could view on the front end. Anything
            // else (wp_template, wp_global_styles, wp_navigation, private
            // CPTs such as coupons) is not something a contributor may list.
            $postType = isset($args['post_type']) && is_string($args['post_type']) ? $args['post_type'] : 'post';
            $typeObject = get_post_type_object($postType);
            if (!$typeObject || !is_post_type_viewable($typeObject)) {
                return [];
            }

            $query = new \WP_Query([
                'post_type'      => $postType,
                'post_status'    => 'publish',
                'posts_per_page' => self::clampPerPage($args['per_page'] ?? null, 50),
                's'              => (string) ($args['search'] ?? ''),
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ]);

            return array_map(
                static fn(\WP_Post $post): array => [
                    'key'   => (string) $post->ID,
                    'label' => $post->post_title !== '' ? $post->post_title : __('(no title)', 'proto-blocks'),
                ],
                $query->posts
            );
        }, ['post_type', 'per_page', 'search']);

        $providers->register('wp:terms', function (array $args): array {
            // Only taxonomies a visitor could query on the front end; this
            // keeps nav_menu, wp_theme and private taxonomies out of reach.
            $taxonomy = isset($args['taxonomy']) && is_string($args['taxonomy']) ? $args['taxonomy'] : 'category';
            $taxonomyObject = get_taxonomy($taxonomy);
            if (!$taxonomyObject || !is_taxonomy_viewable($taxonomyObject)) {
                return [];
            }

            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'number'     => self::clampPerPage($args['per_page'] ?? null, 100),
                'search'     => (string) ($args['search'] ?? ''),
            ]);

            if (is_wp_error($terms)) {
                return [];
            }

            return array_map(
                static fn($term): array => [
                    'key'   => (string) $term->term_id,
                    'label' => $term->name !== '' ? $term->name : __('(no name)', 'proto-blocks'),
                ],
                $terms
            );
        }, ['taxonomy', 'per_page', 'search']);

        $providers->register('wp:users', function (array $args): array {
            // Trust model (mirrors core's /wp/v2/users): the route is open to
            // every edit_posts user, so without restrictions it is an e-mail
            // and login enumeration oracle. We keep the source usable for
            // author pickers by (a) searching display_name/user_nicename
            // only -- never user_email or user_login -- and (b) limiting
            // callers who lack `list_users` to users with published posts,
            // which is exactly what core exposes publicly. Users with
            // `list_users` get the unrestricted listing (still with the
            // restricted search columns). Labels are display_name only; the
            // login is never returned.
            $query = [
                'number'         => self::clampPerPage($args['per_page'] ?? null, 50),
                'orderby'        => 'display_name',
                'order'          => 'ASC',
                'search_columns' => ['display_name', 'user_nicename'],
            ];

            if (!empty($args['search']) && is_string($args['search'])) {
                $query['search'] = '*' . sanitize_text_field($args['search']) . '*';
            }

            if (!current_user_can('list_users')) {
                $query['has_published_posts'] = true;
            }

            $users = get_users($query);

            return array_map(
                static fn(\WP_User $user): array => [
                    'key'   => (string) $user->ID,
                    'label' => $user->display_name !== ''
                        ? $user->display_name
                        : sprintf(__('User #%d', 'proto-blocks'), $user->ID),
                ],
                $users
            );
        }, ['per_page', 'search']);
    }

    /**
     * Clamp a requested per-page count to a sane bounded range (1-200),
     * preventing an editor from triggering an unbounded or oversized query.
     */
    private static function clampPerPage(mixed $value, int $default): int
    {
        $count = ($value === null || $value === '') ? $default : (int) $value;

        return max(1, min($count, 200));
    }

    /**
     * Initialize services
     */
    private function initializeServices(): void
    {
        // Schema Reader
        $this->services['schema_reader'] = new SchemaReader();

        // Template Cache
        $this->services['cache'] = new Cache();

        // Template Engine
        $this->services['engine'] = new Engine(
            $this->getCache(),
            $this->getFieldRegistry()
        );

        // Block Discovery
        $this->services['discovery'] = new Discovery();

        // Block Registrar
        $this->services['registrar'] = new Registrar(
            $this->getSchemaReader(),
            $this->getEngine(),
            $this->getDiscovery()
        );

        // REST API
        $this->services['rest_api'] = new RestAPI(
            $this->getEngine(),
            $this->getRegistrar(),
            $this->getCache(),
            $this->getOptionsProviders()
        );

        // REST routes behind the React admin. Built in every context: REST
        // requests are not is_admin(), so the route groups resolve their own
        // services rather than relying on the admin-only ones.
        $this->services['admin_rest'] = new AdminRestController();

        // AJAX Handler
        $this->services['ajax_handler'] = new AjaxHandler($this->getEngine());

        // Admin Assets
        $this->services['assets'] = new Assets(
            $this->getDiscovery(),
            $this->getSchemaReader(),
            $this->getFieldRegistry(),
            $this->getControlRegistry()
        );

        // Admin Page (only if in admin)
        if (is_admin()) {
            $this->services['admin_page'] = new AdminPage($this->getCache());

            // Setup Wizard
            $this->services['setup_wizard'] = new SetupWizard();
            $this->services['setup_wizard']->register();
        }

        // GitHub self-updater. Created in all contexts so background
        // (cron) auto-updates work; register() handles admin-vs-core
        // hook gating and no-ops on git checkouts.
        $this->services['updater'] = new GitHubUpdater(PROTO_BLOCKS_FILE);
        $this->services['updater']->register();

        // Content-model modules (post types, taxonomies). Booted on
        // after_setup_theme 1 so a theme's proto_blocks_modules filter applies,
        // still before core re-registers its built-in types on init 0.
        $this->services['modules'] = new \ProtoBlocks\Modules\Manager($this->getContentModel());
        add_action('after_setup_theme', [$this->services['modules'], 'boot'], 1);

        // Tailwind Manager
        $tailwindManager = TailwindManager::getInstance();
        $tailwindManager->init($this->getDiscovery());
        $this->services['tailwind_manager'] = $tailwindManager;

        // Tailwind Admin Settings (only if in admin)
        if (is_admin()) {
            $this->services['tailwind_admin'] = new TailwindAdminSettings($tailwindManager);
            $this->services['tailwind_admin']->init();

            // React admin mount point and bootstrap data
            $this->services['admin_app'] = new AdminApp($tailwindManager);
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void
    {
        // Register editor script EARLY (priority 5) - before blocks are registered
        add_action('init', [$this->getAssets(), 'registerEditorScript'], 5);

        // Apply custom category name from settings
        add_filter('proto_blocks_category_title', [$this, 'filterCategoryTitle']);

        // Block category registration (priority 8)
        add_action('init', [new Category(), 'register'], 8);

        // Block registration (priority 10 - default)
        add_action('init', [$this->getRegistrar(), 'registerBlocks']);

        // REST API routes
        add_action('rest_api_init', [$this->getRestAPI(), 'registerRoutes']);
        add_action('rest_api_init', [$this->getAdminRestController(), 'registerRoutes']);

        // AJAX handlers
        add_action('wp_ajax_proto_blocks_preview', [$this->getAjaxHandler(), 'handlePreview']);

        // Attribute trust boundary. Block-comment JSON is never touched by
        // kses, so for users without `unfiltered_html` the plugin sanitises
        // Proto-Blocks attributes itself: at save (wp_insert_post_data) and
        // again at render (render_block_data) as defence in depth for
        // content that was stored before this existed or bypassed save.
        add_filter('wp_insert_post_data', [$this, 'sanitizePostContentAttributes'], 20, 2);
        add_action('wp_after_insert_post', [$this, 'recordPostTrust'], 10, 2);
        add_filter('render_block_data', [$this, 'sanitizeRenderedBlockAttributes'], 9);
        // Trust-context stack: template parts are admin-only content and a
        // synced pattern carries its own trust, so blocks rendered inside
        // them must not be judged by the queried post's author.
        add_filter('pre_render_block', [$this, 'pushRenderTrustContext'], PHP_INT_MAX, 2);
        add_filter('render_block', [$this, 'popRenderTrustContext'], PHP_INT_MAX, 2);

        // Editor assets - localize data (script already registered on init)
        add_action('enqueue_block_editor_assets', [$this->getAssets(), 'enqueueEditorAssets']);

        // Add Tailwind CSS to editor via add_editor_style (for iframe support)
        add_action('after_setup_theme', [$this->getAssets(), 'addEditorStyles']);

        // Frontend assets
        add_action('enqueue_block_assets', [$this->getAssets(), 'enqueueBlockAssets']);

        // Tailwind CSS on all frontend pages (fallback for pages without blocks)
        add_action('wp_enqueue_scripts', [$this->getAssets(), 'enqueueFrontendAssets']);
        add_action('wp_head', [$this->getAssets(), 'printRevealNoscript'], 1);

        // Admin page (if in admin)
        if (is_admin()) {
            add_action('admin_menu', [$this->getAdminPage(), 'addMenuPage']);
            add_action('admin_enqueue_scripts', [$this->getAssets(), 'enqueueAdminAssets']);

            // Preview Capture submenu (a React admin screen).
            add_action('admin_menu', [$this->getPreviewCapture(), 'addMenuPage']);
        }

        // Preview Capture iframe render target -- served via
        // admin-ajax so it bypasses admin chrome entirely.
        add_action(
            'wp_ajax_' . PreviewCapture::RENDER_ACTION,
            [$this->getPreviewCapture(), 'handleRender']
        );

        // Preview Capture REST route -- registered unconditionally so
        // the iframe target works (it's served via admin.php so admin
        // context, but the REST route lives off the front of the site).
        add_action('rest_api_init', [$this->getPreviewCapture(), 'registerRestRoute']);

        // Load CLI commands if WP-CLI is available
        if (defined('WP_CLI') && WP_CLI) {
            $this->loadCLICommands();
        }

        // Tailwind on-reload compilation (for development mode)
        add_action('init', [$this->getTailwindManager(), 'maybeCompileOnReload'], 99);

        // Disable WordPress global styles if enabled in settings
        add_action('init', [$this->getTailwindManager(), 'maybeDisableGlobalStyles'], 10);
    }

    /**
     * wp_insert_post_data (priority 20, 2 args): sanitise Proto-Blocks block
     * attributes in post_content when the saving user lacks unfiltered_html.
     *
     * $data arrives slashed; the content is only re-serialised when a block
     * actually changed so untouched posts keep byte-identical content.
     *
     * @param array $data    Slashed, sanitised post data.
     * @param array $postarr Slashed post data as passed to wp_insert_post().
     */
    public function sanitizePostContentAttributes(array $data, array $postarr = []): array
    {
        $content = $data['post_content'] ?? null;
        if (!is_string($content) || $content === '' || strpos($content, '<!-- wp:proto-blocks/') === false) {
            return $data;
        }

        if (!$this->isAttributeSanitizationEnabled('save')) {
            return $data;
        }

        if (!AttributeSanitizer::shouldSanitizeForUser((int) get_current_user_id())) {
            return $data;
        }

        try {
            $unslashed = wp_unslash($content);
            $blocks    = parse_blocks($unslashed);
            if (!is_array($blocks)) {
                return $data;
            }

            $changed   = false;
            $sanitized = $this->sanitizeBlockTree($blocks, $changed);

            if ($changed) {
                $data['post_content'] = wp_slash(serialize_blocks($sanitized));
            }
        } catch (\Throwable $e) {
            // Parse/serialise failure: keep the original content rather than
            // losing the post; the render-time pass still applies.
            if (defined('PROTO_BLOCKS_DEBUG') && PROTO_BLOCKS_DEBUG) {
                error_log('Proto-Blocks: attribute sanitisation on save failed: ' . $e->getMessage());
            }
        }

        return $data;
    }

    /**
     * Post meta recording whether the last save of a post that contains
     * Proto-Blocks blocks was made by a user with `unfiltered_html`.
     * '1' = trusted, '0' = sanitise at render, absent = legacy (fall back to
     * the post author's capability).
     */
    public const TRUST_META_KEY = '_proto_blocks_unfiltered';

    /**
     * Stack of trust decisions for the enclosing template part / synced
     * pattern currently being rendered (true = trusted). Empty outside them.
     *
     * @var bool[]
     */
    private array $renderTrustStack = [];

    /**
     * wp_after_insert_post: remember who last saved this post. Core's kses
     * works the same way (it keys on the saving user, not the author), so an
     * administrator editing a contributor's draft must not have their edits
     * stripped at render, and a contributor re-saving an administrator's
     * post must not inherit its trust.
     */
    public function recordPostTrust(int $postId, $post): void
    {
        if (!$post instanceof \WP_Post) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post)) {
            return;
        }
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post)) {
            return;
        }

        $content = (string) $post->post_content;
        if (strpos($content, '<!-- wp:proto-blocks/') === false) {
            delete_post_meta($postId, self::TRUST_META_KEY);
            return;
        }

        $trusted = !AttributeSanitizer::shouldSanitizeForUser((int) get_current_user_id());
        update_post_meta($postId, self::TRUST_META_KEY, $trusted ? '1' : '0');
    }

    /**
     * Whether blocks stored in $post may render unsanitised.
     */
    private function isPostTrusted(\WP_Post $post): bool
    {
        $meta = get_post_meta($post->ID, self::TRUST_META_KEY, true);

        return AttributeSanitizer::isPostTrusted(is_string($meta) ? $meta : '', (int) $post->post_author);
    }

    /**
     * pre_render_block (last): entering a template part or synced pattern.
     * Runs at PHP_INT_MAX so a short-circuit by another filter (non-null
     * $preRender, after which core never fires `render_block`) is visible and
     * nothing is pushed for it.
     *
     * @param string|null $preRender
     * @param array       $parsedBlock
     * @return string|null
     */
    public function pushRenderTrustContext($preRender, $parsedBlock)
    {
        if ($preRender !== null || !is_array($parsedBlock)) {
            return $preRender;
        }

        $name = $parsedBlock['blockName'] ?? '';
        if ($name === 'core/template-part') {
            // Editing template parts requires edit_theme_options.
            $this->renderTrustStack[] = true;
        } elseif ($name === 'core/block') {
            $ref  = (int) ($parsedBlock['attrs']['ref'] ?? 0);
            $post = $ref > 0 ? get_post($ref) : null;
            $this->renderTrustStack[] = $post instanceof \WP_Post ? $this->isPostTrusted($post) : false;
        }

        return $preRender;
    }

    /**
     * render_block (last): leaving a template part or synced pattern.
     *
     * @param string $content
     * @param array  $parsedBlock
     * @return string
     */
    public function popRenderTrustContext($content, $parsedBlock)
    {
        $name = is_array($parsedBlock) ? ($parsedBlock['blockName'] ?? '') : '';
        if (($name === 'core/template-part' || $name === 'core/block') && $this->renderTrustStack !== []) {
            array_pop($this->renderTrustStack);
        }

        return $content;
    }

    /**
     * render_block_data (priority 9, before core renders): sanitise a
     * Proto-Blocks block's attributes unless the content it came from is
     * trusted: the enclosing template part / synced pattern when inside one,
     * otherwise the queried post (its recorded trust meta, or for legacy
     * posts its author's capability). Inner blocks reach this filter on
     * their own (WP_Block::render applies it per inner block), so only the
     * current block's attrs are touched here.
     *
     * @param array $parsedBlock The block as returned by parse_blocks().
     */
    public function sanitizeRenderedBlockAttributes($parsedBlock)
    {
        if (!is_array($parsedBlock)) {
            return $parsedBlock;
        }

        $protoConfig = $this->getProtoConfigForBlockName($parsedBlock['blockName'] ?? null);
        if ($protoConfig === null || empty($parsedBlock['attrs']) || !is_array($parsedBlock['attrs'])) {
            return $parsedBlock;
        }

        if (!$this->isAttributeSanitizationEnabled('render')) {
            return $parsedBlock;
        }

        if ($this->renderTrustStack !== []) {
            $trusted = (bool) end($this->renderTrustStack);
        } else {
            $post = get_post();
            if (!$post instanceof \WP_Post) {
                return $parsedBlock;
            }
            $trusted = $this->isPostTrusted($post);
        }

        if ($trusted) {
            return $parsedBlock;
        }

        $parsedBlock['attrs'] = AttributeSanitizer::sanitize($parsedBlock['attrs'], $protoConfig);

        return $parsedBlock;
    }

    /**
     * Walk a parse_blocks() tree (innerBlocks included) and sanitise the
     * attrs of every registered Proto-Blocks block.
     *
     * @param array $blocks  Blocks from parse_blocks().
     * @param bool  $changed Set to true when any block's attrs changed.
     */
    private function sanitizeBlockTree(array $blocks, bool &$changed): array
    {
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }

            $protoConfig = $this->getProtoConfigForBlockName($block['blockName'] ?? null);
            if ($protoConfig !== null && !empty($block['attrs']) && is_array($block['attrs'])) {
                $sanitized = AttributeSanitizer::sanitize($block['attrs'], $protoConfig);
                if ($sanitized !== $block['attrs']) {
                    $block['attrs'] = $sanitized;
                    $changed = true;
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->sanitizeBlockTree($block['innerBlocks'], $changed);
            }

            $blocks[$i] = $block;
        }

        return $blocks;
    }

    /**
     * The protoBlocks config for a registered Proto-Blocks block name
     * ("proto-blocks/hero"), or null when the name is not one of ours.
     */
    private function getProtoConfigForBlockName(mixed $blockName): ?array
    {
        if (!is_string($blockName) || strpos($blockName, 'proto-blocks/') !== 0) {
            return null;
        }

        if (!isset($this->services['registrar'])) {
            return null;
        }

        $name       = substr($blockName, strlen('proto-blocks/'));
        $registered = $this->getRegistrar()->getRegisteredBlocks();
        if (!isset($registered[$name])) {
            return null;
        }

        $protoConfig = $registered[$name]['schema']['protoBlocks'] ?? [];

        return is_array($protoConfig) ? $protoConfig : [];
    }

    /**
     * Whether the attribute trust boundary is active. Sites that trust every
     * editing user can disable it:
     *   add_filter('proto_blocks_sanitize_attributes', '__return_false');
     * $context is 'save' or 'render'.
     */
    private function isAttributeSanitizationEnabled(string $context): bool
    {
        return (bool) apply_filters('proto_blocks_sanitize_attributes', true, $context);
    }

    /**
     * Load WP-CLI commands
     */
    private function loadCLICommands(): void
    {
        require_once PROTO_BLOCKS_DIR . 'includes/CLI/Commands.php';
    }

    // Service getters

    public function getFieldRegistry(): FieldRegistry
    {
        if (!isset($this->services['field_registry'])) {
            $this->services['field_registry'] = new FieldRegistry();
        }
        return $this->services['field_registry'];
    }

    public function getControlRegistry(): ControlRegistry
    {
        if (!isset($this->services['control_registry'])) {
            $this->services['control_registry'] = new ControlRegistry();
        }
        return $this->services['control_registry'];
    }

    public function getOptionsProviders(): OptionsProviders
    {
        if (!isset($this->services['options_providers'])) {
            $this->services['options_providers'] = new OptionsProviders();
        }
        return $this->services['options_providers'];
    }

    public function getSchemaReader(): SchemaReader
    {
        return $this->services['schema_reader'];
    }

    public function getCache(): Cache
    {
        return $this->services['cache'];
    }

    public function getEngine(): Engine
    {
        return $this->services['engine'];
    }

    public function getDiscovery(): Discovery
    {
        return $this->services['discovery'];
    }

    public function getRegistrar(): Registrar
    {
        return $this->services['registrar'];
    }

    public function getRestAPI(): RestAPI
    {
        return $this->services['rest_api'];
    }

    public function getAdminRestController(): AdminRestController
    {
        return $this->services['admin_rest'];
    }

    public function getAjaxHandler(): AjaxHandler
    {
        return $this->services['ajax_handler'];
    }

    public function getAssets(): Assets
    {
        return $this->services['assets'];
    }

    /**
     * Built eagerly under is_admin(); constructed on first use otherwise so
     * REST requests (which are not is_admin()) can reach it.
     */
    public function getAdminPage(): AdminPage
    {
        if (!isset($this->services['admin_page'])) {
            $this->services['admin_page'] = new AdminPage($this->getCache());
        }
        return $this->services['admin_page'];
    }

    public function getPreviewCapture(): PreviewCapture
    {
        if (!isset($this->services['preview_capture'])) {
            $this->services['preview_capture'] = new PreviewCapture();
        }
        return $this->services['preview_capture'];
    }

    /**
     * Declared post types and taxonomies (files and PHP calls), built lazily.
     */
    public function getContentModel(): \ProtoBlocks\ContentModel\Registry
    {
        if (!isset($this->services['content_model'])) {
            $this->services['content_model'] = new \ProtoBlocks\ContentModel\Registry(
                new \ProtoBlocks\ContentModel\FileLoader(),
                fn() => \ProtoBlocks\ContentModel\SchemaPaths::roots()
            );
        }
        return $this->services['content_model'];
    }

    public function getModules(): \ProtoBlocks\Modules\Manager
    {
        return $this->services['modules'];
    }

    public function getTailwindManager(): TailwindManager
    {
        return $this->services['tailwind_manager'];
    }

    /**
     * The React admin's mount point; null outside wp-admin.
     */
    public function getAdminApp(): ?AdminApp
    {
        return $this->services['admin_app'] ?? null;
    }

    /**
     * Filter the category title based on saved settings
     *
     * @param string $title Default category title
     * @return string Modified category title
     */
    public function filterCategoryTitle(string $title): string
    {
        $custom_title = get_option(AdminPage::OPTION_CATEGORY_NAME, '');

        if (!empty($custom_title)) {
            return $custom_title;
        }

        return $title;
    }
}
