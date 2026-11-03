<?php
/**
 * Preview Capture admin page.
 *
 * Lets administrators auto-generate inserter preview PNGs for every
 * registered Proto-Block in one pass:
 *
 *   1. Lists all registered blocks with their current preview status
 *      (present / missing) and a thumbnail of the existing PNG.
 *   2. The React screen (src/admin/screens/PreviewsScreen.tsx) captures
 *      each block in an off-screen iframe using html2canvas-pro at a
 *      fixed 1280px render width and POSTs the resulting base64 PNG back
 *      to a REST endpoint.
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

use ProtoBlocks\API\AdminRestController;
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
        $hook = add_submenu_page(
            AdminPage::SLUG,
            __('Preview Capture', 'proto-blocks'),
            __('Preview Capture', 'proto-blocks'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'route']
        );
        if (is_string($hook)) {
            Plugin::getInstance()->getAdminApp()?->addScreenHook($hook, 'previews');
        }
    }

    /**
     * Page entry point -- the React Preview Capture screen
     * (src/admin/screens/PreviewsScreen.tsx). The iframe render target is
     * served via admin-ajax.php (see handleRender) because admin pages emit
     * chrome (admin bar, sidebar, menu) before the page callback runs, which
     * pollutes the iframe doc.
     */
    public function route(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'proto-blocks'));
        }
        Plugin::getInstance()->getAdminApp()?->renderScreen('previews');
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
     * The attributes a capture renders with. Schema defaults alone give an
     * empty thumbnail for most real blocks (no heading, no image: the author
     * fills those in), so the capture prefers content that exists:
     *
     *   1. `example.attributes` from block.json, when the author wrote one.
     *   2. The block's first instance on the site: the most recently modified
     *      published post of any type, then the theme's template and part
     *      files (header/footer blocks live there).
     *   3. Schema defaults.
     *
     * Instance and example values are merged over the defaults, so a control
     * the instance never set still renders as the schema says.
     *
     * @param array{path:string,schema:array,attributes:array} $block
     */
    private function captureAttributes(string $blockName, array $block): array
    {
        $defaults = [];
        foreach (($block['attributes'] ?? []) as $attrName => $config) {
            if (is_array($config) && array_key_exists('default', $config)) {
                $defaults[$attrName] = $config['default'];
            }
        }

        $example = $block['schema']['example']['attributes'] ?? null;
        if (is_array($example) && $example !== []) {
            $this->captureSource = 'example';
            return array_merge($defaults, $example);
        }

        // The registry is keyed by the short slug; content holds the full block
        // name from block.json (`<!-- wp:proto-blocks/hero -->`).
        $fullName = (string) ($block['schema']['name'] ?? '');
        if ($fullName === '') {
            $fullName = str_contains($blockName, '/') ? $blockName : 'proto-blocks/' . $blockName;
        }
        $instance = $this->findInstanceAttributes($fullName);
        if ($instance !== null) {
            $this->captureSource = 'instance';
            return array_merge($defaults, $instance);
        }

        $this->captureSource = 'defaults';
        return $defaults;
    }

    /** Where the last capture's attributes came from: example, instance or defaults (shown in the render document). */
    private string $captureSource = 'defaults';

    /**
     * Attributes of the block's first instance in published content or in
     * the theme's template/part files; null when the block is used nowhere.
     */
    private function findInstanceAttributes(string $blockName): ?array
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like('<!-- wp:' . $blockName) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_content, post_author FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type NOT IN ('revision', 'attachment', 'wp_navigation')
               AND post_content LIKE %s
             ORDER BY post_modified DESC
             LIMIT 20",
            $like
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            $content = (string) ($row['post_content'] ?? '');
            $attrs = self::firstBlockAttributes(parse_blocks($content), $blockName);
            if ($attrs !== null) {
                // The render document echoes the template's output unescaped on
                // the admin origin. Content saved by a user without
                // `unfiltered_html` was already kses-filtered as post_content,
                // but the JSON inside the block comment is not: re-apply the
                // same policy to every string attribute before it reaches the
                // template.
                $author = (int) ($row['post_author'] ?? 0);
                if ($author <= 0 || !user_can($author, 'unfiltered_html')) {
                    $attrs = self::ksesAttributes($attrs);
                }
                return $attrs;
            }
        }

        $dirs = array_unique([get_stylesheet_directory(), get_template_directory()]);
        foreach ($dirs as $dir) {
            foreach (array_merge(glob($dir . '/parts/*.html') ?: [], glob($dir . '/templates/*.html') ?: []) as $file) {
                $markup = file_get_contents($file);
                if ($markup === false || strpos($markup, '<!-- wp:' . $blockName) === false) {
                    continue;
                }
                $attrs = self::firstBlockAttributes(parse_blocks($markup), $blockName);
                if ($attrs !== null) {
                    return $attrs;
                }
            }
        }

        return null;
    }

    /**
     * Run wp_kses_post over every string in a (nested) attribute array.
     *
     * @param array<mixed> $attrs
     * @return array<mixed>
     */
    public static function ksesAttributes(array $attrs): array
    {
        array_walk_recursive($attrs, static function (&$value): void {
            if (is_string($value)) {
                $value = wp_kses_post($value);
            }
        });
        return $attrs;
    }

    /**
     * Default cap on a captured image upload, in bytes (5 MB). Filterable via
     * `proto_blocks_capture_max_bytes`.
     */
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    /**
     * Image MIME types a capture may be stored as.
     */
    public const ALLOWED_IMAGE_MIMES = ['image/png', 'image/jpeg'];

    /**
     * Validate decoded capture bytes before they are written to disk.
     *
     * Returns null when the bytes are an acceptable image, otherwise an error
     * code: `empty`, `too_large`, `not_image` (not a parseable raster image)
     * or `bad_mime` (an image, but not PNG/JPEG).
     */
    public static function validateImageBytes(string $bytes, int $maxBytes = self::MAX_IMAGE_BYTES): ?string
    {
        if ($bytes === '') {
            return 'empty';
        }
        if ($maxBytes > 0 && strlen($bytes) > $maxBytes) {
            return 'too_large';
        }

        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || empty($info['mime'])) {
            return 'not_image';
        }
        if (!in_array($info['mime'], self::ALLOWED_IMAGE_MIMES, true)) {
            return 'bad_mime';
        }

        return null;
    }

    /**
     * Depth-first search of parsed blocks for the first `$blockName`; its
     * attributes, or null.
     *
     * @param array<int, array{blockName?:?string,attrs?:array,innerBlocks?:array}> $blocks
     */
    public static function firstBlockAttributes(array $blocks, string $blockName): ?array
    {
        foreach ($blocks as $b) {
            if (($b['blockName'] ?? null) === $blockName) {
                return is_array($b['attrs'] ?? null) ? $b['attrs'] : [];
            }
            if (!empty($b['innerBlocks']) && is_array($b['innerBlocks'])) {
                $found = self::firstBlockAttributes($b['innerBlocks'], $blockName);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
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

        $attributes = $this->captureAttributes($blockName, $block);

        $engine = Plugin::getInstance()->getEngine();
        $html   = '<div class="proto-blocks-scope">'
                . $engine->renderPreview($templatePath, $attributes, $schema)
                . '</div>';

        // Build the minimal document. We deliberately bypass
        // get_header() / wp_head() so we don't paint the site nav /
        // banner / admin bar. Only the asset stack runs.
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        // The document is only ever meant to be framed by the capture page on
        // this origin, and it must not leak the admin-ajax URL (with its
        // nonce) to any asset host a template references.
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=1280, initial-scale=1">
<title><?php echo esc_html($schema['title'] ?? $blockName); ?> — Preview</title>
<meta name="proto-blocks-capture-source" content="<?php echo esc_attr($this->captureSource); ?>">
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
  /* The capture measures the body to size the shot: a theme's
     `min-height: 100vh` on html/body would pad it to the frame height. */
  html, body { min-height: 0 !important; height: auto !important; }
  body { width: 1280px; }
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
     * Every registered block with its preview status, for the Preview
     * Capture screen (GET admin/previews drops `path`).
     *
     * @return array<int, array{name:string,title:string,path:string,hasPreview:bool,previewUrl:?string,previewMtime:?int,renderUrl:string}>
     */
    public function collectBlocks(): array
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
        // Not under a web-served directory we know: there is no URL for it.
        // Never fall back to the filesystem path (it would be printed into
        // the page and the REST response).
        return '';
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

        if (!AdminPage::fileModsAllowed()) {
            return new \WP_Error('proto_blocks_capture_file_mods', __('File modifications are disabled on this site (DISALLOW_FILE_MODS).', 'proto-blocks'), ['status' => 403]);
        }

        $registrar  = Plugin::getInstance()->getRegistrar();
        $registered = $registrar->getRegisteredBlocks();
        if (!isset($registered[$blockName])) {
            return new \WP_Error('proto_blocks_capture_not_found', __('Block not registered.', 'proto-blocks'), ['status' => 404]);
        }

        /**
         * Largest capture upload accepted, in bytes.
         *
         * @param int $maxBytes Default 5 MB.
         */
        $maxBytes = max(0, (int) apply_filters('proto_blocks_capture_max_bytes', self::MAX_IMAGE_BYTES));

        // Cheap pre-check on the encoded length before decoding (base64 is
        // 4/3 the size of the payload) so an oversize body is rejected early.
        if ($maxBytes > 0 && strlen($imageData) > (int) ceil($maxBytes * 4 / 3) + 64) {
            return new \WP_Error('proto_blocks_capture_too_large', __('Image too large.', 'proto-blocks'), ['status' => 413]);
        }

        // Strip "data:image/png;base64," prefix.
        if (!preg_match('#^data:image/(png|jpe?g);base64,([A-Za-z0-9+/=\s]+)$#i', $imageData, $matches)) {
            return new \WP_Error('proto_blocks_capture_bad_image', __('Unsupported image payload.', 'proto-blocks'), ['status' => 400]);
        }
        $bytes = base64_decode($matches[2], true);
        if ($bytes === false) {
            return new \WP_Error('proto_blocks_capture_decode', __('Failed to decode image.', 'proto-blocks'), ['status' => 400]);
        }

        // The declared MIME in the data URL is not trusted: the bytes
        // themselves must parse as a PNG or JPEG.
        $problem = self::validateImageBytes($bytes, $maxBytes);
        if ($problem !== null) {
            return match ($problem) {
                'too_large' => new \WP_Error('proto_blocks_capture_too_large', __('Image too large.', 'proto-blocks'), ['status' => 413]),
                default     => new \WP_Error('proto_blocks_capture_not_image', __('Payload is not a PNG or JPEG image.', 'proto-blocks'), ['status' => 400]),
            };
        }

        // Always write to preview.png (the inserter convention). If a
        // preview.jpg was sitting in the folder we leave it alone --
        // SchemaReader checks PNG first so the new file wins.
        $blockDir   = $registered[$blockName]['path'];
        $targetPath = $blockDir . '/preview.png';
        if (!is_dir($blockDir) || !is_writable($blockDir)) {
            return new \WP_Error('proto_blocks_capture_write', __('Could not write file. Check folder permissions.', 'proto-blocks'), ['status' => 500]);
        }

        // Atomic replace: write to a temp file in the same directory, then
        // rename over the target so a reader never sees a half-written PNG.
        $tmpPath = $blockDir . '/.preview-' . wp_generate_password(12, false) . '.tmp';
        $written = file_put_contents($tmpPath, $bytes, LOCK_EX);
        if ($written === false || $written !== strlen($bytes) || !rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            return new \WP_Error('proto_blocks_capture_write', __('Could not write file. Check folder permissions.', 'proto-blocks'), ['status' => 500]);
        }
        @chmod($targetPath, 0644);
        clearstatcache(true, $targetPath);

        return rest_ensure_response([
            'success'    => true,
            'block'      => $blockName,
            'bytes'      => $written,
            'previewUrl' => $this->pathToUrl($targetPath),
            'mtime'      => filemtime($targetPath),
        ]);
    }

    /**
     * Writing into the theme/plugin tree is a file modification: without the
     * capability the answer is false (rest_forbidden); with it but with file
     * mods disabled, the proto_blocks_file_mods error the admin routes use.
     */
    public function restPermission(): bool|\WP_Error
    {
        if (!current_user_can(self::CAPABILITY)) {
            return false;
        }
        return AdminPage::fileModsAllowed() ? true : AdminRestController::fileModsError();
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
}
