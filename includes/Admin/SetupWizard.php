<?php
/**
 * Setup Wizard - Initial plugin configuration wizard
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

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

    /**
     * Total number of steps in the wizard
     */
    private const TOTAL_STEPS = 4;

    /**
     * Register wizard hooks
     */
    public function register(): void
    {
        // Priority 20 to run after main Proto Blocks menu (default priority 10)
        add_action('admin_menu', [$this, 'addWizardPage'], 20);
        add_action('admin_head', [$this, 'hideWizardMenuItem']);
        add_action('admin_init', [$this, 'maybeRedirectToWizard']);
        add_action('admin_init', [$this, 'handleFormSubmission']);
    }

    /**
     * Add wizard page to admin (under Proto Blocks menu)
     */
    public function addWizardPage(): void
    {
        add_submenu_page(
            'proto-blocks', // Parent slug
            __('Proto Blocks Setup', 'proto-blocks'),
            __('Setup Wizard', 'proto-blocks'),
            'manage_options',
            self::SLUG,
            [$this, 'renderWizard']
        );
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
     * Handle form submissions
     */
    public function handleFormSubmission(): void
    {
        // Only handle on wizard page
        if (!$this->isWizardPage()) {
            return;
        }

        // Check for form submission
        if (!isset($_POST['proto_blocks_wizard_action'])) {
            return;
        }

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'proto_blocks_wizard')) {
            return;
        }

        $action = sanitize_text_field($_POST['proto_blocks_wizard_action']);
        $current_step = isset($_GET['step']) ? absint($_GET['step']) : 1;

        switch ($action) {
            case 'save_style':
                $style = isset($_POST['component_style']) ? sanitize_text_field($_POST['component_style']) : 'vanilla';
                update_option(self::OPTION_STYLE, $style);

                // Save category name
                $category_name = isset($_POST['category_name']) ? sanitize_text_field($_POST['category_name']) : '';
                update_option(AdminPage::OPTION_CATEGORY_NAME, $category_name);

                // If tailwind is selected, enable it
                if ($style === 'tailwind') {
                    TailwindManager::getInstance()->updateSettings(['enabled' => true]);
                }

                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&step=3'));
                exit;

            case 'install_demos':
                $style = get_option(self::OPTION_STYLE, 'vanilla');
                $adminPage = Plugin::getInstance()->getAdminPage();

                // Use reflection to call the private method
                $reflection = new \ReflectionClass($adminPage);
                $method = $reflection->getMethod('installDemoBlocks');
                $method->setAccessible(true);
                $result = $method->invoke($adminPage, $style);

                $installed = is_wp_error($result) ? 0 : $result;
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&step=4&installed=' . $installed));
                exit;

            case 'skip_demos':
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&step=4&installed=0'));
                exit;

            case 'complete_wizard':
                update_option(self::OPTION_COMPLETED, true);
                wp_safe_redirect(admin_url('admin.php?page=proto-blocks'));
                exit;
        }
    }

    /**
     * Render the wizard page
     */
    public function renderWizard(): void
    {
        $current_step = isset($_GET['step']) ? absint($_GET['step']) : 1;
        $current_step = max(1, min($current_step, self::TOTAL_STEPS));

        // Enqueue admin assets
        wp_enqueue_style('proto-blocks-admin-fonts');
        wp_enqueue_style('proto-blocks-admin-icons');
        wp_enqueue_style('proto-blocks-admin');

        ?>
        <div class="wrap proto-blocks-admin-ui pb-bg-background-light pb-font-display pb-text-text-main-light" style="margin-left: -20px;">
            <div class="pb-min-h-screen pb-flex pb-items-center pb-justify-center pb-p-6">
                <div class="pb-max-w-2xl pb-w-full pb-bg-surface-light pb-rounded-xl pb-shadow-lg pb-p-8 pb-border pb-border-border-light">
                    <!-- Step Indicator -->
                    <?php $this->renderStepIndicator($current_step); ?>

                    <!-- Step Content -->
                    <div class="pb-mt-8">
                        <?php
                        switch ($current_step) {
                            case 1:
                                $this->renderStep1Welcome();
                                break;
                            case 2:
                                $this->renderStep2ChooseStyle();
                                break;
                            case 3:
                                $this->renderStep3Import();
                                break;
                            case 4:
                                $this->renderStep4Finish();
                                break;
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render step indicator
     */
    private function renderStepIndicator(int $currentStep): void
    {
        $steps = [
            1 => __('Welcome', 'proto-blocks'),
            2 => __('Style', 'proto-blocks'),
            3 => __('Import', 'proto-blocks'),
            4 => __('Finish', 'proto-blocks'),
        ];

        ?>
        <div class="pb-flex pb-justify-center">
            <div class="pb-flex pb-items-center pb-gap-2">
                <?php foreach ($steps as $num => $label): ?>
                    <?php
                    $isActive = $num === $currentStep;
                    $isCompleted = $num < $currentStep;
                    $circleClass = $isActive
                        ? 'pb-bg-primary pb-text-white'
                        : ($isCompleted ? 'pb-bg-secondary pb-text-white' : 'pb-bg-gray-200 pb-text-gray-500');
                    ?>
                    <div class="pb-flex pb-items-center">
                        <div class="pb-flex pb-flex-col pb-items-center">
                            <div class="<?php echo esc_attr($circleClass); ?> pb-w-10 pb-h-10 pb-rounded-full pb-flex pb-items-center pb-justify-center pb-font-bold pb-text-sm pb-shadow-sm">
                                <?php if ($isCompleted): ?>
                                    <span class="material-icons-outlined pb-text-lg">check</span>
                                <?php else: ?>
                                    <?php echo esc_html($num); ?>
                                <?php endif; ?>
                            </div>
                            <span class="pb-text-xs pb-mt-1 <?php echo $isActive ? 'pb-text-primary pb-font-semibold' : 'pb-text-text-muted-light'; ?>"><?php echo esc_html($label); ?></span>
                        </div>
                        <?php if ($num < self::TOTAL_STEPS): ?>
                            <div class="pb-w-12 pb-h-0.5 pb-mx-2 <?php echo $isCompleted ? 'pb-bg-secondary' : 'pb-bg-gray-200'; ?>"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Step 1: Welcome
     */
    private function renderStep1Welcome(): void
    {
        ?>
        <div class="pb-text-center">
            <!-- Logo/Icon -->
            <div class="pb-mb-6">
                <div class="pb-inline-flex pb-items-center pb-justify-center pb-w-20 pb-h-20 pb-bg-primary pb-rounded-2xl pb-shadow-lg">
                    <svg width="48" height="48" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
                        <path fill="white" d="M 247.29 113.27 C 281.81 92.76 317.20 73.73 351.74 53.24 C 381.54 70.41 411.26 87.73 441.05 104.91 C 445.94 107.55 450.55 110.67 455.17 113.76 C 455.17 190.50 455.17 267.24 455.16 343.98 C 421.16 364.15 386.68 383.52 352.65 403.64 C 320.46 422.38 288.26 441.09 256.02 459.73 C 219.34 438.57 182.70 417.33 146.19 395.88 C 116.39 378.59 86.48 361.51 56.83 343.99 C 56.83 303.91 56.82 263.83 56.83 223.75 C 86.20 206.41 115.83 189.50 145.32 172.37 C 150.57 169.58 155.39 166.09 160.53 163.12 C 166.16 166.77 171.92 170.20 178.05 172.97 C 201.17 186.24 224.21 199.68 247.30 213.01 C 247.27 179.76 247.30 146.52 247.29 113.27 M 273.42 118.25 C 299.35 133.26 325.25 148.31 351.22 163.24 C 359.85 159.03 368.03 154.02 376.35 149.25 C 393.91 139.08 411.49 128.94 429.04 118.75 C 403.10 103.76 377.20 88.68 351.22 73.76 C 325.35 88.68 299.41 103.50 273.42 118.25 M 265.23 134.08 C 265.20 163.98 265.22 193.88 265.21 223.78 C 291.25 238.14 316.57 253.78 342.54 268.26 C 342.49 238.42 342.56 208.58 342.51 178.74 C 316.94 163.53 290.99 148.96 265.23 134.08 M 359.95 178.75 C 359.93 208.41 359.94 238.07 359.94 267.73 C 385.92 253.00 411.84 238.15 437.73 223.27 C 437.79 193.43 437.75 163.60 437.75 133.76 C 411.84 148.79 385.67 163.40 359.95 178.75 M 82.95 228.27 C 108.85 243.38 134.92 258.23 160.73 273.50 C 186.52 258.44 212.27 243.33 238.05 228.26 C 212.33 213.18 186.19 198.81 160.76 183.25 C 135.01 198.56 108.86 213.21 82.95 228.27 M 234.70 250.70 C 215.86 261.54 197.02 272.38 178.18 283.24 C 204.09 298.29 230.24 312.94 256.00 328.23 C 281.73 313.36 307.55 298.63 333.27 283.74 C 307.44 268.48 281.22 253.89 255.47 238.51 C 248.74 242.90 241.61 246.61 234.70 250.70 M 74.24 243.76 C 74.25 273.60 74.21 303.43 74.26 333.27 C 100.19 348.25 126.10 363.27 152.04 378.23 C 152.08 348.41 152.05 318.59 152.05 288.77 C 126.09 273.81 100.18 258.76 74.24 243.76 M 359.95 288.74 C 359.93 318.56 359.92 348.38 359.95 378.20 C 385.91 363.28 411.80 348.23 437.74 333.27 C 437.78 303.60 437.75 273.93 437.75 244.26 C 411.82 259.09 385.86 273.87 359.95 288.74 M 264.74 343.44 C 264.66 373.37 264.73 403.31 264.71 433.24 C 290.68 418.40 316.78 403.76 342.51 388.52 C 342.54 358.51 342.52 328.51 342.53 298.51 C 316.58 313.46 290.73 328.56 264.74 343.44 M 169.48 388.51 C 195.07 403.78 221.04 418.43 246.77 433.46 C 246.79 403.65 246.77 373.84 246.79 344.03 C 220.99 329.11 195.22 314.13 169.43 299.19 C 169.53 328.96 169.43 358.74 169.48 388.51 Z" />
                    </svg>
                </div>
            </div>

            <h1 class="pb-text-3xl pb-font-bold pb-text-primary pb-mb-4"><?php esc_html_e('Welcome to Proto Blocks', 'proto-blocks'); ?></h1>
            <p class="pb-text-lg pb-text-text-muted-light pb-mb-8 pb-max-w-md pb-mx-auto">
                <?php esc_html_e('Create Gutenberg blocks using PHP/HTML templates instead of React. Fast, simple, and developer-friendly.', 'proto-blocks'); ?>
            </p>

            <!-- Features -->
            <div class="pb-grid pb-grid-cols-1 sm:pb-grid-cols-3 pb-gap-4 pb-mb-8 pb-text-left">
                <div class="pb-bg-background-light pb-rounded-lg pb-p-4 pb-border pb-border-border-light">
                    <div class="pb-flex pb-items-center pb-gap-3 pb-mb-2">
                        <span class="material-icons-outlined pb-text-secondary pb-text-xl">code</span>
                        <h3 class="pb-font-semibold pb-text-sm"><?php esc_html_e('PHP Templates', 'proto-blocks'); ?></h3>
                    </div>
                    <p class="pb-text-xs pb-text-text-muted-light"><?php esc_html_e('Write blocks with familiar PHP syntax.', 'proto-blocks'); ?></p>
                </div>
                <div class="pb-bg-background-light pb-rounded-lg pb-p-4 pb-border pb-border-border-light">
                    <div class="pb-flex pb-items-center pb-gap-3 pb-mb-2">
                        <span class="material-icons-outlined pb-text-accent pb-text-xl">bolt</span>
                        <h3 class="pb-font-semibold pb-text-sm"><?php esc_html_e('No Build Step', 'proto-blocks'); ?></h3>
                    </div>
                    <p class="pb-text-xs pb-text-text-muted-light"><?php esc_html_e('Works instantly without compilation.', 'proto-blocks'); ?></p>
                </div>
                <div class="pb-bg-background-light pb-rounded-lg pb-p-4 pb-border pb-border-border-light">
                    <div class="pb-flex pb-items-center pb-gap-3 pb-mb-2">
                        <span class="material-icons-outlined pb-text-primary pb-text-xl">palette</span>
                        <h3 class="pb-font-semibold pb-text-sm"><?php esc_html_e('Flexible Styling', 'proto-blocks'); ?></h3>
                    </div>
                    <p class="pb-text-xs pb-text-text-muted-light"><?php esc_html_e('Use vanilla CSS or Tailwind.', 'proto-blocks'); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&step=2')); ?>" class="pb-inline-flex pb-items-center pb-gap-2 pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-8 pb-py-3 pb-rounded-lg pb-shadow-md pb-font-semibold pb-transition-colors">
                <?php esc_html_e('Get Started', 'proto-blocks'); ?>
                <span class="material-icons-outlined">arrow_forward</span>
            </a>
        </div>
        <?php
    }

    /**
     * Render Step 2: Choose Style
     */
    private function renderStep2ChooseStyle(): void
    {
        $currentStyle = get_option(self::OPTION_STYLE, 'vanilla');
        $currentCategoryName = get_option(AdminPage::OPTION_CATEGORY_NAME, '');
        ?>
        <div>
            <div class="pb-text-center pb-mb-8">
                <h1 class="pb-text-2xl pb-font-bold pb-text-primary pb-mb-2"><?php esc_html_e('Configure Your Blocks', 'proto-blocks'); ?></h1>
                <p class="pb-text-text-muted-light"><?php esc_html_e('Set up your preferences. You can change these later in settings.', 'proto-blocks'); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('proto_blocks_wizard'); ?>
                <input type="hidden" name="proto_blocks_wizard_action" value="save_style">

                <div class="pb-grid pb-grid-cols-1 sm:pb-grid-cols-2 pb-gap-4 pb-mb-8">
                    <!-- Vanilla CSS Option -->
                    <label class="pb-cursor-pointer pb-group">
                        <input type="radio" name="component_style" value="vanilla" class="pb-hidden pb-peer" <?php checked($currentStyle, 'vanilla'); ?>>
                        <div class="pb-border-2 pb-border-border-light peer-checked:pb-border-primary pb-rounded-xl pb-p-6 pb-transition-all hover:pb-border-gray-300 peer-checked:pb-bg-blue-50">
                            <div class="pb-flex pb-items-center pb-gap-3 pb-mb-4">
                                <div class="pb-w-12 pb-h-12 pb-bg-orange-100 pb-rounded-lg pb-flex pb-items-center pb-justify-center">
                                    <span class="material-icons-outlined pb-text-orange-600 pb-text-2xl">brush</span>
                                </div>
                                <div>
                                    <h3 class="pb-font-bold pb-text-lg"><?php esc_html_e('Vanilla CSS', 'proto-blocks'); ?></h3>
                                    <span class="pb-text-xs pb-text-green-600 pb-font-medium"><?php esc_html_e('Recommended for beginners', 'proto-blocks'); ?></span>
                                </div>
                            </div>
                            <p class="pb-text-sm pb-text-text-muted-light pb-mb-4"><?php esc_html_e('Traditional CSS styling with custom stylesheets per block. No build tools required.', 'proto-blocks'); ?></p>
                            <ul class="pb-text-xs pb-text-text-muted-light pb-space-y-1">
                                <li class="pb-flex pb-items-center pb-gap-2">
                                    <span class="material-icons-outlined pb-text-green-500 pb-text-sm">check_circle</span>
                                    <?php esc_html_e('No dependencies', 'proto-blocks'); ?>
                                </li>
                                <li class="pb-flex pb-items-center pb-gap-2">
                                    <span class="material-icons-outlined pb-text-green-500 pb-text-sm">check_circle</span>
                                    <?php esc_html_e('Familiar CSS syntax', 'proto-blocks'); ?>
                                </li>
                                <li class="pb-flex pb-items-center pb-gap-2">
                                    <span class="material-icons-outlined pb-text-green-500 pb-text-sm">check_circle</span>
                                    <?php esc_html_e('Full control over styles', 'proto-blocks'); ?>
                                </li>
                            </ul>
                        </div>
                    </label>

                    <!-- Tailwind CSS Option -->
                    <label class="pb-cursor-pointer pb-group">
                        <input type="radio" name="component_style" value="tailwind" class="pb-hidden pb-peer" <?php checked($currentStyle, 'tailwind'); ?>>
                        <div class="pb-border-2 pb-border-border-light peer-checked:pb-border-primary pb-rounded-xl pb-p-6 pb-transition-all hover:pb-border-gray-300 peer-checked:pb-bg-blue-50">
                            <div class="pb-flex pb-items-center pb-gap-3 pb-mb-4">
                                <div class="pb-w-12 pb-h-12 pb-bg-sky-100 pb-rounded-lg pb-flex pb-items-center pb-justify-center">
                                    <span class="material-icons-outlined pb-text-sky-600 pb-text-2xl">wind_power</span>
                                </div>
                                <div>
                                    <h3 class="pb-font-bold pb-text-lg"><?php esc_html_e('Tailwind CSS', 'proto-blocks'); ?></h3>
                                    <span class="pb-text-xs pb-text-blue-600 pb-font-medium"><?php esc_html_e('For rapid development', 'proto-blocks'); ?></span>
                                </div>
                            </div>
                            <p class="pb-text-sm pb-text-text-muted-light pb-mb-4"><?php esc_html_e('Utility-first CSS framework for rapid UI development. Auto-compiled by the plugin.', 'proto-blocks'); ?></p>
                            <ul class="pb-text-xs pb-text-text-muted-light pb-space-y-1">
                                <li class="pb-flex pb-items-center pb-gap-2">
                                    <span class="material-icons-outlined pb-text-green-500 pb-text-sm">check_circle</span>
                                    <?php esc_html_e('Rapid prototyping', 'proto-blocks'); ?>
                                </li>
                                <li class="pb-flex pb-items-center pb-gap-2">
                                    <span class="material-icons-outlined pb-text-green-500 pb-text-sm">check_circle</span>
                                    <?php esc_html_e('Consistent design system', 'proto-blocks'); ?>
                                </li>
                                <li class="pb-flex pb-items-center pb-gap-2">
                                    <span class="material-icons-outlined pb-text-green-500 pb-text-sm">check_circle</span>
                                    <?php esc_html_e('Built-in compilation', 'proto-blocks'); ?>
                                </li>
                            </ul>
                        </div>
                    </label>
                </div>

                <!-- Category Name -->
                <div class="pb-bg-background-light pb-rounded-xl pb-p-5 pb-border pb-border-border-light pb-mb-8">
                    <div class="pb-flex pb-items-center pb-gap-3 pb-mb-4">
                        <div class="pb-w-10 pb-h-10 pb-bg-primary/10 pb-rounded-lg pb-flex pb-items-center pb-justify-center">
                            <span class="material-icons-outlined pb-text-primary">category</span>
                        </div>
                        <div>
                            <h3 class="pb-font-bold pb-text-base"><?php esc_html_e('Block Category Name', 'proto-blocks'); ?></h3>
                            <p class="pb-text-text-muted-light pb-text-xs"><?php esc_html_e('Optional: Customize how your blocks appear in the editor', 'proto-blocks'); ?></p>
                        </div>
                    </div>
                    <input type="text" name="category_name" value="<?php echo esc_attr($currentCategoryName); ?>" placeholder="<?php esc_attr_e('Proto Blocks', 'proto-blocks'); ?>" class="pb-w-full pb-px-4 pb-py-2.5 pb-border pb-border-border-light pb-rounded-lg pb-text-sm focus:pb-outline-none focus:pb-ring-2 focus:pb-ring-primary focus:pb-border-transparent" />
                    <p class="pb-text-text-muted-light pb-text-xs pb-mt-2"><?php esc_html_e('Leave empty to use the default "Proto Blocks" name in the block inserter.', 'proto-blocks'); ?></p>
                </div>

                <!-- Navigation -->
                <div class="pb-flex pb-justify-between pb-items-center">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&step=1')); ?>" class="pb-flex pb-items-center pb-gap-2 pb-text-text-muted-light hover:pb-text-primary pb-transition-colors">
                        <span class="material-icons-outlined">arrow_back</span>
                        <?php esc_html_e('Back', 'proto-blocks'); ?>
                    </a>
                    <button type="submit" class="pb-inline-flex pb-items-center pb-gap-2 pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-8 pb-py-3 pb-rounded-lg pb-shadow-md pb-font-semibold pb-transition-colors">
                        <?php esc_html_e('Continue', 'proto-blocks'); ?>
                        <span class="material-icons-outlined">arrow_forward</span>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render Step 3: Import Demo Blocks
     */
    private function renderStep3Import(): void
    {
        $style = get_option(self::OPTION_STYLE, 'vanilla');
        $adminPage = Plugin::getInstance()->getAdminPage();
        $demoBlocks = $adminPage->getDemoBlockNamesByType($style);
        $styleLabel = $style === 'tailwind' ? __('Tailwind CSS', 'proto-blocks') : __('Vanilla CSS', 'proto-blocks');
        ?>
        <div>
            <div class="pb-text-center pb-mb-8">
                <h1 class="pb-text-2xl pb-font-bold pb-text-primary pb-mb-2"><?php esc_html_e('Import Example Blocks', 'proto-blocks'); ?></h1>
                <p class="pb-text-text-muted-light">
                    <?php printf(
                        esc_html__('Get started quickly with our %s example blocks.', 'proto-blocks'),
                        '<strong>' . esc_html($styleLabel) . '</strong>'
                    ); ?>
                </p>
            </div>

            <!-- Demo Blocks Preview -->
            <div class="pb-bg-background-light pb-rounded-xl pb-p-6 pb-border pb-border-border-light pb-mb-6">
                <div class="pb-flex pb-items-center pb-gap-3 pb-mb-4">
                    <span class="material-icons-outlined pb-text-primary pb-text-xl">inventory_2</span>
                    <h3 class="pb-font-semibold"><?php esc_html_e('Available Example Blocks', 'proto-blocks'); ?></h3>
                    <span class="pb-bg-primary pb-text-white pb-text-xs pb-font-bold pb-px-2 pb-py-0.5 pb-rounded-full"><?php echo count($demoBlocks); ?></span>
                </div>

                <?php if (!empty($demoBlocks)): ?>
                    <div class="pb-flex pb-flex-wrap pb-gap-2">
                        <?php foreach ($demoBlocks as $name): ?>
                            <span class="pb-px-3 pb-py-1.5 pb-text-sm pb-font-medium pb-bg-white pb-text-gray-700 pb-rounded-lg pb-border pb-border-gray-200 pb-flex pb-items-center pb-gap-2">
                                <span class="material-icons-outlined pb-text-sm pb-text-gray-400">widgets</span>
                                <?php echo esc_html($name); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="pb-text-text-muted-light pb-text-sm"><?php esc_html_e('No example blocks available for this style.', 'proto-blocks'); ?></p>
                <?php endif; ?>
            </div>

            <!-- Install Path Info -->
            <div class="pb-bg-blue-50 pb-border-l-4 pb-border-blue-400 pb-p-4 pb-mb-8 pb-text-sm pb-text-blue-800 pb-flex pb-items-start pb-gap-3">
                <span class="material-icons-outlined pb-mt-0.5">info</span>
                <div>
                    <p class="pb-mb-1"><strong><?php esc_html_e('Installation location:', 'proto-blocks'); ?></strong></p>
                    <code class="pb-bg-blue-100 pb-px-2 pb-py-0.5 pb-rounded pb-text-xs"><?php echo esc_html(str_replace(ABSPATH, '', get_stylesheet_directory() . '/proto-blocks/')); ?></code>
                </div>
            </div>

            <!-- Navigation -->
            <div class="pb-flex pb-flex-col sm:pb-flex-row pb-justify-between pb-items-center pb-gap-4">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&step=2')); ?>" class="pb-flex pb-items-center pb-gap-2 pb-text-text-muted-light hover:pb-text-primary pb-transition-colors">
                    <span class="material-icons-outlined">arrow_back</span>
                    <?php esc_html_e('Back', 'proto-blocks'); ?>
                </a>

                <div class="pb-flex pb-items-center pb-gap-3">
                    <!-- Skip Button -->
                    <form method="post" class="pb-inline">
                        <?php wp_nonce_field('proto_blocks_wizard'); ?>
                        <input type="hidden" name="proto_blocks_wizard_action" value="skip_demos">
                        <button type="submit" class="pb-text-text-muted-light hover:pb-text-primary pb-font-medium pb-transition-colors">
                            <?php esc_html_e('Skip for now', 'proto-blocks'); ?>
                        </button>
                    </form>

                    <!-- Install Button -->
                    <?php if (!empty($demoBlocks)): ?>
                        <form method="post" class="pb-inline">
                            <?php wp_nonce_field('proto_blocks_wizard'); ?>
                            <input type="hidden" name="proto_blocks_wizard_action" value="install_demos">
                            <button type="submit" class="pb-inline-flex pb-items-center pb-gap-2 pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-6 pb-py-3 pb-rounded-lg pb-shadow-md pb-font-semibold pb-transition-colors">
                                <span class="material-icons-outlined">download</span>
                                <?php esc_html_e('Install Example Blocks', 'proto-blocks'); ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="pb-inline">
                            <?php wp_nonce_field('proto_blocks_wizard'); ?>
                            <input type="hidden" name="proto_blocks_wizard_action" value="skip_demos">
                            <button type="submit" class="pb-inline-flex pb-items-center pb-gap-2 pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-6 pb-py-3 pb-rounded-lg pb-shadow-md pb-font-semibold pb-transition-colors">
                                <?php esc_html_e('Continue', 'proto-blocks'); ?>
                                <span class="material-icons-outlined">arrow_forward</span>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Step 4: Finish
     */
    private function renderStep4Finish(): void
    {
        $style = get_option(self::OPTION_STYLE, 'vanilla');
        $styleLabel = $style === 'tailwind' ? __('Tailwind CSS', 'proto-blocks') : __('Vanilla CSS', 'proto-blocks');
        $installed = isset($_GET['installed']) ? absint($_GET['installed']) : 0;
        $tailwindEnabled = TailwindManager::getInstance()->isEnabled();
        $categoryName = get_option(AdminPage::OPTION_CATEGORY_NAME, '');
        ?>
        <div class="pb-text-center">
            <!-- Success Icon -->
            <div class="pb-mb-6">
                <div class="pb-inline-flex pb-items-center pb-justify-center pb-w-20 pb-h-20 pb-bg-green-100 pb-rounded-full">
                    <span class="material-icons-outlined pb-text-green-600 pb-text-5xl">check_circle</span>
                </div>
            </div>

            <h1 class="pb-text-3xl pb-font-bold pb-text-primary pb-mb-4"><?php esc_html_e('You\'re All Set!', 'proto-blocks'); ?></h1>
            <p class="pb-text-lg pb-text-text-muted-light pb-mb-8">
                <?php esc_html_e('Proto Blocks is ready to use. Here\'s a summary of your setup:', 'proto-blocks'); ?>
            </p>

            <!-- Summary -->
            <div class="pb-bg-background-light pb-rounded-xl pb-p-6 pb-border pb-border-border-light pb-mb-8 pb-text-left pb-max-w-md pb-mx-auto">
                <h3 class="pb-font-semibold pb-text-sm pb-text-text-muted-light pb-uppercase pb-tracking-wide pb-mb-4"><?php esc_html_e('Setup Summary', 'proto-blocks'); ?></h3>
                <div class="pb-space-y-3">
                    <div class="pb-flex pb-items-center pb-justify-between pb-py-2 pb-border-b pb-border-border-light">
                        <span class="pb-text-text-muted-light"><?php esc_html_e('Styling Approach', 'proto-blocks'); ?></span>
                        <span class="pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <?php if ($style === 'tailwind'): ?>
                                <span class="material-icons-outlined pb-text-sky-500 pb-text-sm">wind_power</span>
                            <?php else: ?>
                                <span class="material-icons-outlined pb-text-orange-500 pb-text-sm">brush</span>
                            <?php endif; ?>
                            <?php echo esc_html($styleLabel); ?>
                        </span>
                    </div>
                    <div class="pb-flex pb-items-center pb-justify-between pb-py-2 pb-border-b pb-border-border-light">
                        <span class="pb-text-text-muted-light"><?php esc_html_e('Category Name', 'proto-blocks'); ?></span>
                        <span class="pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <span class="material-icons-outlined pb-text-primary pb-text-sm">category</span>
                            <?php echo esc_html(!empty($categoryName) ? $categoryName : __('Proto Blocks', 'proto-blocks')); ?>
                        </span>
                    </div>
                    <div class="pb-flex pb-items-center pb-justify-between pb-py-2 pb-border-b pb-border-border-light">
                        <span class="pb-text-text-muted-light"><?php esc_html_e('Example Blocks Installed', 'proto-blocks'); ?></span>
                        <span class="pb-font-semibold">
                            <?php if ($installed > 0): ?>
                                <span class="pb-text-green-600"><?php echo esc_html($installed); ?> <?php esc_html_e('blocks', 'proto-blocks'); ?></span>
                            <?php else: ?>
                                <span class="pb-text-gray-500"><?php esc_html_e('Skipped', 'proto-blocks'); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($style === 'tailwind'): ?>
                        <div class="pb-flex pb-items-center pb-justify-between pb-py-2">
                            <span class="pb-text-text-muted-light"><?php esc_html_e('Tailwind Integration', 'proto-blocks'); ?></span>
                            <span class="pb-font-semibold pb-flex pb-items-center pb-gap-1">
                                <?php if ($tailwindEnabled): ?>
                                    <span class="material-icons-outlined pb-text-green-500 pb-text-sm">check_circle</span>
                                    <span class="pb-text-green-600"><?php esc_html_e('Enabled', 'proto-blocks'); ?></span>
                                <?php else: ?>
                                    <span class="material-icons-outlined pb-text-gray-400 pb-text-sm">cancel</span>
                                    <span class="pb-text-gray-500"><?php esc_html_e('Disabled', 'proto-blocks'); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Next Steps -->
            <div class="pb-bg-blue-50 pb-rounded-lg pb-p-4 pb-mb-8 pb-text-left pb-max-w-md pb-mx-auto">
                <h4 class="pb-font-semibold pb-text-sm pb-text-blue-800 pb-mb-2 pb-flex pb-items-center pb-gap-2">
                    <span class="material-icons-outlined pb-text-lg">lightbulb</span>
                    <?php esc_html_e('What\'s Next?', 'proto-blocks'); ?>
                </h4>
                <ul class="pb-text-sm pb-text-blue-700 pb-space-y-1">
                    <li class="pb-flex pb-items-start pb-gap-2">
                        <span class="material-icons-outlined pb-text-xs pb-mt-1">arrow_right</span>
                        <?php esc_html_e('Explore your installed example blocks in the editor', 'proto-blocks'); ?>
                    </li>
                    <li class="pb-flex pb-items-start pb-gap-2">
                        <span class="material-icons-outlined pb-text-xs pb-mt-1">arrow_right</span>
                        <?php esc_html_e('Create your own custom blocks in your theme', 'proto-blocks'); ?>
                    </li>
                    <li class="pb-flex pb-items-start pb-gap-2">
                        <span class="material-icons-outlined pb-text-xs pb-mt-1">arrow_right</span>
                        <?php esc_html_e('Check the dashboard for quick start guides', 'proto-blocks'); ?>
                    </li>
                </ul>
            </div>

            <!-- Go to Dashboard -->
            <form method="post">
                <?php wp_nonce_field('proto_blocks_wizard'); ?>
                <input type="hidden" name="proto_blocks_wizard_action" value="complete_wizard">
                <button type="submit" class="pb-inline-flex pb-items-center pb-gap-2 pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-8 pb-py-3 pb-rounded-lg pb-shadow-md pb-font-semibold pb-transition-colors">
                    <?php esc_html_e('Go to Dashboard', 'proto-blocks'); ?>
                    <span class="material-icons-outlined">arrow_forward</span>
                </button>
            </form>
        </div>
        <?php
    }
}
