<?php
/**
 * Setup Wizard - Initial plugin configuration wizard
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Tailwind\Manager as TailwindManager;

/**
 * Setup wizard for first-time plugin configuration
 */
class SetupWizard
{
    /**
     * Page slug
     */
    private const SLUG = 'proto-blocks-wizard';

    /**
     * Option key for wizard completion status
     */
    private const OPTION_COMPLETED = 'proto_blocks_wizard_completed';

    /**
     * Option key for component style preference
     */
    private const OPTION_STYLE = 'proto_blocks_component_style';

    private ?AdminPage $adminPage;

    private ?TailwindManager $tailwind;

    /**
     * Both services are resolved lazily when omitted, so the REST routes can
     * build a wizard outside admin context.
     */
    public function __construct(?AdminPage $adminPage = null, ?TailwindManager $tailwind = null)
    {
        $this->adminPage = $adminPage;
        $this->tailwind = $tailwind;
    }

    /**
     * Register wizard hooks
     */
    public function register(): void
    {
        // Priority 20 to run after main Proto Blocks menu (default priority 10)
        add_action('admin_menu', [$this, 'addWizardPage'], 20);
        add_action('admin_head', [$this, 'hideWizardMenuItem']);
        add_action('admin_init', [$this, 'maybeRedirectToWizard']);
    }

    /**
     * Add wizard page to admin (under Proto Blocks menu)
     */
    public function addWizardPage(): void
    {
        $hook = add_submenu_page(
            'proto-blocks', // Parent slug
            __('Proto Blocks Setup', 'proto-blocks'),
            __('Setup Wizard', 'proto-blocks'),
            'manage_options',
            self::SLUG,
            static function (): void {
                Plugin::getInstance()->getAdminApp()?->renderScreen('wizard');
            }
        );
        if (is_string($hook)) {
            Plugin::getInstance()->getAdminApp()?->addScreenHook($hook, 'wizard');
        }
    }

    /**
     * Hide wizard menu item with CSS (keeps page data intact for WordPress)
     */
    public function hideWizardMenuItem(): void
    {
        ?>
        <style>
            #adminmenu a[href="admin.php?page=<?php echo esc_attr(self::SLUG); ?>"] { display: none !important; }
        </style>
        <?php
    }

    /**
     * Component styles the wizard knows how to install.
     */
    public const COMPONENT_STYLES = ['vanilla', 'tailwind'];

    /**
     * Map a submitted/stored component style onto the known enum.
     * Unknown or empty values fall back to 'vanilla' -- never to "all".
     */
    public static function normalizeComponentStyle(string $style): string
    {
        $style = strtolower(trim($style));
        return in_array($style, self::COMPONENT_STYLES, true) ? $style : 'vanilla';
    }

    /**
     * Check if wizard should be shown
     */
    private function shouldShowWizard(): bool
    {
        // Don't show if user can't manage options
        if (!current_user_can('manage_options')) {
            return false;
        }

        // Check if wizard has been completed
        $completed = get_option(self::OPTION_COMPLETED, null);

        // If option doesn't exist (null) or is explicitly false, show wizard
        return $completed !== true && $completed !== '1';
    }

    /**
     * Check if current page is a Proto Blocks admin page
     */
    private function isProtoBlocksPage(): bool
    {
        if (!isset($_GET['page'])) {
            return false;
        }

        $page = sanitize_text_field($_GET['page']);
        return strpos($page, 'proto-blocks') === 0;
    }

    /**
     * Check if we're on the wizard page
     */
    private function isWizardPage(): bool
    {
        return isset($_GET['page']) && $_GET['page'] === self::SLUG;
    }

    /**
     * Redirect to wizard if needed
     */
    public function maybeRedirectToWizard(): void
    {
        // Don't redirect if wizard is complete
        if (!$this->shouldShowWizard()) {
            return;
        }

        // Don't redirect if already on wizard page
        if ($this->isWizardPage()) {
            return;
        }

        // Don't redirect AJAX requests
        if (wp_doing_ajax()) {
            return;
        }

        // Only redirect from Proto Blocks pages or on first admin load after activation
        $transient = get_transient('proto_blocks_activated');
        if ($this->isProtoBlocksPage() || $transient) {
            delete_transient('proto_blocks_activated');
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
            exit;
        }
    }

    /**
     * Wizard state for the React screen.
     *
     * @return array{style:string,categoryName:string,demos:array{vanilla:string[],tailwind:string[]},installPath:string,tailwindEnabled:bool,completed:bool}
     */
    public function data(): array
    {
        $adminPage = $this->adminPage();
        $dest = trailingslashit(get_stylesheet_directory()) . 'proto-blocks/';

        return [
            'style' => self::normalizeComponentStyle((string) get_option(self::OPTION_STYLE, 'vanilla')),
            'categoryName' => (string) get_option(AdminPage::OPTION_CATEGORY_NAME, ''),
            'demos' => [
                'vanilla' => array_values($adminPage->getDemoBlockNamesByType('vanilla')),
                'tailwind' => array_values($adminPage->getDemoBlockNamesByType('tailwind')),
            ],
            'installPath' => AdminRestController::relativePath($dest, defined('ABSPATH') ? (string) \ABSPATH : ''),
            'tailwindEnabled' => $this->tailwind()->isEnabled(),
            'completed' => $this->isCompleted(),
        ];
    }

    /**
     * Store the chosen style and category name. Unknown styles collapse to
     * vanilla; choosing tailwind also enables the Tailwind integration.
     */
    public function saveStyle(string $style, string $categoryName): void
    {
        $style = self::normalizeComponentStyle($style);
        update_option(self::OPTION_STYLE, $style);
        update_option(AdminPage::OPTION_CATEGORY_NAME, sanitize_text_field($categoryName));

        if ($style === 'tailwind') {
            $this->tailwind()->updateSettings(['enabled' => true]);
        }
    }

    /**
     * Install the demo blocks of the stored style. The stored option is
     * re-validated so a stale value can never widen the install to "all".
     * Not exposed in the UI since 2.15.
     *
     * @return array{installed:int,skipped:string[]}|\WP_Error
     */
    public function installDemos(): array|\WP_Error
    {
        $style = self::normalizeComponentStyle((string) get_option(self::OPTION_STYLE, 'vanilla'));
        $adminPage = $this->adminPage();
        $result = $adminPage->installDemoBlocks($style);

        if ($result instanceof \WP_Error) {
            return $result;
        }

        return [
            'installed' => (int) $result,
            'skipped' => array_values($adminPage->getSkippedDemoBlocks()),
        ];
    }

    /**
     * Mark the wizard as done so it stops redirecting.
     */
    public function complete(): void
    {
        update_option(self::OPTION_COMPLETED, true);
    }

    private function isCompleted(): bool
    {
        $completed = get_option(self::OPTION_COMPLETED, null);
        return $completed === true || $completed === '1';
    }

    private function adminPage(): AdminPage
    {
        return $this->adminPage ??= Plugin::getInstance()->getAdminPage();
    }

    private function tailwind(): TailwindManager
    {
        return $this->tailwind ??= TailwindManager::getInstance();
    }
}
