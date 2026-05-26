<?php
/**
 * Admin Settings - Handles Tailwind admin UI and AJAX
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

/**
 * Tailwind CSS admin settings page and AJAX handlers
 */
class AdminSettings
{
    /**
     * AJAX action prefix
     */
    private const ACTION_PREFIX = 'proto_blocks_tailwind_';

    /**
     * Nonce action
     */
    private const NONCE_ACTION = 'proto_blocks_tailwind_nonce';

    /**
     * Manager instance
     */
    private Manager $manager;

    /**
     * Constructor
     */
    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Initialize hooks
     */
    public function init(): void
    {
        // Register AJAX handlers
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'toggle', [$this, 'handleToggle']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'set_mode', [$this, 'handleSetMode']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'set_engine', [$this, 'handleSetEngine']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'compile', [$this, 'handleCompile']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'clear_cache', [$this, 'handleClearCache']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'download_cli', [$this, 'handleDownloadCli']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'get_compile_inputs', [$this, 'handleGetCompileInputs']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'store_css', [$this, 'handleStoreCss']);
        // Theme config is now file-based (see ConfigEditor::getThemeCssPath).
        // The save_config and load_preset AJAX endpoints have been removed.
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'create_theme_file', [$this, 'handleCreateThemeFile']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'get_status', [$this, 'handleGetStatus']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'toggle_global_styles', [$this, 'handleToggleGlobalStyles']);
    }

    /**
     * Render the settings section
     */
    public function render(): void
    {
        $status = $this->manager->getStatus();
        $settings = $this->manager->getSettings();
        $configData = $this->manager->getConfigEditor()->getConfigData();

        ?>
        <!-- Tailwind CSS Settings Section -->
        <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-overflow-hidden" id="proto-blocks-tailwind-settings">
            <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-gray-50">
                <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2">
                    <span class="material-icons-outlined pb-text-secondary">wind_power</span>
                    <?php \esc_html_e('Tailwind CSS Support', 'proto-blocks'); ?>
                </h2>
            </div>
            <div class="pb-p-6">
                <p class="pb-text-text-muted-light pb-text-sm pb-mb-6">
                    <?php \esc_html_e('Enable Tailwind CSS utility classes in your Proto Blocks without requiring a Node.js build step.', 'proto-blocks'); ?>
                </p>

                <?php \wp_nonce_field(self::NONCE_ACTION, 'proto_blocks_tailwind_nonce'); ?>

                <!-- Enable/Disable Toggle -->
                <div class="pb-space-y-6">
                    <div class="pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-4 pb-py-4 pb-border-b pb-border-border-light">
                        <div class="sm:pb-w-48 pb-flex-shrink-0">
                            <label for="tailwind-enabled" class="pb-font-medium pb-text-text-main-light"><?php \esc_html_e('Enable Tailwind CSS', 'proto-blocks'); ?></label>
                        </div>
                        <div class="pb-flex-1">
                            <label class="pb-toggle">
                                <input type="checkbox" id="tailwind-enabled" <?php \checked($status['enabled']); ?>>
                                <span class="pb-toggle-slider"></span>
                            </label>
                            <p class="pb-text-text-muted-light pb-text-sm pb-mt-2">
                                <?php \esc_html_e('When enabled, Tailwind utility classes will be compiled and loaded for all Proto Blocks.', 'proto-blocks'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Compilation Mode -->
                    <div class="tailwind-option pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-4 pb-py-4 pb-border-b pb-border-border-light" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                        <div class="sm:pb-w-48 pb-flex-shrink-0">
                            <span class="pb-font-medium pb-text-text-main-light"><?php \esc_html_e('Compilation Mode', 'proto-blocks'); ?></span>
                        </div>
                        <div class="pb-flex-1 pb-space-y-3">
                            <label class="pb-flex pb-items-start pb-gap-3 pb-cursor-pointer">
                                <input type="radio" name="tailwind-mode" value="cached" <?php \checked($settings['mode'], 'cached'); ?> class="pb-mt-1">
                                <div>
                                    <span class="pb-font-medium"><?php \esc_html_e('Cached (Production)', 'proto-blocks'); ?></span>
                                    <p class="pb-text-text-muted-light pb-text-sm"><?php \esc_html_e('CSS is compiled once and served from cache. Use the "Compile" button to update.', 'proto-blocks'); ?></p>
                                </div>
                            </label>
                            <label class="pb-flex pb-items-start pb-gap-3 pb-cursor-pointer">
                                <input type="radio" name="tailwind-mode" value="on_reload" <?php \checked($settings['mode'], 'on_reload'); ?> class="pb-mt-1">
                                <div>
                                    <span class="pb-font-medium"><?php \esc_html_e('On-Reload (Development)', 'proto-blocks'); ?></span>
                                    <p class="pb-text-text-muted-light pb-text-sm"><?php \esc_html_e('CSS is recompiled on each page load when logged in as admin. Not recommended for production.', 'proto-blocks'); ?></p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Compile Engine -->
                    <div class="tailwind-option pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-4 pb-py-4 pb-border-b pb-border-border-light" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                        <div class="sm:pb-w-48 pb-flex-shrink-0">
                            <span class="pb-font-medium pb-text-text-main-light"><?php \esc_html_e('Compile Engine', 'proto-blocks'); ?></span>
                            <p class="pb-text-text-muted-light pb-text-xs pb-mt-1">
                                <?php
                                printf(
                                    /* translators: %s: resolved engine name */
                                    \esc_html__('Currently using: %s', 'proto-blocks'),
                                    '<strong>' . \esc_html($status['engine'] === 'browser' ? \__('Browser', 'proto-blocks') : \__('CLI binary', 'proto-blocks')) . '</strong>'
                                );
                                ?>
                            </p>
                        </div>
                        <div class="pb-flex-1 pb-space-y-3">
                            <label class="pb-flex pb-items-start pb-gap-3 pb-cursor-pointer">
                                <input type="radio" name="tailwind-engine" value="auto" <?php \checked($status['engine_setting'], 'auto'); ?> class="pb-mt-1">
                                <div>
                                    <span class="pb-font-medium"><?php \esc_html_e('Automatic (recommended)', 'proto-blocks'); ?></span>
                                    <p class="pb-text-text-muted-light pb-text-sm"><?php \esc_html_e('Use the CLI binary when the server has shell access, otherwise the browser compiler. Best default for most sites.', 'proto-blocks'); ?></p>
                                </div>
                            </label>
                            <label class="pb-flex pb-items-start pb-gap-3 pb-cursor-pointer">
                                <input type="radio" name="tailwind-engine" value="cli" <?php \checked($status['engine_setting'], 'cli'); ?> class="pb-mt-1">
                                <div>
                                    <span class="pb-font-medium"><?php \esc_html_e('CLI binary (server)', 'proto-blocks'); ?></span>
                                    <p class="pb-text-text-muted-light pb-text-sm">
                                        <?php \esc_html_e('Pros: compiles on the server, fast, supports true on-reload regeneration on every front-end request, and full Tailwind features (JS config/plugins). Cons: requires PHP shell access (exec) and a downloaded platform binary — unavailable on many managed hosts.', 'proto-blocks'); ?>
                                        <?php if (!$status['shell_available']): ?>
                                        <strong class="pb-text-red-600"><?php \esc_html_e('Shell access is not available in this environment, so CLI will not work here.', 'proto-blocks'); ?></strong>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </label>
                            <label class="pb-flex pb-items-start pb-gap-3 pb-cursor-pointer">
                                <input type="radio" name="tailwind-engine" value="browser" <?php \checked($status['engine_setting'], 'browser'); ?> class="pb-mt-1">
                                <div>
                                    <span class="pb-font-medium"><?php \esc_html_e('Browser compiler', 'proto-blocks'); ?></span>
                                    <p class="pb-text-text-muted-light pb-text-sm"><?php \esc_html_e('Pros: no server requirements — works where shell access (exec) is disabled, e.g. many managed hosts. Cons: runs in an admin browser; no JS config/plugin modules (browser-safe subset); heavier on the client. With On-Reload mode it auto-regenerates while you author (editor + admin) and on front-end loads for logged-in admins — convenient but intensive, so dev only.', 'proto-blocks'); ?></p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Disable Global Styles -->
                    <div class="tailwind-option pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-4 pb-py-4 pb-border-b pb-border-border-light" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                        <div class="sm:pb-w-48 pb-flex-shrink-0">
                            <label for="disable-global-styles" class="pb-font-medium pb-text-text-main-light"><?php \esc_html_e('Disable WP Global Styles', 'proto-blocks'); ?></label>
                        </div>
                        <div class="pb-flex-1">
                            <label class="pb-toggle">
                                <input type="checkbox" id="disable-global-styles" <?php \checked($status['disable_global_styles'] ?? false); ?>>
                                <span class="pb-toggle-slider"></span>
                            </label>
                            <p class="pb-text-text-muted-light pb-text-sm pb-mt-2">
                                <?php \esc_html_e('Disable WordPress global styles to prevent conflicts with Tailwind CSS.', 'proto-blocks'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Status Display -->
                    <div class="tailwind-option pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-4 pb-py-4 pb-border-b pb-border-border-light" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                        <div class="sm:pb-w-48 pb-flex-shrink-0">
                            <span class="pb-font-medium pb-text-text-main-light"><?php \esc_html_e('Status', 'proto-blocks'); ?></span>
                        </div>
                        <div class="pb-flex-1">
                            <div class="pb-grid pb-grid-cols-1 sm:pb-grid-cols-3 pb-gap-4">
                                <div class="pb-p-3 pb-bg-gray-50 pb-rounded-lg">
                                    <div class="pb-text-xs pb-text-text-muted-light pb-uppercase pb-tracking-wide pb-mb-1"><?php \esc_html_e('CLI Status', 'proto-blocks'); ?></div>
                                    <div class="pb-flex pb-items-center pb-gap-1" id="cli-status">
                                        <?php if ($status['engine'] === 'browser'): ?>
                                            <span class="material-icons-outlined pb-text-green-600 pb-text-lg">cloud_done</span>
                                            <span class="pb-text-sm"><?php \esc_html_e('Browser compiler', 'proto-blocks'); ?></span>
                                        <?php elseif ($status['cli_functional']): ?>
                                            <span class="material-icons-outlined pb-text-green-600 pb-text-lg">check_circle</span>
                                            <span class="pb-text-sm"><?php printf(\esc_html__('Server CLI v%s', 'proto-blocks'), \esc_html($status['cli_version'])); ?></span>
                                        <?php elseif ($status['cli_installed']): ?>
                                            <span class="material-icons-outlined pb-text-orange-500 pb-text-lg">warning</span>
                                            <span class="pb-text-sm pb-text-orange-600"><?php \esc_html_e('Installed but not runnable — re-download', 'proto-blocks'); ?></span>
                                        <?php else: ?>
                                            <span class="material-icons-outlined pb-text-red-600 pb-text-lg">error</span>
                                            <span class="pb-text-sm pb-text-red-600"><?php \esc_html_e('Not installed', 'proto-blocks'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="pb-p-3 pb-bg-gray-50 pb-rounded-lg">
                                    <div class="pb-text-xs pb-text-text-muted-light pb-uppercase pb-tracking-wide pb-mb-1"><?php \esc_html_e('Last Compiled', 'proto-blocks'); ?></div>
                                    <div class="pb-text-sm pb-font-medium" id="last-compiled">
                                        <?php
                                        if ($settings['last_compiled']) {
                                            echo \esc_html(sprintf(\__('%s ago', 'proto-blocks'), \human_time_diff($settings['last_compiled'])));
                                        } else {
                                            \esc_html_e('Never', 'proto-blocks');
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="pb-p-3 pb-bg-gray-50 pb-rounded-lg">
                                    <div class="pb-text-xs pb-text-text-muted-light pb-uppercase pb-tracking-wide pb-mb-1"><?php \esc_html_e('CSS Size', 'proto-blocks'); ?></div>
                                    <div class="pb-text-sm pb-font-medium" id="css-size">
                                        <?php echo \esc_html($this->manager->getCache()->getFormattedSize()); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="tailwind-option pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-4 pb-py-4" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                        <div class="sm:pb-w-48 pb-flex-shrink-0">
                            <span class="pb-font-medium pb-text-text-main-light"><?php \esc_html_e('Actions', 'proto-blocks'); ?></span>
                        </div>
                        <div class="pb-flex-1">
                            <div class="pb-flex pb-flex-wrap pb-gap-3">
                                <?php if ($status['shell_available']): ?>
                                <button type="button" id="download-cli" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors">
                                    <span class="material-icons-outlined pb-text-sm">download</span>
                                    <?php
                                    // Always offer a (re-)download so a missing, broken, or outdated
                                    // binary can be replaced — never gate this on "installed".
                                    if ($status['cli_functional']) {
                                        \esc_html_e('Re-download / Update CLI', 'proto-blocks');
                                    } else {
                                        \esc_html_e('Download Tailwind CLI', 'proto-blocks');
                                    }
                                    ?>
                                </button>
                                <?php endif; ?>
                                <button type="button" id="compile-tailwind" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors disabled:pb-opacity-50 disabled:pb-cursor-not-allowed" <?php echo ($status['engine'] === 'browser' || $status['cli_functional']) ? '' : 'disabled'; ?>>
                                    <span class="material-icons-outlined pb-text-sm">sync</span>
                                    <?php \esc_html_e('Compile CSS', 'proto-blocks'); ?>
                                </button>
                                <button type="button" id="clear-cache" class="pb-bg-white pb-border pb-border-gray-300 pb-text-gray-700 hover:pb-bg-gray-50 pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors">
                                    <span class="material-icons-outlined pb-text-sm">delete_outline</span>
                                    <?php \esc_html_e('Clear Cache', 'proto-blocks'); ?>
                                </button>
                            </div>
                            <div id="tailwind-message" class="pb-mt-4 pb-p-3 pb-rounded pb-text-sm" style="display: none;"></div>
                        </div>
                    </div>
                </div>

                <!-- Theme Configuration (file-based, read-only) -->
                <div class="tailwind-option pb-mt-8 pb-pt-6 pb-border-t pb-border-border-light" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                    <h3 class="pb-font-semibold pb-text-lg pb-mb-2"><?php \esc_html_e('Theme Configuration', 'proto-blocks'); ?></h3>
                    <p class="pb-text-text-muted-light pb-text-sm pb-mb-4">
                        <?php \esc_html_e('Tailwind theme tokens (@theme block) are read from a CSS file in the active theme. Edit the file directly and commit it to your repo -- nothing is stored in the database.', 'proto-blocks'); ?>
                    </p>

                    <div class="pb-bg-gray-50 pb-border pb-border-gray-200 pb-rounded-lg pb-p-4 pb-mb-4">
                        <div class="pb-flex pb-flex-wrap pb-items-center pb-gap-2 pb-mb-2">
                            <span class="pb-text-sm pb-font-semibold"><?php \esc_html_e('Theme file:', 'proto-blocks'); ?></span>
                            <code class="pb-text-xs pb-font-mono pb-bg-white pb-px-2 pb-py-1 pb-rounded pb-border pb-border-gray-200"><?php echo \esc_html($configData['path']); ?></code>
                            <?php if ($configData['exists']): ?>
                                <span class="pb-text-xs pb-bg-green-100 pb-text-green-800 pb-px-2 pb-py-0.5 pb-rounded"><?php \esc_html_e('found', 'proto-blocks'); ?></span>
                            <?php else: ?>
                                <span class="pb-text-xs pb-bg-yellow-100 pb-text-yellow-800 pb-px-2 pb-py-0.5 pb-rounded"><?php \esc_html_e('not found', 'proto-blocks'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="pb-text-xs pb-text-text-muted-light pb-mb-3">
                            <?php
                            printf(
                                /* translators: %s: filter name in <code> */
                                \esc_html__('Override the path with the %s filter.', 'proto-blocks'),
                                '<code>proto_blocks_theme_css_path</code>'
                            );
                            ?>
                        </p>
                        <?php if (!$configData['exists']): ?>
                            <button type="button" id="create-theme-file"
                                class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-3 pb-py-1.5 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-transition-colors">
                                <?php \esc_html_e('Create starter file', 'proto-blocks'); ?>
                            </button>
                            <span class="pb-text-xs pb-text-text-muted-light pb-ml-2">
                                <?php \esc_html_e('Writes a minimal tailwind-theme.css into the active theme so you can start editing tokens.', 'proto-blocks'); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($configData['preview'])): ?>
                        <details class="pb-mt-2">
                            <summary class="pb-text-sm pb-font-medium pb-cursor-pointer pb-py-1"><?php \esc_html_e('Show file contents', 'proto-blocks'); ?></summary>
                            <pre class="pb-w-full pb-border pb-border-gray-200 pb-rounded pb-p-3 pb-font-mono pb-text-xs pb-bg-gray-50 pb-overflow-auto pb-max-h-96 pb-mt-2"><?php echo \esc_html($configData['preview']); ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const nonce = $('#proto_blocks_tailwind_nonce').val();
            const actionPrefix = '<?php echo self::ACTION_PREFIX; ?>';
            const compileEngine = '<?php echo \esc_js($status['engine']); ?>';

            function showMessage(message, type) {
                const $msg = $('#tailwind-message');
                // Remove old type classes
                $msg.removeClass('pb-bg-green-100 pb-text-green-800 pb-bg-red-100 pb-text-red-800 pb-bg-yellow-100 pb-text-yellow-800');
                // Add new type classes
                if (type === 'success') {
                    $msg.addClass('pb-bg-green-100 pb-text-green-800');
                } else if (type === 'error') {
                    $msg.addClass('pb-bg-red-100 pb-text-red-800');
                } else {
                    $msg.addClass('pb-bg-yellow-100 pb-text-yellow-800');
                }
                $msg.text(message);
                $msg.slideDown();
                setTimeout(() => $msg.slideUp(), 5000);
            }

            function toggleOptions(show) {
                if (show) {
                    $('.tailwind-option').slideDown();
                } else {
                    $('.tailwind-option').slideUp();
                }
            }

            // Enable/Disable toggle
            $('#tailwind-enabled').on('change', function() {
                const enabled = $(this).is(':checked');
                $.post(ajaxurl, {
                    action: actionPrefix + 'toggle',
                    enabled: enabled ? 1 : 0,
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        toggleOptions(enabled);
                        showMessage(response.data.message, 'success');
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                });
            });

            // Mode change
            $('input[name="tailwind-mode"]').on('change', function() {
                $.post(ajaxurl, {
                    action: actionPrefix + 'set_mode',
                    mode: $(this).val(),
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                });
            });

            // Engine change
            $('input[name="tailwind-engine"]').on('change', function() {
                $.post(ajaxurl, {
                    action: actionPrefix + 'set_engine',
                    engine: $(this).val(),
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        // Reload so the "currently using" line + compile path reflect the new engine.
                        setTimeout(function() { location.reload(); }, 600);
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                });
            });

            // Disable Global Styles toggle
            $('#disable-global-styles').on('change', function() {
                const disabled = $(this).is(':checked');
                $.post(ajaxurl, {
                    action: actionPrefix + 'toggle_global_styles',
                    disabled: disabled ? 1 : 0,
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                });
            });

            // Download CLI
            $('#download-cli').on('click', function() {
                const $btn = $(this);
                const originalHtml = $btn.html();
                $btn.prop('disabled', true).text('<?php \esc_html_e('Downloading...', 'proto-blocks'); ?>');

                $.post(ajaxurl, {
                    action: actionPrefix + 'download_cli',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        location.reload();
                    } else {
                        showMessage(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                });
            });

            // Compile
            $('#compile-tailwind').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true);
                $btn.find('.material-icons-outlined').addClass('spin');

                if (compileEngine === 'browser') {
                    window.ProtoBlocksTailwind.runBrowserCompile({
                        ajaxUrl: ajaxurl,
                        actionPrefix: actionPrefix,
                        nonce: nonce
                    }).then(function(result) {
                        showMessage(result.message, result.success ? 'success' : 'error');
                        if (result.success) {
                            location.reload();
                        } else {
                            $btn.prop('disabled', false);
                            $btn.find('.material-icons-outlined').removeClass('spin');
                        }
                    }).catch(function(err) {
                        showMessage(String(err && err.message ? err.message : err), 'error');
                        $btn.prop('disabled', false);
                        $btn.find('.material-icons-outlined').removeClass('spin');
                    });
                    return;
                }

                $.post(ajaxurl, {
                    action: actionPrefix + 'compile',
                    nonce: nonce
                }, function(response) {
                    $btn.prop('disabled', false);
                    $btn.find('.material-icons-outlined').removeClass('spin');

                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        $('#last-compiled').text('<?php \esc_html_e('Just now', 'proto-blocks'); ?>');
                        if (response.data.css_size) {
                            $('#css-size').text(response.data.css_size_formatted);
                        }
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                });
            });

            // Clear cache
            $('#clear-cache').on('click', function() {
                if (!confirm('<?php \esc_html_e('Are you sure you want to clear the Tailwind cache?', 'proto-blocks'); ?>')) {
                    return;
                }

                $.post(ajaxurl, {
                    action: actionPrefix + 'clear_cache',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        $('#css-size').text('0 B');
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                });
            });

            // Theme config UI is read-only. The only mutating action is
            // 'Create starter file', shown when the file is missing.
            $('#create-theme-file').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true).text('<?php \esc_html_e('Creating...', 'proto-blocks'); ?>');

                $.post(ajaxurl, {
                    action: actionPrefix + 'create_theme_file',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        // Reload to surface the now-existing file preview.
                        setTimeout(() => location.reload(), 800);
                    } else {
                        showMessage(response.data.message, 'error');
                        $btn.prop('disabled', false).text('<?php \esc_html_e('Create starter file', 'proto-blocks'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle enable/disable toggle
     */
    public function handleToggle(): void
    {
        $this->verifyNonce();

        $enabled = !empty($_POST['enabled']);

        if ($enabled) {
            $this->manager->enable();
            $message = \__('Tailwind CSS support enabled.', 'proto-blocks');
        } else {
            $this->manager->disable();
            $message = \__('Tailwind CSS support disabled.', 'proto-blocks');
        }

        \wp_send_json_success(['message' => $message]);
    }

    /**
     * Handle mode change
     */
    public function handleSetMode(): void
    {
        $this->verifyNonce();

        $mode = \sanitize_text_field($_POST['mode'] ?? '');

        if ($this->manager->setMode($mode)) {
            \wp_send_json_success([
                'message' => sprintf(\__('Compilation mode set to %s.', 'proto-blocks'), $mode),
            ]);
        } else {
            \wp_send_json_error([
                'message' => \__('Invalid mode specified.', 'proto-blocks'),
            ]);
        }
    }

    /**
     * Handle compile-engine change
     */
    public function handleSetEngine(): void
    {
        $this->verifyNonce();

        $engine = \sanitize_text_field($_POST['engine'] ?? '');

        if ($this->manager->setEngine($engine)) {
            \wp_send_json_success([
                'message' => sprintf(\__('Compile engine set to %s.', 'proto-blocks'), $engine),
            ]);
        } else {
            \wp_send_json_error([
                'message' => \__('Invalid engine specified.', 'proto-blocks'),
            ]);
        }
    }

    /**
     * Handle toggle global styles
     */
    public function handleToggleGlobalStyles(): void
    {
        $this->verifyNonce();

        $disabled = !empty($_POST['disabled']);

        $this->manager->setDisableGlobalStyles($disabled);

        if ($disabled) {
            $message = \__('WordPress global styles will be disabled on frontend.', 'proto-blocks');
        } else {
            $message = \__('WordPress global styles will be loaded normally.', 'proto-blocks');
        }

        \wp_send_json_success(['message' => $message]);
    }

    /**
     * Handle compile request
     */
    public function handleCompile(): void
    {
        $this->verifyNonce();

        $result = $this->manager->compile();

        if ($result['success']) {
            $cache = $this->manager->getCache();
            \wp_send_json_success([
                'message' => $result['message'],
                'css_size' => $cache->getSize(),
                'css_size_formatted' => $cache->getFormattedSize(),
            ]);
        } else {
            \wp_send_json_error([
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * Handle cache clear request
     */
    public function handleClearCache(): void
    {
        $this->verifyNonce();

        $result = $this->manager->clearCache(false);

        if ($result['success']) {
            \wp_send_json_success(['message' => $result['message']]);
        } else {
            \wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * Handle CLI download request
     */
    public function handleDownloadCli(): void
    {
        $this->verifyNonce();

        $result = $this->manager->getBinaryManager()->download(true);

        if ($result['success']) {
            \wp_send_json_success(['message' => $result['message']]);
        } else {
            \wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * Return the inputs the browser compiler needs: the generated input.css
     * and the aggregated block content to scan. Pure PHP (no exec).
     */
    public function handleGetCompileInputs(): void
    {
        $this->verifyNonce();

        $scanner = $this->manager->getScanner();
        $scanner->refresh();

        $content = $scanner->scanAllBlocks();
        // The browser scans `content` directly, so reference it by a stable
        // virtual name rather than a real file path.
        $inputCss = $this->manager->getConfigEditor()->generateInputCss('proto-blocks-content.html');

        \wp_send_json_success([
            'inputCss' => $inputCss,
            'content' => $content,
            'hash' => $scanner->getContentHash(),
        ]);
    }

    /**
     * Receive browser-compiled CSS, scope + save it, and record the hash.
     */
    public function handleStoreCss(): void
    {
        $this->verifyNonce();

        // CSS can contain characters WP slashes on input; unslash before use.
        $css = isset($_POST['css']) ? \wp_unslash((string) $_POST['css']) : '';
        $hash = isset($_POST['hash']) ? \sanitize_text_field((string) $_POST['hash']) : '';

        $compiler = new \ProtoBlocks\Tailwind\BrowserCompiler(
            $this->manager->getScoper(),
            $this->manager->getCache()
        );

        if (!$compiler->store($css)) {
            \wp_send_json_error([
                'message' => \__('No CSS was produced by the browser compiler.', 'proto-blocks'),
            ]);
            return; // wp_send_json_error exits, but be explicit (and testable).
        }

        // Record the content hash the same way the CLI path does, so
        // Manager::needsRecompilation() (which compares settings['content_hash'])
        // stays consistent across both compile engines.
        if ($hash !== '') {
            $this->manager->getCache()->saveHash($hash);
        }
        $this->manager->updateSettings([
            'last_compiled' => time(),
            'content_hash' => $hash !== '' ? $hash : null,
        ]);

        $cache = $this->manager->getCache();
        \wp_send_json_success([
            'message' => \__('Tailwind CSS compiled in the browser and saved.', 'proto-blocks'),
            'css_size' => $cache->getSize(),
            'css_size_formatted' => $cache->getFormattedSize(),
        ]);
    }

    /**
     * Handle the "Create starter file" action: writes a minimal
     * tailwind-theme.css into the active theme so developers don't have
     * to remember the file name / template / location.
     */
    public function handleCreateThemeFile(): void
    {
        $this->verifyNonce();

        $configEditor = $this->manager->getConfigEditor();
        $path         = $configEditor->getThemeCssPath();

        if (file_exists($path)) {
            \wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: filesystem path */
                    \__('Theme file already exists at %s -- not overwriting.', 'proto-blocks'),
                    $path
                ),
            ]);
        }

        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            \wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: directory path */
                    \__('Active theme directory is not writable: %s', 'proto-blocks'),
                    $dir
                ),
            ]);
        }

        $contents = $this->getStarterThemeCss();

        $bytes = file_put_contents($path, $contents);
        if ($bytes === false) {
            \wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: filesystem path */
                    \__('Failed to write theme file at %s.', 'proto-blocks'),
                    $path
                ),
            ]);
        }

        \wp_send_json_success([
            'message' => sprintf(
                /* translators: %s: filesystem path */
                \__('Created starter theme file at %s. Edit it in your theme repo and recompile.', 'proto-blocks'),
                $path
            ),
        ]);
    }

    /**
     * Starter @theme template copied into the active theme on the
     * "Create starter file" action. Intentionally minimal -- it's a
     * jumping-off point, not a full color system.
     */
    private function getStarterThemeCss(): string
    {
        return <<<'CSS'
/*
 * Tailwind v4 design tokens.
 *
 * Each --color-*, --font-*, --shadow-* etc. exposed here is compiled into a
 * matching utility class (bg-brand, text-brand, font-display, ...) on every
 * Tailwind recompile. Edit this file in your theme repo -- nothing is read
 * from the database.
 *
 * Recompile happens on any front-end page load, or run:
 *   wp eval 'ProtoBlocks\Core\Plugin::getInstance()->getTailwindManager()->compile();'
 *
 * Docs:
 *   https://tailwindcss.com/docs/theme
 */

@theme {
  /* Brand colors -- replace with yours */
  --color-brand:     #3B82F6;
  --color-brand-700: #1D4ED8;

  /* Typography */
  --font-display: "Inter", ui-sans-serif, system-ui, sans-serif;
  --font-body:    "Inter", ui-sans-serif, system-ui, sans-serif;
}
CSS;
    }

    /**
     * Handle status request
     */
    public function handleGetStatus(): void
    {
        $this->verifyNonce();
        \wp_send_json_success($this->manager->getStatus());
    }

    /**
     * Verify nonce
     */
    private function verifyNonce(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_send_json_error(['message' => \__('Permission denied.', 'proto-blocks')]);
        }

        $nonce = $_POST['nonce'] ?? '';
        if (!\wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            \wp_send_json_error(['message' => \__('Security check failed.', 'proto-blocks')]);
        }
    }

    /**
     * Get nonce action name
     */
    public static function getNonceAction(): string
    {
        return self::NONCE_ACTION;
    }
}
