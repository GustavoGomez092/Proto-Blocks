<?php
/**
 * WP-CLI Commands for Proto-Blocks
 *
 * @package ProtoBlocks
 */

namespace ProtoBlocks\CLI;

use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Schema\SchemaValidator;
use WP_CLI;
use WP_CLI_Command;

/**
 * Manage Proto-Blocks from the command line.
 */
class Commands extends WP_CLI_Command {
    /**
     * List all registered Proto-Blocks.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks list
     *     wp proto-blocks list --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function list( array $args, array $assoc_args ): void {
        $plugin    = Plugin::getInstance();
        $registrar = $plugin->getRegistrar();

        if ( ! $registrar ) {
            WP_CLI::error( 'Proto-Blocks is not properly initialized.' );
            return;
        }

        $blocks_data = $registrar->getBlocksData();

        if ( empty( $blocks_data ) ) {
            WP_CLI::warning( 'No Proto-Blocks registered.' );
            return;
        }

        $items = array_map(
            function ( $block ) {
                return [
                    'name'        => 'proto-blocks/' . $block['name'],
                    'title'       => $block['title'],
                    'category'    => $block['category'],
                    'description' => $block['description'] ?? '',
                ];
            },
            $blocks_data
        );

        $format = $assoc_args['format'] ?? 'table';
        WP_CLI\Utils\format_items( $format, $items, [ 'name', 'title', 'category', 'description' ] );
    }

    /**
     * Create a new Proto-Block scaffold.
     *
     * ## OPTIONS
     *
     * <name>
     * : The block name (slug format, e.g., 'card' or 'testimonial').
     *
     * [--title=<title>]
     * : The block title.
     *
     * [--description=<description>]
     * : The block description.
     *
     * [--category=<category>]
     * : The block category.
     * ---
     * default: proto-blocks
     * ---
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields in format name:type (e.g., "title:text,content:wysiwyg,image:image").
     *
     * [--dir=<directory>]
     * : Directory to create the block in.
     * ---
     * default: theme
     * options:
     *   - theme
     *   - plugin
     * ---
     *
     * [--force]
     * : Overwrite existing files.
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks create card --title="Card Block"
     *     wp proto-blocks create testimonial --fields="quote:wysiwyg,author:text,image:image"
     *     wp proto-blocks create hero --title="Hero Section" --category="layout"
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function create( array $args, array $assoc_args ): void {
        $name = sanitize_title( $args[0] );

        if ( empty( $name ) ) {
            WP_CLI::error( 'Block name is required.' );
            return;
        }

        // Determine target directory
        $dir_type = $assoc_args['dir'] ?? 'theme';
        if ( 'theme' === $dir_type ) {
            $base_dir = get_stylesheet_directory() . '/proto-blocks';
        } else {
            $base_dir = WP_PLUGIN_DIR . '/proto-blocks-custom';
        }

        $block_dir = $base_dir . '/' . $name;

        // Check if block already exists
        if ( is_dir( $block_dir ) && empty( $assoc_args['force'] ) ) {
            WP_CLI::error( "Block '{$name}' already exists. Use --force to overwrite." );
            return;
        }

        // Create directory
        if ( ! wp_mkdir_p( $block_dir ) ) {
            WP_CLI::error( "Failed to create directory: {$block_dir}" );
            return;
        }

        // Parse fields
        $fields = [];
        if ( ! empty( $assoc_args['fields'] ) ) {
            $field_pairs = explode( ',', $assoc_args['fields'] );
            foreach ( $field_pairs as $pair ) {
                $parts = explode( ':', trim( $pair ) );
                if ( count( $parts ) >= 2 ) {
                    $field_name = sanitize_key( $parts[0] );
                    $field_type = sanitize_key( $parts[1] );
                    $fields[ $field_name ] = $this->getFieldConfig( $field_type, $field_name );
                }
            }
        }

        // Default fields if none specified
        if ( empty( $fields ) ) {
            $fields = [
                'title'   => [ 'type' => 'text', 'tagName' => 'h2' ],
                'content' => [ 'type' => 'wysiwyg' ],
            ];
        }

        // Build block.json
        $title       = $assoc_args['title'] ?? ucwords( str_replace( '-', ' ', $name ) );
        $description = $assoc_args['description'] ?? "A {$title} block.";
        $category    = $assoc_args['category'] ?? 'proto-blocks';

        $block_json = [
            '$schema'     => 'https://schemas.wp.org/trunk/block.json',
            'apiVersion'  => 3,
            'name'        => "proto-blocks/{$name}",
            'title'       => $title,
            'description' => $description,
            'category'    => $category,
            'icon'        => 'block-default',
            'keywords'    => [ $name ],
            'supports'    => [
                'html'            => false,
                'anchor'          => true,
                'customClassName' => true,
            ],
            'protoBlocks' => [
                'version'  => '1.0',
                'template' => 'template.php',
                'fields'   => $fields,
            ],
        ];

        // Write block.json
        $json_file = $block_dir . '/block.json';
        $json_content = wp_json_encode( $block_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! file_put_contents( $json_file, $json_content ) ) {
            WP_CLI::error( "Failed to write block.json" );
            return;
        }

        // Generate template.php
        $template_content = $this->generateTemplate( $name, $fields );
        $template_file = $block_dir . '/template.php';
        if ( ! file_put_contents( $template_file, $template_content ) ) {
            WP_CLI::error( "Failed to write template.php" );
            return;
        }

        // Generate style.css
        $style_content = $this->generateStyles( $name, $fields );
        $style_file = $block_dir . '/style.css';
        file_put_contents( $style_file, $style_content );

        WP_CLI::success( "Block '{$name}' created at: {$block_dir}" );
        WP_CLI::log( "Files created:" );
        WP_CLI::log( "  - block.json" );
        WP_CLI::log( "  - template.php" );
        WP_CLI::log( "  - style.css" );
    }

    /**
     * Validate all Proto-Blocks.
     *
     * ## OPTIONS
     *
     * [<name>]
     * : Specific block name to validate.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks validate
     *     wp proto-blocks validate card
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function validate( array $args, array $assoc_args ): void {
        $plugin    = Plugin::getInstance();
        $discovery = $plugin->getDiscovery();

        if ( ! $discovery ) {
            WP_CLI::error( 'Proto-Blocks is not properly initialized.' );
            return;
        }

        $validator = new SchemaValidator();
        $blocks    = $discovery->discoverBlocks();

        // Filter to specific block if provided
        if ( ! empty( $args[0] ) ) {
            $target_name = $args[0];
            $blocks = array_filter(
                $blocks,
                function ( $path ) use ( $target_name ) {
                    return basename( $path ) === $target_name;
                }
            );

            if ( empty( $blocks ) ) {
                WP_CLI::error( "Block '{$target_name}' not found." );
                return;
            }
        }

        if ( empty( $blocks ) ) {
            WP_CLI::warning( 'No Proto-Blocks found to validate.' );
            return;
        }

        $results = [];
        $has_errors = false;

        foreach ( $blocks as $block_path ) {
            $name = basename( $block_path );
            $json_file = $block_path . '/block.json';

            if ( ! file_exists( $json_file ) ) {
                $results[] = [
                    'block'  => $name,
                    'status' => 'error',
                    'message' => 'Missing block.json',
                ];
                $has_errors = true;
                continue;
            }

            $schema = json_decode( file_get_contents( $json_file ), true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $results[] = [
                    'block'  => $name,
                    'status' => 'error',
                    'message' => 'Invalid JSON: ' . json_last_error_msg(),
                ];
                $has_errors = true;
                continue;
            }

            $validation = $validator->validate( $schema );

            if ( $validation->isValid() ) {
                $warnings = $validation->getWarnings();
                if ( ! empty( $warnings ) ) {
                    $results[] = [
                        'block'  => $name,
                        'status' => 'warning',
                        'message' => implode( '; ', $warnings ),
                    ];
                } else {
                    $results[] = [
                        'block'  => $name,
                        'status' => 'valid',
                        'message' => 'OK',
                    ];
                }
            } else {
                $results[] = [
                    'block'  => $name,
                    'status' => 'error',
                    'message' => implode( '; ', $validation->getErrors() ),
                ];
                $has_errors = true;
            }
        }

        $format = $assoc_args['format'] ?? 'table';
        WP_CLI\Utils\format_items( $format, $results, [ 'block', 'status', 'message' ] );

        if ( $has_errors ) {
            WP_CLI::error( 'Validation failed for one or more blocks.' );
        } else {
            WP_CLI::success( 'All blocks validated successfully.' );
        }
    }

    /**
     * Clear the Proto-Blocks template cache.
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks cache clear
     *
     * @subcommand clear
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function cache_clear( array $args, array $assoc_args ): void {
        $plugin = Plugin::getInstance();
        $cache  = $plugin->getCache();

        if ( ! $cache ) {
            WP_CLI::error( 'Cache system is not available.' );
            return;
        }

        $cache->clear();
        WP_CLI::success( 'Template cache cleared successfully.' );
    }

    /**
     * Show cache statistics.
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks cache stats
     *
     * @subcommand stats
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function cache_stats( array $args, array $assoc_args ): void {
        $cache_dir = WP_CONTENT_DIR . '/cache/proto-blocks';

        if ( ! is_dir( $cache_dir ) ) {
            WP_CLI::log( 'Cache directory does not exist (no items cached yet).' );
            return;
        }

        $files = glob( $cache_dir . '/*.php' );
        $total_size = 0;

        foreach ( $files as $file ) {
            $total_size += filesize( $file );
        }

        WP_CLI::log( sprintf( 'Cached templates: %d', count( $files ) ) );
        WP_CLI::log( sprintf( 'Total cache size: %s', size_format( $total_size ) ) );
        WP_CLI::log( sprintf( 'Cache directory: %s', $cache_dir ) );
    }

    /**
     * Export a block to a standalone directory.
     *
     * ## OPTIONS
     *
     * <name>
     * : The block name to export.
     *
     * [--output=<path>]
     * : Output directory path.
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks export card
     *     wp proto-blocks export testimonial --output=/path/to/export
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function export( array $args, array $assoc_args ): void {
        $name = $args[0];

        $plugin    = Plugin::getInstance();
        $discovery = $plugin->getDiscovery();

        if ( ! $discovery ) {
            WP_CLI::error( 'Proto-Blocks is not properly initialized.' );
            return;
        }

        $blocks = $discovery->discoverBlocks();
        $source_path = null;

        foreach ( $blocks as $block_path ) {
            if ( basename( $block_path ) === $name ) {
                $source_path = $block_path;
                break;
            }
        }

        if ( ! $source_path ) {
            WP_CLI::error( "Block '{$name}' not found." );
            return;
        }

        $output_dir = $assoc_args['output'] ?? getcwd() . '/' . $name;

        if ( is_dir( $output_dir ) ) {
            WP_CLI::error( "Output directory already exists: {$output_dir}" );
            return;
        }

        // Copy directory recursively
        $this->copyDirectory( $source_path, $output_dir );

        WP_CLI::success( "Block '{$name}' exported to: {$output_dir}" );
    }

    /**
     * Get field configuration based on type.
     *
     * @param string $type       Field type.
     * @param string $field_name Field name.
     * @return array Field configuration.
     */
    private function getFieldConfig( string $type, string $field_name ): array {
        $config = [ 'type' => $type ];

        switch ( $type ) {
            case 'text':
                if ( in_array( $field_name, [ 'title', 'heading' ], true ) ) {
                    $config['tagName'] = 'h2';
                } else {
                    $config['tagName'] = 'p';
                }
                break;

            case 'image':
                $config['sizes'] = [ 'medium', 'large', 'full' ];
                break;

            case 'link':
                $config['tagName'] = 'a';
                break;

            case 'repeater':
                $config['min'] = 1;
                $config['max'] = 10;
                $config['fields'] = [
                    'text' => [ 'type' => 'text' ],
                ];
                break;
        }

        return $config;
    }

