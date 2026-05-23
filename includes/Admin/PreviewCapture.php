<?php
/**
 * Preview Capture admin page.
 *
 * Lets administrators auto-generate inserter preview PNGs for every
 * registered Proto-Block in one pass:
 *
 *   1. Lists all registered blocks with their current preview status
 *      (present / missing) and a thumbnail of the existing PNG.
 *   2. JS captures each block in an off-screen iframe using
 *      html2canvas-pro at a fixed 1280px render width and POSTs the
 *      resulting base64 PNG back to a REST endpoint.
 *   3. The REST endpoint writes the PNG to `{block-folder}/preview.png`
 *      so it survives across themes and gets picked up automatically
 *      by SchemaReader::detectPreviewImage().
 *
 * The capture iframe is fed by this same class via a "render" mode --
 * hitting the page with `?proto_blocks_render=<block>&_wpnonce=<n>`
 * outputs a minimal HTML document containing just that block plus all
 * front-end theme + plugin styles, then exits. That way the iframe
 * loads a real, fully-styled rendering of the block without us having
 * to inject CSS manually.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Template\Engine;

class PreviewCapture
{
    public const SLUG           = 'proto-blocks-previews';
    public const RENDER_NONCE   = 'proto_blocks_capture_render';
    public const RENDER_ACTION  = 'proto_blocks_capture_render';
    public const CAPABILITY     = 'manage_options';

    public function addMenuPage(): void
    {
        add_submenu_page(
            AdminPage::SLUG,
            __('Preview Capture', 'proto-blocks'),
            __('Preview Capture', 'proto-blocks'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'route']
        );
    }

    /**
     * Page entry point -- renders the capture admin UI. The iframe
     * render target is served via admin-ajax.php (see handleRender)
     * because admin pages emit chrome (admin bar, sidebar, menu)
     * before the page callback runs, which pollutes the iframe doc.
     */
    public function route(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'proto-blocks'));
        }
        $this->renderAdminUi();
    }

    /**
     * admin-ajax handler -- outputs the minimal "just this block"
     * HTML doc that the capture iframe loads. Bypasses WP admin
     * chrome entirely.
     */
    public function handleRender(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Permission denied.', 'proto-blocks'), '', ['response' => 403]);
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_key($_GET['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, self::RENDER_NONCE)) {
            wp_die(esc_html__('Invalid capture nonce.', 'proto-blocks'), '', ['response' => 403]);
        }

        $blockName = isset($_GET['block']) ? sanitize_text_field(wp_unslash($_GET['block'])) : '';
        if ($blockName === '') {
            wp_die(esc_html__('Missing block parameter.', 'proto-blocks'), '', ['response' => 400]);
        }

        $this->renderBlockDocument($blockName);
    }

    /**
     * Render the capture-UI admin page (table of blocks + buttons).
     */
    private function renderAdminUi(): void
    {
        $blocks = $this->collectBlocks();
        ?>
        <div class="wrap proto-blocks-capture">
            <h1><?php echo esc_html__('Proto-Blocks Preview Capture', 'proto-blocks'); ?></h1>
            <p class="proto-blocks-capture__intro">
                <?php echo esc_html__('Auto-generate inserter preview thumbnails for every registered Proto-Block. Captures use the live front-end render at 1280px width.', 'proto-blocks'); ?>
            </p>

            <div class="proto-blocks-capture__actions">
                <button type="button" class="button button-primary" data-action="capture-missing">
                    <?php echo esc_html__('Capture missing', 'proto-blocks'); ?>
                </button>
                <button type="button" class="button" data-action="capture-selected">
                    <?php echo esc_html__('Capture selected', 'proto-blocks'); ?>
                </button>
                <button type="button" class="button" data-action="capture-all">
                    <?php echo esc_html__('Recapture all', 'proto-blocks'); ?>
                </button>
                <span class="proto-blocks-capture__status" aria-live="polite"></span>
            </div>

            <table class="widefat striped proto-blocks-capture__table">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" data-select-all></td>
                        <th><?php echo esc_html__('Block', 'proto-blocks'); ?></th>
                        <th><?php echo esc_html__('Preview', 'proto-blocks'); ?></th>
                        <th><?php echo esc_html__('Status', 'proto-blocks'); ?></th>
                        <th><?php echo esc_html__('Action', 'proto-blocks'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($blocks as $block): ?>
                    <tr data-block-row="<?php echo esc_attr($block['name']); ?>"
                        data-has-preview="<?php echo $block['hasPreview'] ? '1' : '0'; ?>"
                        data-render-url="<?php echo esc_attr($block['renderUrl']); ?>">
                        <th scope="row" class="check-column">
                            <input type="checkbox" data-block-checkbox value="<?php echo esc_attr($block['name']); ?>">
                        </th>
                        <td>
                            <strong><?php echo esc_html($block['title']); ?></strong><br>
                            <code><?php echo esc_html($block['name']); ?></code>
                        </td>
                        <td class="proto-blocks-capture__thumb-cell">
                            <?php if ($block['previewUrl']): ?>
                            <img src="<?php echo esc_url($block['previewUrl']); ?>?v=<?php echo (int) ($block['previewMtime'] ?? 0); ?>"
                                alt="" class="proto-blocks-capture__thumb">
                            <?php else: ?>
                            <span class="proto-blocks-capture__thumb proto-blocks-capture__thumb--empty">
                                <?php echo esc_html__('— no preview —', 'proto-blocks'); ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="proto-blocks-capture__badge proto-blocks-capture__badge--<?php echo $block['hasPreview'] ? 'ok' : 'missing'; ?>">
                                <?php echo $block['hasPreview']
                                    ? esc_html__('Present', 'proto-blocks')
                                    : esc_html__('Missing', 'proto-blocks'); ?>
                            </span>
                            <span class="proto-blocks-capture__row-status" aria-live="polite"></span>
                        </td>
                        <td>
                            <button type="button" class="button button-small" data-action="capture-one"
                                data-block="<?php echo esc_attr($block['name']); ?>">
                                <?php echo esc_html__('Capture', 'proto-blocks'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($blocks)): ?>
                    <tr>
                        <td colspan="5">
                            <?php echo esc_html__('No Proto-Blocks are registered yet.', 'proto-blocks'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Hidden iframe used as the capture surface. JS swaps its
                 src for each block, runs html2canvas-pro against the
                 loaded document, then POSTs the resulting PNG back. -->
            <iframe class="proto-blocks-capture__frame"
                title="Preview render"
                aria-hidden="true"></iframe>
        </div>
        <?php
    }

    /**
     * Output a minimal HTML document containing just the requested
     * block, with the front-end theme + plugin styles enqueued. Used
     * as the iframe target during capture. Calls `exit` so WP's admin
     * chrome is never emitted.
     */
    private function renderBlockDocument(string $blockName): void
    {
        $registrar = Plugin::getInstance()->getRegistrar();
        $registered = $registrar->getRegisteredBlocks();
        if (!isset($registered[$blockName])) {
            status_header(404);
            echo esc_html__('Block not found.', 'proto-blocks');
            exit;
        }

        $block        = $registered[$blockName];
        $schema       = $block['schema'];
        $templatePath = $schema['protoBlocks']['templatePath'] ?? ($block['path'] . '/' . basename($block['path']) . '.php');

        // Use schema-declared default attributes so the block renders
        // its placeholder/example content (the same content authors
        // see before they fill anything in).
        $attributes = [];
        foreach (($block['attributes'] ?? []) as $attrName => $config) {
            if (array_key_exists('default', $config)) {
                $attributes[$attrName] = $config['default'];
            }
        }

        $engine = Plugin::getInstance()->getEngine();
        $html   = '<div class="proto-blocks-scope">'
                . $engine->renderPreview($templatePath, $attributes, $schema)
                . '</div>';

        // Build the minimal document. We deliberately bypass
        // get_header() / wp_head() so we don't paint the site nav /
        // banner / admin bar. Only the asset stack runs.
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=1280, initial-scale=1">
<title><?php echo esc_html($schema['title'] ?? $blockName); ?> — Preview</title>
<?php
// Force the WP enqueue system to think it's on a content page so the
// theme's enqueue_block_assets hook (which fires for both front end
// and editor canvas) wires up correctly.
do_action('wp_enqueue_scripts');
do_action('enqueue_block_assets');
wp_print_styles();
wp_print_head_scripts();
?>
<style>
  html, body { margin: 0; padding: 0; background: #ffffff; }
  body { width: 1280px; min-height: 1px; }
  /* Hide anything that might have slipped through (theme banners,
     intro overlays, sticky nav, admin bar). Capture surface only. */
  .oit-intro,
  .wp-block-template-part,
  header.wp-block-template-part,
  #wpadminbar { display: none !important; }
  body.admin-bar { margin-top: 0 !important; }
</style>
</head>
<body class="proto-blocks-capture-body">
<?php echo $html; // already trusted server-rendered output ?>
<?php
wp_print_footer_scripts();
?>
</body>
</html><?php
        exit;
    }

    /**
     * Build the block list for the admin UI.
     *
     * @return array<int, array{name:string,title:string,path:string,hasPreview:bool,previewUrl:?string,previewMtime:?int,renderUrl:string}>
     */
    private function collectBlocks(): array
    {
        $registrar  = Plugin::getInstance()->getRegistrar();
        $registered = $registrar->getRegisteredBlocks();
        $items      = [];

        foreach ($registered as $name => $block) {
            $path        = $block['path'];
            $previewPath = $this->findPreview($path);
            $previewUrl  = $previewPath ? $this->pathToUrl($previewPath) : null;
            $items[] = [
                'name'         => $name,
                'title'        => $block['schema']['title'] ?? $name,
                'path'         => $path,
                'hasPreview'   => (bool) $previewPath,
                'previewUrl'   => $previewUrl,
                'previewMtime' => $previewPath ? @filemtime($previewPath) : null,
                'renderUrl'    => $this->buildRenderUrl($name),
            ];
        }

        usort($items, static fn($a, $b) => strcmp($a['title'], $b['title']));

        return $items;
    }

    private function findPreview(string $blockDir): ?string
    {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $candidate = $blockDir . '/preview.' . $ext;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function pathToUrl(string $path): string
    {
        $themeDir = get_stylesheet_directory();
        $themeUri = get_stylesheet_directory_uri();
        if (str_starts_with($path, $themeDir)) {
            return $themeUri . substr($path, strlen($themeDir));
        }
        $pluginsDir = WP_PLUGIN_DIR;
        $pluginsUri = plugins_url();
        if (str_starts_with($path, $pluginsDir)) {
            return $pluginsUri . substr($path, strlen($pluginsDir));
        }
        return $path;
    }

    public function buildRenderUrl(string $blockName): string
    {
        return add_query_arg(
            [
                'action'   => self::RENDER_ACTION,
                'block'    => $blockName,
                '_wpnonce' => wp_create_nonce(self::RENDER_NONCE),
            ],
            admin_url('admin-ajax.php')
        );
    }

    /**
     * REST endpoint: persist a captured PNG.
     *
     * Resolves the block's folder via the Registrar so this works
     * across themes -- the file always lands next to block.json.
     */
    public function restCapture(\WP_REST_Request $request)
    {
        $blockName = (string) $request->get_param('block');
        $imageData = (string) $request->get_param('image');

        if ($blockName === '' || $imageData === '') {
            return new \WP_Error('proto_blocks_capture_invalid', __('Missing block or image.', 'proto-blocks'), ['status' => 400]);
        }

        $registrar  = Plugin::getInstance()->getRegistrar();
        $registered = $registrar->getRegisteredBlocks();
        if (!isset($registered[$blockName])) {
            return new \WP_Error('proto_blocks_capture_not_found', __('Block not registered.', 'proto-blocks'), ['status' => 404]);
        }

        // Strip "data:image/png;base64," prefix.
        if (!preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#i', $imageData, $matches)) {
            return new \WP_Error('proto_blocks_capture_bad_image', __('Unsupported image payload.', 'proto-blocks'), ['status' => 400]);
        }
        $ext   = strtolower($matches[1]) === 'jpg' ? 'jpeg' : strtolower($matches[1]);
        $bytes = base64_decode($matches[2], true);
        if ($bytes === false) {
            return new \WP_Error('proto_blocks_capture_decode', __('Failed to decode image.', 'proto-blocks'), ['status' => 400]);
        }

        // Always write to preview.png (the inserter convention). If a
        // preview.jpg was sitting in the folder we leave it alone --
        // SchemaReader checks PNG first so the new file wins.
        $targetPath = $registered[$blockName]['path'] . '/preview.png';
        $written    = file_put_contents($targetPath, $bytes);
        if ($written === false) {
            return new \WP_Error('proto_blocks_capture_write', __('Could not write file. Check folder permissions.', 'proto-blocks'), ['status' => 500]);
        }

        return rest_ensure_response([
            'success'    => true,
            'block'      => $blockName,
            'bytes'      => $written,
            'previewUrl' => $this->pathToUrl($targetPath),
            'mtime'      => filemtime($targetPath),
        ]);
    }

    public function restPermission(): bool
    {
        return current_user_can(self::CAPABILITY);
    }

    public function registerRestRoute(): void
    {
        register_rest_route('proto-blocks/v1', '/preview-capture', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restCapture'],
            'permission_callback' => [$this, 'restPermission'],
            'args'                => [
                'block' => ['required' => true, 'type' => 'string'],
                'image' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    /**
     * Enqueue capture-only assets (vendor + glue + admin styles).
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        // The hook suffix for a submenu is "proto-blocks_page_<slug>".
        if (!str_ends_with($hookSuffix, self::SLUG)) {
            return;
        }

        $base = plugins_url('', PROTO_BLOCKS_FILE);

        wp_enqueue_script(
            'html2canvas-pro',
            $base . '/assets/vendor/html2canvas-pro.min.js',
            [],
            '1.5.13',
            true
        );

        wp_enqueue_script(
            'proto-blocks-capture',
            $base . '/assets/admin/preview-capture.js',
            ['html2canvas-pro', 'wp-api-fetch'],
            $this->fileVersion('assets/admin/preview-capture.js'),
            true
        );

        wp_localize_script('proto-blocks-capture', 'protoBlocksCapture', [
            'restUrl' => esc_url_raw(rest_url('proto-blocks/v1/preview-capture')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'width'   => 1280,
        ]);

        wp_enqueue_style(
            'proto-blocks-capture',
            $base . '/assets/css/preview-capture.css',
            [],
            $this->fileVersion('assets/css/preview-capture.css')
        );
    }

    private function fileVersion(string $relative): string
    {
        $abs = plugin_dir_path(PROTO_BLOCKS_FILE) . $relative;
        return file_exists($abs) ? (string) filemtime($abs) : '1.0.0';
    }
}
