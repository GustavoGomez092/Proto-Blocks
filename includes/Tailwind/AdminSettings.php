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
        <div class="proto-blocks-tailwind-settings" id="proto-blocks-tailwind-settings">
            <h2><?php \esc_html_e('Tailwind CSS Support', 'proto-blocks'); ?></h2>
            <p class="description">
                <?php \esc_html_e('Enable Tailwind CSS utility classes in your Proto Blocks without requiring a Node.js build step.', 'proto-blocks'); ?>
            </p>

            <?php \wp_nonce_field(self::NONCE_ACTION, 'proto_blocks_tailwind_nonce'); ?>

            <!-- Enable/Disable Toggle -->
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="tailwind-enabled"><?php \esc_html_e('Enable Tailwind CSS', 'proto-blocks'); ?></label>
                    </th>
                    <td>
                        <label class="proto-blocks-toggle">
                            <input type="checkbox" id="tailwind-enabled" <?php \checked($status['enabled']); ?>>
                            <span class="proto-blocks-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php \esc_html_e('When enabled, Tailwind utility classes will be compiled and loaded for all Proto Blocks.', 'proto-blocks'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Compilation Mode -->
                <tr class="tailwind-option" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                    <th scope="row"><?php \esc_html_e('Compilation Mode', 'proto-blocks'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="tailwind-mode" value="cached" <?php \checked($settings['mode'], 'cached'); ?>>
                                <?php \esc_html_e('Cached (Production)', 'proto-blocks'); ?>
                            </label>
                            <p class="description">
                                <?php \esc_html_e('CSS is compiled once and served from cache. Use the "Compile" button to update.', 'proto-blocks'); ?>
                            </p>
                            <br>
                            <label>
                                <input type="radio" name="tailwind-mode" value="on_reload" <?php \checked($settings['mode'], 'on_reload'); ?>>
                                <?php \esc_html_e('On-Reload (Development)', 'proto-blocks'); ?>
                            </label>
                            <p class="description">
                                <?php \esc_html_e('CSS is recompiled on each page load when logged in as admin. Not recommended for production.', 'proto-blocks'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>

                <!-- Disable Global Styles -->
                <tr class="tailwind-option" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                    <th scope="row">
                        <label for="disable-global-styles"><?php \esc_html_e('Disable WP Global Styles', 'proto-blocks'); ?></label>
                    </th>
                    <td>
                        <label class="proto-blocks-toggle">
                            <input type="checkbox" id="disable-global-styles" <?php \checked($status['disable_global_styles'] ?? false); ?>>
                            <span class="proto-blocks-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php \esc_html_e('Disable WordPress global styles to prevent conflicts with Tailwind CSS. This removes the inline CSS that WordPress adds for theme.json styles.', 'proto-blocks'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Status Display -->
                <tr class="tailwind-option" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                    <th scope="row"><?php \esc_html_e('Status', 'proto-blocks'); ?></th>
                    <td>
                        <div class="proto-blocks-status-grid">
                            <div class="status-item">
                                <span class="status-label"><?php \esc_html_e('CLI Status:', 'proto-blocks'); ?></span>
                                <span class="status-value" id="cli-status">
                                    <?php if ($status['cli_installed']): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                        <?php printf(\esc_html__('Installed (v%s)', 'proto-blocks'), \esc_html($status['cli_version'] ?? 'unknown')); ?>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                                        <?php \esc_html_e('Not installed', 'proto-blocks'); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <span class="status-label"><?php \esc_html_e('Last Compiled:', 'proto-blocks'); ?></span>
                                <span class="status-value" id="last-compiled">
                                    <?php
                                    if ($settings['last_compiled']) {
                                        echo \esc_html(
                                            sprintf(
                                                \__('%s ago', 'proto-blocks'),
                                                \human_time_diff($settings['last_compiled'])
                                            )
                                        );
                                    } else {
                                        \esc_html_e('Never', 'proto-blocks');
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <span class="status-label"><?php \esc_html_e('CSS Size:', 'proto-blocks'); ?></span>
                                <span class="status-value" id="css-size">
                                    <?php echo \esc_html($this->manager->getCache()->getFormattedSize()); ?>
                                </span>
                            </div>
                        </div>
                    </td>
                </tr>

                <!-- Actions -->
                <tr class="tailwind-option" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                    <th scope="row"><?php \esc_html_e('Actions', 'proto-blocks'); ?></th>
                    <td>
                        <div class="proto-blocks-actions">
                            <?php if (!$status['cli_installed']): ?>
                                <button type="button" class="button button-primary" id="download-cli">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php \esc_html_e('Download Tailwind CLI', 'proto-blocks'); ?>
                                </button>
                            <?php endif; ?>

                            <button type="button" class="button button-primary" id="compile-tailwind" <?php echo $status['cli_installed'] ? '' : 'disabled'; ?>>
                                <span class="dashicons dashicons-update"></span>
                                <?php \esc_html_e('Compile CSS', 'proto-blocks'); ?>
                            </button>

                            <button type="button" class="button" id="clear-cache">
                                <span class="dashicons dashicons-trash"></span>
                                <?php \esc_html_e('Clear Cache', 'proto-blocks'); ?>
                            </button>
                        </div>
                        <div id="tailwind-message" class="notice inline" style="display: none; margin-top: 10px;">
                            <p></p>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Theme Configuration -->
            <div class="tailwind-option tailwind-config-section" <?php echo $status['enabled'] ? '' : 'style="display:none;"'; ?>>
                <h3><?php \esc_html_e('Theme Configuration', 'proto-blocks'); ?></h3>
                <p class="description">
                    <?php \esc_html_e('Customize your Tailwind theme using CSS variables. Changes require recompilation.', 'proto-blocks'); ?>
                </p>

                <div class="config-editor-toolbar">
                    <label for="preset-select"><?php \esc_html_e('Presets:', 'proto-blocks'); ?></label>
                    <select id="preset-select">
                        <option value=""><?php \esc_html_e('-- Select a preset --', 'proto-blocks'); ?></option>
                        <?php foreach ($configData['presets'] as $key => $preset): ?>
                            <option value="<?php echo \esc_attr($key); ?>">
                                <?php echo \esc_html($preset['name']); ?> - <?php echo \esc_html($preset['description']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="reset-config">
                        <?php \esc_html_e('Reset to Default', 'proto-blocks'); ?>
                    </button>
                </div>

                <div class="config-editor-wrapper">
                    <textarea id="theme-config" rows="15" class="large-text code"><?php echo \esc_textarea($configData['current']); ?></textarea>
                </div>

                <div class="config-editor-footer">
                    <button type="button" class="button button-primary" id="save-config">
                        <?php \esc_html_e('Save Configuration', 'proto-blocks'); ?>
                    </button>
                    <span class="config-status" id="config-status"></span>
                </div>
            </div>
        </div>

        <style>
            .proto-blocks-tailwind-settings {
                max-width: 800px;
            }
            .proto-blocks-toggle {
                position: relative;
                display: inline-block;
                width: 50px;
                height: 26px;
            }
            .proto-blocks-toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .proto-blocks-toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .4s;
                border-radius: 26px;
            }
            .proto-blocks-toggle-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 4px;
                bottom: 4px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
            }
            .proto-blocks-toggle input:checked + .proto-blocks-toggle-slider {
                background-color: #2271b1;
            }
            .proto-blocks-toggle input:checked + .proto-blocks-toggle-slider:before {
                transform: translateX(24px);
            }
            .proto-blocks-status-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 10px;
            }
            .status-item {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .status-label {
                font-weight: 600;
            }
            .proto-blocks-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .proto-blocks-actions .button .dashicons {
                margin-right: 5px;
                vertical-align: middle;
                line-height: 1.4;
            }
            .tailwind-config-section {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #dcdcde;
            }
            .config-editor-toolbar {
                display: flex;
                gap: 10px;
                align-items: center;
                margin-bottom: 10px;
            }
            .config-editor-wrapper {
                margin-bottom: 10px;
            }
            .config-editor-wrapper textarea {
                font-family: Consolas, Monaco, monospace;
                font-size: 13px;
                line-height: 1.5;
            }
            .config-editor-footer {
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .config-status {
                font-style: italic;
                color: #666;
            }
            .notice.inline {
                padding: 8px 12px;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            const nonce = $('#proto_blocks_tailwind_nonce').val();
            const actionPrefix = '<?php echo self::ACTION_PREFIX; ?>';

            function showMessage(message, type) {
                const $msg = $('#tailwind-message');
                $msg.removeClass('notice-success notice-error notice-warning')
                    .addClass('notice-' + type)
                    .find('p').text(message);
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