    /**
     * Generate template.php content.
     *
     * @param string $name   Block name.
     * @param array  $fields Block fields.
     * @return string Template content.
     */
    private function generateTemplate( string $name, array $fields ): string {
        $class_name = 'wp-block-proto-blocks-' . $name;

        $output = "<?php\n";
        $output .= "/**\n";
        $output .= " * Block: " . ucwords( str_replace( '-', ' ', $name ) ) . "\n";
        $output .= " *\n";
        $output .= " * @var array \$attributes Block attributes.\n";
        $output .= " * @var string \$content Inner blocks content.\n";
        $output .= " * @var WP_Block \$block Block instance.\n";
        $output .= " */\n\n";
        $output .= "?>\n";
        $output .= "<div class=\"{$class_name}\">\n";

        foreach ( $fields as $field_name => $config ) {
            $type = $config['type'] ?? 'text';
            $tag = $config['tagName'] ?? 'div';

            switch ( $type ) {
                case 'text':
                case 'wysiwyg':
                    $output .= "    <{$tag} data-proto-field=\"{$field_name}\"><?php echo esc_html( \$attributes['{$field_name}'] ?? '' ); ?></{$tag}>\n";
                    break;

                case 'image':
                    $output .= "    <?php if ( ! empty( \$attributes['{$field_name}']['url'] ) ) : ?>\n";
                    $output .= "    <figure data-proto-field=\"{$field_name}\">\n";
                    $output .= "        <img src=\"<?php echo esc_url( \$attributes['{$field_name}']['url'] ); ?>\" alt=\"<?php echo esc_attr( \$attributes['{$field_name}']['alt'] ?? '' ); ?>\" />\n";
                    $output .= "    </figure>\n";
                    $output .= "    <?php endif; ?>\n";
                    break;

                case 'link':
                    $output .= "    <?php if ( ! empty( \$attributes['{$field_name}']['url'] ) ) : ?>\n";
                    $output .= "    <a data-proto-field=\"{$field_name}\" href=\"<?php echo esc_url( \$attributes['{$field_name}']['url'] ); ?>\">\n";
                    $output .= "        <?php echo esc_html( \$attributes['{$field_name}']['text'] ?? '' ); ?>\n";
                    $output .= "    </a>\n";
                    $output .= "    <?php endif; ?>\n";
                    break;

                case 'repeater':
                    $output .= "    <?php if ( ! empty( \$attributes['{$field_name}'] ) ) : ?>\n";
                    $output .= "    <div data-proto-repeater=\"{$field_name}\">\n";
                    $output .= "        <?php foreach ( \$attributes['{$field_name}'] as \$item ) : ?>\n";
                    $output .= "        <div data-proto-repeater-item=\"{$field_name}\">\n";
                    $output .= "            <!-- Repeater item content -->\n";
                    $output .= "        </div>\n";
                    $output .= "        <?php endforeach; ?>\n";
                    $output .= "    </div>\n";
                    $output .= "    <?php endif; ?>\n";
                    break;

                case 'inner-blocks':
                    $output .= "    <div data-proto-inner-blocks>\n";
                    $output .= "        <?php echo \$content; ?>\n";
                    $output .= "    </div>\n";
                    break;
            }
        }

        $output .= "</div>\n";

        return $output;
    }

