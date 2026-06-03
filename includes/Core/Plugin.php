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
use ProtoBlocks\API\AjaxHandler;
use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\Admin\PreviewCapture;
use ProtoBlocks\Admin\Assets;
use ProtoBlocks\Admin\SetupWizard;
use ProtoBlocks\Tailwind\Manager as TailwindManager;
use ProtoBlocks\Tailwind\AdminSettings as TailwindAdminSettings;
use ProtoBlocks\Updater\GitHubUpdater;

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

        // Allow extensions to register custom options providers
        do_action('proto_blocks_register_options_providers', $this->getOptionsProviders());

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

        $registry->register('innerblocks', [
            'php_class' => \ProtoBlocks\Fields\Types\InnerBlocksField::class,
            'attribute_schema' => ['type' => 'string', 'default' => ''],
        ]);
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

        $registry->register('select', [
            'data_type' => 'string',
            'default' => '',
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
    }

    /**
     * Register built-in options providers (WP relationships).
     */
    private function registerCoreOptionsProviders(): void
    {
        $providers = $this->getOptionsProviders();

        $providers->register('wp:posts', function (array $args): array {
            $query = new \WP_Query([
                'post_type'      => $args['post_type'] ?? 'post',
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
            $terms = get_terms([
                'taxonomy'   => $args['taxonomy'] ?? 'category',
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
            $users = get_users([
                'number'  => self::clampPerPage($args['per_page'] ?? null, 50),
                'search'  => !empty($args['search']) ? '*' . $args['search'] . '*' : '',
                'orderby' => 'display_name',
                'order'   => 'ASC',
            ]);

            return array_map(
                static fn(\WP_User $user): array => [
                    'key'   => (string) $user->ID,
                    'label' => $user->display_name !== '' ? $user->display_name : (string) $user->user_login,
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

        // Tailwind Manager
        $tailwindManager = TailwindManager::getInstance();
        $tailwindManager->init($this->getDiscovery());
        $this->services['tailwind_manager'] = $tailwindManager;

        // Tailwind Admin Settings (only if in admin)
        if (is_admin()) {
            $this->services['tailwind_admin'] = new TailwindAdminSettings($tailwindManager);
            $this->services['tailwind_admin']->init();
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

        // AJAX handlers
        add_action('wp_ajax_proto_blocks_preview', [$this->getAjaxHandler(), 'handlePreview']);

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

            // Preview Capture submenu + its own enqueue.
            add_action('admin_menu', [$this->getPreviewCapture(), 'addMenuPage']);
            add_action('admin_enqueue_scripts', [$this->getPreviewCapture(), 'enqueueAssets']);
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

    public function getAjaxHandler(): AjaxHandler
    {
        return $this->services['ajax_handler'];
    }

    public function getAssets(): Assets
    {
        return $this->services['assets'];
    }

    public function getAdminPage(): AdminPage
    {
        return $this->services['admin_page'];
    }

    public function getPreviewCapture(): PreviewCapture
    {
        if (!isset($this->services['preview_capture'])) {
            $this->services['preview_capture'] = new PreviewCapture();
        }
        return $this->services['preview_capture'];
    }

    public function getTailwindManager(): TailwindManager
    {
        return $this->services['tailwind_manager'];
    }

    public function getTailwindAdminSettings(): ?TailwindAdminSettings
    {
        return $this->services['tailwind_admin'] ?? null;
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
