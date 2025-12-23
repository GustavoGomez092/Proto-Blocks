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
use ProtoBlocks\Template\Engine;
use ProtoBlocks\Template\Cache;
use ProtoBlocks\Blocks\Registrar;
use ProtoBlocks\Blocks\Discovery;
use ProtoBlocks\Blocks\Category;
use ProtoBlocks\API\RestAPI;
use ProtoBlocks\API\AjaxHandler;
use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\Admin\Assets;
use ProtoBlocks\Tailwind\Manager as TailwindManager;
use ProtoBlocks\Tailwind\AdminSettings as TailwindAdminSettings;

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
            $this->getCache()
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
        }

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

        // Admin page (if in admin)
        if (is_admin()) {
            add_action('admin_menu', [$this->getAdminPage(), 'addMenuPage']);
            add_action('admin_enqueue_scripts', [$this->getAssets(), 'enqueueAdminAssets']);
        }

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

    public function getTailwindManager(): TailwindManager
    {
        return $this->services['tailwind_manager'];
    }

    public function getTailwindAdminSettings(): ?TailwindAdminSettings
    {
        return $this->services['tailwind_admin'] ?? null;
    }
}