    /**
     * Generate basic styles.
     *
     * @param string $name   Block name.
     * @param array  $fields Block fields.
     * @return string CSS content.
     */
    private function generateStyles( string $name, array $fields ): string {
        $class_name = 'wp-block-proto-blocks-' . $name;

        $output = "/**\n";
        $output .= " * Styles for " . ucwords( str_replace( '-', ' ', $name ) ) . " block\n";
        $output .= " */\n\n";
        $output .= ".{$class_name} {\n";
        $output .= "    /* Add your styles here */\n";
        $output .= "}\n";

        return $output;
    }

    /**
     * Copy a directory recursively.
     *
     * @param string $source      Source directory.
     * @param string $destination Destination directory.
     */
    private function copyDirectory( string $source, string $destination ): void {
        if ( ! is_dir( $destination ) ) {
            wp_mkdir_p( $destination );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $target = $destination . '/' . $iterator->getSubPathName();

            if ( $item->isDir() ) {
                wp_mkdir_p( $target );
            } else {
                copy( $item->getPathname(), $target );
            }
        }
    }
}

/**
 * Register WP-CLI commands if available.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'proto-blocks', Commands::class );

    // Add cache subcommand
    WP_CLI::add_command( 'proto-blocks cache', function( $args, $assoc_args ) {
        $commands = new Commands();

        if ( empty( $args[0] ) || $args[0] === 'clear' ) {
            $commands->cache_clear( $args, $assoc_args );
        } elseif ( $args[0] === 'stats' ) {
            $commands->cache_stats( $args, $assoc_args );
        } else {
            WP_CLI::error( "Unknown cache command: {$args[0]}" );
        }
    } );
}
