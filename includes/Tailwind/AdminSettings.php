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
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'compile', [$this, 'handleCompile']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'clear_cache', [$this, 'handleClearCache']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'download_cli', [$this, 'handleDownloadCli']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'save_config', [$this, 'handleSaveConfig']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'load_preset', [$this, 'handleLoadPreset']);
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
                                        <?php if ($status['cli_installed']): ?>
                                            <span class="material-icons-outlined pb-text-green-600 pb-text-lg">check_circle</span>
                                            <span class="pb-text-sm"><?php printf(\esc_html__('v%s', 'proto-blocks'), \esc_html($status['cli_version'] ?? '?')); ?></span>
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
                                <?php if (!$status['cli_installed']): ?>
                                    <button type="button" id="download-cli" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors">
                                        <span class="material-icons-outlined pb-text-sm">download</span>
                                        <?php \esc_html_e('Download Tailwind CLI', 'proto-blocks'); ?>
                                    </button>
                                <?php endif; ?>
                                <button type="button" id="compile-tailwind" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors disabled:pb-opacity-50 disabled:pb-cursor-not-allowed" <?php echo $status['cli_installed'] ? '' : 'disabled'; ?>>
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

                <!-- Theme Configuration -->
                <div class="tailwind-option pb-mt-8 pb-pt-6 pb-border-t pb-border-border-light" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                    <h3 class="pb-font-semibold pb-text-lg pb-mb-2"><?php \esc_html_e('Theme Configuration', 'proto-blocks'); ?></h3>
                    <p class="pb-text-text-muted-light pb-text-sm pb-mb-4">
                        <?php \esc_html_e('Customize your Tailwind theme using CSS variables. Changes require recompilation.', 'proto-blocks'); ?>
                    </p>

                    <div class="pb-flex pb-flex-wrap pb-items-center pb-gap-3 pb-mb-4">
                        <label for="preset-select" class="pb-text-sm pb-font-medium"><?php \esc_html_e('Presets:', 'proto-blocks'); ?></label>
                        <select id="preset-select" class="pb-border pb-border-gray-300 pb-rounded pb-px-3 pb-py-1.5 pb-text-sm">
                            <option value=""><?php \esc_html_e('-- Select a preset --', 'proto-blocks'); ?></option>
                            <?php foreach ($configData['presets'] as $key => $preset): ?>
                                <option value="<?php echo \esc_attr($key); ?>">
                                    <?php echo \esc_html($preset['name']); ?> - <?php echo \esc_html($preset['description']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="reset-config" class="pb-bg-white pb-border pb-border-gray-300 pb-text-gray-700 hover:pb-bg-gray-50 pb-px-3 pb-py-1.5 pb-rounded pb-text-sm pb-font-medium pb-transition-colors">
                            <?php \esc_html_e('Reset to Default', 'proto-blocks'); ?>
                        </button>
                    </div>

                    <textarea id="theme-config" rows="12" class="pb-w-full pb-border pb-border-gray-300 pb-rounded-lg pb-p-4 pb-font-mono pb-text-sm pb-bg-gray-50 focus:pb-border-primary focus:pb-ring-1 focus:pb-ring-primary pb-outline-none"><?php echo \esc_textarea($configData['current']); ?></textarea>

                    <div class="pb-flex pb-items-center pb-gap-4 pb-mt-4">
                        <button type="button" id="save-config" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-transition-colors">
                            <?php \esc_html_e('Save Configuration', 'proto-blocks'); ?>
                        </button>
                        <span id="config-status" class="pb-text-sm pb-text-green-600 pb-italic"></span>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const nonce = $('#proto_blocks_tailwind_nonce').val();
            const actionPrefix = '<?php echo self::ACTION_PREFIX; ?>';

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
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> <?php \esc_html_e('Download Tailwind CLI', 'proto-blocks'); ?>');
                    }
                });
            });

            // Compile
            $('#compile-tailwind').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true);
                $btn.find('.dashicons').addClass('spin');

                $.post(ajaxurl, {
                    action: actionPrefix + 'compile',
                    nonce: nonce
                }, function(response) {
                    $btn.prop('disabled', false);
                    $btn.find('.dashicons').removeClass('spin');

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

            // Save config
            $('#save-config').on('click', function() {
                const $btn = $(this);
                const config = $('#theme-config').val();
                $btn.prop('disabled', true);

                $.post(ajaxurl, {
                    action: actionPrefix + 'save_config',
                    config: config,
                    nonce: nonce
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $('#config-status').text('<?php \esc_html_e('Saved!', 'proto-blocks'); ?>').fadeIn().delay(2000).fadeOut();
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                });
            });

            // Load preset
            $('#preset-select').on('change', function() {
                const preset = $(this).val();
                if (!preset) return;

                $.post(ajaxurl, {
                    action: actionPrefix + 'load_preset',
                    preset: preset,
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        $('#theme-config').val(response.data.config);
                        $('#preset-select').val('');
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                });
            });

            // Reset config
            $('#reset-config').on('click', function() {
                if (!confirm('<?php \esc_html_e('Reset to default configuration?', 'proto-blocks'); ?>')) {
                    return;
                }
                $('#theme-config').val(<?php echo json_encode($configData['default']); ?>);
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
     * Handle config save request
     */
    public function handleSaveConfig(): void
    {
        $this->verifyNonce();

        $config = \wp_unslash($_POST['config'] ?? '');

        $configEditor = $this->manager->getConfigEditor();
        $validation = $configEditor->validateConfig($config);

        if (!$validation['valid']) {
            \wp_send_json_error([
                'message' => implode(' ', $validation['errors']),
            ]);
        }

        if ($configEditor->saveThemeConfig($config)) {
            \wp_send_json_success([
                'message' => \__('Configuration saved.', 'proto-blocks'),
            ]);
        } else {
            \wp_send_json_error([
                'message' => \__('Failed to save configuration.', 'proto-blocks'),
            ]);
        }
    }

    /**
     * Handle preset load request
     */
    public function handleLoadPreset(): void
    {
        $this->verifyNonce();

        $preset = \sanitize_text_field($_POST['preset'] ?? '');
        $config = $this->manager->getConfigEditor()->loadPreset($preset);

        if ($config !== null) {
            \wp_send_json_success(['config' => $config]);
        } else {
            \wp_send_json_error([
                'message' => \__('Invalid preset.', 'proto-blocks'),
            ]);
        }
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
